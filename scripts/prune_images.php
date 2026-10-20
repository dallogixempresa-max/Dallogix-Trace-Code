<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/image_storage_path.php';
$host = getenv("DB_HOST") ?: "127.0.0.1";
$name = getenv("DB_NAME") ?: "trace_local";
$user = getenv("DB_USER") ?: "trace";
$password = trim((string) getenv("DB_PASSWORD"));
if ($password === "") {
    throw new RuntimeException("DB_PASSWORD não configurado.");
}
$days = max(1, (int) (getenv("IMAGE_RETENTION_DAYS") ?: 30));
$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$cutoff = (new DateTimeImmutable("-{$days} days"))->format("Y-m-d H:i:s");
$statement = $pdo->prepare(
    "SELECT path FROM imagens WHERE captured_at < :image_cutoff
     UNION
     SELECT evidence_pdf_path AS path FROM solicitacoes_captura_camera
     WHERE evidence_pdf_path IS NOT NULL AND COALESCE(captured_at, requested_at) < :request_cutoff",
);
$statement->execute(["image_cutoff" => $cutoff, "request_cutoff" => $cutoff]);
$removed = 0;
$failed = 0;
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $image) {
    // Não apague o registro se a evidência estiver ausente, for um symlink
    // para fora do armazenamento ou não puder ser removida.
    if (trace_remove_image_storage_file($root . '/armazenamento', (string) $image['path']) !== true) {
        $failed++;
        continue;
    }
    $pdo->beginTransaction();
    try {
        $clearCapturePath = $pdo->prepare(
            "UPDATE solicitacoes_captura_camera SET evidence_pdf_path = NULL
             WHERE evidence_pdf_path = :path AND COALESCE(captured_at, requested_at) < :request_cutoff",
        );
        $clearCapturePath->execute(["path" => $image["path"], "request_cutoff" => $cutoff]);
        $delete = $pdo->prepare(
            "DELETE FROM imagens WHERE path = :path AND captured_at < :image_cutoff",
        );
        $delete->execute(["path" => $image["path"], "image_cutoff" => $cutoff]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        continue;
    }
    $removed++;
}
$localCopiesRemoved = 0;
$localEvidenceDirectory = $root . '/armazenamento/camera-pc';
if (is_link($localEvidenceDirectory)) {
    $failed++;
    error_log('Camera evidence retention skipped a symbolic-link directory.');
} elseif (is_dir($localEvidenceDirectory)) {
    try {
        $entries = new DirectoryIterator($localEvidenceDirectory);
        foreach ($entries as $entry) {
            if ($entry->isDot() || $entry->isLink() || !$entry->isFile()) {
                continue;
            }
            $filename = $entry->getFilename();
            $metadata = lstat($entry->getPathname());
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000) {
                continue;
            }
            $isExpiredPdf = preg_match('/\\Arequest-\\d+-\\d{14,20}\\.pdf\\z/', $filename) === 1
                && $metadata['mtime'] < time() - $days * 86400;
            $isExpiredTemporaryPdf = preg_match(
                '/\\Arequest-\\d+-\\d{14,20}\\.pdf\\.tmp-[a-f0-9]{16}\\z/',
                $filename,
            ) === 1 && $metadata['mtime'] < time() - 86400;
            if (!$isExpiredPdf && !$isExpiredTemporaryPdf) {
                continue;
            }
            $relativePath = 'camera-pc/' . $filename;
            if (trace_remove_image_storage_file($root . '/armazenamento', $relativePath) !== true) {
                $failed++;
                continue;
            }
            if ($isExpiredPdf) {
                $localCopiesRemoved++;
            }
        }
    } catch (UnexpectedValueException $exception) {
        $failed++;
        error_log('Camera evidence retention could not scan its local PDF directory: ' . $exception->getMessage());
    }
}
echo "Removed {$removed} server evidence file(s) and {$localCopiesRemoved} local PC PDF copy/copies older than {$days} days; {$failed} file(s) retained after a deletion error.\n";
if ($failed > 0) {
    fwrite(STDERR, "Falha ao remover {$failed} arquivo(s) de evidência; retenção incompleta.\n");
    exit(1);
}
