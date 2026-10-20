<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . '/../../configuracao/bootstrap.php';
require_once __DIR__ . '/../../../scripts/image_storage_path.php';
require_once __DIR__ . '/../../src/Aplicacao/RelatorioAuditoriaPdf.php';

use App\Aplicacao\RelatorioAuditoriaPdf;

exigir_metodo_http(['POST']);
$device = require_device_token(['CAMERA']);
$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
$upload = $_FILES['file'] ?? null;
if (!$requestId || !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
    json_response(['error' => 'Envie request_id e o PDF de evidência da câmera.'], 422);
}
$requestId = (int) $requestId;
$bytes = filesize($upload['tmp_name']);
if ($bytes === false || $bytes < 100 || $bytes > 10 * 1024 * 1024) {
    json_response(['error' => 'PDF vazio ou acima de 10 MiB.'], 413);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
$mime = is_string($mime) ? $mime : '';
if ($mime !== 'application/pdf') {
    json_response(['error' => 'A evidência deve ser enviada como PDF.'], 422);
}

$pdo = db();
$claim = $pdo->prepare(
    "SELECT r.id, r.equipment_id, r.reason, r.evidence_pdf_path, c.company_id
     FROM solicitacoes_captura_camera r
     JOIN carregamentos c ON c.id = r.carregamento_id
     WHERE r.id = :request_id AND r.equipment_id = :equipment_id
       AND c.company_id = :company_id
       AND r.claimed_by_device_id = :device_id AND r.status = 'CAPTURANDO'
     LIMIT 1",
);
$claim->execute([
    'request_id' => $requestId,
    'equipment_id' => $device['equipment_id'],
    'company_id' => $device['company_id'],
    'device_id' => $device['id'],
]);
if (!$request = $claim->fetch()) {
    json_response(['error' => 'Pedido não reservado por esta câmera.'], 404);
}

$pdfBytes = file_get_contents($upload['tmp_name']);
if (!is_string($pdfBytes) || strlen($pdfBytes) !== $bytes
    || !str_starts_with($pdfBytes, "%PDF-1.4\n") || !str_ends_with($pdfBytes, "%%EOF")) {
    json_response(['error' => 'O arquivo recebido não é um PDF completo.'], 422);
}
$sourceImages = RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($pdfBytes);
if (count($sourceImages) !== 1 || $sourceImages[0]['mime'] !== 'image/jpeg'
    || strlen($sourceImages[0]['bytes']) > 4 * 1024 * 1024) {
    json_response(['error' => 'O PDF deve conter uma única captura JPEG válida e recuperável.'], 422);
}
$sha256 = hash('sha256', $pdfBytes);
$sourceJpeg = $sourceImages[0]['bytes'];

$storageRoot = realpath(__DIR__ . '/../../../armazenamento');
if ($storageRoot === false) {
    json_response(['error' => 'Armazenamento de evidências indisponível.'], 503);
}
$relativeFolder = 'company_' . (int) $device['company_id'] . '/equipment_' . (int) $device['equipment_id'];
$resolvedFolder = trace_prepare_image_storage_directory($storageRoot, $relativeFolder);
if ($resolvedFolder === null) {
    json_response(['error' => 'Não foi possível preparar a pasta de evidências.'], 503);
}
$filename = 'capture-' . $requestId . '-' . $sha256 . '.pdf';
$storedPdfPath = $relativeFolder . '/' . $filename;
if (is_string($request['evidence_pdf_path'] ?? null) && $request['evidence_pdf_path'] !== $storedPdfPath) {
    json_response(['error' => 'Esta captura já recebeu outro PDF de evidência.'], 409);
}
$destination = trace_image_storage_path($storageRoot, $storedPdfPath);
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Pasta canônica; nome usa o ID inteiro validado e SHA-256 gerado pelo servidor.
if ($destination === null && is_link($resolvedFolder . DIRECTORY_SEPARATOR . $filename)) {
    json_response(['error' => 'Nome de evidência PDF já ocupado por um link simbólico.'], 409);
}
$alreadyStored = $destination !== null;
if (!$alreadyStored) {
    $destination = $resolvedFolder . DIRECTORY_SEPARATOR . $filename;
}
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Destino existente vem do resolvedor, que recusa travessia e componentes com link simbólico.
if ($alreadyStored && hash_file('sha256', $destination) !== $sha256) {
    json_response(['error' => 'Nome de evidência PDF já ocupado por outro arquivo.'], 409);
}
if (!$alreadyStored) {
    $temporaryRelativePath = $storedPdfPath . '.tmp-' . bin2hex(random_bytes(8));
    $temporary = $resolvedFolder . DIRECTORY_SEPARATOR . basename($temporaryRelativePath);
    if (file_put_contents($temporary, $pdfBytes, LOCK_EX) !== strlen($pdfBytes)
        || !chmod($temporary, 0600) || !rename($temporary, $destination)) {
        if (is_file($temporary)) {
            trace_remove_image_storage_file($storageRoot, $temporaryRelativePath);
        }
        json_response(['error' => 'Falha ao guardar o PDF de evidência.'], 503);
    }
}
if (trace_camera_evidence_pdf_path($storageRoot, (int) $device['company_id'],
    (int) $device['equipment_id'], (int) $requestId, $storedPdfPath) === null) {
    if (!$alreadyStored) {
        trace_remove_image_storage_file($storageRoot, $storedPdfPath);
    }
    json_response(['error' => 'O PDF de evidência não pôde ser validado.'], 422);
}
try {
    $savePath = $pdo->prepare(
        "UPDATE solicitacoes_captura_camera
         SET evidence_pdf_path = :evidence_pdf_path
         WHERE id = :id AND claimed_by_device_id = :device_id AND status = 'CAPTURANDO'
           AND (evidence_pdf_path IS NULL OR evidence_pdf_path = :same_path)",
    );
    $savePath->execute([
        'evidence_pdf_path' => $storedPdfPath,
        'id' => $requestId,
        'device_id' => $device['id'],
        'same_path' => $storedPdfPath,
    ]);
    $confirmPath = $pdo->prepare(
        "SELECT evidence_pdf_path FROM solicitacoes_captura_camera
         WHERE id = :id AND claimed_by_device_id = :device_id AND status = 'CAPTURANDO'",
    );
    $confirmPath->execute(['id' => $requestId, 'device_id' => $device['id']]);
    if ($confirmPath->fetchColumn() !== $storedPdfPath) {
        throw new RuntimeException('O caminho do PDF não foi confirmado pela solicitação de captura.');
    }
} catch (Throwable $exception) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Verificação limitada à pasta canônica e ao nome de evidência gerado pelo servidor.
    if (!$alreadyStored && is_file($destination)) {
        trace_remove_image_storage_file($storageRoot, $storedPdfPath);
    }
    json_response(['error' => 'Não foi possível vincular o PDF à solicitação de captura.'], 503);
}
json_response(['data' => [
    'request_id' => (int) $requestId,
    'evidence_pdf_path' => $storedPdfPath,
    'source_bytes' => strlen($sourceJpeg),
    'pdf_bytes' => strlen($pdfBytes),
    'source_sha256' => hash('sha256', $sourceJpeg),
    'pdf_sha256' => $sha256,
]], 201);
