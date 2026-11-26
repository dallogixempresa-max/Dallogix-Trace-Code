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
    private const MAXIMO_CONTADOR_ROTA_TCP = 1000000000;
    private const MAXIMO_TEXTO_ROTA_TCP = 120;
    private const MAXIMO_ESTADO_ROTA_TCP = 40;
    private const MAXIMO_CARREGAMENTOS_ROTA_TCP = 5;
    private const VALIDADE_ERRO_ROTA_TCP_SEGUNDOS = 600;

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
                $probeMethod = strtoupper((string) ($details["reader_probe_method"] ?? ""));
                $device["reader_probe_method"] = in_array($probeMethod, ["ICMP_PING", "TCP_CONNECT"], true)
                    ? $probeMethod
                    : ($device["reader_probe_port"] !== null ? "TCP_CONNECT" : null);
                $readerIp = filter_var($details["reader_ip"] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                $device["reader_ip"] = $readerIp !== false ? $readerIp : null;
                $probeCheckedAt = $details["reader_probe_checked_at"] ?? null;
                $probeCheckedTimestamp = is_string($probeCheckedAt) ? strtotime($probeCheckedAt) : false;
                $device["reader_probe_checked_at"] = $probeCheckedTimestamp !== false ? $probeCheckedAt : null;
                $now = time();
                $probeIsRecent = $probeCheckedTimestamp !== false
                    && $probeCheckedTimestamp >= $now - 15
                    && $probeCheckedTimestamp <= $now + 30;
                $lastReadAt = $details["reader_last_read_at"] ?? null;
                $lastReadTimestamp = is_string($lastReadAt) ? strtotime($lastReadAt) : false;
                $device["reader_online_source"] = $probeIsRecent && $device["reader_network_status"] === "ONLINE"
                    ? "REDE"
                    : null;
                $device["reader_probe_is_recent"] = $probeIsRecent;
                $device["scanner_service_status"] = (string) $device["status"];
                $device["reader_last_read_at"] = $lastReadTimestamp !== false ? $lastReadAt : null;
                $device["ftp_image_receiver_listening"] = is_bool($details["ftp_image_receiver_listening"] ?? null)
                    ? $details["ftp_image_receiver_listening"]
                    : null;
                $device["image_archive_directory_available"] = is_bool($details["image_archive_directory_available"] ?? null)
                    ? $details["image_archive_directory_available"]
                    : null;
                $queueStatus = strtoupper((string) ($details["image_queue_status"] ?? ""));
                $device["image_queue_status"] = in_array($queueStatus, ["OK", "ERRO", "AGUARDANDO_CORRELACAO"], true)
                    ? $queueStatus
                    : null;
                foreach (["unmatched_image_count", "ftp_images_received"] as $countField) {
                    $count = filter_var($details[$countField] ?? null, FILTER_VALIDATE_INT);
                    $device[$countField] = $count !== false && $count >= 0 && $count <= 1000000000 ? $count : null;
                }
                $oldestImageAgeMs = filter_var($details["oldest_unmatched_image_age_ms"] ?? null, FILTER_VALIDATE_INT);
                $device["oldest_unmatched_image_age_ms"] = $oldestImageAgeMs !== false
                    && $oldestImageAgeMs >= 0 && $oldestImageAgeMs <= 31536000000
                    ? $oldestImageAgeMs
                    : null;
                $ftpLastImageAt = $details["ftp_last_image_received_at"] ?? null;
                $ftpLastImageTimestamp = is_string($ftpLastImageAt) ? strtotime($ftpLastImageAt) : false;
                $device["ftp_last_image_received_at"] = $ftpLastImageTimestamp !== false ? $ftpLastImageAt : null;
                $ftpTransferErrorCode = trim((string) ($details["ftp_transfer_error_code"] ?? ""));
                $device["ftp_transfer_error_code"] = preg_match('/^[a-z0-9_]{1,80}$/', $ftpTransferErrorCode) === 1
                    ? $ftpTransferErrorCode
                    : null;
                // Rota TCP do leitor: o ping acima só confirma a rede. A conexão do
                // leitor com o worker e as leituras sem passagem vêm do heartbeat.
                $device["tcp_route"] = self::normalizarRotaTcp($details["tcp_route"] ?? null, $now);
            }
            unset($device["details"]);
            $limit = match ($deviceType) {
                "SCANNER" => $scannerSignalLimit,
                default => $clpSignalLimit,
            };
            $ageMicros = $device["micros_sem_sinal"];
            $reportedStatus = (string) $device["status"];
            $isFresh = $ageMicros !== null && (int) $ageMicros <= $limit * 1_000_000;
            if ($deviceType === "SCANNER" && is_array($device["tcp_route"] ?? null)) {
                // Heartbeat vencido: a rota é a última que o worker informou, não
                // o estado atual. O cartão não pode dizer "Rota TCP conectada".
                $device["tcp_route"]["stale"] = !$isFresh;
                $device["tcp_route"]["stale_since"] = !$isFresh && is_string($device["last_seen_at"] ?? null)
                    ? $device["last_seen_at"]
                    : null;
            }
            if ($deviceType === "SCANNER" && !$isFresh) {
                // Um heartbeat vencido indica que não há dado recente do agente,
                // não que o scanner foi fisicamente desconectado.
                $device["status"] = "DESCONHECIDO";
            } elseif ($deviceType === "SCANNER") {
                // O estado principal do leitor acompanha a sondagem recente da
                // rede. Falhas do worker, FTP ou da fila de imagens continuam
                // expostas em scanner_service_status e nos detalhes da fila.
                $device["status"] = $device["reader_online_source"] !== null
                    ? "ONLINE"
                    : ($device["reader_probe_is_recent"] && $device["reader_network_status"] === "OFFLINE"
                        ? "OFFLINE"
                        : ($reportedStatus === "ERRO" ? "ERRO" : "DESCONHECIDO"));
            } elseif ($reportedStatus === "ERRO") {
                $device["status"] = "ERRO";
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

    /**
     * Valida a rota TCP informada pelo worker do leitor. Sem o objeto (worker
     * anterior a esta versão) devolve null e o cartão segue só com o ping.
     *
     * @return array<string,mixed>|null
     */
    private static function normalizarRotaTcp(mixed $route, int $now): ?array
    {
        if (!is_array($route) || $route === [] || array_is_list($route)) {
            return null;
        }
        $scanError = self::textoRotaTcp($route["scan_error"] ?? null, self::MAXIMO_TEXTO_ROTA_TCP);
        $scanErrorAt = $scanError !== null ? self::dataIsoRotaTcp($route["scan_error_at"] ?? null) : null;
        // O worker limpa o erro depois de 10 min; um heartbeat atrasado não o
        // mantém na tela além desse prazo.
        if ($scanErrorAt !== null && (int) strtotime($scanErrorAt) < $now - self::VALIDADE_ERRO_ROTA_TCP_SEGUNDOS) {
            $scanError = null;
            $scanErrorAt = null;
        }

        $unassociated = [];
        $entries = $route["unassociated_by_loading"] ?? null;
        if (is_array($entries) && array_is_list($entries)) {
            foreach ($entries as $entry) {
                if (count($unassociated) >= self::MAXIMO_CARREGAMENTOS_ROTA_TCP) {
                    break;
                }
                if (!is_array($entry)) {
                    continue;
                }
                $loadingId = $entry["loading_id"] ?? null;
                $last = $entry["last"] ?? null;
                $unassociated[] = [
                    "loading_id" => is_int($loadingId) && $loadingId >= 1 ? $loadingId : null,
                    "count" => self::contadorRotaTcp($entry["count"] ?? null),
                    "last" => is_array($last) && !array_is_list($last) ? [
                        "barcode" => self::codigoRotaTcp($last["barcode"] ?? null),
                        "no_read" => ($last["no_read"] ?? false) === true,
                        "reason" => self::textoRotaTcp($last["reason"] ?? null, self::MAXIMO_TEXTO_ROTA_TCP) ?? "",
                        "received_at" => self::dataIsoRotaTcp($last["received_at"] ?? null),
                        // Estado do carregamento quando o código chegou (STATUS do
                        // worker). Fora de CARREGANDO o código não entra em count
                        // e o visor não o mostra, como a rota HID com a esteira parada.
                        "loading_state" => self::textoRotaTcp($last["loading_state"] ?? null, self::MAXIMO_ESTADO_ROTA_TCP),
                    ] : null,
                ];
            }
        }

        return [
            "connected" => ($route["connected"] ?? false) === true,
            "connections_open" => self::contadorRotaTcp($route["connections_open"] ?? null),
            "last_connected_at" => self::dataIsoRotaTcp($route["last_connected_at"] ?? null),
            "last_disconnected_at" => self::dataIsoRotaTcp($route["last_disconnected_at"] ?? null),
            "last_disconnect_reason" => self::textoRotaTcp(
                $route["last_disconnect_reason"] ?? null,
                self::MAXIMO_TEXTO_ROTA_TCP,
            ),
            "last_frame_at" => self::dataIsoRotaTcp($route["last_frame_at"] ?? null),
            "frames_received" => self::contadorRotaTcp($route["frames_received"] ?? null),
            "frames_rejected" => self::contadorRotaTcp($route["frames_rejected"] ?? null),
            "scans_registered" => self::contadorRotaTcp($route["scans_registered"] ?? null),
            "scans_unassociated" => self::contadorRotaTcp($route["scans_unassociated"] ?? null),
            "scans_rejected_by_trace" => self::contadorRotaTcp($route["scans_rejected_by_trace"] ?? null),
            "failed_scans_pending" => self::contadorRotaTcp($route["failed_scans_pending"] ?? null),
            "scan_error" => $scanError,
            "scan_error_at" => $scanErrorAt,
            "unassociated_by_loading" => $unassociated,
        ];
    }

    private static function contadorRotaTcp(mixed $value): int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAXIMO_CONTADOR_ROTA_TCP ? $value : 0;
    }

    /** Aceita somente data ISO 8601 com fuso, como o worker grava (toISOString). */
    private static function dataIsoRotaTcp(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 40
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        return strtotime($value) !== false ? $value : null;
    }

    private static function textoRotaTcp(mixed $value, int $maximo): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', " ", $value);
        $text = trim(mb_substr(is_string($text) ? $text : "", 0, $maximo, "UTF-8"));
        return $text !== "" ? $text : null;
    }

    /** Código exibido no visor: somente ASCII imprimível, até 128 caracteres. */
    private static function codigoRotaTcp(mixed $value): string
    {
        if (!is_string($value)) {
            return "";
        }
        return substr((string) preg_replace('/[^\x20-\x7E]/', "", $value), 0, 128);
    }
}
