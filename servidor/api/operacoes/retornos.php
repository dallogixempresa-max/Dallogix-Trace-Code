<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
$payload = request_json();
$offlineEventId = trim((string) ($_SERVER["HTTP_X_TRACE_OFFLINE_ID"] ?? ""));
if (preg_match('/^[a-f0-9-]{16,80}$/i', $offlineEventId)) {
    $duplicate = db()->prepare("SELECT entity_id FROM logs_auditoria WHERE event_uuid = :event_uuid AND company_id = :company_id LIMIT 1");
    $duplicate->execute(["event_uuid" => $offlineEventId, "company_id" => $user["company_id"]]);
    if ($duplicate->fetch()) {
        json_response(["data" => ["queued" => true, "duplicate" => true]]);
    }
}
$loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
$readingId = filter_var($payload["leitura_id"] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($payload["reason"] ?? ""));
if (!$loadingId || $reason === "") {
    json_response(["error" => "Carregamento e motivo do retorno são obrigatórios."], 422);
}
$pdo = db();
$loading = $pdo->prepare("SELECT id, equipment_id, state FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1");
$loading->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
if (!$loading->fetch()) {
    json_response(["error" => "Carregamento não encontrado."], 404);
}
$valid = $pdo->prepare("SELECT l.id, l.product_id, l.barcode FROM leituras l WHERE l.id = :reading_id AND l.carregamento_id = :loading_id AND l.result = 'VALIDO' AND NOT EXISTS (SELECT 1 FROM retornos r WHERE r.leitura_id = l.id) LIMIT 1");
$valid->execute(["reading_id" => $readingId ?: 0, "loading_id" => $loadingId]);
$reading = $valid->fetch();
if (!$reading) {
    json_response(["error" => "Selecione uma leitura válida ainda não retornada."], 422);
}
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare("INSERT INTO retornos (carregamento_id, leitura_id, quantity, reason, created_by) VALUES (:loading_id, :reading_id, 1, :reason, :created_by)");
    $insert->execute(["loading_id" => $loadingId, "reading_id" => $reading["id"], "reason" => $reason, "created_by" => $user["id"]]);
    $returnId = (int) $pdo->lastInsertId();
    $reverse = $pdo->prepare("INSERT INTO leituras (company_id, carregamento_id, product_id, barcode, attempt_number, result, read_at) VALUES (:company_id, :loading_id, :product_id, :barcode, 1, 'RETORNO', NOW(3))");
    $reverse->execute(["company_id" => $user["company_id"], "loading_id" => $loadingId, "product_id" => $reading["product_id"], "barcode" => $reading["barcode"]]);
    record_operational_event($pdo, $user, "RETORNO_REGISTRADO", "retorno", $returnId, ["carregamento_id" => (int) $loadingId, "leitura_id" => (int) $reading["id"], "reason" => $reason, ...(preg_match('/^[a-f0-9-]{16,80}$/i', $offlineEventId) ? ["event_uuid" => $offlineEventId] : [])]);
    $pdo->commit();
    json_response(["data" => ["id" => $returnId, "leitura_id" => (int) $reading["id"], "result" => "RETORNO", "quantity" => 1]], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Return registration failed: " . $exception->getMessage());
    json_response(["error" => "Não foi possível registrar o retorno."], 500);
}
