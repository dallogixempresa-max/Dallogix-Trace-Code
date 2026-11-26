<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/vinculo_leitor.php";

/**
 * Guarda o resultado do leitor que não foi ligado a nenhuma passagem do
 * sensor, para que ele não desapareça só no contador da tela: código que
 * chegou sem passagem, captura feita com a esteira parada ou captura que a
 * tela descartou. O relatório de auditoria lista esses resultados.
 */
$usuarioAtor = exigir_sessao_usuario();
if ($usuarioAtor["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder_json(["error" => "Método não permitido."], 405);
}
exigir_csrf();
session_write_close();

const TRACE_MOTIVOS_SEM_PASSAGEM = ["SEM_PASSAGEM", "ESTEIRA_PARADA", "DESCARTADA_TELA"];

$payload = ler_json_da_requisicao();
$loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
if (!$loadingId || $loadingId < 1) {
    responder_json(["error" => "Carregamento obrigatório."], 422);
}
$motivo = strtoupper(trim((string) ($payload["motivo"] ?? "")));
if (!in_array($motivo, TRACE_MOTIVOS_SEM_PASSAGEM, true)) {
    responder_json(["error" => "Motivo inválido."], 422);
}
[$barcode] = trace_split_scanner_read_time(trim((string) ($payload["barcode"] ?? "")));
$noRead = ($payload["no_read"] ?? false) === true || preg_match('/^ERROR(?:[\s:_-]?[0-9]{8,14})?$/i', $barcode) === 1;
if ($noRead) {
    $barcode = "";
}
if (mb_strlen($barcode, "UTF-8") > 128 || preg_match('/[\x00-\x1F\x7F]/', $barcode) === 1) {
    responder_json(["error" => "Código de barras inválido."], 422);
}
if ($barcode === "" && !$noRead) {
    responder_json(["error" => "Informe o código ou a falha de leitura."], 422);
}
try {
    $capturedAtMs = trace_scanner_observed_at_ms($payload["scanner_received_at_epoch_ms"] ?? null);
} catch (InvalidArgumentException $exception) {
    responder_json(["error" => $exception->getMessage()], 422);
}
$eventUuid = trim((string) ($_SERVER["HTTP_X_TRACE_OFFLINE_ID"] ?? ""));
if ($eventUuid !== "" && preg_match('/^[a-f0-9-]{16,80}$/i', $eventUuid) !== 1) {
    responder_json(["error" => "Identificador da leitura inválido."], 422);
}

$pdo = db();
$loading = $pdo->prepare("SELECT id FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1");
$loading->execute(["id" => $loadingId, "company_id" => $usuarioAtor["company_id"]]);
if ($loading->fetchColumn() === false) {
    responder_json(["error" => "Carregamento não encontrado."], 404);
}

$capturedAt = (new DateTimeImmutable("@" . intdiv($capturedAtMs, 1000)))
    ->setTimezone(new DateTimeZone("UTC"))
    ->format("Y-m-d H:i:s") . sprintf(".%03d", $capturedAtMs % 1000);
$insert = $pdo->prepare(
    "INSERT INTO leituras_sem_passagem
        (company_id, carregamento_id, barcode, no_read, motivo, captured_at, user_id, event_uuid)
     VALUES (:company_id, :carregamento_id, :barcode, :no_read, :motivo, :captured_at, :user_id, :event_uuid)",
);
try {
    $insert->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
        "barcode" => $barcode === "" ? null : $barcode,
        "no_read" => $noRead ? 1 : 0,
        "motivo" => $motivo,
        "captured_at" => $capturedAt,
        "user_id" => $usuarioAtor["id"] ?? null,
        "event_uuid" => $eventUuid === "" ? null : $eventUuid,
    ]);
} catch (PDOException $exception) {
    // Reenvio da mesma captura: já registrada.
    if (($exception->errorInfo[1] ?? null) === 1062) {
        responder_json(["data" => ["duplicate" => true]], 200);
    }
    throw $exception;
}
responder_json(["data" => ["id" => (int) $pdo->lastInsertId(), "motivo" => $motivo]], 201);
