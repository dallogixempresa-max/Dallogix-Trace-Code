<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioMapeamentosSincronizacao;
use App\Persistencia\RepositorioPayloadSincronizacao;
use App\Persistencia\RepositorioInstalacaoLocal;

require_once __DIR__ . "/ClienteHttpSincronizacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioMapeamentosSincronizacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioPayloadSincronizacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioInstalacaoLocal.php";

final class ServicoSincronizacao
{
    private const RESERVA_EXPIRADA_APOS_MINUTOS = 5;
    private readonly ClienteHttpSincronizacao $clienteHttp;
    private readonly RepositorioFilaSincronizacao $repositorioFila;
    private readonly RepositorioPayloadSincronizacao $payloadSincronizacao;
    private readonly RepositorioMapeamentosSincronizacao $repositorioMapeamentos;
    private readonly RepositorioInstalacaoLocal $repositorioInstalacaoLocal;
    private bool $configuracaoInstalacaoCarregada = false;
    /** @var array{company_id:?int,sync_token:?string}|null */
    private ?array $configuracaoInstalacao = null;

    public function __construct(
        PDO $connection,
        ?ClienteHttpSincronizacao $clienteHttp = null,
        ?RepositorioPayloadSincronizacao $payloadSincronizacao = null,
        ?RepositorioInstalacaoLocal $repositorioInstalacaoLocal = null,
        ?RepositorioFilaSincronizacao $repositorioFila = null,
        ?RepositorioMapeamentosSincronizacao $repositorioMapeamentos = null,
    )
    {
        $this->clienteHttp = $clienteHttp ?? new ClienteHttpSincronizacao();
        $this->repositorioFila = $repositorioFila ?? new RepositorioFilaSincronizacao($connection);
        $this->payloadSincronizacao = $payloadSincronizacao ?? new RepositorioPayloadSincronizacao($connection);
        $this->repositorioInstalacaoLocal = $repositorioInstalacaoLocal ?? new RepositorioInstalacaoLocal($connection);
        $this->repositorioMapeamentos = $repositorioMapeamentos ?? new RepositorioMapeamentosSincronizacao($connection);
    }

    /** @return array{processed:bool,status:string,id:int,reason?:string,error?:string,http_code?:int} */
    public function processarUm(int $queueId, int $companyId): array
    {
        // Recupera reservas vencidas antes de consultar o evento; isso evita que
        // um retry individual fique preso em PROCESSANDO para sempre.
        $this->recuperarReservasVencidas();
        $event = $this->repositorioFila->localizarEvento($queueId, $companyId);
        if ($event === null) {
            return [
                "processed" => false,
                "status" => "NAO_ENCONTRADO",
                "id" => $queueId,
                "reason" => "Evento não encontrado ou não disponível para retry.",
            ];
        }

        $batchUrl = $this->obterUrlRemotaEmLote();
        $remoteUrl = $batchUrl !== "" ? $batchUrl : $this->obterUrlRemota($companyId);
        if ($remoteUrl === "") {
            return [
                "processed" => false,
                "status" => (string) $event["status"],
                "id" => $queueId,
                "reason" => "Nenhum endpoint de sincronização remota configurado",
            ];
        }

        $reserved = $this->repositorioFila->reservarLote(1, $companyId, $queueId);
        if ($reserved === []) {
            return [
                "processed" => false,
                "status" => "CONCORRENTE",
                "id" => $queueId,
                "reason" => "Evento já reservado ou aguardando o próximo horário de tentativa.",
            ];
        }

        return $batchUrl !== ""
            ? $this->entregarLote($reserved, $remoteUrl)
            : $this->entregarEvento($reserved[0], $remoteUrl);
    }

    /** @return array{reserved:int,sent:int,failed:int,skipped:int} */
    public function processarLote(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        // Recupera reservas expiradas antes de descobrir quais empresas ainda
        // possuem eventos disponíveis para sincronização.
        $this->recuperarReservasVencidas();
        $installedCompanyId = $this->obterIdEmpresaInstalada();
        $installedRemoteUrl = $installedCompanyId !== null
            ? $this->obterUrlRemota($installedCompanyId)
            : "";
        $usesInstalledSync = $installedCompanyId !== null && $installedRemoteUrl !== "";
        $batchUrl = $usesInstalledSync ? "" : $this->obterUrlRemotaEmLote();
        $globalRemoteUrl = $usesInstalledSync ? "" : $this->obterUrlRemota();
        if ($usesInstalledSync) {
            $destinations = [["company_id" => $installedCompanyId, "url" => $installedRemoteUrl]];
        } elseif ($batchUrl !== "") {
            $destinations = [["company_id" => null, "url" => $batchUrl]];
        } elseif ($globalRemoteUrl !== "") {
            $destinations = [["company_id" => null, "url" => $globalRemoteUrl]];
        } else {
            // Carrega empresa e URL na mesma consulta para evitar uma ida ao
            // banco por empresa quando existem várias filas pendentes.
            $destinations = array_map(
                static fn (array $destination): array => [
                    "company_id" => $destination["company_id"],
                    "url" => \url_remota_segura(trim($destination["sync_remote_url"])),
                ],
                $this->repositorioFila->destinosRemotosDisponiveis(),
            );
        }
        $summary = ["reserved" => 0, "sent" => 0, "failed" => 0, "skipped" => 0];

        foreach ($destinations as $destination) {
            $companyId = $destination["company_id"];
            $remoteUrl = $destination["url"];
            if ($remoteUrl === "") {
                continue;
            }
            $events = $this->repositorioFila->reservarLote($limit, $companyId);
            $summary["reserved"] += count($events);
            if ($events === []) {
                continue;
            }
            if ($batchUrl !== "") {
                $result = $this->entregarLote($events, $remoteUrl);
                $summary["sent"] += (int) ($result["sent"] ?? 0);
                $summary["failed"] += (int) ($result["failed"] ?? 0);
                continue;
            }
            foreach ($events as $event) {
                $result = $this->entregarEvento($event, $remoteUrl);
                if ($result["processed"] === true && $result["status"] === "ENVIADO") {
                    $summary["sent"]++;
                } elseif ($result["status"] === "ERRO") {
                    $summary["failed"]++;
                } else {
                    $summary["skipped"]++;
                }
            }
        }

        return $summary;
    }

    /** @return list<array<string,mixed>> */
    public function reservarLote(int $limit = 50, ?int $companyId = null, ?int $queueId = null): array
    {
        $this->recuperarReservasVencidas();
        return $this->repositorioFila->reservarLote($limit, $companyId, $queueId);
    }

    /** @return array{processed:bool,status:string,id:int,sent?:int,failed?:int,error?:string,http_code?:int} */
    private function entregarEvento(array $event, string $remoteUrl): array
    {
        try {
            $result = $this->enviarEventoRemoto($event, $remoteUrl);
        } catch (\Throwable $exception) {
            $result = [
                "ok" => false,
                "error" => "Payload de sincronização inválido: " . $exception->getMessage(),
                "http_code" => 0,
            ];
        }
        $id = (int) $event["id"];
        if ($result["ok"] === true) {
            try {
                $this->repositorioMapeamentos->aplicar([$event], (array) ($result["response"] ?? []));
                $this->marcarComoEnviados([$event]);
                return ["processed" => true, "status" => "ENVIADO", "id" => $id];
            } catch (\Throwable $exception) {
                $this->marcarComoFalha(
                    [$event],
                    "Mapeamento remoto inválido: " . $exception->getMessage(),
                    0,
                    true,
                );
                return [
                    "processed" => false,
                    "status" => "ERRO",
                    "id" => $id,
                    "error" => "Mapeamento remoto inválido: " . $exception->getMessage(),
                    "http_code" => (int) ($result["http_code"] ?? 0),
                ];
            }
        }

        $error = $result["error"];
        $this->marcarComoFalha([$event], $error, (int) ($result["http_code"] ?? 0));
        return [
            "processed" => false,
            "status" => "ERRO",
            "id" => $id,
            "error" => $error,
            "http_code" => $result["http_code"],
        ];
    }

    /** @return array{processed:bool,status:string,id:int,sent:int,failed:int,error?:string,http_code?:int} */
    private function entregarLote(array $events, string $remoteUrl): array
    {
        $firstId = (int) ($events[0]["id"] ?? 0);
        try {
            $result = $this->enviarLote($events, $remoteUrl);
        } catch (\Throwable $exception) {
            $result = [
                "ok" => false,
                "error" => "Payload de sincronização inválido: " . $exception->getMessage(),
                "http_code" => 0,
            ];
        }
        if ($result["ok"] === true) {
            try {
                $this->repositorioMapeamentos->aplicar($events, (array) ($result["response"] ?? []));
                $this->marcarComoEnviados($events);
                return [
                    "processed" => true,
                    "status" => "ENVIADO",
                    "id" => $firstId,
                    "sent" => count($events),
                    "failed" => 0,
                ];
            } catch (\Throwable $exception) {
                $error = "Mapeamento remoto inválido: " . $exception->getMessage();
                if (count($events) > 1) {
                    return $this->entregarLotePorEvento($events, $remoteUrl, $error, true);
                }
                $this->marcarComoFalha($events, $error, 0, true);
                return [
                    "processed" => false,
                    "status" => "ERRO",
                    "id" => $firstId,
                    "sent" => 0,
                    "failed" => count($events),
                    "error" => $error,
                    "http_code" => (int) ($result["http_code"] ?? 0),
                ];
            }
        }

        $httpCode = (int) ($result["http_code"] ?? 0);
        // Respostas 4xx normalmente apontam para um único payload inválido.
        // Tente os eventos separadamente para que os válidos avancem. Falhas
        // de rede, 5xx e 429 continuam usando uma única reserva do lote.
        if ($httpCode >= 400 && $httpCode < 500 && !in_array($httpCode, [408, 429], true) && count($events) > 1) {
            return $this->entregarLotePorEvento($events, $remoteUrl, $result["error"], false);
        }
        $this->marcarComoFalha($events, $result["error"], $httpCode);
        return [
            "processed" => false,
            "status" => "ERRO",
            "id" => $firstId,
            "sent" => 0,
            "failed" => count($events),
            "error" => $result["error"],
            "http_code" => $httpCode,
        ];
    }

    /** @param list<array<string,mixed>> $events */
    private function entregarLotePorEvento(
        array $events,
        string $remoteUrl,
        string $fallbackError,
        bool $mappingFailure,
    ): array {
        $sent = 0;
        $failed = 0;
        $firstId = (int) ($events[0]["id"] ?? 0);
        foreach ($events as $event) {
            if ($mappingFailure) {
                $this->marcarComoFalha([$event], $fallbackError, 0, true);
                $failed++;
                continue;
            }
            $result = $this->entregarEvento($event, $remoteUrl);
            if (($result["status"] ?? "") === "ENVIADO") {
                $sent++;
            } else {
                $failed++;
            }
        }
        return [
            "processed" => $failed === 0,
            "status" => $failed === 0 ? "ENVIADO" : "ERRO",
            "id" => $firstId,
            "sent" => $sent,
            "failed" => $failed,
            "error" => $failed > 0 ? $fallbackError : null,
        ];
    }

    /** @return array{ok:bool,error:string,http_code:int,response?:array<string,mixed>} */
    private function enviarEventoRemoto(array $event, string $remoteUrl): array
    {
        $body = json_encode([
            "event_uuid" => $event["event_uuid"],
            "company_id" => $this->idEmpresaRemota($event),
            "aggregate_type" => $event["aggregate_type"],
            "aggregate_id" => (int) $event["aggregate_id"],
            "payload" => $this->decodificarCarga($event),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->postarJson($remoteUrl, $body, (int) $event["company_id"]);
    }

    /** @return array{ok:bool,error:string,http_code:int,response?:array<string,mixed>} */
    private function enviarLote(array $events, string $remoteUrl): array
    {
        $items = [];
        foreach ($events as $event) {
            $items[] = [
                "event_uuid" => $event["event_uuid"],
                "company_id" => $this->idEmpresaRemota($event),
                "aggregate_type" => $event["aggregate_type"],
                "aggregate_id" => (int) $event["aggregate_id"],
                "payload" => $this->decodificarCarga($event),
            ];
        }
        $body = json_encode(["events" => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $companyId = (int) ($events[0]["company_id"] ?? 0);
        return $this->postarJson($remoteUrl, $body, $companyId > 0 ? $companyId : null);
    }

    private function idEmpresaRemota(array $event): int
    {
        $remoteCompanyId = filter_var($event["remote_company_id"] ?? null, FILTER_VALIDATE_INT);
        return $remoteCompanyId !== false && (int) $remoteCompanyId > 0
            ? (int) $remoteCompanyId
            : (int) $event["company_id"];
    }

    /** @return array{ok:bool,error:string,http_code:int,response?:array<string,mixed>} */
    private function postarJson(string $remoteUrl, string $body, ?int $companyId = null): array
    {
        return $this->clienteHttp->postarJson($remoteUrl, $body, $this->obterTokenRemoto($companyId));
    }

    /** @return mixed */
    private function decodificarCarga(array $event): mixed
    {
        return $this->payloadSincronizacao->prepararEvento($event);
    }

    /** @param list<array<string,mixed>> $events */
    private function marcarComoEnviados(array $events): void
    {
        $this->repositorioFila->marcarComoEnviados($events);
    }

    /** @param list<array<string,mixed>> $events */
    private function marcarComoFalha(
        array $events,
        string $error,
        int $httpCode = 0,
        bool $permanentOverride = false,
    ): void
    {
        $maxAttempts = max(1, min(100, (int) (getenv("SYNC_MAX_ATTEMPTS") ?: 5)));
        $permanent = $permanentOverride || (
            $httpCode >= 400 &&
            $httpCode < 500 &&
            !in_array($httpCode, [408, 429], true)
        );
        $this->repositorioFila->marcarComoFalha($events, $error, $permanent, $maxAttempts);
    }

    private function recuperarReservasVencidas(): void
    {
        $this->repositorioFila->recuperarReservasVencidas(self::RESERVA_EXPIRADA_APOS_MINUTOS);
    }

    private function obterUrlRemota(?int $companyId = null): string
    {
        if ($companyId !== null) {
            $configured = $this->repositorioFila->urlRemotaDaEmpresa($companyId);
            if ($configured !== "") {
                return \url_remota_segura($configured);
            }
        }
        return \url_remota_segura(trim((string) (getenv("SYNC_REMOTE_URL") ?: "")));
    }

    private function obterTokenRemoto(?int $companyId = null): string
    {
        $installation = $this->configuracaoInstalacaoLocal();
        if ($installation) {
            $installedCompanyId = (int) ($installation["company_id"] ?? 0);
            if ($companyId === null || $installedCompanyId === $companyId) {
                $normalized = strtolower(trim((string) ($installation["sync_token"] ?? "")));
                return preg_match('/\A[a-f0-9]{64}\z/', $normalized) === 1 ? $normalized : "";
            }
            // Nunca use um token global para enviar eventos de outra empresa
            // quando esta instalação já está vinculada a uma empresa.
            return "";
        }
        return trim((string) (getenv("SYNC_REMOTE_TOKEN") ?: ""));
    }

    private function obterIdEmpresaInstalada(): ?int
    {
        $installation = $this->configuracaoInstalacaoLocal();
        if ($installation === null || trim((string) ($installation["sync_token"] ?? "")) === "") {
            return null;
        }
        return (int) ($installation["company_id"] ?? 0);
    }

    /**
     * O worker cria este serviço para processar um lote; reutilizar a leitura
     * da instalação evita consultar a mesma credencial uma vez por evento.
     *
     * @return array{company_id:?int,sync_token:?string}|null
     */
    private function configuracaoInstalacaoLocal(): ?array
    {
        if (!$this->configuracaoInstalacaoCarregada) {
            $this->configuracaoInstalacao = $this->repositorioInstalacaoLocal->buscarConfiguracaoSincronizacao();
            $this->configuracaoInstalacaoCarregada = true;
        }
        return $this->configuracaoInstalacao;
    }

    private function obterUrlRemotaEmLote(): string
    {
        return \url_remota_segura(trim((string) (getenv("SYNC_REMOTE_BATCH_URL") ?: "")));
    }
}
