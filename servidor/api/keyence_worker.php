<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../configuracao/bootstrap.php";
require_once __DIR__ . "/../../scripts/image_storage_path.php";

exigir_metodo_http(["POST"]);
$device = require_device_token(["SCANNER"]);
$isMultipart = str_starts_with(strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? "")), "multipart/form-data");
$payload = $isMultipart ? $_POST : request_json();
$action = strtoupper(trim((string) ($payload["action"] ?? "")));
$pdo = db();

if ($action === "STATUS") {
    $active = $pdo->prepare(
        "SELECT id FROM carregamentos
         WHERE equipment_id = :equipment_id AND company_id = :company_id AND state = 'CARREGANDO'
         ORDER BY id DESC LIMIT 1",
    );
    $active->execute([
        "equipment_id" => $device["equipment_id"],
        "company_id" => $device["company_id"],
    ]);
    $loadingId = (int) ($active->fetchColumn() ?: 0);
    $pendingSensorEventIds = [];
    if ($loadingId > 0) {
        $windowMs = filter_var(getenv("TRACE_SCANNER_NO_READ_TIMEOUT_MS") ?: "1500", FILTER_VALIDATE_INT);
        $windowMs = max(500, min(10000, $windowMs === false ? 1500 : (int) $windowMs));
        $windowMicroseconds = $windowMs * 1000;
        $pendingEvents = $pdo->prepare(
            "SELECT s.id FROM eventos_sensor s
             WHERE s.carregamento_id = :loading_id AND s.equipment_id = :equipment_id
               AND s.detected_at >= DATE_SUB(NOW(3), INTERVAL {$windowMicroseconds} MICROSECOND)
               AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
             ORDER BY s.detected_at ASC, s.id ASC LIMIT 20",
        );
        $pendingEvents->execute([
            "loading_id" => $loadingId,
            "equipment_id" => $device["equipment_id"],
        ]);
        $pendingSensorEventIds = array_map("intval", $pendingEvents->fetchAll(PDO::FETCH_COLUMN));
    }
    json_response(["data" => [
        "equipment_id" => $device["equipment_id"],
        "active_loading_id" => $loadingId ?: null,
        "pending_sensor_event_id" => $pendingSensorEventIds[0] ?? null,
        "pending_sensor_event_ids" => $pendingSensorEventIds,
    ]]);
}

if ($action === "PENDING_IMAGES") {
    expirar_solicitacoes_imagem_keyence($pdo, (int) $device["equipment_id"]);
    $statement = $pdo->prepare(
        "SELECT r.id, r.sensor_event_id,
                CAST(ROUND(UNIX_TIMESTAMP(r.requested_at) * 1000) AS UNSIGNED) AS requested_at_epoch_ms
         FROM solicitacoes_imagens_evidencia r
         JOIN carregamentos c ON c.id = r.carregamento_id
         WHERE r.equipment_id = :equipment_id AND c.company_id = :company_id
           AND r.reason = 'SEM_LEITURA' AND r.status = 'PENDENTE'
           AND r.requested_at >= NOW(3) - INTERVAL 10 MINUTE
         ORDER BY r.requested_at, r.id LIMIT 100",
    );
    $statement->execute([
        "equipment_id" => $device["equipment_id"],
        "company_id" => $device["company_id"],
    ]);
    json_response(["data" => array_map(static fn(array $row): array => [
        "id" => (int) $row["id"],
        "sensor_event_id" => (int) $row["sensor_event_id"],
        "requested_at_epoch_ms" => (int) $row["requested_at_epoch_ms"],
    ], $statement->fetchAll(PDO::FETCH_ASSOC))]);
}

if ($action !== "UPLOAD_IMAGE") {
    json_response(["error" => "Ação Keyence inválida."], 422);
}

$requestId = filter_var($payload["request_id"] ?? null, FILTER_VALIDATE_INT);
$upload = $_FILES["file"] ?? null;
if (!$requestId || $requestId < 1 || !is_array($upload)
    || ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($upload["tmp_name"] ?? null) || !is_uploaded_file($upload["tmp_name"])) {
    json_response(["error" => "Envie request_id e uma imagem JPEG válida."], 422);
}
$bytes = filesize($upload["tmp_name"]);
if ($bytes === false || $bytes < 100 || $bytes > 10 * 1024 * 1024) {
    json_response(["error" => "Imagem vazia ou acima de 10 MiB."], 413);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload["tmp_name"]);
$imageInfo = getimagesize($upload["tmp_name"]);
if ($mime !== "image/jpeg" || !is_array($imageInfo) || $imageInfo["mime"] !== "image/jpeg"
    || $imageInfo[0] < 1 || $imageInfo[1] < 1
    || (int) $imageInfo[0] * (int) $imageInfo[1] > 40000000) {
    json_response(["error" => "A evidência deve ser uma imagem JPEG válida de até 40 megapixels."], 422);
}
$jpeg = file_get_contents($upload["tmp_name"]);
if (!is_string($jpeg) || strlen($jpeg) !== $bytes) {
    json_response(["error" => "Não foi possível ler a imagem JPEG recebida."], 422);
}
$sha256 = hash("sha256", $jpeg);

$pdo->beginTransaction();
$createdFile = false;
$storedPath = "";
$storageRoot = realpath(__DIR__ . "/../../armazenamento");
try {
    $requestStatement = $pdo->prepare(
        "SELECT r.id, r.sensor_event_id, r.carregamento_id, r.equipment_id, r.reason, r.status, c.company_id
         FROM solicitacoes_imagens_evidencia r
         JOIN carregamentos c ON c.id = r.carregamento_id
         WHERE r.id = :id AND r.equipment_id = :equipment_id AND c.company_id = :company_id
         LIMIT 1 FOR UPDATE",
    );
    $requestStatement->execute([
        "id" => (int) $requestId,
        "equipment_id" => $device["equipment_id"],
        "company_id" => $device["company_id"],
    ]);
    $capture = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if (!$capture || $capture["reason"] !== "SEM_LEITURA") {
        $pdo->rollBack();
        json_response(["error" => "Solicitação de evidência sem leitura não encontrada para esta Dala."], 404);
    }
    if ($capture["status"] === "CAPTURADA") {
        $pdo->commit();
        json_response(["data" => ["request_id" => (int) $requestId, "duplicate" => true]]);
    }
    if ($capture["status"] !== "PENDENTE") {
        $pdo->rollBack();
        json_response(["error" => "A solicitação não está mais disponível para a imagem Keyence."], 409);
    }
    $readingStatement = $pdo->prepare(
        "SELECT id FROM leituras
         WHERE sensor_event_id = :sensor_event_id AND carregamento_id = :loading_id
           AND company_id = :company_id AND result = 'SEM_LEITURA'
         LIMIT 1",
    );
    $readingStatement->execute([
        "sensor_event_id" => (int) $capture["sensor_event_id"],
        "loading_id" => (int) $capture["carregamento_id"],
        "company_id" => (int) $capture["company_id"],
    ]);
    $readingId = (int) ($readingStatement->fetchColumn() ?: 0);
    if ($readingId < 1) {
        $pdo->rollBack();
        json_response(["error" => "A passagem sem leitura ainda não está registrada no Trace."], 409);
    }
    if ($storageRoot === false) {
        $pdo->rollBack();
        json_response(["error" => "Armazenamento de evidências indisponível."], 503);
    }
    $relativeFolder = "company_" . (int) $device["company_id"] . "/equipment_" . (int) $device["equipment_id"];
    $resolvedFolder = trace_prepare_image_storage_directory($storageRoot, $relativeFolder);
    if ($resolvedFolder === null) {
        $pdo->rollBack();
        json_response(["error" => "Não foi possível preparar a pasta de evidências."], 503);
    }
    $storedPath = $relativeFolder . "/keyence-" . (int) $requestId . "-" . $sha256 . ".jpg";
    $destination = trace_image_storage_path($storageRoot, $storedPath);
    if ($destination === null && is_link($resolvedFolder . DIRECTORY_SEPARATOR . basename($storedPath))) {
        $pdo->rollBack();
        json_response(["error" => "Nome de evidência já ocupado por um link simbólico."], 409);
    }
    if ($destination !== null) {
        if (hash_file("sha256", $destination) !== $sha256) {
            $pdo->rollBack();
            json_response(["error" => "Nome de evidência já ocupado por outro arquivo."], 409);
        }
    } else {
        $destination = $resolvedFolder . DIRECTORY_SEPARATOR . basename($storedPath);
        $temporary = $destination . ".tmp-" . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $jpeg, LOCK_EX) !== strlen($jpeg)
            || !chmod($temporary, 0600) || !rename($temporary, $destination)) {
            if (is_file($temporary) && !unlink($temporary)) {
                error_log("Keyence image upload could not remove its incomplete temporary file.");
            }
            $pdo->rollBack();
            json_response(["error" => "Falha ao guardar a imagem de evidência."], 503);
        }
        $createdFile = true;
    }
    if (trace_evidence_image_path(
        $storageRoot,
        (int) $device["company_id"],
        (int) $device["equipment_id"],
        (int) $requestId,
        $storedPath,
    ) === null) {
        throw new RuntimeException("A imagem Keyence não passou pela validação do arquivo persistido.");
    }

    $image = $pdo->prepare(
        "INSERT INTO imagens (company_id, carregamento_id, equipment_id, reading_id, path, reason, captured_at)
         VALUES (:company_id, :carregamento_id, :equipment_id, :reading_id, :path, 'SEM_LEITURA', NOW(3))",
    );
    $image->execute([
        "company_id" => $device["company_id"],
        "carregamento_id" => $capture["carregamento_id"],
        "equipment_id" => $device["equipment_id"],
        "reading_id" => $readingId,
        "path" => $storedPath,
    ]);
    $imageId = (int) $pdo->lastInsertId();
    $complete = $pdo->prepare(
        "UPDATE solicitacoes_imagens_evidencia
         SET status = 'CAPTURADA', captured_at = NOW(3), claimed_by_device_id = :device_id,
             error_message = NULL, updated_at = NOW(3)
         WHERE id = :id AND status = 'PENDENTE'",
    );
    $complete->execute(["device_id" => $device["id"], "id" => (int) $requestId]);
    if ($complete->rowCount() !== 1) {
        throw new RuntimeException("A solicitação de evidência mudou durante o recebimento.");
    }
    record_operational_event(
        $pdo,
        ["id" => null, "company_id" => (int) $device["company_id"]],
        "KEYENCE_IMAGEM_EVIDENCIA_RECEBIDA",
        "evidence_request",
        (int) $requestId,
        [
            "image_id" => $imageId,
            "sensor_event_id" => (int) $capture["sensor_event_id"],
            "carregamento_id" => (int) $capture["carregamento_id"],
            "equipment_id" => (int) $device["equipment_id"],
            "device_id" => (int) $device["id"],
            "source" => "KEYENCE_SR_X100W",
            "sha256" => $sha256,
            "bytes" => $bytes,
        ],
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($createdFile && $storageRoot !== false && $storedPath !== "") {
        if (trace_remove_image_storage_file($storageRoot, $storedPath) !== true) {
            error_log("Keyence image upload could not remove an unlinked evidence file after a database error.");
        }
    }
    if ($exception instanceof RuntimeException) {
        json_response(["error" => "Não foi possível concluir o vínculo da imagem com a falha de leitura."], 409);
    }
    throw $exception;
}

json_response(["data" => [
    "request_id" => (int) $requestId,
    "sensor_event_id" => (int) $capture["sensor_event_id"],
    "path" => $storedPath,
    "sha256" => $sha256,
    "bytes" => $bytes,
]], 201);
