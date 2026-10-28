<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;
use App\Persistencia\RepositorioMonitoramento;

require_once __DIR__ . "/EstadoFisicoClp.php";
require_once __DIR__ . "/../Persistencia/RepositorioMonitoramento.php";

/** Serviço compartilhado pelo endpoint HTTP e pelo stream SSE do monitoramento. */
final class ServicoMonitoramento
{
    private const MAXIMO_PERIODO_DIAS = 3650;
    private const MAXIMO_SINAL_CLP_SEGUNDOS = 60;
    private const MAXIMO_SINAL_SCANNER_SEGUNDOS = 300;

    private readonly RepositorioMonitoramento $repositorio;

    public function __construct(PDO $connection, ?RepositorioMonitoramento $repositorio = null)
    {
        $this->repositorio = $repositorio ?? new RepositorioMonitoramento($connection);
    }

    /** @return list<array<string,mixed>> */
    public function obterStatusDispositivos(
        int $companyId,
        int $clpSignalLimit = 3,
        int $scannerSignalLimit = 15,
    ): array {
        $clpSignalLimit = self::limitarValorPositivo($clpSignalLimit, self::MAXIMO_SINAL_CLP_SEGUNDOS);
        $scannerSignalLimit = self::limitarValorPositivo($scannerSignalLimit, self::MAXIMO_SINAL_SCANNER_SEGUNDOS);
        $devices = $this->repositorio->listarStatusDispositivos($companyId);
        foreach ($devices as &$device) {
            $deviceType = (string) $device["device_type"];
            $details = json_decode((string) ($device["details"] ?? ""), true);
            $details = is_array($details) ? $details : [];
            if ($deviceType === "CLP") {
                $capability = is_array($details) && is_array($details["command_capability"] ?? null)
                    ? $details["command_capability"]
                    : [];
                $device["machine_commands_available"] = ($capability["available"] ?? false) === true;
                $mode = strtoupper((string) ($capability["mode"] ?? "DESCONHECIDO"));
                $device["machine_command_mode"] = in_array($mode, ["FISICO", "SIMULADO", "BLOQUEADO"], true)
                    ? $mode
                    : "DESCONHECIDO";
            }
            if ($deviceType === "SCANNER") {
                $readerStatus = strtoupper((string) ($details["reader_network_status"] ?? "DESCONHECIDO"));
                $device["reader_network_status"] = in_array($readerStatus, ["ONLINE", "OFFLINE"], true)
                    ? $readerStatus
                    : "DESCONHECIDO";
                $probePort = filter_var($details["reader_probe_port"] ?? null, FILTER_VALIDATE_INT);
                $device["reader_probe_port"] = $probePort !== false && $probePort >= 1 && $probePort <= 65535
                    ? $probePort
                    : null;
                $lastReadAt = $details["reader_last_read_at"] ?? null;
                $lastReadTimestamp = is_string($lastReadAt) ? strtotime($lastReadAt) : false;
                $lastReadAgeMs = filter_var($details["reader_last_read_age_ms"] ?? null, FILTER_VALIDATE_INT);
                $recentRead = $lastReadAgeMs !== false && $lastReadAgeMs >= 0
                    && $lastReadAgeMs <= $scannerSignalLimit * 1000;
                $device["reader_online_source"] = $device["reader_network_status"] === "ONLINE"
                    ? "REDE"
                    : ($recentRead ? "LEITURA" : null);
                $device["scanner_service_status"] = (string) $device["status"];
                $device["reader_last_read_at"] = $lastReadTimestamp !== false ? $lastReadAt : null;
            }
            unset($device["details"]);
            $limit = match ($deviceType) {
                "SCANNER" => $scannerSignalLimit,
                default => $clpSignalLimit,
            };
            $ageMicros = $device["micros_sem_sinal"];
            $reportedStatus = (string) $device["status"];
            $isFresh = $ageMicros !== null && (int) $ageMicros <= $limit * 1_000_000;
            if ($deviceType === "SCANNER" && !$isFresh) {
                // Um heartbeat vencido indica que não há dado recente do agente,
                // não que o scanner foi fisicamente desconectado.
                $device["status"] = "DESCONHECIDO";
            } elseif ($reportedStatus === "ERRO") {
                $device["status"] = "ERRO";
            } elseif ($deviceType === "SCANNER") {
                $device["status"] = $device["reader_online_source"] !== null
                    ? "ONLINE"
                    : ($device["reader_network_status"] === "OFFLINE" ? "OFFLINE" : "DESCONHECIDO");
            } elseif ($reportedStatus === "DESCONHECIDO") {
                $device["status"] = "DESCONHECIDO";
            } else {
                $device["status"] = $reportedStatus === "ONLINE" && $isFresh
                    ? "ONLINE"
                    : "OFFLINE";
            }
            unset($device["micros_sem_sinal"]);
        }
        unset($device);

        return $devices;
    }

    /**
     * Lista as máquinas da empresa com o estado operacional calculado uma vez,
     * para telas que precisam do estado da Dala sem carregar métricas históricas.
     *
     * @return list<array<string,mixed>>
     */
    public function obterMaquinas(int $companyId, int $clpSignalLimit = 3): array
    {
        $clpSignalLimit = self::limitarValorPositivo($clpSignalLimit, self::MAXIMO_SINAL_CLP_SEGUNDOS);
        $machines = $this->repositorio->listarMaquinas($companyId, $clpSignalLimit);
        foreach ($machines as &$machine) {
            $running = EstadoFisicoClp::estaEmFuncionamentoPelosDetalhes($machine["clp_details"] ?? null);
            $machine["physical_running"] = $running;
            $state = (string) ($machine["carregamento_state"] ?? "");
            if ($machine["clp_status"] !== "ONLINE") {
                $machine["operational_status"] = "SEM_COMUNICACAO";
            } elseif ($running === true) {
                $machine["operational_status"] = "OPERANDO";
            } elseif ($running === false) {
                $machine["operational_status"] = "PARADA_CONFIRMADA";
            } elseif ($state === "EMERGENCIA") {
                $machine["operational_status"] = "EMERGENCIA_SEM_CONFIRMACAO";
            } elseif ($state === "PAUSADO") {
                $machine["operational_status"] = "PARADA_SOLICITADA";
            } elseif (in_array($state, ["CARREGANDO", "FINALIZANDO"], true)) {
                $machine["operational_status"] = "OPERACAO_SEM_RETORNO";
            } else {
                $machine["operational_status"] = "OCIOSA";
            }
            unset($machine["clp_details"]);
        }
        unset($machine);

        return $machines;
    }

    /** @return array<string,mixed> */
    public function obterInstantaneo(
        int $companyId,
        int $periodDays = 30,
        int $clpSignalLimit = 3,
        int $scannerSignalLimit = 15,
    ): array
    {
        $periodDays = self::limitarValorPositivo($periodDays, self::MAXIMO_PERIODO_DIAS);
        $clpSignalLimit = self::limitarValorPositivo($clpSignalLimit, self::MAXIMO_SINAL_CLP_SEGUNDOS);
        $scannerSignalLimit = self::limitarValorPositivo($scannerSignalLimit, self::MAXIMO_SINAL_SCANNER_SEGUNDOS);
        $romaneios = $this->repositorio->contarRomaneios($companyId, $periodDays);
        $readings = $this->repositorio->contarLeituras($companyId, $periodDays);
        $lastReading = $this->repositorio->obterUltimaLeitura($companyId);
        $occurrences = $this->repositorio->listarOcorrenciasRecentes($companyId);
        $audit = $this->repositorio->listarAuditoriaRecente($companyId);
        $pendingSync = $this->repositorio->contarSincronizacoesPendentes($companyId);
        $devices = $this->obterStatusDispositivos($companyId, $clpSignalLimit, $scannerSignalLimit);
        $maquinas = $this->obterMaquinas($companyId, $clpSignalLimit);
        $romaneioSummary = [];
        foreach ($romaneios as $row) {
            $romaneioSummary[(string) $row["status"]] = (int) $row["total"];
        }
        return [
            "romaneios" => $romaneioSummary,
            "leituras" => $this->resumirLeituras($readings),
            "ultima_leitura" => $lastReading[0] ?? null,
            "ocorrencias" => $occurrences,
            "auditoria" => $audit,
            "sync_pendente" => $pendingSync,
            "dispositivos" => $devices,
            "maquinas" => $maquinas,
            "periodo_dias" => $periodDays,
            "limite_sinal_clp_segundos" => $clpSignalLimit,
            "limite_sinal_scanner_segundos" => $scannerSignalLimit,
        ];
    }

    /**
     * Retorna apenas os dados usados em Alertas, evitando consultas históricas
     * que a tela não exibe a cada atualização automática.
     *
     * @return array{leituras:array<string,int>,dispositivos:list<array<string,mixed>>,maquinas:list<array<string,mixed>>,periodo_dias:int,limite_sinal_clp_segundos:int,limite_sinal_scanner_segundos:int}
     */
    public function obterResumoAlertas(
        int $companyId,
        int $periodDays = 30,
        int $clpSignalLimit = 3,
        int $scannerSignalLimit = 15,
    ): array {
        $periodDays = self::limitarValorPositivo($periodDays, self::MAXIMO_PERIODO_DIAS);
        $clpSignalLimit = self::limitarValorPositivo($clpSignalLimit, self::MAXIMO_SINAL_CLP_SEGUNDOS);
        $scannerSignalLimit = self::limitarValorPositivo($scannerSignalLimit, self::MAXIMO_SINAL_SCANNER_SEGUNDOS);

        return [
            "leituras" => $this->resumirLeituras($this->repositorio->contarLeituras($companyId, $periodDays)),
            "dispositivos" => $this->obterStatusDispositivos($companyId, $clpSignalLimit, $scannerSignalLimit),
            "maquinas" => $this->obterMaquinas($companyId, $clpSignalLimit),
            "periodo_dias" => $periodDays,
            "limite_sinal_clp_segundos" => $clpSignalLimit,
            "limite_sinal_scanner_segundos" => $scannerSignalLimit,
        ];
    }

    /**
     * Retorna apenas os indicadores usados pelo resumo de encerramento.
     * Evita consultar estado de máquinas, dispositivos e auditoria a cada ciclo da tela.
     *
     * @return array{leituras:array<string,int>,ocorrencias:list<array<string,mixed>>,sync_pendente:int}
     */
    public function obterResumoFinal(int $companyId, int $periodDays = 30): array
    {
        $periodDays = self::limitarValorPositivo($periodDays, self::MAXIMO_PERIODO_DIAS);

        return [
            "leituras" => $this->resumirLeituras($this->repositorio->contarLeituras($companyId, $periodDays)),
            "ocorrencias" => $this->repositorio->listarOcorrenciasRecentes($companyId),
            "sync_pendente" => $this->repositorio->contarSincronizacoesPendentes($companyId),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function carregamentosAtivos(int $companyId): array
    {
        return $this->repositorio->listarCarregamentosAtivos($companyId);
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array<string,int>
     */
    private function resumirLeituras(array $rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row["result"]] = (int) $row["total"];
        }

        return $summary;
    }

    private static function limitarValorPositivo(int $valor, int $maximo): int
    {
        return max(1, min($maximo, $valor));
    }
}
