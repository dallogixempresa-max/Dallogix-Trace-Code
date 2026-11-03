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
$requestContentType = strtolower(trim(explode(";", (string) ($_SERVER["CONTENT_TYPE"] ?? ""))[0]));
if ($requestContentType === "application/pdf") {
    require __DIR__ . "/receber_relatorio_auditoria.php";
    exit();
}
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
            "LEITURA_REGISTRADA",
            "EXCESSO_DETECTADO",
            "REMOCAO_UNIDADE_E_PARADA_FISICA_CONFIRMADAS",
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

        if ($action === "REMOCAO_UNIDADE_E_PARADA_FISICA_CONFIRMADAS") {
            $sourceLoadingId = (int) ($data["carregamento_id"] ?? $entityId);
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $loadingId = $resolveLoadingId(
                $companyId,
                $remoteLoadingId !== false && $remoteLoadingId > 0 ? (int) $remoteLoadingId : 0,
                $sourceLoadingId,
            );
            if ($loadingId === null) {
                throw new RuntimeException("Não foi possível localizar o carregamento da confirmação da parada.");
            }
            $stopReason = strtoupper(trim((string) ($data["stop_reason"] ?? "")));
            if (in_array($stopReason, ["PRODUTO_INCORRETO", "EXCESSO"], true)) {
                $clearStop = $pdo->prepare(
                    "UPDATE carregamentos SET stop_reason = NULL, operator_alert = NULL
                     WHERE id = :id AND company_id = :company_id AND state = 'PAUSADO'
                       AND stop_reason = :stop_reason",
                );
                $clearStop->execute([
                    "id" => $loadingId,
                    "company_id" => $companyId,
                    "stop_reason" => $stopReason,
                ]);
            }
            $entityId = $loadingId;
        }

        if ($action === "EXCESSO_DETECTADO") {
            $sourceLoadingId = (int) ($data["carregamento_id"] ?? $data["source_carregamento_id"] ?? $entityId);
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $loadingId = $resolveLoadingId(
                $companyId,
                $remoteLoadingId !== false && $remoteLoadingId > 0 ? (int) $remoteLoadingId : 0,
                $sourceLoadingId,
            );
            if ($loadingId === null) {
                throw new RuntimeException("Não foi possível localizar o carregamento do excesso detectado pelo sensor.");
            }

            $stopReason = strtoupper(trim((string) ($data["stop_reason"] ?? "EXCESSO")));
            if (!in_array($stopReason, ["PRODUTO_INCORRETO", "EXCESSO"], true)) {
                $stopReason = "EXCESSO";
            }
            $operatorAlert = trim((string) ($data["operator_alert"] ?? ""));
            if ($operatorAlert === "") {
                $operatorAlert = "Quantidade excedida detectada pelo sensor M2052. Verifique a parada física no PC industrial, remova a unidade excedente e confirme antes de retomar.";
            }
            $pauseLoading = $pdo->prepare(
                "UPDATE carregamentos
                 SET state = 'PAUSADO', stop_reason = :stop_reason, operator_alert = :operator_alert
                 WHERE id = :id AND company_id = :company_id
                   AND state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
                   AND (stop_reason IS NULL OR stop_reason = :stop_reason)",
            );
            $pauseLoading->execute([
                "stop_reason" => $stopReason,
                "operator_alert" => $operatorAlert,
                "id" => $loadingId,
                "company_id" => $companyId,
            ]);
            if ($pauseLoading->rowCount() > 0) {
                $manifestEventsRepository->sincronizarStatusCarregamento($companyId, $loadingId, "PAUSADO");
            }
            $entityId = $loadingId;
        }

        if ($action === "LEITURA_REGISTRADA") {
            $readingResult = strtoupper(trim((string) ($data["result"] ?? "")));
            $validResults = ["VALIDO", "PRODUTO_INCORRETO", "SEM_LEITURA", "RETORNO", "EXCESSO"];
            if (!in_array($readingResult, $validResults, true)) {
                throw new RuntimeException("O evento de leitura contém um resultado inválido.");
            }

            $sourceLoadingId = (int) ($data["carregamento_id"] ?? $entityId);
            $remoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            $loadingId = $resolveLoadingId(
                $companyId,
                $remoteLoadingId !== false && $remoteLoadingId > 0 ? (int) $remoteLoadingId : 0,
                $sourceLoadingId,
            );
            if ($loadingId === null) {
                throw new RuntimeException("Não foi possível localizar o carregamento da leitura sincronizada.");
            }

            $readingCompanyStmt = $pdo->prepare(
                "SELECT company_id FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
            );
            $readingCompanyStmt->execute(["id" => $loadingId, "company_id" => $companyId]);
            $readingCompanyId = (int) ($readingCompanyStmt->fetchColumn() ?: 0);
            if ($readingCompanyId !== $companyId) {
                throw new RuntimeException("O carregamento da leitura não pertence à empresa sincronizada.");
            }

            $readingBarcode = isset($data["barcode"]) && $data["barcode"] !== ""
                ? (string) $data["barcode"] : null;
            $sourceProductId = filter_var($data["product_id"] ?? null, FILTER_VALIDATE_INT);
            $readingProductId = null;
            if ($sourceProductId !== false && $sourceProductId > 0) {
                $productStmt = $pdo->prepare(
                    "SELECT id FROM produtos
                     WHERE company_id = :company_id AND remote_product_id = :source_product_id
                     LIMIT 1",
                );
                $productStmt->execute([
                    "company_id" => $readingCompanyId,
                    "source_product_id" => (int) $sourceProductId,
                ]);
                $resolvedProductId = $productStmt->fetchColumn();
                $readingProductId = $resolvedProductId !== false ? (int) $resolvedProductId : null;
            }
            if ($readingProductId === null && $readingBarcode !== null) {
                $barcodeProduct = $pdo->prepare(
                    "SELECT product_id FROM codigos_produtos
                     WHERE company_id = :company_id AND barcode = :barcode LIMIT 1",
                );
                $barcodeProduct->execute([
                    "company_id" => $readingCompanyId,
                    "barcode" => $readingBarcode,
                ]);
                $resolvedProductId = $barcodeProduct->fetchColumn();
                $readingProductId = $resolvedProductId !== false ? (int) $resolvedProductId : null;
            }

            $readingAtInput = trim((string) ($data["read_at"] ?? ""));
            $readingAt = preg_match(
                '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',
                $readingAtInput,
            ) === 1 ? str_replace("T", " ", $readingAtInput) : null;
            $readingAttempt = max(1, (int) ($data["attempt_number"] ?? 1));
            $findSyncedReading = $pdo->prepare(
                "SELECT id, carregamento_id, result, barcode FROM leituras
                 WHERE company_id = :company_id AND sync_event_uuid = :event_uuid
                 LIMIT 1 FOR UPDATE",
            );
            $findSyncedReading->execute([
                "company_id" => $readingCompanyId,
                "event_uuid" => $eventUuid,
            ]);
            $existingSyncedReading = $findSyncedReading->fetch(PDO::FETCH_ASSOC);
            $readingInserted = false;
            if ($existingSyncedReading !== false) {
                if (
                    (int) $existingSyncedReading["carregamento_id"] !== $loadingId
                    || (string) $existingSyncedReading["result"] !== $readingResult
                    || (string) ($existingSyncedReading["barcode"] ?? "") !== (string) ($readingBarcode ?? "")
                ) {
                    throw new RuntimeException("O identificador do evento já está associado a outra leitura.");
                }
                $entityId = (int) $existingSyncedReading["id"];
            } else {
                $insertReading = $pdo->prepare(
                    "INSERT INTO leituras
                     (company_id, carregamento_id, sensor_event_id, sync_event_uuid, product_id, barcode, attempt_number, result, read_at)
                     VALUES (:company_id, :carregamento_id, NULL, :event_uuid, :product_id, :barcode, :attempt_number, :result, COALESCE(:read_at, NOW(3)))",
                );
                try {
                    $insertReading->execute([
                        "company_id" => $readingCompanyId,
                        "carregamento_id" => $loadingId,
                        "event_uuid" => $eventUuid,
                        "product_id" => $readingProductId,
                        "barcode" => $readingBarcode,
                        "attempt_number" => $readingAttempt,
                        "result" => $readingResult,
                        "read_at" => $readingAt,
                    ]);
                    $entityId = (int) $pdo->lastInsertId();
                    $readingInserted = true;
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                        throw $exception;
                    }
                    $findSyncedReading->execute([
                        "company_id" => $readingCompanyId,
                        "event_uuid" => $eventUuid,
                    ]);
                    $existingSyncedReading = $findSyncedReading->fetch(PDO::FETCH_ASSOC);
                    if (
                        $existingSyncedReading === false
                        || (int) $existingSyncedReading["carregamento_id"] !== $loadingId
                        || (string) $existingSyncedReading["result"] !== $readingResult
                        || (string) ($existingSyncedReading["barcode"] ?? "") !== (string) ($readingBarcode ?? "")
                    ) {
                        throw $exception;
                    }
                    $entityId = (int) $existingSyncedReading["id"];
                }
            }

            if ($readingInserted && $readingResult === "VALIDO") {
                $updateValidCount = $pdo->prepare(
                    "UPDATE carregamentos SET leituras_validas = leituras_validas + 1
                     WHERE id = :id AND company_id = :company_id",
                );
                $updateValidCount->execute(["id" => $loadingId, "company_id" => $readingCompanyId]);
            }
            if ($readingResult === "EXCESSO") {
                $excessAlert = "Quantidade excedida recebida da instalação industrial. Confirme a parada física e a remoção da unidade no PC industrial antes de retomar.";
                $pauseLoading = $pdo->prepare(
                    "UPDATE carregamentos
                     SET state = 'PAUSADO', stop_reason = 'EXCESSO', operator_alert = :operator_alert
                     WHERE id = :id AND company_id = :company_id
                       AND state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
                       AND (stop_reason IS NULL OR stop_reason = 'EXCESSO')",
                );
                $pauseLoading->execute([
                    "operator_alert" => $excessAlert,
                    "id" => $loadingId,
                    "company_id" => $readingCompanyId,
                ]);
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
