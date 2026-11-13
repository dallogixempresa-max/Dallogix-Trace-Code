<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../operacoes/temporizacao_leitor.php";
require_once __DIR__ . "/../../src/Aplicacao/RelatorioAuditoriaPdf.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoPublicacaoRelatoriosAuditoria.php";
require_once __DIR__ . "/../../../scripts/image_storage_path.php";

use App\Aplicacao\RelatorioAuditoriaPdf;
use App\Aplicacao\ServicoPublicacaoRelatoriosAuditoria;

/** Remove imagens legadas soltas; PDFs de captura ficam até a política de retenção. */
function trace_cleanup_report_evidence(PDO $pdo, array $loadIds, int $companyId, string $storageRoot): void
{
    if ($loadIds === []) {
        return;
    }
    $placeholders = implode(",", array_fill(0, count($loadIds), "?"));
    $pathsQuery = $pdo->prepare(
        "SELECT i.path FROM imagens i JOIN carregamentos c ON c.id = i.carregamento_id
         WHERE c.company_id = ? AND i.carregamento_id IN ({$placeholders})
         UNION ALL
         SELECT r.evidence_pdf_path FROM solicitacoes_imagens_evidencia r
         JOIN carregamentos c ON c.id = r.carregamento_id
         WHERE c.company_id = ? AND r.carregamento_id IN ({$placeholders}) AND r.evidence_pdf_path IS NOT NULL",
    );
    $pathsQuery->execute([$companyId, ...$loadIds, $companyId, ...$loadIds]);
    $paths = array_unique(array_filter(array_map(
        static fn(array $row): string => trim((string) ($row["path"] ?? "")),
        $pathsQuery->fetchAll(PDO::FETCH_ASSOC),
    )));
    foreach ($paths as $storedPath) {
        $relativePath = ltrim($storedPath, "/");
        if (str_starts_with($relativePath, "armazenamento/")) {
            $relativePath = substr($relativePath, strlen("armazenamento/"));
        }
        if (str_ends_with(strtolower($relativePath), ".pdf")) {
            continue;
        }
        if (preg_match(
            '/\\Acompany_' . $companyId . '\/equipment_[0-9]+\/(?:capture|keyence)-[0-9]+-[a-f0-9]{64}\\.(jpg|png)\\z/',
            $relativePath,
        ) !== 1) {
            continue;
        }
        if (trace_remove_image_storage_file($storageRoot, $relativePath) === false) {
            throw new RuntimeException("Não foi possível remover um arquivo de captura após salvar o PDF final.");
        }
    }
    $pdo->beginTransaction();
    try {
        $deleteImages = $pdo->prepare(
            "DELETE i FROM imagens i JOIN carregamentos c ON c.id = i.carregamento_id
             WHERE c.company_id = ? AND i.carregamento_id IN ({$placeholders})
               AND (LOWER(i.path) LIKE '%.jpg' OR LOWER(i.path) LIKE '%.png')",
        );
        $deleteImages->execute([$companyId, ...$loadIds]);
        $clearCapturePaths = $pdo->prepare(
            "UPDATE solicitacoes_imagens_evidencia SET evidence_pdf_path = NULL
             WHERE carregamento_id IN ({$placeholders}) AND status = 'CAPTURADA' AND reason <> 'NORMAL'",
        );
        $clearCapturePaths->execute($loadIds);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$apiClient = $GLOBALS["trace_api_v1_client"] ?? null;
$user = is_array($apiClient)
    ? ["id" => null, "company_id" => (int) $apiClient["company_id"]]
    : require_session_user();
$respondJson = static function (array $payload, int $status = 200) use ($apiClient): never {
    if (is_array($apiClient)) {
        $message = (string) ($payload["error"] ?? "Relatório indisponível.");
        $code = (string) ($payload["error_code"] ?? match ($status) {
            404 => "not_found",
            409 => "report_not_ready",
            422 => "invalid_request",
            default => "report_unavailable",
        });
        api_v1_responder_erro($code, $message, $status);
    }
    json_response($payload, $status);
};
$reportFailure = static function (Throwable $exception) use ($respondJson): never {
    registrar_log_erro($exception, "relatorio_auditoria");
    $respondJson(
        ["error" => "Não foi possível gerar o relatório de auditoria. As evidências foram preservadas."],
        503,
    );
};
$romaneioId = filter_var($_GET["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
if ($user["company_id"] === null || !$romaneioId) {
    $respondJson(["error" => "Romaneio obrigatório."], 422);
}

if (!is_array($apiClient)) {
    session_write_close();
}
$pdo = db();
$reportPublisher = new ServicoPublicacaoRelatoriosAuditoria($pdo);
$manifest = $pdo->prepare("SELECT r.id, r.number, r.status, r.scheduled_date, r.expedidor,
    c.name AS company_name,
    (SELECT rt.plate FROM romaneio_caminhoes rt WHERE rt.romaneio_id = r.id ORDER BY rt.id LIMIT 1) AS plate,
    (SELECT rt.driver_name FROM romaneio_caminhoes rt WHERE rt.romaneio_id = r.id ORDER BY rt.id LIMIT 1) AS driver_name
    FROM romaneios r JOIN empresas c ON c.id = r.company_id
    WHERE r.id = :id AND r.company_id = :company_id LIMIT 1");
$manifest->execute(["id" => $romaneioId, "company_id" => $user["company_id"]]);
$romaneio = $manifest->fetch();
if (!$romaneio) {
    $respondJson(["error" => "Romaneio não encontrado."], 404);
}
$downloadAuditPdf = static function (string $pdfBytes) use ($apiClient, $pdo, $user, $romaneioId, $romaneio): never {
    if (is_array($apiClient)) {
        api_v1_registrar_download_pdf($pdo, $apiClient, (int) $romaneioId);
    } else {
        record_operational_event($pdo, $user, "RELATORIO_AUDITORIA_BAIXADO", "romaneio", (int) $romaneioId, []);
    }
    header_remove("Content-Type");
    header("Content-Type: application/pdf");
    header(
        'Content-Disposition: attachment; filename="romaneio-' .
            preg_replace("/[^A-Za-z0-9_-]/", "-", (string) $romaneio["number"]) .
            '-auditoria.pdf"',
    );
    header("Cache-Control: no-store");
    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- Resposta binária application/pdf previamente validada; escapar corromperia o arquivo.
    echo $pdfBytes;
    exit();
};
if (!in_array($romaneio["status"], ["FINALIZADO", "CANCELADO"], true)) {
    $respondJson(
        [
            "error" =>
                "O relatório fica disponível após a finalização ou cancelamento do romaneio.",
        ],
        409,
    );
}

$loads = $pdo->prepare('SELECT c.id, c.state, c.started_at, c.finished_at, e.name AS equipment_name, e.equipment_code
    FROM carregamentos c LEFT JOIN equipamentos e ON e.id = c.equipment_id WHERE c.romaneio_id = :id ORDER BY c.id');
$loads->execute(["id" => $romaneioId]);
$loadRows = $loads->fetchAll();
$loadIds = array_map(static fn(array $row): int => (int) $row["id"], $loadRows);
// Um PDF publicado pelo PC já contém as fotos Keyence originais. O Central não
// recebe os arquivos de imagem separadamente, então precisa servir esse PDF
// validado antes de exigir evidências locais que só existem no PC industrial.
$publishedNoReadCount = 0;
$publishedExcessCount = 0;
$publishedWrongProductCount = 0;
if ($loadIds !== []) {
    $publishedPlaceholders = implode(",", array_fill(0, count($loadIds), "?"));
    $publishedNoReadQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id IN ({$publishedPlaceholders}) AND result = 'SEM_LEITURA'",
    );
    $publishedNoReadQuery->execute($loadIds);
    $publishedNoReadCount = (int) $publishedNoReadQuery->fetchColumn();
    $publishedExcessQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia
         WHERE carregamento_id IN ({$publishedPlaceholders}) AND reason = 'EXCESSO'",
    );
    $publishedExcessQuery->execute($loadIds);
    $publishedExcessCount = (int) $publishedExcessQuery->fetchColumn();
    $publishedWrongProductQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id IN ({$publishedPlaceholders}) AND result = 'PRODUTO_INCORRETO'",
    );
    $publishedWrongProductQuery->execute($loadIds);
    $publishedWrongProductCount = (int) $publishedWrongProductQuery->fetchColumn();
}
$publishedStorageRoot = realpath(__DIR__ . "/../../../armazenamento");
if ($publishedStorageRoot !== false) {
    $publishedRelativePath = "company_" . (int) $user["company_id"] . "/reports/romaneio-" . (int) $romaneioId . "-auditoria.pdf";
    $publishedReport = trace_image_storage_path($publishedStorageRoot, $publishedRelativePath);
    $publishedManifest = $publishedReport === null ? null : $publishedReport . ".evidence.json";
    if ($publishedReport !== null && $publishedManifest !== null
        && !is_link($publishedReport) && !is_link($publishedManifest) && is_file($publishedManifest)) {
        $publishedManifestBytes = file_get_contents($publishedManifest);
        $publishedManifestData = is_string($publishedManifestBytes) ? json_decode($publishedManifestBytes, true) : null;
        $publishedHash = hash_file("sha256", $publishedReport);
        $publishedVersion = is_array($publishedManifestData) ? ($publishedManifestData["version"] ?? null) : null;
        $publishedCountsMatch = is_array($publishedManifestData)
            && (int) ($publishedManifestData["no_read_count"] ?? -1) === $publishedNoReadCount
            && (int) ($publishedManifestData["evidence_count"] ?? -1) >= $publishedNoReadCount;
        $publishedExcessMatches = is_array($publishedManifestData)
            && (int) ($publishedManifestData["excess_count"] ?? 0) === $publishedExcessCount
            && (int) ($publishedManifestData["excess_evidence_count"] ?? 0) >= $publishedExcessCount;
        $publishedWrongProductMatches = is_array($publishedManifestData)
            && (int) ($publishedManifestData["wrong_product_count"] ?? -1) === $publishedWrongProductCount
            && (int) ($publishedManifestData["wrong_product_evidence_count"] ?? 0) >= $publishedWrongProductCount;
        $publishedEvidenceValid = $publishedCountsMatch
            && (($publishedVersion === 3 && $publishedExcessMatches && $publishedWrongProductMatches)
                // Versões anteriores não registravam fotos de produtos fora do romaneio.
                || ($publishedVersion === 2 && $publishedExcessMatches && $publishedWrongProductCount === 0)
                || ($publishedVersion === 1 && $publishedExcessCount === 0 && $publishedWrongProductCount === 0))
            && is_string($publishedHash)
            && hash_equals((string) ($publishedManifestData["pdf_sha256"] ?? ""), $publishedHash);
        if ($publishedEvidenceValid) {
            $publishedPdfBytes = file_get_contents($publishedReport);
            if (!is_string($publishedPdfBytes) || !str_starts_with($publishedPdfBytes, "%PDF-")) {
                $respondJson(["error" => "O PDF publicado está inválido; as evidências foram preservadas."], 503);
            }
            try {
                $reportPublisher->enfileirar(
                    (int) $user["company_id"],
                    (int) $romaneioId,
                    $publishedRelativePath,
                );
            } catch (Throwable $exception) {
                registrar_log_erro($exception, "relatorio_auditoria_sync");
                $respondJson(["error" => "O PDF foi preservado, mas não entrou na fila de sincronização. Refaça o download para tentar novamente."], 503);
            }
            $downloadAuditPdf($publishedPdfBytes);
        }
    }
}
if ($loadIds !== []) {
    expirar_solicitacoes_imagem_keyence($pdo, null, $loadIds);
    $pendingCaptures = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
         WHERE i.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND i.status IN ('PENDENTE', 'CAPTURANDO')",
    );
    $pendingCaptures->execute($loadIds);
    if ((int) $pendingCaptures->fetchColumn() > 0) {
        $respondJson(["error" => "Aguarde o envio das imagens de erro do Keyence antes de gerar o PDF."], 409);
    }
    $missingRequests = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras l
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'SEM_LEITURA'
         WHERE l.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND l.result = 'SEM_LEITURA' AND l.conta_na_meta = 1
           AND (r.id IS NULL OR r.status <> 'CAPTURADA')",
    );
    $missingRequests->execute($loadIds);
    $missingCount = (int) $missingRequests->fetchColumn();
    if ($missingCount > 0) {
        $respondJson([
            "error" => "O relatório aguarda foto do Keyence para {$missingCount} passagem(ns) sem código.",
            "missing_evidence_count" => $missingCount,
        ], 409);
    }
    $missingWrongProductEvidence = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras l
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'PRODUTO_INCORRETO'
         WHERE l.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND l.result = 'PRODUTO_INCORRETO'
           AND (r.id IS NULL OR r.status <> 'CAPTURADA')",
    );
    $missingWrongProductEvidence->execute($loadIds);
    $missingWrongProductCount = (int) $missingWrongProductEvidence->fetchColumn();
    if ($missingWrongProductCount > 0) {
        $respondJson([
            "error" => "O relatório aguarda foto do Keyence para {$missingWrongProductCount} produto(s) fora do romaneio.",
            "missing_evidence_count" => $missingWrongProductCount,
        ], 409);
    }
    $uncapturedExcessRequests = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
         WHERE i.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND i.reason = 'EXCESSO' AND i.status <> 'CAPTURADA'",
    );
    $uncapturedExcessRequests->execute($loadIds);
    $uncapturedExcessCount = (int) $uncapturedExcessRequests->fetchColumn();
    if ($uncapturedExcessCount > 0) {
        $respondJson([
            "error" => "O relatório aguarda foto do Keyence para {$uncapturedExcessCount} solicitação(ões) de excesso.",
            "missing_evidence_count" => $uncapturedExcessCount,
        ], 409);
    }
    $missingExcessEvidence = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras l
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'EXCESSO'
         WHERE l.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND l.result = 'EXCESSO' AND l.sensor_event_id IS NOT NULL
           AND (r.id IS NULL OR r.status <> 'CAPTURADA')",
    );
    $missingExcessEvidence->execute($loadIds);
    $missingExcessCount = (int) $missingExcessEvidence->fetchColumn();
    if ($missingExcessCount > 0) {
        $respondJson([
            "error" => "O relatório aguarda foto do Keyence para {$missingExcessCount} passagem(ns) em excesso.",
            "missing_evidence_count" => $missingExcessCount,
        ], 409);
    }
}
$noReadCount = 0;
$excessEvidenceCount = 0;
$excessImagesCount = 0;
$wrongProductCount = 0;
$wrongProductImagesCount = 0;
if ($loadIds !== []) {
    $noReadCountQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ") AND result = 'SEM_LEITURA' AND conta_na_meta = 1",
    );
    $noReadCountQuery->execute($loadIds);
    $noReadCount = (int) $noReadCountQuery->fetchColumn();
    $excessEvidenceCountQuery = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'CAPTURADA' THEN 1 ELSE 0 END) AS captured
         FROM solicitacoes_imagens_evidencia i
         WHERE i.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND i.reason = 'EXCESSO'",
    );
    $excessEvidenceCountQuery->execute($loadIds);
    $excessEvidenceCounts = $excessEvidenceCountQuery->fetch(PDO::FETCH_ASSOC) ?: [];
    $excessEvidenceCount = (int) ($excessEvidenceCounts["total"] ?? 0);
    $excessImagesCount = (int) ($excessEvidenceCounts["captured"] ?? 0);
    $wrongProductEvidenceCountQuery = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'CAPTURADA' THEN 1 ELSE 0 END) AS captured
         FROM solicitacoes_imagens_evidencia i
         WHERE i.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND i.reason = 'PRODUTO_INCORRETO'",
    );
    $wrongProductEvidenceCountQuery->execute($loadIds);
    $wrongProductEvidenceCounts = $wrongProductEvidenceCountQuery->fetch(PDO::FETCH_ASSOC) ?: [];
    $wrongProductCountQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND result = 'PRODUTO_INCORRETO'",
    );
    $wrongProductCountQuery->execute($loadIds);
    $wrongProductCount = (int) $wrongProductCountQuery->fetchColumn();
    $wrongProductImagesCount = (int) ($wrongProductEvidenceCounts["captured"] ?? 0);
}
$storageRoot = realpath(__DIR__ . "/../../../armazenamento");
if ($storageRoot === false) {
    $respondJson(["error" => "Armazenamento de relatórios indisponível."], 503);
}
$reportRelativeDirectory = "company_" . (int) $user["company_id"] . "/reports";
$resolvedReportDirectory = trace_prepare_image_storage_directory($storageRoot, $reportRelativeDirectory);
if ($resolvedReportDirectory === null) {
    error_log("Relatório de auditoria: diretório {$reportRelativeDirectory} indisponível ou sem permissão de escrita.");
    $respondJson(["error" => "Armazenamento de relatórios sem permissão de escrita; verifique a prontidão da instalação."], 503);
}
$reportRelativePath = $reportRelativeDirectory . "/romaneio-" . (int) $romaneioId . "-auditoria.pdf";
$reportPath = $resolvedReportDirectory . DIRECTORY_SEPARATOR . basename($reportRelativePath);
if (is_link($reportPath)) {
    $respondJson(["error" => "Destino do relatório inválido."], 503);
}
$savedReport = trace_image_storage_path($storageRoot, $reportRelativePath);
 $evidenceManifestPath = $reportPath . ".evidence.json";
$cachedEvidenceValid = false;
 if ($savedReport !== null && is_file($evidenceManifestPath) && !is_link($evidenceManifestPath)) {
    $manifestBytes = file_get_contents($evidenceManifestPath);
    $manifestData = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
    $cachedPdfHash = hash_file("sha256", $savedReport);
    $cachedEvidenceValid = is_array($manifestData)
        && ($manifestData["version"] ?? null) === 3
        && (int) ($manifestData["no_read_count"] ?? -1) === $noReadCount
        && (int) ($manifestData["evidence_count"] ?? -1) >= $noReadCount
        && (int) ($manifestData["excess_count"] ?? -1) === $excessEvidenceCount
        && (int) ($manifestData["excess_evidence_count"] ?? -1) >= $excessEvidenceCount
        && (int) ($manifestData["wrong_product_count"] ?? -1) === $wrongProductCount
        && (int) ($manifestData["wrong_product_evidence_count"] ?? -1) >= $wrongProductCount
        && is_string($cachedPdfHash)
        && hash_equals((string) ($manifestData["pdf_sha256"] ?? ""), $cachedPdfHash);
 }
if ($savedReport !== null && $cachedEvidenceValid) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Caminho usa IDs inteiros validados e é resolvido na raiz canônica, recusando links simbólicos.
    $savedPdf = file_get_contents($savedReport);
    if (!is_string($savedPdf) || !str_starts_with($savedPdf, "%PDF-")) {
        $respondJson(["error" => "O PDF salvo está inválido; as evidências foram preservadas."], 503);
    }
    try {
        trace_cleanup_report_evidence($pdo, $loadIds, (int) $user["company_id"], $storageRoot);
    } catch (Throwable $exception) {
        $reportFailure($exception);
    }
    try {
        $reportPublisher->enfileirar((int) $user["company_id"], (int) $romaneioId, $reportRelativePath);
    } catch (Throwable $exception) {
        registrar_log_erro($exception, "relatorio_auditoria_sync");
        $respondJson(["error" => "O PDF foi preservado, mas não entrou na fila de sincronização. Refaça o download para tentar novamente."], 503);
    }
    if (is_array($apiClient)) {
        api_v1_registrar_download_pdf($pdo, $apiClient, (int) $romaneioId);
    } else {
        record_operational_event($pdo, $user, "RELATORIO_AUDITORIA_BAIXADO", "romaneio", (int) $romaneioId, []);
    }
    header_remove("Content-Type");
    header("Content-Type: application/pdf");
    header(
        'Content-Disposition: attachment; filename="romaneio-' .
            preg_replace("/[^A-Za-z0-9_-]/", "-", (string) $romaneio["number"]) .
            '-auditoria.pdf"',
    );
    header("Cache-Control: no-store");
    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- Resposta binária application/pdf validada pelo prefixo %PDF-; escapar corromperia o arquivo.
    echo $savedPdf;
    exit();
}
$placeholders = implode(",", array_fill(0, count($loadIds), "?"));
$formatDate = static function (?string $value): string {
    if (!$value) {
        return "—";
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date("d/m/Y", $timestamp);
};
// Carimbos DATETIME são gravados em UTC (NOW() roda com --default-time-zone=+00:00).
// A auditoria precisa exibir o relógio da operação, senão início, fim e legendas das
// fotos de falha aparecem adiantados pelo deslocamento do fuso.
$formatDateTime = static function (?string $value): string {
    if (!$value) {
        return "—";
    }
    try {
        $moment = new DateTimeImmutable($value, new DateTimeZone("UTC"));
    } catch (Exception) {
        return $value;
    }
    // A zero-date do MySQL ("0000-00-00 00:00:00") não lança e viraria 29/11/-0001.
    // Num documento de auditoria é melhor exibir o valor cru do que uma data absurda.
    if ((int) $moment->format("Y") < 1970) {
        return $value;
    }
    return $moment
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format("d/m/Y H:i");
};
$equipmentLabels = array_values(array_unique(array_map(
    static fn(array $row): string => trim((string) ($row["equipment_name"] ?: $row["equipment_code"] ?: "Dala")),
    $loadRows,
)));
$firstLoad = $loadRows[0] ?? [];
$lastLoad = $loadRows !== [] ? $loadRows[array_key_last($loadRows)] : [];

$items = $pdo->prepare("SELECT p.name, COALESCE(MIN(pc.barcode), p.code, '—') AS barcode, SUM(ri.planned_quantity) AS planned_quantity,
    GREATEST(0, COALESCE((SELECT COUNT(*) FROM leituras l JOIN carregamentos c ON c.id = l.carregamento_id
      WHERE c.romaneio_id = ri.romaneio_id
        AND l.result = 'VALIDO' AND l.conta_na_meta = 1 AND l.product_id = ri.product_id), 0) - COALESCE((SELECT SUM(ret.quantity) FROM retornos ret
      JOIN carregamentos c ON c.id = ret.carregamento_id
      JOIN leituras lr ON lr.id = ret.leitura_id
      WHERE c.romaneio_id = ri.romaneio_id AND lr.conta_na_meta = 1
        AND lr.product_id = ri.product_id), 0)) AS moved_quantity,
    (SELECT COUNT(*) FROM leituras l JOIN carregamentos c ON c.id = l.carregamento_id
      WHERE c.romaneio_id = ri.romaneio_id
        AND l.result = 'SEM_LEITURA' AND l.conta_na_meta = 1
        AND NOT EXISTS (SELECT 1 FROM retornos rt WHERE rt.leitura_id = l.id)
        AND (
          l.product_id = ri.product_id
          OR (l.product_id IS NULL
            AND (SELECT COUNT(DISTINCT ri2.product_id) FROM romaneio_itens ri2
                 WHERE ri2.romaneio_id = ri.romaneio_id) = 1)
        )) AS no_read_quantity
    FROM romaneio_itens ri JOIN produtos p ON p.id = ri.product_id
    LEFT JOIN codigos_produtos pc ON pc.product_id = p.id AND pc.company_id = p.company_id
    WHERE ri.romaneio_id = ?
    GROUP BY ri.romaneio_id, ri.product_id, p.id, p.name, p.code
    ORDER BY p.name, p.code");
$items->execute([$romaneioId]);
$itemRows = $items->fetchAll();

$occurrences = [];
$incidentImages = [];
$noReadRows = [];
$wrongProductRows = [];
$sackReferencesByEvent = [];
$loadOrderById = [];
$operationSummary = [
    "passages" => 0,
    "valid_readings" => 0,
    "returned_bags" => 0,
    "no_reads" => 0,
    "no_read_images" => 0,
    "scanner_readings" => 0,
    "excesses" => 0,
    "excess_images" => 0,
    "wrong_products" => 0,
    "wrong_product_images" => 0,
];
foreach ($loadIds as $index => $loadingId) {
    $loadOrderById[$loadingId] = $index + 1;
}
if ($loadIds !== []) {
    $passageQuery = $pdo->prepare(
        "SELECT id, carregamento_id, device_detected_at, event_at_epoch_ms, conta_na_meta,
                EXISTS (SELECT 1 FROM leituras l
                        WHERE l.sensor_event_id = eventos_sensor.id
                          AND l.result = 'PRODUTO_INCORRETO') AS produto_incorreto,
                EXISTS (SELECT 1 FROM leituras l
                        WHERE l.sensor_event_id = eventos_sensor.id
                          AND l.result = 'EXCESSO') AS produto_excedente,
                CAST(ROUND(UNIX_TIMESTAMP(detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
         FROM eventos_sensor WHERE carregamento_id IN ({$placeholders})",
    );
    $passageQuery->execute($loadIds);
    $passages = array_map(static function (array $passage): array {
        $passage["event_at_epoch_ms"] = $passage["event_at_epoch_ms"] !== null
            ? (int) $passage["event_at_epoch_ms"]
            : ($passage["device_detected_at"] !== null
                ? trace_sql_datetime_to_epoch_ms((string) $passage["device_detected_at"])
                : (int) $passage["server_detected_at_epoch_ms"]);
        return $passage;
    }, $passageQuery->fetchAll(PDO::FETCH_ASSOC));
    usort($passages, static fn(array $left, array $right): int =>
        ((int) $left["carregamento_id"] <=> (int) $right["carregamento_id"])
        ?: ((int) $left["event_at_epoch_ms"] <=> (int) $right["event_at_epoch_ms"])
        ?: ((int) $left["id"] <=> (int) $right["id"])
    );
    $passageNumbers = [];
    foreach ($passages as $passage) {
        $loadingId = (int) $passage["carregamento_id"];
        if ((int) $passage["conta_na_meta"] !== 1) {
            $reason = (int) $passage["produto_incorreto"] === 1
                ? "Produto fora do romaneio"
                : ((int) $passage["produto_excedente"] === 1
                    ? "Saco excedente"
                    : "Passagem durante pausa");
            $sackReferencesByEvent[(int) $passage["id"]] = "$reason · não contabilizada";
            continue;
        }
        $passageNumbers[$loadingId] = ($passageNumbers[$loadingId] ?? 0) + 1;
        $operationSummary["passages"]++;
        $sackReferencesByEvent[(int) $passage["id"]] =
            "Carga " . ($loadOrderById[$loadingId] ?? "—") . " · saco " . $passageNumbers[$loadingId];
    }

    $readingCounts = $pdo->prepare(
        "SELECT result, COUNT(*) AS quantity FROM leituras
         WHERE carregamento_id IN ({$placeholders}) AND conta_na_meta = 1 GROUP BY result",
    );
    $readingCounts->execute($loadIds);
    foreach ($readingCounts->fetchAll(PDO::FETCH_ASSOC) as $readingCount) {
        if ($readingCount["result"] === "VALIDO") {
            $operationSummary["valid_readings"] = (int) $readingCount["quantity"];
        }
    }
    $scannerCount = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id IN ({$placeholders}) AND barcode IS NOT NULL
           AND result IN ('VALIDO', 'PRODUTO_INCORRETO', 'EXCESSO')",
    );
    $scannerCount->execute($loadIds);
    $operationSummary["scanner_readings"] = (int) $scannerCount->fetchColumn();
    $operationSummary["excesses"] = $excessEvidenceCount;
    $operationSummary["excess_images"] = $excessImagesCount;
    $returnCount = $pdo->prepare(
        "SELECT COALESCE(SUM(ret.quantity), 0) FROM retornos ret
         LEFT JOIN leituras l ON l.id = ret.leitura_id
         WHERE ret.carregamento_id IN ({$placeholders}) AND (l.id IS NULL OR l.conta_na_meta = 1)",
    );
    $returnCount->execute($loadIds);
    $operationSummary["returned_bags"] = (int) $returnCount->fetchColumn();

    $noReadQuery = $pdo->prepare(
        "SELECT l.id, l.sensor_event_id, l.carregamento_id, l.read_at,
                l.product_id,
                p.name AS product_name,
                r.status AS evidence_status,
                rt.reason AS return_reason,
                EXISTS (
                    SELECT 1 FROM imagens i
                    WHERE i.carregamento_id = l.carregamento_id
                      AND i.reason = 'SEM_LEITURA'
                      AND (i.reading_id = l.id OR (
                          i.reading_id IS NULL AND r.id IS NOT NULL
                          AND i.path LIKE CONCAT('%keyence-', r.id, '-%')
                      ))
                ) AS has_image
         FROM leituras l
         LEFT JOIN produtos p ON p.id = l.product_id
         LEFT JOIN retornos rt ON rt.leitura_id = l.id
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'SEM_LEITURA'
         WHERE l.carregamento_id IN ({$placeholders}) AND l.result = 'SEM_LEITURA' AND l.conta_na_meta = 1
         ORDER BY l.read_at, l.id",
    );
    $noReadQuery->execute($loadIds);
    $noReadRows = $noReadQuery->fetchAll(PDO::FETCH_ASSOC);
    $operationSummary["no_reads"] = count($noReadRows);
    // Sacas que passaram sem leitura e não têm product_id: a identidade do produto
    // é desconhecida e não pode ser atribuída. Exibidas separadamente na auditoria.
    $operationSummary["no_reads_sem_produto"] = count(array_filter($noReadRows, static fn(array $r): bool => $r["product_id"] === null));
    foreach ($noReadRows as &$noRead) {
        $sensorEventId = (int) ($noRead["sensor_event_id"] ?? 0);
        $noRead["sack_reference"] = $sackReferencesByEvent[$sensorEventId] ?? "Passagem não vinculada";
        $noRead["has_image"] = (bool) $noRead["has_image"];
        if ($noRead["has_image"]) {
            $noRead["evidence_label"] = "Foto anexada";
            $operationSummary["no_read_images"]++;
        } elseif ($noRead["evidence_status"] === "CAPTURADA") {
            $noRead["evidence_label"] = "Foto recebida anteriormente";
        } elseif (in_array($noRead["evidence_status"], ["PENDENTE", "CAPTURANDO"], true)) {
            $noRead["evidence_label"] = "Foto pendente";
        } elseif ($noRead["evidence_status"] === "ERRO") {
            $noRead["evidence_label"] = "Foto não recebida";
        } else {
            $noRead["evidence_label"] = "Foto não solicitada";
        }
        if ($noRead["return_reason"] !== null) {
            $noRead["evidence_label"] = "Retirada: " . $noRead["return_reason"] . " · " . $noRead["evidence_label"];
        }
    }
    unset($noRead);
    $missingEvidence = count(array_filter($noReadRows, static fn(array $row): bool => !$row["has_image"]));
    if ($missingEvidence > 0) {
        $respondJson([
            "error" => "O relatório aguarda foto de {$missingEvidence} passagem(ns) sem código. Verifique a captura do Keyence antes de gerar o PDF.",
            "missing_evidence_count" => $missingEvidence,
        ], 409);
    }

    $wrongProductQuery = $pdo->prepare(
        "SELECT l.id, l.sensor_event_id, l.carregamento_id, l.read_at, l.barcode,
                p.name AS product_name,
                r.status AS evidence_status,
                EXISTS (
                    SELECT 1 FROM imagens i
                    WHERE i.carregamento_id = l.carregamento_id
                      AND i.reason = 'PRODUTO_INCORRETO'
                      AND (i.reading_id = l.id OR (
                          i.reading_id IS NULL AND r.id IS NOT NULL
                          AND i.path LIKE CONCAT('%keyence-', r.id, '-%')
                      ))
                ) AS has_image
         FROM leituras l
         LEFT JOIN produtos p ON p.id = l.product_id
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'PRODUTO_INCORRETO'
         WHERE l.carregamento_id IN ({$placeholders}) AND l.result = 'PRODUTO_INCORRETO'
         ORDER BY l.read_at, l.id",
    );
    $wrongProductQuery->execute($loadIds);
    $wrongProductRows = $wrongProductQuery->fetchAll(PDO::FETCH_ASSOC);
    $operationSummary["wrong_products"] = count($wrongProductRows);
    foreach ($wrongProductRows as &$wrongProduct) {
        $sensorEventId = (int) ($wrongProduct["sensor_event_id"] ?? 0);
        $wrongProduct["sack_reference"] = $sackReferencesByEvent[$sensorEventId] ?? "Passagem não contabilizada";
        $wrongProduct["has_image"] = (bool) $wrongProduct["has_image"];
        $wrongProduct["evidence_label"] = $wrongProduct["has_image"]
            ? "Foto anexada"
            : (($wrongProduct["evidence_status"] === "CAPTURADA") ? "Foto recebida anteriormente" : "Foto não recebida");
        if ($wrongProduct["has_image"]) {
            $operationSummary["wrong_product_images"]++;
        }
    }
    unset($wrongProduct);
    $wrongProductMissingImages = count(array_filter(
        $wrongProductRows,
        static fn(array $row): bool => !$row["has_image"],
    ));
    if ($wrongProductMissingImages > 0) {
        $respondJson([
            "error" => "O relatório aguarda a imagem de {$wrongProductMissingImages} produto(s) fora do romaneio.",
            "missing_evidence_count" => $wrongProductMissingImages,
        ], 409);
    }

    $occurrenceQuery = $pdo->prepare("SELECT o.type, o.quantity, o.description, o.created_at, p.name AS product_name
        FROM ocorrencias o LEFT JOIN produtos p ON p.id = o.product_id WHERE o.carregamento_id IN ({$placeholders}) ORDER BY o.created_at");
    $occurrenceQuery->execute($loadIds);
    $occurrences = $occurrenceQuery->fetchAll();

    $imageQuery = $pdo->prepare(
        "SELECT i.path, i.reason, i.captured_at, COALESCE(l.sensor_event_id, r.sensor_event_id) AS sensor_event_id
         FROM imagens i LEFT JOIN leituras l
           ON l.id = i.reading_id AND l.carregamento_id = i.carregamento_id
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON i.reading_id IS NULL AND r.carregamento_id = i.carregamento_id
          AND i.path LIKE CONCAT('%keyence-', r.id, '-%')
         WHERE i.carregamento_id IN ({$placeholders}) AND i.reason <> 'NORMAL'
           AND (i.reason <> 'EXCESSO'
             OR COALESCE(l.sensor_event_id, r.sensor_event_id) IS NULL
             OR EXISTS (
               SELECT 1 FROM eventos_sensor s
               WHERE s.id = COALESCE(l.sensor_event_id, r.sensor_event_id) AND s.conta_na_meta = 1
             ))
         ORDER BY i.captured_at",
    );
    $imageQuery->execute($loadIds);
    $incidentImages = $imageQuery->fetchAll();
}

$evidenceImages = [];
if ($incidentImages !== []) {
    foreach ($incidentImages as $image) {
        $relativePath = ltrim((string) $image["path"], "/");
        if (str_starts_with($relativePath, "armazenamento/")) {
            $relativePath = substr($relativePath, strlen("armazenamento/"));
        }
        $absolutePath = trace_image_storage_path($storageRoot, $relativePath);
        if ($absolutePath === null) {
            $reportFailure(new RuntimeException("Uma evidência do romaneio não foi encontrada."));
        }
        $capturedAt = $formatDateTime((string) $image["captured_at"]);
        $sensorEventId = (int) ($image["sensor_event_id"] ?? 0);
        $caption = (string) $image["reason"] . " · " . $capturedAt;
        if ($image["reason"] === "SEM_LEITURA" && $sensorEventId > 0) {
            $sackReference = $sackReferencesByEvent[$sensorEventId] ?? "passagem sem número";
            $caption = "Falha de leitura · {$sackReference} · {$capturedAt}";
        } elseif ($image["reason"] === "PRODUTO_INCORRETO" && $sensorEventId > 0) {
            $sackReference = $sackReferencesByEvent[$sensorEventId] ?? "passagem não contabilizada";
            $caption = "Produto fora do romaneio · {$sackReference} · {$capturedAt}";
        } elseif ($image["reason"] === "EXCESSO" && $sensorEventId > 0) {
            $sackReference = $sackReferencesByEvent[$sensorEventId] ?? "passagem sem número";
            $caption = "Saca em excesso · {$sackReference} · {$capturedAt}";
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        if ($mime === "application/pdf") {
            $evidencePdf = file_get_contents($absolutePath);
            if (!is_string($evidencePdf) || !str_starts_with($evidencePdf, "%PDF-")) {
                $reportFailure(new RuntimeException("Um PDF de evidência está inválido."));
            }
            $sourceImages = RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($evidencePdf);
            if ($sourceImages === []) {
                $reportFailure(new RuntimeException("Um PDF de evidência não contém uma imagem recuperável."));
            }
            foreach ($sourceImages as $sourceImage) {
                $evidenceImages[] = ["bytes" => $sourceImage["bytes"], "mime" => $sourceImage["mime"], "caption" => $caption];
            }
        } elseif (in_array($mime, ["image/jpeg", "image/png"], true)) {
            $sourceImage = file_get_contents($absolutePath);
            if (!is_string($sourceImage)) {
                $reportFailure(new RuntimeException("Não foi possível ler uma evidência do romaneio."));
            }
            $evidenceImages[] = ["bytes" => $sourceImage, "mime" => $mime, "caption" => $caption];
        } else {
            $reportFailure(new RuntimeException("Formato de evidência não suportado."));
        }
    }
}

$report = new RelatorioAuditoriaPdf(
    (string) $romaneio["company_name"],
    "Romaneio " . $romaneio["number"] . " · " . ($romaneio["status"] === "CANCELADO" ? "Cancelado" : "Finalizado"),
);
$report->escreverTitulo("Dados do romaneio");
$report->escreverCartoesResumo([
    "Romaneio" => $romaneio["number"],
    "Status" => $romaneio["status"] === "CANCELADO" ? "Cancelado" : "Finalizado",
    "Data programada" => $formatDate($romaneio["scheduled_date"]),
    "Expedidor" => $romaneio["expedidor"] ?: "—",
    "Placa" => $romaneio["plate"] ?: "—",
    "Motorista" => $romaneio["driver_name"] ?: "—",
    "Dala" => $equipmentLabels !== [] ? implode(", ", $equipmentLabels) : "—",
    "Início" => $formatDateTime($firstLoad["started_at"] ?? null),
    "Fim" => $formatDateTime($lastLoad["finished_at"] ?? null),
]);
$report->escreverTitulo("Resumo da operação");
$resumoOp = [
    "Sacas detectadas pelo sensor M2052" => $operationSummary["passages"],
    "Leituras válidas" => $operationSummary["valid_readings"],
    "Sacas retornadas" => $operationSummary["returned_bags"],
    "Falhas sem leitura" => $operationSummary["no_reads"],
    "Fotos de falha anexadas" => $operationSummary["no_read_images"],
    "Produtos fora do romaneio" => $operationSummary["wrong_products"],
    "Fotos desses produtos anexadas" => $operationSummary["wrong_product_images"],
];
// Exibe "Sacas não identificadas" somente quando há SEM_LEITURA com product_id
// NULL — situação exclusiva de romaneios com múltiplos produtos. Em romaneios
// de produto único todas as SEM_LEITURA já carregam o product_id correto.
if (($operationSummary["no_reads_sem_produto"] ?? 0) > 0) {
    $resumoOp["Sacas não identificadas"] = $operationSummary["no_reads_sem_produto"];
}
$report->escreverCartoesResumo($resumoOp);
$report->escreverTitulo("Falhas de leitura do código de barras");
if ($noReadRows === []) {
    $report->escreverParagrafo("Nenhuma falha sem leitura foi registrada nesta operação.");
} else {
    $report->escreverParagrafo("Cada falha corresponde a uma passagem de saco registrada pelo sensor. A foto é anexada quando recebida do Keyence. Quando o romaneio contém múltiplos produtos e o código de barras não foi lido, a identidade do produto é registrada como \"Não identificado\" — a passagem está contabilizada no total do sensor mas não em nenhum item específico.");
    $report->escreverTabela(
        ["Data/hora", "Produto", "Passagem", "Evidência da imagem"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["read_at"]),
                $row["product_name"] ?? "Não identificado",
                $row["sack_reference"],
                $row["evidence_label"],
            ],
            $noReadRows,
        ),
        [110, 120, 140, 145],
    );
}
if ($wrongProductRows !== []) {
    $report->escreverTitulo("Produtos fora do romaneio");
    $report->escreverParagrafo("Cada linha representa um código reconhecido de um produto que não pertence ao romaneio. Essas passagens não entram na quantidade prevista; a foto correspondente aparece na seção de evidências.");
    $report->escreverTabela(
        ["Data/hora", "Produto reconhecido", "Código lido", "Passagem", "Evidência"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["read_at"]),
                $row["product_name"] ?? "Produto não identificado",
                $row["barcode"] ?? "—",
                $row["sack_reference"],
                $row["evidence_label"],
            ],
            $wrongProductRows,
        ),
        [88, 105, 95, 115, 92],
    );
}
if ($romaneio["status"] === "CANCELADO") {
    $report->escreverParagrafo("Valores registrados até o cancelamento.");
}
$report->escreverTitulo("Conferência dos itens");
$report->escreverTabela(
    ["Produto", "Código", "Previsto", "Movido", "Sem leitura", "Status"],
    array_map(static function (array $item): array {
        $planned = (int) $item["planned_quantity"];
        $moved = (int) $item["moved_quantity"];
        return [
            $item["name"],
            $item["barcode"],
            $planned,
            $moved,
            (int) $item["no_read_quantity"],
            $moved === $planned
                ? "Concluído"
                : ($moved < $planned
                    ? "Parcial"
                    : "Excesso"),
        ];
    }, $itemRows),
    [185, 95, 60, 60, 65, 50],
);
$sensorPassages = (int) ($operationSummary["passages"] ?? 0);
$scannerReadings = (int) ($operationSummary["scanner_readings"] ?? 0);
$excessImages = (int) ($operationSummary["excess_images"] ?? 0);
$excesses = (int) ($operationSummary["excesses"] ?? 0);
$report->escreverParagrafo(
    "Sensor: {$sensorPassages} passagem(ns) detectada(s) · " .
    "Scanner: {$scannerReadings} leitura(s) com código · " .
    "Excesso com foto: {$excessImages} de {$excesses} passagem(ns).",
);
// Nota sobre sacas sem identificação: só aparece quando há SEM_LEITURA com
// product_id NULL, o que ocorre quando o romaneio tem múltiplos produtos e o
// sensor registrou a passagem mas o leitor não leu o código. Essas sacas não
// entram na coluna Movido de nenhum produto — são rastreáveis pelas fotos.
if (($operationSummary["no_reads_sem_produto"] ?? 0) > 0) {
    $semProduto = $operationSummary["no_reads_sem_produto"];
    $report->escreverParagrafo(
        "Atenção: {$semProduto} " . ($semProduto === 1 ? "saca passou" : "sacas passaram") . " pelo sensor sem leitura do código de barras e sem identificação de produto. " .
        "Essas passagens constam no total detectado pelo sensor M2052, mas não são atribuídas a nenhum item acima. " .
        "Consulte a seção \"Falhas de leitura do código de barras\" e as evidências fotográficas para rastrear cada passagem.",
    );
}
$divergences = array_values(
    array_filter(
        $itemRows,
        static fn(array $item): bool => (int) $item["planned_quantity"] !==
            (int) $item["moved_quantity"],
    ),
);
if ($divergences === []) {
    $report->escreverParagrafo("Sem divergências de itens identificados.");
} else {
    $report->escreverTitulo("Divergências");
    $report->escreverTabela(
        ["Produto", "Código", "Movido / previsto", "Situação"],
        array_map(
            static fn(array $item): array => [
                $item["name"],
                $item["barcode"],
                $item["moved_quantity"] . " / " . $item["planned_quantity"],
                (int) $item["moved_quantity"] < (int) $item["planned_quantity"]
                    ? "Falta"
                    : "Excesso",
            ],
            $divergences,
        ),
        [215, 110, 110, 80],
    );
}
if ($occurrences !== []) {
    $occurrenceLabels = [
        "FALHA_SEM_LEITURA" => "Falha na leitura do código de barras",
        "SACA_RASGADA" => "Saca rasgada",
        "SACA_AVARIADA" => "Saca avariada",
        "PARADA_MAQUINA" => "Parada de máquina",
        "LIMPEZA_LINHA" => "Limpeza de linha",
        "QUEDA_ENERGIA" => "Queda de energia",
        "AJUSTE_EQUIPAMENTO" => "Ajuste de equipamento",
        "FALHA_ELETRICA" => "Falha elétrica",
    ];
    $report->escreverTitulo("Ocorrências");
    $report->escreverTabela(
        ["Data/hora", "Tipo", "Produto", "Detalhes"],
        array_map(
            static function (array $row) use ($formatDateTime, $occurrenceLabels): array {
                return [
                    $formatDateTime($row["created_at"]),
                    $occurrenceLabels[$row["type"]] ?? $row["type"],
                    $row["product_name"] ?: "—",
                    $row["description"] ?: "—",
                ];
            },
            $occurrences,
        ),
        [100, 115, 150, 150],
    );
}
if ($evidenceImages !== []) {
    $report->escreverTitulo("Evidências");
    foreach ($evidenceImages as $image) {
        if (!$report->inserirDadosDaImagemDaOcorrencia($image["bytes"], $image["mime"], $image["caption"])) {
            $reportFailure(new RuntimeException("Não foi possível incorporar uma evidência ao PDF final."));
        }
    }
}
try {
    $pdfBytes = $report->obterArquivoPdf();
} catch (Throwable $exception) {
    $reportFailure($exception);
}
$temporaryReportPath = $reportPath . ".tmp-" . bin2hex(random_bytes(8));
if (file_put_contents($temporaryReportPath, $pdfBytes, LOCK_EX) !== strlen($pdfBytes)
    || !chmod($temporaryReportPath, 0600) || !rename($temporaryReportPath, $reportPath)) {
    if (is_file($temporaryReportPath)) {
        trace_remove_image_storage_file($storageRoot, $temporaryReportPath);
    }
    $respondJson(["error" => "Não foi possível salvar o PDF final no servidor; as evidências foram preservadas."], 503);
}
{
    $manifestPath = $reportPath . ".evidence.json";
    if (is_link($manifestPath)) {
        $respondJson(["error" => "Manifesto de evidências inválido; as evidências foram preservadas."], 503);
    }
    $manifest = json_encode([
        "version" => 3,
        "pdf_sha256" => hash("sha256", $pdfBytes),
        "no_read_count" => $noReadCount,
        "evidence_count" => (int) ($operationSummary["no_read_images"] ?? 0),
        "excess_count" => $excessEvidenceCount,
        "excess_evidence_count" => $excessImagesCount,
        "wrong_product_count" => $wrongProductCount,
        "wrong_product_evidence_count" => $wrongProductImagesCount,
    ], JSON_UNESCAPED_SLASHES);
    $temporaryManifestPath = $manifestPath . ".tmp-" . bin2hex(random_bytes(8));
    if (!is_string($manifest)
        || file_put_contents($temporaryManifestPath, $manifest, LOCK_EX) !== strlen((string) $manifest)
        || !chmod($temporaryManifestPath, 0600)
        || !rename($temporaryManifestPath, $manifestPath)) {
        if (is_file($temporaryManifestPath)) {
            trace_remove_image_storage_file($storageRoot, $temporaryManifestPath);
        }
        $respondJson(["error" => "Não foi possível validar as evidências do relatório salvo."], 503);
    }
}
try {
    trace_cleanup_report_evidence($pdo, $loadIds, (int) $user["company_id"], $storageRoot);
} catch (Throwable $exception) {
    $reportFailure($exception);
}
try {
    $reportPublisher->enfileirar((int) $user["company_id"], (int) $romaneioId, $reportRelativePath);
} catch (Throwable $exception) {
    registrar_log_erro($exception, "relatorio_auditoria_sync");
    $respondJson(["error" => "O PDF foi preservado, mas não entrou na fila de sincronização. Refaça o download para tentar novamente."], 503);
}
if (is_array($apiClient)) {
    api_v1_registrar_download_pdf($pdo, $apiClient, (int) $romaneioId);
} else {
    record_operational_event($pdo, $user, "RELATORIO_AUDITORIA_BAIXADO", "romaneio", (int) $romaneioId, []);
}
header_remove("Content-Type");
header("Content-Type: application/pdf");
header(
    'Content-Disposition: attachment; filename="romaneio-' .
        preg_replace("/[^A-Za-z0-9_-]/", "-", (string) $romaneio["number"]) .
        '-auditoria.pdf"',
);
header("Cache-Control: no-store");
echo $pdfBytes;
