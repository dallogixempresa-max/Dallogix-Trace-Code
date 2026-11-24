<?php

declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioCarregamentosRemotos;
use App\Persistencia\RepositorioComandosRemotos;
use App\Persistencia\RepositorioEquipamentosRemotos;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioProdutosRemotos;
use App\Persistencia\RepositorioRomaneiosRemotos;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . "/ValidadorInstantaneoRemoto.php";
require_once __DIR__ . "/DetalhesHeartbeatInstalacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../Persistencia/RepositorioProdutosRemotos.php";
require_once __DIR__ . "/../Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../Persistencia/RepositorioEquipamentosRemotos.php";
require_once __DIR__ . "/../Persistencia/RepositorioComandosRemotos.php";
require_once __DIR__ . "/../Persistencia/RepositorioCarregamentosRemotos.php";

final class ServicoSincronizacaoRemota
{
    private const TEMPO_LIMITE_CONEXAO_SEGUNDOS = 3;
    private const TEMPO_LIMITE_REQUISICAO_SEGUNDOS = 8;

    private readonly RepositorioFilaSincronizacao $filaSincronizacao;
    private readonly RepositorioProdutosRemotos $repositorioProdutos;
    private readonly RepositorioRomaneiosRemotos $repositorioRomaneios;
    private readonly RepositorioEquipamentosRemotos $repositorioEquipamentos;
    private readonly RepositorioComandosRemotos $repositorioComandos;
    private readonly RepositorioCarregamentosRemotos $repositorioCarregamentos;

    public function __construct(private readonly PDO $connection)
    {
        $this->filaSincronizacao = new RepositorioFilaSincronizacao($connection);
        $this->repositorioProdutos = new RepositorioProdutosRemotos($connection, $this->filaSincronizacao);
        $this->repositorioRomaneios = new RepositorioRomaneiosRemotos($connection, $this->filaSincronizacao);
        $this->repositorioEquipamentos = new RepositorioEquipamentosRemotos($connection, $this->filaSincronizacao);
        $this->repositorioComandos = new RepositorioComandosRemotos($connection);
        $this->repositorioCarregamentos = new RepositorioCarregamentosRemotos(
            $connection,
            $this->filaSincronizacao,
            $this->repositorioRomaneios,
        );
    }

    /** @return array{enabled:bool,synced:bool,updated?:int,error?:string} */
    public function sincronizarConfiguracaoDaInstalacao(): array
    {
        if ((string) (getenv("TRACE_LOCAL_SIMULATION") ?: "0") === "1") {
            return ["enabled" => false, "synced" => false, "updated" => 0];
        }
        $installation = $this->instalacao();
        if (!$installation || trim((string) ($installation["sync_token"] ?? "")) === "") {
            return ["enabled" => false, "synced" => false, "updated" => 0];
        }

        $centralUrl = $this->obterUrlCentral((int) $installation["company_id"]);
        if ($centralUrl === "") {
            return ["enabled" => false, "synced" => false, "updated" => 0];
        }

        $installationToken = $this->normalizarTokenInstalacao((string) $installation["sync_token"]);
        if ($installationToken === "") {
            $error = "A instalação local não possui uma credencial segura de sincronização.";
            $this->marcarErro($error);
            return ["enabled" => true, "synced" => false, "updated" => 0, "error" => $error];
        }

        try {
            $snapshot = $this->solicitarDados(
                rtrim($centralUrl, "/") . "/api/sincronizacao_instalacao.php",
                $installationToken,
                "GET",
            );
            $warnings = ValidadorInstantaneoRemoto::separarRomaneiosDisponiveisInvalidos($snapshot);
            ValidadorInstantaneoRemoto::validar($installation, $snapshot);
            $updated = $this->aplicarInstantaneo($installation, $snapshot, $warnings);
            $this->enviarSinaisDeVida(
                $centralUrl,
                $installationToken,
                (int) $installation["company_id"],
                (int) ($snapshot["instalacao"]["equipment_id"] ?? 0),
                (int) ($snapshot["sync_cursor"] ?? 0),
            );
            return ["enabled" => true, "synced" => true, "updated" => $updated];
        } catch (Throwable $exception) {
            $this->marcarErro((string) $exception->getMessage());
            return [
                "enabled" => true,
                "synced" => false,
                "updated" => 0,
                "error" => $exception->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function instalacao(): ?array
    {
        $statement = $this->connection->query(
            "SELECT i.company_id, i.remote_company_id, i.remote_installation_id,
                    i.remote_equipment_id, i.sync_token
             FROM instalacoes_locais i WHERE i.id = 1 LIMIT 1",
        );
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function obterUrlCentral(int $companyId): string
    {
        $configured = trim((string) (getenv("TRACE_CENTRAL_URL") ?: ""));
        if ($configured !== "") {
            $configured = \url_remota_segura($configured);
            if ($configured === "") {
                return "";
            }
            $parts = parse_url($configured);
            return is_array($parts) && isset($parts["host"])
                ? "https://" . $parts["host"] . (isset($parts["port"]) ? ":{$parts["port"]}" : "")
                : "";
        }
        $statement = $this->connection->prepare(
            "SELECT sync_remote_url FROM configuracoes_empresa WHERE company_id = :company_id LIMIT 1",
        );
        $statement->execute(["company_id" => $companyId]);
        $remote = trim((string) ($statement->fetchColumn() ?: ""));
        if ($remote === "") {
            $remote = trim((string) (getenv("SYNC_REMOTE_URL") ?: getenv("SYNC_REMOTE_BATCH_URL") ?: ""));
        }
        $remote = \url_remota_segura($remote);
        $parts = parse_url($remote);
        if (!is_array($parts) || !isset($parts["scheme"], $parts["host"])) {
            return "";
        }
        return $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":{$parts["port"]}" : "");
    }

    private function normalizarTokenInstalacao(string $token): string
    {
        $normalized = strtolower(trim($token));
        return preg_match('/\A[a-f0-9]{64}\z/', $normalized) === 1 ? $normalized : "";
    }

    /** @return array<string,mixed> */
    private function solicitarDados(string $url, string $token, string $method, ?array $payload = null): array
    {
        $handle = \curl_init_url_remota_segura($url);
        if ($handle === false) {
            throw new RuntimeException("Não foi possível iniciar a sincronização remota.");
        }
        $headers = ["Accept: application/json", "Authorization: Bearer {$token}"];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TEMPO_LIMITE_CONEXAO_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TEMPO_LIMITE_REQUISICAO_SEGUNDOS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = "Content-Type: application/json";
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $curlError = trim((string) curl_error($handle));
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($curlError !== "") {
            throw new RuntimeException("Falha de rede na sincronização: {$curlError}");
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Resposta inválida do servidor central.");
        }
        if ($status < 200 || $status >= 300) {
            if (($decoded["error_code"] ?? "") === "LICENSE_INACTIVE") {
                $this->sincronizarEstadoLocalDaLicenca(
                    (string) ($decoded["license_status"] ?? "BLOQUEADA"),
                    $decoded["blocked_reason"] ?? ($decoded["error"] ?? null),
                );
            }
            throw new RuntimeException((string) ($decoded["error"] ?? "Servidor central respondeu HTTP {$status}."));
        }
        return $decoded["data"] ?? $decoded;
    }

    /**
     * @param array<string,mixed> $installation @param array<string,mixed> $snapshot
     * @param list<string> $warnings romaneios do central que não puderam ser aplicados
     */
    private function aplicarInstantaneo(array $installation, array $snapshot, array $warnings = []): int
    {
        $companyId = (int) $installation["company_id"];
        $updated = 0;
        $this->connection->beginTransaction();
        try {
            $this->connection->query("SELECT id FROM instalacoes_locais WHERE id = 1 FOR UPDATE");
            $remoteInstallation = $snapshot["instalacao"];
            $updateInstallation = $this->connection->prepare(
                "UPDATE instalacoes_locais
                 SET remote_installation_id = :remote_installation_id,
                     remote_equipment_id = :remote_equipment_id
                 WHERE id = 1 AND company_id = :company_id"
            );
            $updateInstallation->execute([
                "remote_installation_id" => (int) $remoteInstallation["id"],
                "remote_equipment_id" => (int) $remoteInstallation["equipment_id"],
                "company_id" => $companyId,
            ]);
            $localEquipment = $this->connection->prepare(
                "SELECT equipment_code FROM equipamentos WHERE company_id = :company_id ORDER BY id",
            );
            $localEquipment->execute(["company_id" => $companyId]);
            $localCodes = $localEquipment->fetchAll(PDO::FETCH_COLUMN);
            $remoteCodes = array_map(
                static fn (array $equipment): string => (string) $equipment["equipment_code"],
                $snapshot["equipamentos"],
            );
            if (count($localCodes) > 1 || ($localCodes !== [] && $remoteCodes !== [] && $localCodes[0] !== $remoteCodes[0])) {
                throw new RuntimeException("Dala local diferente da Dala recebida; nenhuma configuração foi importada.");
            }
            $remoteLicense = is_array($snapshot["empresa"] ?? null)
                ? $snapshot["empresa"]
                : [];
            $this->sincronizarEstadoLocalDaLicenca(
                (string) ($remoteLicense["license_status"] ?? "ATIVA"),
                $remoteLicense["license_reason"] ?? null,
            );

            $updated += $this->repositorioProdutos->sincronizar($companyId, $snapshot["produtos"] ?? []);

            $equipmentIds = [];
            foreach (($snapshot["equipamentos"] ?? []) as $remoteEquipment) {
                $equipmentSync = $this->repositorioEquipamentos->sincronizarEquipamento($companyId, $remoteEquipment);
                $equipmentIds[(int) $remoteEquipment["id"]] = $equipmentSync["id"];
                if ($equipmentSync["provisionar"]) {
                    ProvisionadorDispositivosLocais::garantir(
                        $this->connection,
                        $companyId,
                        $equipmentSync["id"],
                        $equipmentSync["codigo"],
                    );
                }
                $updated++;
            }
            $updated += $this->repositorioEquipamentos->removerEquipamentosRemotosObsoletos(
                $companyId,
                array_keys($equipmentIds),
            );

            $updated += $this->repositorioRomaneios->sincronizarRomaneiosCancelados(
                $companyId,
                $snapshot["romaneios_cancelados"] ?? [],
            );
            $updated += $this->repositorioRomaneios->sincronizarRomaneiosDisponiveis(
                $companyId,
                $snapshot["romaneios_disponiveis"] ?? [],
                $warnings,
            );

            $loadingIds = [];
            foreach (($snapshot["carregamentos_ativos"] ?? []) as $remoteLoading) {
                $remoteManifestStatus = strtoupper(trim((string) ($remoteLoading["romaneio_status"] ?? "")));
                $remoteLoadingState = strtoupper(trim((string) ($remoteLoading["state"] ?? "")));
                if (in_array($remoteManifestStatus, ["FINALIZADO", "CANCELADO"], true)
                    || $remoteLoadingState === "FINALIZADO"
                ) {
                    // Uma versão antiga do central pode ainda devolver um
                    // carregamento órfão. Não replique uma operação encerrada.
                    continue;
                }
                $remoteEquipmentId = filter_var($remoteLoading["equipment_id"] ?? null, FILTER_VALIDATE_INT);
                $equipmentId = null;
                if ($remoteEquipmentId !== false && $remoteEquipmentId !== null && $remoteEquipmentId > 0) {
                    $equipmentId = $equipmentIds[(int) $remoteEquipmentId]
                        ?? $this->localizarIdEquipamento($companyId, (string) ($remoteLoading["equipment_code"] ?? ""));
                }
                if ($remoteEquipmentId !== false && $remoteEquipmentId !== null && $remoteEquipmentId > 0 && !$equipmentId) {
                    continue;
                }
                $loadingId = $this->repositorioCarregamentos->salvarOuAtualizar(
                    $companyId,
                    $equipmentId,
                    $remoteLoading,
                );
                if ($loadingId !== null) {
                    $loadingIds[(int) $remoteLoading["id"]] = $loadingId;
                    $updated++;
                }
            }

            $this->repositorioCarregamentos->finalizarAusentes($companyId, array_keys($loadingIds));

            $adminId = $this->repositorioComandos->localizarIdAdministradorLocal($companyId);
            if ($adminId) {
                foreach (($snapshot["comandos"] ?? []) as $remoteCommand) {
                    $remoteLoadingId = (int) ($remoteCommand["carregamento_id"] ?? 0);
                    $loadingId = $loadingIds[$remoteLoadingId]
                        ?? $this->repositorioCarregamentos->localizarId($companyId, $remoteLoadingId);
                    $equipmentId = $equipmentIds[(int) ($remoteCommand["equipment_id"] ?? 0)]
                        ?? $this->localizarIdEquipamento($companyId, (string) ($remoteCommand["equipment_code"] ?? ""));
                    if (!$loadingId || !$equipmentId) {
                        continue;
                    }
                    $this->repositorioComandos->salvarOuAtualizar(
                        $companyId,
                        $equipmentId,
                        $loadingId,
                        $adminId,
                        $remoteCommand,
                    );
                    $updated++;
                }
            }

            // Romaneios ignorados vão para a tela de logs de erros (no máximo
            // uma vez por hora por mensagem) sem marcar a sincronização como
            // falha: o restante do snapshot foi aplicado normalmente.
            if ($warnings !== []) {
                $this->registrarAvisosRomaneiosIgnorados($companyId, $warnings);
            }
            $statement = $this->connection->prepare(
                "UPDATE instalacoes_locais
                 SET last_remote_sync_at = NOW(3), last_remote_sync_error = NULL
                 WHERE id = 1",
            );
            $statement->execute();
            $this->connection->commit();
            return $updated;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function localizarIdEquipamento(int $companyId, string $code): ?int
    {
        if ($code === "") {
            return null;
        }
        $statement = $this->connection->prepare("SELECT id FROM equipamentos WHERE company_id = :company_id AND equipment_code = :code LIMIT 1");
        $statement->execute(["company_id" => $companyId, "code" => $code]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function enviarSinaisDeVida(string $centralUrl, string $token, int $companyId, int $remoteEquipmentId, int $syncCursor): void
    {
        if ($remoteEquipmentId < 1) {
            throw new RuntimeException("A instalação não possui uma Dala vinculada para enviar o heartbeat.");
        }
        $statement = $this->connection->prepare(
            "SELECT e.company_id, e.remote_equipment_id, e.equipment_code, s.device_type, s.status, s.last_seen_at, s.details
             FROM status_dispositivos s JOIN equipamentos e ON e.id = s.equipment_id
             WHERE e.company_id = :company_id AND e.remote_equipment_id = :remote_equipment_id",
        );
        $statement->execute([
            "company_id" => $companyId,
            "remote_equipment_id" => $remoteEquipmentId,
        ]);
        $heartbeats = array_map(function (array $row): array {
            $details = DetalhesHeartbeatInstalacao::normalizar(
                (string) $row["device_type"],
                (string) $row["status"],
                $row["details"] ?? null,
            );
            if (strtoupper((string) $row["device_type"]) === "SENSOR"
                && isset($details["sensor_event_outbox_pending_by_loading"])) {
                $localIds = array_keys($details["sensor_event_outbox_pending_by_loading"]);
                $remoteIds = [];
                if ($localIds !== []) {
                    $placeholders = implode(",", array_fill(0, count($localIds), "?"));
                    $lookup = $this->connection->prepare(
                        "SELECT id, remote_carregamento_id FROM carregamentos
                         WHERE company_id = ? AND id IN ({$placeholders})",
                    );
                    $lookup->execute([(int) $row["company_id"], ...array_map("intval", $localIds)]);
                    foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $loading) {
                        $remoteId = (int) ($loading["remote_carregamento_id"] ?? 0);
                        if ($remoteId > 0) {
                            $remoteIds[(string) $loading["id"]] = (string) $remoteId;
                        }
                    }
                }
                $translated = [];
                foreach ($details["sensor_event_outbox_pending_by_loading"] as $localId => $count) {
                    if (isset($remoteIds[$localId])) {
                        $key = $remoteIds[$localId];
                        $translated[$key] = ($translated[$key] ?? 0) + $count;
                    }
                }
                $details["sensor_event_outbox_pending_by_loading"] = $translated;
            }
            return [
            "remote_equipment_id" => $row["remote_equipment_id"] === null ? null : (int) $row["remote_equipment_id"],
            "equipment_code" => $row["equipment_code"],
            "device_type" => $row["device_type"],
            "status" => $row["status"],
            "last_seen_at" => $row["last_seen_at"],
            "details" => $details,
            ];
        }, $statement->fetchAll());
        $storagePath = dirname(__DIR__, 3) . "/armazenamento";
        $diskFree = is_dir($storagePath) ? @disk_free_space($storagePath) : false;
        $diskTotal = is_dir($storagePath) ? @disk_total_space($storagePath) : false;
        $diskFreePercent = is_numeric($diskFree) && is_numeric($diskTotal) && (float) $diskTotal > 0
            ? round(((float) $diskFree / (float) $diskTotal) * 100, 2)
            : null;
        $this->solicitarDados(
            rtrim($centralUrl, "/") . "/api/sincronizacao_instalacao.php",
            $token,
            "POST",
            [
                "action" => "heartbeat",
                // O heartbeat só é enviado depois que o pacote do servidor
                // central foi validado e aplicado. Assim ele confirma, de
                // forma idempotente, quais eventos chegaram ao PC industrial.
                "delivered_queue_id" => max(0, $syncCursor),
                "industrial_pc" => [
                    "status" => "ONLINE",
                    "reported_at" => date("c"),
                    "disk" => [
                        "free_bytes" => is_numeric($diskFree) ? (int) $diskFree : null,
                        "total_bytes" => is_numeric($diskTotal) ? (int) $diskTotal : null,
                        "free_percent" => $diskFreePercent,
                    ],
                ],
                "heartbeats" => $heartbeats,
            ],
        );
    }

    /** @param list<string> $warnings */
    private function registrarAvisosRomaneiosIgnorados(int $companyId, array $warnings): void
    {
        $recent = $this->connection->prepare(
            "SELECT 1 FROM logs_erros
             WHERE company_id = :company_id AND origem = 'sincronizacao_central'
               AND mensagem = :mensagem
               AND criado_em >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
             LIMIT 1",
        );
        $insert = $this->connection->prepare(
            "INSERT INTO logs_erros (company_id, user_id, origem, mensagem, contexto)
             VALUES (:company_id, NULL, 'sincronizacao_central', :mensagem, :contexto)",
        );
        foreach (array_values(array_unique($warnings)) as $warning) {
            $message = mb_substr($warning, 0, 1000);
            error_log("Dallogix Trace: " . $message);
            $recent->execute(["company_id" => $companyId, "mensagem" => $message]);
            if ($recent->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                "company_id" => $companyId,
                "mensagem" => $message,
                "contexto" => json_encode(["tipo" => "ROMANEIO_CENTRAL_IGNORADO"], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function marcarErro(string $message): void
    {
        $statement = $this->connection->prepare(
            "UPDATE instalacoes_locais SET last_remote_sync_error = :error WHERE id = 1",
        );
        $statement->execute(["error" => mb_substr($message, 0, 1000)]);
    }

    private function sincronizarEstadoLocalDaLicenca(string $status, mixed $reason): void
    {
        $normalizedStatus = strtoupper(trim($status)) === "ATIVA" ? "ATIVA" : "BLOQUEADA";
        $blockedReason = trim((string) ($reason ?? ""));

        $companyId = (int) ($this->connection->query(
            "SELECT company_id FROM instalacoes_locais WHERE id = 1 LIMIT 1",
        )->fetchColumn() ?: 0);
        if ($companyId < 1) {
            throw new RuntimeException("A instalação local não possui empresa vinculada para atualizar a licença.");
        }

        $statement = $this->connection->prepare(
            "INSERT INTO licencas (company_id, plan_name, billing_period, status, blocked_at, blocked_reason)
             VALUES (:company_id, 'Trace Mensal', 'MENSAL', :status, :blocked_at, :blocked_reason)
             ON DUPLICATE KEY UPDATE status = VALUES(status), blocked_at = VALUES(blocked_at),
                 blocked_reason = VALUES(blocked_reason)",
        );
        $statement->execute([
            "company_id" => $companyId,
            "status" => $normalizedStatus,
            "blocked_at" => $normalizedStatus === "ATIVA" ? null : date("Y-m-d H:i:s"),
            "blocked_reason" => $normalizedStatus === "ATIVA"
                ? null
                : mb_substr($blockedReason !== "" ? $blockedReason : "Licença bloqueada no servidor central.", 0, 255),
        ]);
    }
}
