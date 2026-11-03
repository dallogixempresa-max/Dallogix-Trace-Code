<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
$payload = request_json();
$loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
$readingId = filter_var($payload["leitura_id"] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($payload["reason"] ?? ""));
if (!$loadingId || !$readingId || $reason === "") {
    json_response(["error" => "Carregamento, leitura e motivo do retorno são obrigatórios."], 422);
}
$offlineEventId = trim((string) ($_SERVER["HTTP_X_TRACE_OFFLINE_ID"] ?? ""));
$pdo = db();
$pdo->beginTransaction();
try {
    $loading = $pdo->prepare("SELECT id, equipment_id, state FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE");
    $loading->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
    $loadingRow = $loading->fetch();
    if (!$loadingRow) {
        $pdo->rollBack();
        json_response(["error" => "Carregamento não encontrado."], 404);
    }
    // Consultar após o bloqueio torna reenvios simultâneos idempotentes.
    if (preg_match('/^[a-f0-9-]{16,80}$/i', $offlineEventId)) {
        $duplicate = $pdo->prepare("SELECT action, metadata FROM logs_auditoria WHERE event_uuid = :event_uuid AND company_id = :company_id LIMIT 1");
        $duplicate->execute(["event_uuid" => $offlineEventId, "company_id" => $user["company_id"]]);
        $registered = $duplicate->fetch(PDO::FETCH_ASSOC);
        if ($registered) {
            $original = json_decode((string) $registered["metadata"], true);
            $sameReturn = $registered["action"] === "RETORNO_REGISTRADO"
                && is_array($original)
                && (int) ($original["carregamento_id"] ?? 0) === (int) $loadingId
                && (int) ($original["leitura_id"] ?? 0) === (int) $readingId
                && (string) ($original["reason"] ?? "") === $reason;
            $pdo->rollBack();
            if (!$sameReturn) {
                json_response(["error" => "Identificador já usado em outro retorno."], 409);
            }
            json_response(["data" => ["queued" => true, "duplicate" => true]]);
        }
    }
    if (!in_array($loadingRow["state"], ["CARREGANDO", "FINALIZANDO", "PAUSADO"], true)) {
        $pdo->rollBack();
        json_response(["error" => "O carregamento não aceita novos retornos neste estado."], 409);
    }
    $valid = $pdo->prepare("SELECT l.id, l.product_id, l.barcode FROM leituras l
        WHERE l.id = :reading_id AND l.carregamento_id = :loading_id AND l.company_id = :company_id
          AND l.result = 'VALIDO' AND NOT EXISTS (SELECT 1 FROM retornos r WHERE r.leitura_id = l.id)
        LIMIT 1 FOR UPDATE");
    $valid->execute(["reading_id" => $readingId, "loading_id" => $loadingId, "company_id" => $user["company_id"]]);
    $reading = $valid->fetch();
    if (!$reading) {
        $pdo->rollBack();
        json_response(["error" => "Selecione uma leitura válida ainda não retornada."], 422);
    }
    $insert = $pdo->prepare("INSERT INTO retornos (carregamento_id, leitura_id, quantity, reason, created_by) VALUES (:loading_id, :reading_id, 1, :reason, :created_by)");
    $insert->execute(["loading_id" => $loadingId, "reading_id" => $reading["id"], "reason" => $reason, "created_by" => $user["id"]]);
    $returnId = (int) $pdo->lastInsertId();
    $reverse = $pdo->prepare("INSERT INTO leituras (company_id, carregamento_id, product_id, barcode, attempt_number, result, read_at) VALUES (:company_id, :loading_id, :product_id, :barcode, 1, 'RETORNO', NOW(3))");
    $reverse->execute(["company_id" => $user["company_id"], "loading_id" => $loadingId, "product_id" => $reading["product_id"], "barcode" => $reading["barcode"]]);
    record_operational_event($pdo, $user, "RETORNO_REGISTRADO", "retorno", $returnId, ["carregamento_id" => (int) $loadingId, "leitura_id" => (int) $reading["id"], "reason" => $reason, ...(preg_match('/^[a-f0-9-]{16,80}$/i', $offlineEventId) ? ["event_uuid" => $offlineEventId] : [])]);
    $pdo->commit();
    json_response(["data" => ["id" => $returnId, "leitura_id" => (int) $reading["id"], "result" => "RETORNO", "quantity" => 1]], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof PDOException) {
        $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);
        if ($exception->getCode() === "40001" || in_array($driverErrorCode, [1205, 1213], true)) {
            registrar_log_erro($exception, "conflito_transitorio_retorno");
            header("Retry-After: 0");
            json_response(["error" => "Conflito temporário ao registrar o retorno. Tente novamente.", "retryable" => true], 503);
        }
    }
    error_log("Return registration failed: " . $exception->getMessage());
    json_response(["error" => "Não foi possível registrar o retorno."], 500);
}
