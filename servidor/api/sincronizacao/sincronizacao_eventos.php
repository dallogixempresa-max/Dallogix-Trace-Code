<?php

declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/InicializadorAcoesDala.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoPreparacaoCarregamentoSincronizado.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioItensRomaneioSincronizacao.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioAuditoriaEventosSincronizacao.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioCarregamentosEventos.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioComandosEventos.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioDalasEventos.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioProdutosEventos.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioRomaneiosEventos.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioStatusDispositivosEventos.php";

use App\Aplicacao\InicializadorAcoesDala;
use App\Aplicacao\ServicoPreparacaoCarregamentoSincronizado;
use App\Persistencia\RepositorioAuditoriaEventosSincronizacao;
use App\Persistencia\RepositorioCarregamentosEventos;
use App\Persistencia\RepositorioComandosEventos;
use App\Persistencia\RepositorioDalasEventos;
use App\Persistencia\RepositorioItensRomaneioSincronizacao;
use App\Persistencia\RepositorioProdutosEventos;
use App\Persistencia\RepositorioRomaneiosEventos;
use App\Persistencia\RepositorioStatusDispositivosEventos;

if (trace_e_instalacao_local()) {
    responder_json(["error" => "Endpoint disponível somente no servidor central."], 403);
}
exigir_metodo_http(["POST"]);
$company = exigir_instalacao_remota(true);
$companyId = (int) $company["id"];
$installationId = (int) $company["installation_id"];
$installationEquipmentId = $company["equipment_id"] === null ? null : (int) $company["equipment_id"];
$allowFirstDalaLink = (bool) ($company["auto_link_first_dala"] ?? false);
$assignedEquipmentCode = "";
$pdo = obter_conexao_banco();
$manifestItemsRepository = new RepositorioItensRomaneioSincronizacao($pdo);
$auditEventsRepository = new RepositorioAuditoriaEventosSincronizacao($pdo);
$loadingEventsRepository = new RepositorioCarregamentosEventos($pdo);
$commandEventsRepository = new RepositorioComandosEventos($pdo);
$dalaEventsRepository = new RepositorioDalasEventos($pdo);
$productEventsRepository = new RepositorioProdutosEventos($pdo);
$manifestEventsRepository = new RepositorioRomaneiosEventos($pdo, $installationId);
$deviceStatusEventsRepository = new RepositorioStatusDispositivosEventos($pdo);
$loadingPreparationService = new ServicoPreparacaoCarregamentoSincronizado(
    $loadingEventsRepository,
    $dalaEventsRepository,
    $manifestEventsRepository,
    $manifestItemsRepository,
    $productEventsRepository,
);
if ($installationEquipmentId !== null) {
    $assignedEquipmentStatement = $pdo->prepare(
        "SELECT equipment_code FROM equipamentos
         WHERE id = :equipment_id AND company_id = :company_id LIMIT 1",
    );
    $assignedEquipmentStatement->execute([
        "equipment_id" => $installationEquipmentId,
        "company_id" => $companyId,
    ]);
    $assignedEquipmentCode = trim((string) ($assignedEquipmentStatement->fetchColumn() ?: ""));
    if ($assignedEquipmentCode === "") {
        responder_json([
            "error" => "A Dala vinculada a este PC não está cadastrada na empresa no servidor central.",
            "error_code" => "INSTALLATION_DALA_REQUIRED",
        ], 409);
    }
}
$payload = ler_json_da_requisicao();
$events = isset($payload["events"]) && is_array($payload["events"])
    ? $payload["events"]
    : [$payload];
$processed = 0;
$ignored = 0;
$mappings = [];
if ($events === []) {
    responder_json(["error" => "Nenhum evento de sincronização foi informado."], 422);
}
foreach ($events as $event) {
    if (!is_array($event)) {
        responder_json(["error" => "Evento de sincronização inválido."], 422);
    }
}
if ($installationEquipmentId === null) {
    // Uma exclusão aplicada limpa o vínculo do PC. Se a resposta se perder,
    // aceite o reenvio já auditado antes de exigir novamente uma Dala ativa.
    $remainingEvents = [];
    $existingActions = $auditEventsRepository->buscarAcoesPorUuid(
        $companyId,
        array_map(static fn (array $event): string => trim((string) ($event["event_uuid"] ?? "")), $events),
    );
    foreach ($events as $event) {
        $eventUuid = trim((string) ($event["event_uuid"] ?? ""));
        $eventCompanyId = filter_var($event["company_id"] ?? null, FILTER_VALIDATE_INT);
        $eventPayload = is_array($event["payload"] ?? null) ? $event["payload"] : [];
        if (
            ($eventPayload["action"] ?? "") === "DALA_EXCLUIDA"
            && preg_match('/^[a-f0-9-]{16,80}$/i', $eventUuid)
            && $eventCompanyId !== false
            && (int) $eventCompanyId === $companyId
        ) {
            if (($existingActions[strtolower($eventUuid)] ?? null) === "DALA_EXCLUIDA") {
                $ignored++;
                continue;
            }
        }
        $remainingEvents[] = $event;
    }
    $events = $remainingEvents;
    if ($events === []) {
        responder_json(["data" => ["processed" => 0, "ignored" => $ignored, "mappings" => []]]);
    }
    if (!$allowFirstDalaLink) {
        responder_json([
            "error" => "Esta instalação antiga tem várias Dalas possíveis. A Master deve vincular a Dala correta ao PC antes da sincronização.",
            "error_code" => "INSTALLATION_DALA_REQUIRED",
        ], 409);
    }
    $registrationIndex = null;
    foreach ($events as $index => $event) {
        $eventPayload = is_array($event["payload"] ?? null) ? $event["payload"] : [];
        if (($eventPayload["action"] ?? "") === "DALA_CADASTRADA") {
            $registrationIndex = $index;
            break;
        }
    }
    if ($registrationIndex === null) {
        responder_json([
            "error" => "Este PC aguarda o primeiro cadastro de sua Dala local.",
            "error_code" => "INSTALLATION_DALA_REQUIRED",
        ], 409);
    }
    if ($registrationIndex > 0) {
        $registrationEvent = $events[$registrationIndex];
        array_splice($events, $registrationIndex, 1);
        array_unshift($events, $registrationEvent);
    }
}
$upsertEquipment = static function (int $companyId, array $data) use (&$installationEquipmentId, &$assignedEquipmentCode, $installationId, $dalaEventsRepository, $pdo): int {
    $equipment = $dalaEventsRepository->salvarDalaVinculada(
        $companyId,
        $installationId,
        $installationEquipmentId,
        $assignedEquipmentCode,
        $data,
    );
    $installationEquipmentId = $equipment["id"];
    $assignedEquipmentCode = $equipment["code"];
    InicializadorAcoesDala::garantir($pdo, $companyId, $installationEquipmentId);
    return $installationEquipmentId;
};

$deleteEquipment = static function (int $companyId, array $data) use (&$installationEquipmentId, &$assignedEquipmentCode, $installationId, $dalaEventsRepository): int {
    $code = trim((string) ($data["equipment_code"] ?? ""));
    $previousCode = trim((string) ($data["previous_equipment_code"] ?? ""));
    if ($code !== $assignedEquipmentCode && $previousCode !== $assignedEquipmentCode) {
        throw new RuntimeException("O PC industrial tentou excluir uma Dala que não está vinculada a ele.");
    }
    if ($installationEquipmentId === null) {
        return 0;
    }
    $equipmentId = $installationEquipmentId;
    $dalaEventsRepository->excluirDalaVinculada($companyId, $installationId, $equipmentId);
    $installationEquipmentId = null;
    $assignedEquipmentCode = "";
    return $equipmentId;
};

$findEquipment = static function (int $companyId, array $data) use (&$installationEquipmentId, &$assignedEquipmentCode, $dalaEventsRepository): int {
    return $dalaEventsRepository->obterIdDalaVinculada(
        $companyId,
        $installationEquipmentId,
        $assignedEquipmentCode,
        $data,
    );
};

$resolveLoadingId = static function (int $companyId, int $candidateId, int $sourceId = 0) use ($installationId, &$installationEquipmentId, $loadingEventsRepository): ?int {
    return $loadingEventsRepository->localizar(
        $companyId,
        $installationId,
        $installationEquipmentId,
        $candidateId,
        $sourceId,
    );
};

$pdo->beginTransaction();
try {
    $knownEventActions = $auditEventsRepository->buscarAcoesPorUuid(
        $companyId,
        array_map(static fn (array $event): string => trim((string) ($event["event_uuid"] ?? "")), $events),
    );
    foreach ($events as $event) {
        $eventUuid = trim((string) ($event["event_uuid"] ?? ""));
        $eventCompanyId = filter_var($event["company_id"] ?? null, FILTER_VALIDATE_INT);
        if (!preg_match('/^[a-f0-9-]{16,80}$/i', $eventUuid) || $eventCompanyId === false || (int) $eventCompanyId !== $companyId) {
            throw new RuntimeException("Evento de sincronização inválido.");
        }
        $eventPayload = is_array($event["payload"] ?? null) ? $event["payload"] : [];
        $action = trim((string) ($eventPayload["action"] ?? ""));
        $data = is_array($eventPayload["data"] ?? null) ? $eventPayload["data"] : [];
        $entityType = trim((string) ($event["aggregate_type"] ?? $eventPayload["entity_type"] ?? "sincronizacao"));
        $entityId = (int) ($event["aggregate_id"] ?? $eventPayload["entity_id"] ?? 0);
        if ($installationEquipmentId === null && $action !== "DALA_CADASTRADA") {
            throw new RuntimeException("O PC precisa sincronizar primeiro o cadastro de sua Dala.");
        }
        $isReplaySafeEvent = in_array($action, [
            "DALA_CADASTRADA",
            "DALA_ATUALIZADA",
            "DALA_EXCLUIDA",
            "PRODUTO_CADASTRADO",
            "PRODUTO_ATUALIZADO",
            "PRODUTO_EXCLUIDO",
            "ROMANEIO_CRIADO",
            "ROMANEIO_ATUALIZADO",
            "ROMANEIO_CANCELADO",
            "CARREGAMENTO_PREPARADO",
            "CARREGAMENTO_DALA_DESVINCULADO",
            "CARREGAMENTO_DALA_VINCULADO",
            "ESTADO_CARREGAMENTO_ALTERADO",
            "CARREGAMENTO_FINALIZADO",
        ], true);
        $eventKey = strtolower($eventUuid);
        $replay = array_key_exists($eventKey, $knownEventActions);
        if ($replay && $action === "DALA_EXCLUIDA") {
            $ignored++;
            continue;
        }
        if ($replay && !$isReplaySafeEvent) {
            $ignored++;
            continue;
        }

        if (in_array($action, ["DALA_CADASTRADA", "DALA_ATUALIZADA"], true)) {
            $entityId = $upsertEquipment($companyId, $data);
        }

        if ($action === "DALA_EXCLUIDA") {
            $entityId = $deleteEquipment($companyId, $data);
        }

        if (in_array($action, ["PRODUTO_CADASTRADO", "PRODUTO_ATUALIZADO"], true)) {
            $entityId = $productEventsRepository->salvar($companyId, $data);
        }

        if ($action === "PRODUTO_EXCLUIDO") {
            $entityId = $productEventsRepository->excluir($companyId, $data, $entityId);
        }

        if (in_array($action, ["ROMANEIO_CRIADO", "ROMANEIO_ATUALIZADO", "ROMANEIO_CANCELADO"], true)) {
            $manifest = $manifestEventsRepository->salvarRomaneio($companyId, $entityId, $data);
            $truck = null;
            if (is_array($data["truck"] ?? null)) {
                $truck = $manifestEventsRepository->salvarCaminhao((int) $manifest["id"], $data["truck"]);
            }
            if ($truck !== null && isset($data["items"]) && is_array($data["items"])) {
                $manifestItemsRepository->substituirItens(
                    $companyId,
                    (int) $manifest["id"],
                    (int) $truck["id"],
                    $data["items"],
                    [$productEventsRepository, "salvarVarios"],
                );
            }
            $entityId = (int) $manifest["id"];
            if ($action === "ROMANEIO_CANCELADO") {
                $loadingEventsRepository->finalizarPorRomaneio($companyId, (int) $manifest["id"]);
            }
            $mappings[] = [
                "event_uuid" => $eventUuid,
                "aggregate_type" => $entityType,
                "aggregate_id" => (int) ($event["aggregate_id"] ?? 0),
                "local" => [
                    "romaneio_id" => (int) ($event["aggregate_id"] ?? 0),
                    "truck_id" => is_array($data["truck"] ?? null) ? (int) ($data["truck"]["source_truck_id"] ?? 0) : 0,
                ],
                "remote" => [
                    "romaneio_id" => (int) $manifest["id"],
                    "truck_id" => $truck === null ? 0 : (int) $truck["id"],
                ],
            ];
        }

        if ($action === "CARREGAMENTO_PREPARADO") {
            $loading = $loadingPreparationService->preparar(
                $companyId,
                $installationId,
                $installationEquipmentId,
                $assignedEquipmentCode,
                $entityId,
                $data,
            );
            $entityId = (int) $loading["id"];
            $mappings[] = [
                "event_uuid" => $eventUuid,
                "aggregate_type" => $entityType,
                "aggregate_id" => (int) ($event["aggregate_id"] ?? 0),
                "local" => [
                    "carregamento_id" => (int) ($event["aggregate_id"] ?? 0),
                    "romaneio_id" => (int) ($data["romaneio_id"] ?? 0),
                    "truck_id" => (int) ($data["truck_id"] ?? ($data["truck"]["source_truck_id"] ?? 0)),
                    "equipment_id" => (int) ($data["equipment_id"] ?? 0),
                ],
                "remote" => [
                    "carregamento_id" => (int) $loading["id"],
                    "romaneio_id" => (int) $loading["manifest_id"],
                    "truck_id" => (int) $loading["truck_id"],
                    "equipment_id" => (int) $loading["equipment_id"],
                ],
            ];
        }

        if (in_array($action, ["CARREGAMENTO_DALA_DESVINCULADO", "CARREGAMENTO_DALA_VINCULADO"], true)) {
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $candidateLoadingId = $remoteLoadingId !== false && $remoteLoadingId > 0
                ? (int) $remoteLoadingId
                : 0;
            $loadingId = $resolveLoadingId(
                $companyId,
                $candidateLoadingId,
                (int) ($data["source_carregamento_id"] ?? $entityId),
            );
            if ($loadingId > 0) {
                if ($action === "CARREGAMENTO_DALA_DESVINCULADO") {
                    $loadingEventsRepository->desvincularDala($companyId, $loadingId);
                } else {
                    $equipmentId = $findEquipment($companyId, $data);
                    if ($equipmentId) {
                        $loadingEventsRepository->vincularDala($companyId, $loadingId, $equipmentId);
                    }
                }
                $entityId = $loadingId;
            }
        }

        if ($action === "ESTADO_CARREGAMENTO_ALTERADO") {
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $state = strtoupper(trim((string) ($data["state"] ?? "")));
            if (in_array($state, ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA"], true)) {
                $loadingId = $resolveLoadingId(
                    $companyId,
                    $remoteLoadingId !== false && $remoteLoadingId > 0 ? (int) $remoteLoadingId : 0,
                    (int) ($data["source_carregamento_id"] ?? $entityId),
                );
                if ($loadingId !== null) {
                    $loadingEventsRepository->atualizarEstado($companyId, $loadingId, $state);
                    $manifestEventsRepository->sincronizarStatusCarregamento($companyId, $loadingId, $state);
                    $entityId = $loadingId;
                }
            }
        }

        if ($action === "CARREGAMENTO_FINALIZADO") {
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $loadingId = $resolveLoadingId(
                $companyId,
                $remoteLoadingId !== false && $remoteLoadingId > 0 ? (int) $remoteLoadingId : 0,
                (int) ($data["source_carregamento_id"] ?? $entityId),
            );
            if ($loadingId !== null) {
                $loadingEventsRepository->finalizar($companyId, $loadingId);
                $manifestEventsRepository->sincronizarStatusCarregamento(
                    $companyId,
                    $loadingId,
                    "FINALIZADO",
                    filter_var($data["romaneio_finalizado"] ?? false, FILTER_VALIDATE_BOOLEAN),
                );
                $entityId = $loadingId;
            }
        }

        if (in_array($action, ["COMANDO_CLP_CONCLUIDO", "COMANDO_CLP_EXPIRADO"], true)) {
            $remoteCommandId = filter_var($data["remote_command_id"] ?? null, FILTER_VALIDATE_INT);
            if ($remoteCommandId !== false && $remoteCommandId > 0) {
                $commandEquipmentId = $findEquipment($companyId, $data);
                $status = $action === "COMANDO_CLP_EXPIRADO" ? "ERRO" : strtoupper((string) ($data["status"] ?? "ERRO"));
                if (!in_array($status, ["APLICADO", "REJEITADO", "ERRO"], true)) {
                    $status = "ERRO";
                }
                $commandEventsRepository->registrarRetorno(
                    $companyId,
                    $commandEquipmentId,
                    (int) $remoteCommandId,
                    $status,
                    $data["message"] ?? null,
                );
                $entityId = (int) $remoteCommandId;
            }
        }

        if ($action === "STATUS_DISPOSITIVO_ALTERADO") {
            $equipmentId = $findEquipment($companyId, $data);
            $status = strtoupper((string) ($data["status"] ?? "DESCONHECIDO"));
            $deviceType = strtoupper((string) ($data["device_type"] ?? "CLP"));
            if ($equipmentId && in_array($status, ["ONLINE", "OFFLINE", "ERRO", "DESCONHECIDO"], true)) {
                $deviceStatusEventsRepository->registrar(
                    $equipmentId,
                    $deviceType,
                    $status,
                    json_encode(["source" => "instalacao_local"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                );
                $entityId = (int) $equipmentId;
            }
        }

        if ($action === "DALA_CADASTRADA" || $action === "DALA_ATUALIZADA") {
            $mappings[] = [
                "event_uuid" => $eventUuid,
                "aggregate_type" => $entityType,
                "aggregate_id" => (int) ($event["aggregate_id"] ?? 0),
                "local" => ["equipment_id" => (int) ($event["aggregate_id"] ?? 0)],
                "remote" => ["equipment_id" => $entityId],
            ];
        }

        $auditData = [
            "event_uuid" => $eventUuid,
            "company_id" => $companyId,
            "action" => $action !== "" ? $action : "SINCRONIZACAO_RECEBIDA",
            "entity_type" => $entityType,
            "entity_id" => max(1, $entityId),
            "metadata" => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $auditEventsRepository->registrar($auditData, $replay);
        $knownEventActions[$eventKey] = $auditData["action"];
        $processed++;
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder_json(["error" => $exception->getMessage()], 422);
}

responder_json(["data" => ["processed" => $processed, "ignored" => $ignored, "mappings" => $mappings]]);
