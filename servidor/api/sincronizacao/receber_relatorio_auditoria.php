<?php

declare(strict_types=1);

require_once __DIR__ . "/../../../scripts/image_storage_path.php";

$eventUuid = trim((string) ($_SERVER["HTTP_X_TRACE_EVENT_UUID"] ?? ""));
$sourceManifestId = filter_var($_SERVER["HTTP_X_TRACE_SOURCE_ROMANEIO_ID"] ?? null, FILTER_VALIDATE_INT);
$remoteManifestId = filter_var($_SERVER["HTTP_X_TRACE_REMOTE_ROMANEIO_ID"] ?? null, FILTER_VALIDATE_INT);
$expectedHash = strtolower(trim((string) ($_SERVER["HTTP_X_TRACE_REPORT_SHA256"] ?? "")));
$contentType = strtolower(trim(explode(";", (string) ($_SERVER["CONTENT_TYPE"] ?? ""))[0]));
$contentLength = filter_var($_SERVER["CONTENT_LENGTH"] ?? null, FILTER_VALIDATE_INT);
$maximumBytes = 24 * 1024 * 1024;

if ($contentType !== "application/pdf"
    || !preg_match('/\A[a-f0-9-]{16,80}\z/i', $eventUuid)
    || $sourceManifestId === false || (int) $sourceManifestId <= 0
    || $remoteManifestId === false || (int) $remoteManifestId <= 0
    || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1
    || ($contentLength !== false && (int) $contentLength > $maximumBytes)) {
    responder_json(["error" => "Metadados do relatório de romaneio inválidos."], 422);
}

$pdf = file_get_contents("php://input");
if (!is_string($pdf) || strlen($pdf) < 5 || strlen($pdf) > $maximumBytes
    || !str_starts_with($pdf, "%PDF-")
    || !str_contains(substr($pdf, -2048), "%%EOF")
    || !hash_equals($expectedHash, hash("sha256", $pdf))) {
    responder_json(["error" => "Integridade ou tamanho do PDF de romaneio inválido."], 422);
}

$manifestStatement = $pdo->prepare(
    "SELECT id FROM romaneios WHERE id = :id AND company_id = :company_id LIMIT 1",
);
$manifestStatement->execute([
    "id" => (int) $remoteManifestId,
    "company_id" => (int) $companyId,
]);
if ($manifestStatement->fetchColumn() === false) {
    responder_json(["error" => "Romaneio remoto ainda não foi sincronizado."], 409);
}

$storageRoot = realpath(__DIR__ . "/../../../armazenamento");
if ($storageRoot === false) {
    responder_json(["error" => "Armazenamento de relatórios indisponível."], 503);
}
$relativeDirectory = "company_" . (int) $companyId . "/reports";
$reportDirectory = trace_prepare_image_storage_directory($storageRoot, $relativeDirectory);
if ($reportDirectory === null) {
    responder_json(["error" => "Armazenamento de relatórios sem permissão de escrita."], 503);
}
$reportName = "romaneio-" . (int) $remoteManifestId . "-auditoria.pdf";
$reportPath = $reportDirectory . DIRECTORY_SEPARATOR . $reportName;
if (is_link($reportPath)) {
    responder_json(["error" => "Destino do relatório inválido."], 503);
}

$temporaryPath = $reportPath . ".tmp-" . bin2hex(random_bytes(8));
if (file_put_contents($temporaryPath, $pdf, LOCK_EX) !== strlen($pdf)
    || !chmod($temporaryPath, 0600)
    || !rename($temporaryPath, $reportPath)) {
    if (is_file($temporaryPath)) {
        trace_remove_image_storage_file($storageRoot, $relativeDirectory . "/" . basename($temporaryPath));
    }
    responder_json(["error" => "Não foi possível persistir o relatório recebido."], 503);
}

$metadata = json_encode([
    "source_installation_id" => (int) $installationId,
    "source_romaneio_id" => (int) $sourceManifestId,
    "remote_romaneio_id" => (int) $remoteManifestId,
    "sha256" => $expectedHash,
    "size_bytes" => strlen($pdf),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$pdo->beginTransaction();
try {
    $existingEvent = $pdo->prepare(
        "SELECT action FROM logs_auditoria
         WHERE company_id = :company_id AND event_uuid = :event_uuid LIMIT 1 FOR UPDATE",
    );
    $existingEvent->execute([
        "company_id" => (int) $companyId,
        "event_uuid" => $eventUuid,
    ]);
    $existingAction = $existingEvent->fetchColumn();
    if ($existingAction !== false && $existingAction !== "RELATORIO_AUDITORIA_PUBLICADO") {
        throw new RuntimeException("UUID do evento já pertence a outra ação.");
    }
    if ($existingAction === false) {
        $insertEvent = $pdo->prepare(
            "INSERT INTO logs_auditoria
             (event_uuid, company_id, user_id, action, entity_type, entity_id, metadata, delivered_at)
             VALUES (:event_uuid, :company_id, NULL, 'RELATORIO_AUDITORIA_PUBLICADO', 'romaneio', :entity_id, :metadata, NOW(3))",
        );
        $insertEvent->execute([
            "event_uuid" => $eventUuid,
            "company_id" => (int) $companyId,
            "entity_id" => (int) $remoteManifestId,
            "metadata" => $metadata,
        ]);
    } else {
        $updateEvent = $pdo->prepare(
            "UPDATE logs_auditoria
             SET entity_type = 'romaneio', entity_id = :entity_id, metadata = :metadata, delivered_at = NOW(3)
             WHERE company_id = :company_id AND event_uuid = :event_uuid",
        );
        $updateEvent->execute([
            "entity_id" => (int) $remoteManifestId,
            "metadata" => $metadata,
            "company_id" => (int) $companyId,
            "event_uuid" => $eventUuid,
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof RuntimeException && $exception->getMessage() === "UUID do evento já pertence a outra ação.") {
        responder_json(["error" => $exception->getMessage()], 409);
    }
    throw $exception;
}

responder_json(["data" => [
    "uploaded" => true,
    "remote_romaneio_id" => (int) $remoteManifestId,
    "sha256" => $expectedHash,
]]);
