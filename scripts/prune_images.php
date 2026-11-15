<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/image_storage_path.php';
require_once __DIR__ . '/image_prune_policy.php';
$host = getenv("DB_HOST") ?: "127.0.0.1";
$name = getenv("DB_NAME") ?: "trace_local";
$user = getenv("DB_USER") ?: "trace";
$password = trim((string) getenv("DB_PASSWORD"));
if ($password === "") {
    throw new RuntimeException("DB_PASSWORD não configurado.");
}
$days = max(1, (int) (getenv("IMAGE_RETENTION_DAYS") ?: 30));
$storageRoot = realpath($root . '/armazenamento');
if ($storageRoot === false) {
    throw new RuntimeException("Armazenamento de imagens indisponível.");
}
$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$cutoff = (new DateTimeImmutable("-{$days} days"))->format("Y-m-d H:i:s");

/** @return array<string,mixed>|null */
function trace_prune_synced_report(PDO $pdo, string $storageRoot, int $companyId, int $romaneioId): ?array
{
    if ($companyId < 1 || $romaneioId < 1) {
        return null;
    }
    $relativeReportPath = "company_{$companyId}/reports/romaneio-{$romaneioId}-auditoria.pdf";
    $reportPath = trace_image_storage_path($storageRoot, $relativeReportPath);
    if ($reportPath === null) {
        return null;
    }
    $manifestPath = $reportPath . ".evidence.json";
    if (is_link($manifestPath) || !is_file($manifestPath)) {
        return null;
    }
    $manifestBytes = file_get_contents($manifestPath);
    $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
    $reportHash = hash_file("sha256", $reportPath);
    if (!is_array($manifest) || !is_string($reportHash)
        || !hash_equals((string) ($manifest["pdf_sha256"] ?? ""), $reportHash)
        || !is_array($manifest["source_images"] ?? null)) {
        return null;
    }

    $synced = $pdo->prepare(
        "SELECT 1 FROM fila_sincronizacao
         WHERE company_id = :company_id AND aggregate_type = 'romaneio' AND aggregate_id = :romaneio_id
           AND status = 'ENVIADO'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.action')) = 'RELATORIO_AUDITORIA_PUBLICADO'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.sha256')) = :report_hash
         LIMIT 1",
    );
    $synced->execute([
        "company_id" => $companyId,
        "romaneio_id" => $romaneioId,
        "report_hash" => $reportHash,
    ]);
    return $synced->fetchColumn() === false ? null : $manifest;
}

$candidates = $pdo->prepare(
    "SELECT i.id AS image_id, NULL AS request_id, i.path, i.reason, i.captured_at,
            i.company_id, i.carregamento_id, c.romaneio_id, NULL AS image_sha256
     FROM imagens i JOIN carregamentos c ON c.id = i.carregamento_id
     WHERE i.path <> '' AND i.captured_at < :image_cutoff
     UNION ALL
     SELECT NULL AS image_id, r.id AS request_id, r.evidence_pdf_path AS path,
            r.reason, COALESCE(r.captured_at, r.requested_at) AS captured_at,
            c.company_id, r.carregamento_id, c.romaneio_id, r.image_sha256
     FROM solicitacoes_imagens_evidencia r JOIN carregamentos c ON c.id = r.carregamento_id
     WHERE r.evidence_pdf_path IS NOT NULL
       AND r.status = 'CAPTURADA'
       AND COALESCE(r.captured_at, r.requested_at) < :request_cutoff",
);
$candidates->execute(["image_cutoff" => $cutoff, "request_cutoff" => $cutoff]);

$removed = 0;
$skippedUntilSync = 0;
$skippedWithoutReportMapping = 0;
$unresolvedPath = 0;
$failed = 0;
$syncedReports = [];
foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    $path = trim((string) ($candidate["path"] ?? ""));
    if ($path === "") {
        continue;
    }
    $candidate["path"] = $path;
    $absolutePath = trace_image_storage_path($storageRoot, $path);
    if ($absolutePath === null) {
        $unresolvedPath++;
        continue;
    }

    if (strtoupper((string) ($candidate["reason"] ?? "")) !== "NORMAL") {
        $companyId = (int) $candidate["company_id"];
        $romaneioId = (int) $candidate["romaneio_id"];
        $reportKey = $companyId . ":" . $romaneioId;
        if (!array_key_exists($reportKey, $syncedReports)) {
            $syncedReports[$reportKey] = trace_prune_synced_report(
                $pdo,
                $storageRoot,
                $companyId,
                $romaneioId,
            );
        }
        $manifest = $syncedReports[$reportKey];
        if (!is_array($manifest)) {
            $skippedUntilSync++;
            continue;
        }
        $currentFileHash = hash_file("sha256", $absolutePath);
        if (!is_string($currentFileHash)
            || !trace_prune_candidate_is_in_report($manifest, $candidate, $currentFileHash)) {
            $skippedWithoutReportMapping++;
            continue;
        }
    }

    $removedFile = trace_remove_image_storage_file($storageRoot, $path);
    if ($removedFile === true) {
        $removed++;
    } elseif ($removedFile === null) {
        $unresolvedPath++;
    } else {
        $failed++;
    }
}

echo "Removed {$removed} aged image file(s); {$unresolvedPath} skipped because paths were absent or unsafe; "
    . "{$skippedUntilSync} kept until their PDF is confirmed synchronized; "
    . "{$skippedWithoutReportMapping} kept because the PDF does not map them; "
    . "{$failed} deletion(s) failed. Database image and evidence records were preserved.\n";
if ($failed > 0) {
    fwrite(STDERR, "Falha ao remover {$failed} arquivo(s) de evidência; retenção incompleta.\n");
    exit(1);
}
