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
$readingId = filter_var($payload["leitura_id"] ?? null, FILTER_VALIDATE_INT);
$barcode = trim((string) ($payload["barcode"] ?? ""));
$productId = filter_var($payload["product_id"] ?? null, FILTER_VALIDATE_INT);
if (!$readingId || ($barcode === "" && !$productId)) {
    json_response(["error" => "Leitura e produto são obrigatórios."], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
$reading = $pdo->prepare(
    "SELECT l.id, l.carregamento_id, l.result, c.romaneio_id, c.truck_id
     FROM leituras l JOIN carregamentos c ON c.id = l.carregamento_id
     WHERE l.id = :id AND c.company_id = :company_id LIMIT 1 FOR UPDATE",
);
$reading->execute(["id" => $readingId, "company_id" => $user["company_id"]]);
$current = $reading->fetch();
if (!$current) {
    $pdo->rollBack();
    json_response(["error" => "Leitura não encontrada."], 404);
}
if ($current["result"] !== "SEM_LEITURA") {
    $pdo->rollBack();
    json_response(["error" => "Somente leituras sem código podem ser identificadas."], 409);
}

if ($productId) {
    $product = $pdo->prepare("SELECT id, name, code FROM produtos WHERE id = :id AND company_id = :company_id AND active = 1 LIMIT 1");
    $product->execute(["id" => $productId, "company_id" => $user["company_id"]]);
} else {
    $product = $pdo->prepare(
        "SELECT p.id, p.name, p.code
         FROM codigos_produtos pc
         JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
         WHERE pc.company_id = :company_id AND pc.barcode = :barcode AND p.active = 1
         LIMIT 1",
    );
    $product->execute(["barcode" => $barcode, "company_id" => $user["company_id"]]);
}
$selected = $product->fetch();
if (!$selected) {
    $pdo->rollBack();
    json_response(["error" => "Produto não encontrado ou inativo."], 422);
}
$expected = $pdo->prepare("SELECT 1 FROM romaneio_itens WHERE romaneio_id = :romaneio_id AND product_id = :product_id AND (truck_id = :truck_id OR truck_id IS NULL) LIMIT 1");
$expected->execute([
    "romaneio_id" => $current["romaneio_id"],
    "product_id" => $selected["id"],
    "truck_id" => $current["truck_id"],
]);
$result = $expected->fetch() ? "VALIDO" : "PRODUTO_INCORRETO";
$update = $pdo->prepare("UPDATE leituras SET product_id = :product_id, barcode = COALESCE(NULLIF(:barcode, ''), barcode), result = :result WHERE id = :id AND company_id = :company_id");
$update->execute([
    "product_id" => $selected["id"],
    "barcode" => $barcode,
    "result" => $result,
    "id" => $readingId,
    "company_id" => $user["company_id"],
]);
if ($result === "VALIDO") {
    // A leitura manual passa a compor o mesmo contador usado pelo scanner.
    // Como a linha foi bloqueada e só SEM_LEITURA é aceita, o incremento ocorre uma única vez.
    $counter = $pdo->prepare("UPDATE carregamentos SET leituras_validas = leituras_validas + 1 WHERE id = :id AND company_id = :company_id");
    $counter->execute(["id" => (int) $current["carregamento_id"], "company_id" => $user["company_id"]]);
}
record_operational_event($pdo, $user, "LEITURA_IDENTIFICADA_MANUALMENTE", "leitura", (int) $readingId, ["product_id" => (int) $selected["id"], "result" => $result, ...(preg_match('/^[a-f0-9-]{16,80}$/i', $offlineEventId) ? ["event_uuid" => $offlineEventId] : [])]);
$pdo->commit();
json_response(["data" => ["id" => (int) $readingId, "result" => $result, "product_id" => (int) $selected["id"], "product_name" => $selected["name"]]]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
