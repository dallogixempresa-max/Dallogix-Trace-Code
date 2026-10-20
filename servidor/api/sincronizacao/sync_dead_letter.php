<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["GET", "PATCH"]);
$usuario = exigir_sessao_usuario();
if (!in_array($usuario["role"], ["ADMIN_DALLOGIX", "ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    responder_json(["error" => "Perfil sem permissão para consultar a fila morta."], 403);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = obter_conexao_banco();
$companyId = filter_var($_GET["company_id"] ?? null, FILTER_VALIDATE_INT);
if ($usuario["role"] !== "ADMIN_DALLOGIX") {
    $companyId = (int) $usuario["company_id"];
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $where = ["resolved_at IS NULL"];
    $params = [];
    if ($companyId !== false && $companyId !== null && (int) $companyId > 0) {
        $where[] = "company_id = :company_id";
        $params["company_id"] = (int) $companyId;
    }
    $statement = $pdo->prepare(
        "SELECT id, original_event_id, company_id, event_uuid, event_type, aggregate_id,
                last_error, failed_attempts, moved_at
         FROM sync_dead_letter_queue
         WHERE " . implode(" AND ", $where) . "
         ORDER BY moved_at DESC, id DESC LIMIT 100",
    );
    $statement->execute($params);
    responder_json(["data" => $statement->fetchAll()]);
}

require_csrf();
$id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
$note = trim((string) ($_POST["resolution_note"] ?? ""));
$payload = ler_json_da_requisicao();
$action = strtolower(trim((string) ($payload["action"] ?? "resolve")));
$id = $id ?: filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
$note = $note !== "" ? $note : trim((string) ($payload["resolution_note"] ?? ""));
if (!$id || !in_array($action, ["resolve", "requeue"], true) || $note === "" || mb_strlen($note) > 1000) {
    responder_json(["error" => "Evento e observação de resolução são obrigatórios."], 422);
}

$conditions = ["id = :id", "resolved_at IS NULL"];
$params = ["id" => (int) $id];
if ($companyId !== false && $companyId !== null && (int) $companyId > 0) {
    $conditions[] = "company_id = :company_id";
    $params["company_id"] = (int) $companyId;
}

$pdo->beginTransaction();
try {
    $entryQuery = $pdo->prepare(
        "SELECT id, original_event_id, company_id, event_uuid, event_type, aggregate_id,
                payload, failed_attempts
         FROM sync_dead_letter_queue
         WHERE " . implode(" AND ", $conditions) . " FOR UPDATE",
    );
    $entryQuery->execute($params);
    $entry = $entryQuery->fetch();
    if (!$entry) {
        $pdo->rollBack();
        responder_json(["error" => "Evento não encontrado ou já resolvido."], 404);
    }

    if ($action === "requeue") {
        $entryCompanyId = (int) ($entry["company_id"] ?? 0);
        if ($entryCompanyId < 1) {
            throw new RuntimeException("O evento não possui empresa válida para reprocessamento.");
        }
        $requeue = $pdo->prepare(
            "INSERT INTO fila_sincronizacao
                (company_id, event_uuid, aggregate_type, aggregate_id, payload, status, attempts, last_error, available_at)
             VALUES (:company_id, :event_uuid, :event_type, :aggregate_id, :payload, 'PENDENTE', 0, NULL, NOW())",
        );
        $requeue->execute([
            "company_id" => $entryCompanyId,
            "event_uuid" => $entry["event_uuid"],
            "event_type" => $entry["event_type"],
            "aggregate_id" => (int) $entry["aggregate_id"],
            "payload" => $entry["payload"],
        ]);
    }

    $resolutionNote = $action === "requeue" ? "Reenfileirado: {$note}" : $note;
    $update = $pdo->prepare(
        "UPDATE sync_dead_letter_queue
         SET resolved_at = NOW(), resolved_by = :resolved_by, resolution_note = :resolution_note
         WHERE id = :id AND resolved_at IS NULL",
    );
    $update->execute([
        "id" => (int) $entry["id"],
        "resolved_by" => (int) $usuario["id"],
        "resolution_note" => $resolutionNote,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException("O evento da fila morta não pôde ser resolvido.");
    }

    registrar_evento_operacional(
        $pdo,
        $usuario,
        $action === "requeue" ? "SYNC_DEAD_LETTER_REENFILEIRADA" : "SYNC_DEAD_LETTER_RESOLVIDA",
        "sync_dead_letter",
        (int) $entry["id"],
        [
            "company_id" => (int) $entry["company_id"],
            "original_event_id" => (int) $entry["original_event_id"],
            "event_uuid" => $entry["event_uuid"],
            "failed_attempts" => (int) $entry["failed_attempts"],
            "resolution_note" => $note,
            "requeued" => $action === "requeue",
        ],
    );

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof PDOException && $exception->getCode() === "23000") {
        responder_json(["error" => "O evento já está presente na fila de sincronização."], 409);
    }
    throw $exception;
}
responder_json([
    "data" => [
        "id" => (int) $id,
        "resolved" => true,
        "requeued" => $action === "requeue",
    ],
]);
