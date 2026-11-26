<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../operacoes/temporizacao_leitor.php";
require_once __DIR__ . "/../../src/Aplicacao/RelatorioAuditoriaPdf.php";
require_once __DIR__ . "/../../src/Aplicacao/FontesEvidenciasRelatorio.php";
require_once __DIR__ . "/../../src/Aplicacao/ConferidorEvidenciasRelatorio.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoPublicacaoRelatoriosAuditoria.php";
require_once __DIR__ . "/../../../scripts/image_storage_path.php";

use App\Aplicacao\RelatorioAuditoriaPdf;
use App\Aplicacao\FontesEvidenciasRelatorio;
use App\Aplicacao\ConferidorEvidenciasRelatorio;
use App\Aplicacao\ServicoPublicacaoRelatoriosAuditoria;

// Geração interna pelo report-worker do PC (scripts/gerar_relatorio_auditoria_interno.php):
// sem sessão, sem registrar download e sem devolver o PDF. O arquivo com as
// fotos do Keyence é salvo e publicado no central mesmo que ninguém abra o
// relatório no PC.
$internalReport = $GLOBALS["trace_relatorio_interno"] ?? null;
$apiClient = is_array($internalReport) ? null : ($GLOBALS["trace_api_v1_client"] ?? null);
$user = match (true) {
    is_array($internalReport) => [
        "id" => null,
        "company_id" => ((int) ($internalReport["company_id"] ?? 0)) > 0 ? (int) $internalReport["company_id"] : null,
    ],
    is_array($apiClient) => ["id" => null, "company_id" => (int) $apiClient["company_id"]],
    default => require_session_user(),
};
$respondJson = static function (array $payload, int $status = 200) use ($apiClient, $internalReport): never {
    if (is_array($internalReport)) {
        echo json_encode(["status" => $status] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit($status >= 400 ? 1 : 0);
    }
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

if (!is_array($apiClient) && !is_array($internalReport)) {
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
$finishInternalReport = static function (array $data) use ($internalReport): void {
    if (!is_array($internalReport)) {
        return;
    }
    echo json_encode(["status" => 200, "data" => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
};
$downloadAuditPdf = static function (string $pdfBytes) use ($apiClient, $pdo, $user, $romaneioId, $romaneio, $finishInternalReport): never {
    $finishInternalReport(["romaneio_id" => (int) $romaneioId, "cached" => true]);
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
$evidenceWarnings = [];
$evidenceAttachmentFailure = null;
$unresolvedPassageCount = 0;
$loadIds = array_map(static fn(array $row): int => (int) $row["id"], $loadRows);
if ($loadIds !== []) {
    $unresolvedPassages = $pdo->prepare(
        "SELECT COUNT(*) FROM eventos_sensor s
         WHERE s.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)",
    );
    $unresolvedPassages->execute($loadIds);
    $unresolvedPassageCount = (int) $unresolvedPassages->fetchColumn();
    if ($unresolvedPassageCount > 0) {
        $evidenceWarnings[] = "{$unresolvedPassageCount} passagem(ns) com leitura ainda em processamento. Os resultados refletem os dados disponíveis nesta emissão.";
    }
}
// Hashes das capturas são comparáveis entre PC e Central sem depender dos IDs locais.
$manifestCaptureQuery = $pdo->prepare(
    "SELECT e.reason, e.image_sha256, s.event_uuid AS sensor_event_uuid FROM solicitacoes_imagens_evidencia e
     JOIN carregamentos c ON c.id = e.carregamento_id
     JOIN eventos_sensor s ON s.id = e.sensor_event_id AND s.carregamento_id = e.carregamento_id
     WHERE c.romaneio_id = :romaneio_id AND c.company_id = :company_id
       AND e.reason IN ('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO')
       AND e.status = 'CAPTURADA'
       AND (e.reason <> 'EXCESSO' OR s.conta_na_meta = 1) ORDER BY e.id",
);
$manifestCaptureQuery->execute(["romaneio_id" => $romaneioId, "company_id" => $user["company_id"]]);
$manifestCaptures = $manifestCaptureQuery->fetchAll(PDO::FETCH_ASSOC);
$isLocalReport = function_exists("trace_e_instalacao_local") && trace_e_instalacao_local();
// O Central não replica as solicitações de captura. Nesse caso, a identidade
// verificada no PC chega pelo upload autenticado e fica junto ao hash do PDF.
// Havendo solicitações locais, exigimos também correspondência individual.
$publishedIdentityMatches = static function (array $data) use ($manifestCaptures): bool {
    if ($manifestCaptures !== []) {
        return ConferidorEvidenciasRelatorio::manifestoCorresponde($data, $manifestCaptures);
    }
    return preg_match('/\A[a-f0-9]{64}\z/', (string) ($data["evidence_identity_sha256"] ?? "")) === 1;
};
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
        && (int) ($publishedManifestData["evidence_count"] ?? -1) >= 0
        && (int) ($publishedManifestData["evidence_count"] ?? -1) <= $publishedNoReadCount;
        $publishedExcessMatches = is_array($publishedManifestData)
            && (int) ($publishedManifestData["excess_count"] ?? 0) === $publishedExcessCount
        && (int) ($publishedManifestData["excess_evidence_count"] ?? -1) >= 0
        && (int) ($publishedManifestData["excess_evidence_count"] ?? -1) <= $publishedExcessCount;
        $publishedWrongProductMatches = is_array($publishedManifestData)
            && (int) ($publishedManifestData["wrong_product_count"] ?? -1) === $publishedWrongProductCount
        && (int) ($publishedManifestData["wrong_product_evidence_count"] ?? -1) >= 0
        && (int) ($publishedManifestData["wrong_product_evidence_count"] ?? -1) <= $publishedWrongProductCount;
        $publishedEvidenceValid = $publishedCountsMatch
            && (($publishedVersion === 3 && $publishedExcessMatches && $publishedWrongProductMatches)
                // Versões anteriores não registravam fotos de produtos fora do romaneio.
                || ($publishedVersion === 2 && $publishedExcessMatches && $publishedWrongProductCount === 0)
                || ($publishedVersion === 1 && $publishedExcessCount === 0 && $publishedWrongProductCount === 0))
            && is_string($publishedHash)
            && hash_equals((string) ($publishedManifestData["pdf_sha256"] ?? ""), $publishedHash);
        if ($publishedEvidenceValid && !$isLocalReport
            && $publishedIdentityMatches($publishedManifestData)) {
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
                header("X-Trace-Report-Sync: pending");
            }
            $downloadAuditPdf($publishedPdfBytes);
        }
    }
}
if ($loadIds !== []) {
    expirar_solicitacoes_imagem_keyence($pdo, null, $loadIds);
    $unavailableCaptures = $pdo->prepare(
        "SELECT id, sensor_event_id, reason, status FROM solicitacoes_imagens_evidencia
         WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND reason IN ('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO')
           AND status <> 'CAPTURADA' ORDER BY id",
    );
    $unavailableCaptures->execute($loadIds);
    foreach ($unavailableCaptures->fetchAll(PDO::FETCH_ASSOC) as $capture) {
        $evidenceWarnings[] = "Evento " . $capture["sensor_event_id"] . " · Evidência " . $capture["id"]
            . " · " . $capture["reason"] . ": foto não recebida nesta emissão (" . $capture["status"] . ").";
    }
    $incidentsWithoutRequest = $pdo->prepare(
        "SELECT l.id, l.sensor_event_id, l.result FROM leituras l
         WHERE l.carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND l.result IN ('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO')
           AND NOT EXISTS (SELECT 1 FROM solicitacoes_imagens_evidencia r
             WHERE r.carregamento_id = l.carregamento_id AND r.sensor_event_id = l.sensor_event_id
               AND r.reason = l.result) ORDER BY l.id",
    );
    $incidentsWithoutRequest->execute($loadIds);
    foreach ($incidentsWithoutRequest->fetchAll(PDO::FETCH_ASSOC) as $incident) {
        $evidenceWarnings[] = "Leitura " . $incident["id"] . " · Evento " . ($incident["sensor_event_id"] ?? "não vinculado")
            . " · " . $incident["result"] . ": foto não solicitada ou solicitação não vinculada.";
    }
}

$noReadCount = 0;
$excessEvidenceCount = 0;
$excessImagesCount = 0;
$wrongProductCount = 0;
$wrongProductImagesCount = 0;
if ($loadIds !== []) {
    $noReadCountQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ") AND result = 'SEM_LEITURA'",
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
        && (int) ($manifestData["evidence_count"] ?? -1) >= 0
        && (int) ($manifestData["evidence_count"] ?? -1) <= $noReadCount
        && (int) ($manifestData["excess_count"] ?? -1) === $excessEvidenceCount
        && (int) ($manifestData["excess_evidence_count"] ?? -1) >= 0
        && (int) ($manifestData["excess_evidence_count"] ?? -1) <= $excessEvidenceCount
        && (int) ($manifestData["wrong_product_count"] ?? -1) === $wrongProductCount
        && (int) ($manifestData["wrong_product_evidence_count"] ?? -1) >= 0
        && (int) ($manifestData["wrong_product_evidence_count"] ?? -1) <= $wrongProductCount
        && is_string($cachedPdfHash)
        && hash_equals((string) ($manifestData["pdf_sha256"] ?? ""), $cachedPdfHash);
 }
if ($savedReport !== null && $cachedEvidenceValid && !$isLocalReport
    && $publishedIdentityMatches($manifestData)) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Caminho usa IDs inteiros validados e é resolvido na raiz canônica, recusando links simbólicos.
    $savedPdf = file_get_contents($savedReport);
    if (!is_string($savedPdf) || !str_starts_with($savedPdf, "%PDF-")) {
        $respondJson(["error" => "O PDF salvo está inválido; as evidências foram preservadas."], 503);
    }
    try {
        $reportPublisher->enfileirar((int) $user["company_id"], (int) $romaneioId, $reportRelativePath);
    } catch (Throwable $exception) {
        registrar_log_erro($exception, "relatorio_auditoria_sync");
        header("X-Trace-Report-Sync: pending");
    }
    $finishInternalReport(["romaneio_id" => (int) $romaneioId, "report" => $reportRelativePath, "cached" => true]);
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
$unmatchedReaderRows = [];
$wrongProductRows = [];
$unregisteredCodeEvents = [];
$sackReferencesByEvent = [];
$passagesDuringPause = [];
$wrongProductReasonByEvent = [];
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
                          AND l.result = 'PRODUTO_INCORRETO' AND l.product_id IS NULL
                          AND NOT EXISTS (SELECT 1 FROM codigos_produtos cp
                                          JOIN produtos cpp ON cpp.id = cp.product_id AND cpp.company_id = cp.company_id
                                          WHERE cp.barcode = l.barcode AND cp.company_id = l.company_id
                                            AND cpp.active = 1)) AS codigo_nao_cadastrado,
                -- Produto previsto para este caminhão, lido depois de completar a quantidade.
                EXISTS (SELECT 1 FROM leituras l
                        JOIN carregamentos lc ON lc.id = l.carregamento_id
                        JOIN romaneio_itens lri ON lri.romaneio_id = lc.romaneio_id AND lri.product_id = l.product_id
                          AND (lri.truck_id = lc.truck_id OR lri.truck_id IS NULL)
                        WHERE l.sensor_event_id = eventos_sensor.id
                          AND l.result = 'PRODUTO_INCORRETO') AS acima_quantidade_item,
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
    // Janelas de pausa (mesma regra de ServicoPausasCarregamento::estaNaPausa):
    // separam a passagem com a esteira parada, como a saca retirada passando
    // pelo sensor, do saco excedente que passou depois da meta.
    $pauseQuery = $pdo->prepare(
        "SELECT carregamento_id, paused_at_epoch_ms, resumed_at_epoch_ms FROM pausas_carregamento
         WHERE carregamento_id IN ({$placeholders})",
    );
    $pauseQuery->execute($loadIds);
    $pausesByLoading = [];
    foreach ($pauseQuery->fetchAll(PDO::FETCH_ASSOC) as $pause) {
        $pausesByLoading[(int) $pause["carregamento_id"]][] = $pause;
    }
    $passageDuringPause = static function (array $passage) use ($pausesByLoading): bool {
        $eventAt = (int) $passage["event_at_epoch_ms"];
        foreach ($pausesByLoading[(int) $passage["carregamento_id"]] ?? [] as $pause) {
            if ((int) $pause["paused_at_epoch_ms"] <= $eventAt
                && ($pause["resumed_at_epoch_ms"] === null || (int) $pause["resumed_at_epoch_ms"] > $eventAt)) {
                return true;
            }
        }
        return false;
    };
    $passageNumbers = [];
    foreach ($passages as $passage) {
        $loadingId = (int) $passage["carregamento_id"];
        if ((int) $passage["conta_na_meta"] !== 1) {
            $duringPause = $passageDuringPause($passage);
            $passagesDuringPause[(int) $passage["id"]] = $duringPause;
            $reason = (int) $passage["codigo_nao_cadastrado"] === 1
                ? "Código válido não cadastrado"
                : ((int) $passage["acima_quantidade_item"] === 1
                ? "Acima da quantidade prevista"
                : ((int) $passage["produto_incorreto"] === 1
                ? "Produto fora do romaneio"
                : ((int) $passage["produto_excedente"] === 1 || !$duringPause
                    ? "Saco excedente"
                    : "Passagem durante pausa")));
            if ((int) $passage["produto_incorreto"] === 1) {
                $wrongProductReasonByEvent[(int) $passage["id"]] = $reason;
            }
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
    // Produto incorreto (fora do romaneio ou código não cadastrado) é retirado
    // da carga: aparece listado abaixo, mas não entra no total de leituras.
    $scannerCount = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id IN ({$placeholders}) AND barcode IS NOT NULL
           AND result IN ('VALIDO', 'EXCESSO')",
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
        "SELECT l.id, l.sensor_event_id, l.carregamento_id, l.read_at, l.barcode,
                l.product_id, l.conta_na_meta,
                p.name AS product_name,
                r.status AS evidence_status,
                rt.reason AS return_reason,
                (EXISTS (
                    SELECT 1 FROM imagens i
                    WHERE i.carregamento_id = l.carregamento_id
                      AND i.reason = 'SEM_LEITURA'
                      AND (i.reading_id = l.id OR (
                          i.reading_id IS NULL AND r.id IS NOT NULL
                          AND i.path LIKE CONCAT('%keyence-', r.id, '-%')
                      ))
                ) OR (r.status = 'CAPTURADA' AND r.image_sha256 REGEXP '^[a-f0-9]{64}$')) AS has_image
         FROM leituras l
         LEFT JOIN produtos p ON p.id = l.product_id
         LEFT JOIN retornos rt ON rt.leitura_id = l.id
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'SEM_LEITURA'
         WHERE l.carregamento_id IN ({$placeholders}) AND l.result = 'SEM_LEITURA'
         ORDER BY l.read_at, l.id",
    );
    $noReadQuery->execute($loadIds);
    $noReadRows = $noReadQuery->fetchAll(PDO::FETCH_ASSOC);
    // Passagem sem código com a esteira parada (por exemplo, a saca retirada
    // passando pelo sensor) continua listada como "não contabilizada", mas não
    // soma em nenhum cartão. O saco excedente sem código segue nos totais.
    $countedNoReadRows = array_filter(
        $noReadRows,
        static fn(array $r): bool => (int) $r["conta_na_meta"] === 1
            || !($passagesDuringPause[(int) ($r["sensor_event_id"] ?? 0)] ?? false),
    );
    $operationSummary["no_reads"] = count($countedNoReadRows);
    // Sacas que passaram sem leitura e não têm product_id: a identidade do produto
    // é desconhecida e não pode ser atribuída. Exibidas separadamente na auditoria.
    $operationSummary["no_reads_sem_produto"] = count(array_filter($countedNoReadRows, static fn(array $r): bool => $r["product_id"] === null));
    foreach ($noReadRows as &$noRead) {
        $sensorEventId = (int) ($noRead["sensor_event_id"] ?? 0);
        $noRead["sack_reference"] = $sackReferencesByEvent[$sensorEventId] ?? "Passagem não vinculada";
    }
    unset($noRead);
    // Resultados do leitor que não foram ligados a nenhuma passagem (código sem
    // passagem, captura com a esteira parada ou descartada pela tela). Antes
    // eles ficavam só no contador da tela de operação.
    try {
        $unmatchedQuery = $pdo->prepare(
            "SELECT u.captured_at, u.barcode, u.no_read, u.motivo,
                    (SELECT p.name FROM codigos_produtos pc
                       JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
                      WHERE pc.company_id = u.company_id AND pc.barcode = u.barcode
                      LIMIT 1) AS product_name
             FROM leituras_sem_passagem u
             WHERE u.carregamento_id IN ({$placeholders})
             ORDER BY u.captured_at, u.id",
        );
        $unmatchedQuery->execute($loadIds);
        $unmatchedReaderRows = $unmatchedQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        // Tabela ainda não criada (migração 085 pendente): o relatório sai sem a seção.
        $unmatchedReaderRows = [];
    }
    $wrongProductQuery = $pdo->prepare(
        "SELECT l.id, l.sensor_event_id, l.carregamento_id, l.read_at, l.barcode,
                -- Produto do catálogo fora do romaneio é gravado sem product_id;
                -- o código lido ainda identifica o produto pelo catálogo.
                COALESCE(p.name, (SELECT cpp.name FROM codigos_produtos cp
                                  JOIN produtos cpp ON cpp.id = cp.product_id AND cpp.company_id = cp.company_id
                                  WHERE cp.barcode = l.barcode AND cp.company_id = l.company_id AND cpp.active = 1
                                  ORDER BY cpp.id LIMIT 1)) AS product_name,
                r.status AS evidence_status,
                (EXISTS (
                    SELECT 1 FROM imagens i
                    WHERE i.carregamento_id = l.carregamento_id
                      AND i.reason = 'PRODUTO_INCORRETO'
                      AND (i.reading_id = l.id OR (
                          i.reading_id IS NULL AND r.id IS NOT NULL
                          AND i.path LIKE CONCAT('%keyence-', r.id, '-%')
                      ))
                ) OR (r.status = 'CAPTURADA' AND r.image_sha256 REGEXP '^[a-f0-9]{64}$')) AS has_image
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
    }
    unset($wrongProduct);
    foreach ($wrongProductRows as $wrongProduct) {
        if ($wrongProduct["product_name"] === null && (int) ($wrongProduct["sensor_event_id"] ?? 0) > 0) {
            $unregisteredCodeEvents[(int) $wrongProduct["sensor_event_id"]] = true;
        }
    }
    $occurrenceQuery = $pdo->prepare("SELECT o.type, o.quantity, o.description, o.created_at, p.name AS product_name
        FROM ocorrencias o LEFT JOIN produtos p ON p.id = o.product_id WHERE o.carregamento_id IN ({$placeholders}) ORDER BY o.created_at");
    $occurrenceQuery->execute($loadIds);
    $occurrences = $occurrenceQuery->fetchAll();

    $imageQuery = $pdo->prepare(
        "SELECT i.id AS image_id, i.company_id, i.equipment_id, i.carregamento_id, i.reading_id, i.path, i.reason, i.captured_at,
                COALESCE(l.sensor_event_id, r.sensor_event_id) AS sensor_event_id,
                r.id AS request_id, NULL AS image_sha256
         FROM imagens i LEFT JOIN leituras l
           ON l.id = i.reading_id AND l.carregamento_id = i.carregamento_id
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.carregamento_id = i.carregamento_id AND r.reason = i.reason
          AND (i.path LIKE CONCAT('%keyence-', r.id, '-%') OR i.path LIKE CONCAT('%capture-', r.id, '-%'))
         WHERE i.carregamento_id IN ({$placeholders}) AND i.reason <> 'NORMAL'
           AND (i.reason <> 'EXCESSO'
             OR COALESCE(l.sensor_event_id, r.sensor_event_id) IS NULL
             OR EXISTS (
               SELECT 1 FROM eventos_sensor s
               WHERE s.id = COALESCE(l.sensor_event_id, r.sensor_event_id) AND s.conta_na_meta = 1
             ))
         ORDER BY i.captured_at, i.id",
    );
    $imageQuery->execute($loadIds);
    $incidentImages = $imageQuery->fetchAll();

    $capturedRequestsQuery = $pdo->prepare(
        "SELECT r.id AS request_id, r.carregamento_id, c.company_id,
                COALESCE(r.equipment_id, c.equipment_id) AS equipment_id,
                r.reason, r.captured_at, r.sensor_event_id, r.image_sha256, l.id AS reading_id, s_ref.event_uuid AS sensor_event_uuid
         FROM solicitacoes_imagens_evidencia r
         JOIN carregamentos c ON c.id = r.carregamento_id
         JOIN eventos_sensor s_ref ON s_ref.id = r.sensor_event_id AND s_ref.carregamento_id = r.carregamento_id
         LEFT JOIN leituras l ON l.sensor_event_id = r.sensor_event_id
           AND l.carregamento_id = r.carregamento_id AND l.company_id = c.company_id
           AND ((r.reason = 'SEM_LEITURA' AND l.result = 'SEM_LEITURA')
             OR (r.reason = 'PRODUTO_INCORRETO' AND l.result = 'PRODUTO_INCORRETO')
             OR (r.reason = 'EXCESSO' AND l.result IN ('EXCESSO', 'SEM_LEITURA')))
         WHERE r.carregamento_id IN ({$placeholders}) AND r.status = 'CAPTURADA'
           AND r.reason IN ('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO')
           AND LOWER(r.image_sha256) REGEXP '^[a-f0-9]{64}$'
           AND (r.reason <> 'EXCESSO' OR EXISTS (
             SELECT 1 FROM eventos_sensor s
             WHERE s.id = r.sensor_event_id AND s.conta_na_meta = 1
           ))
         ORDER BY r.captured_at, r.id",
    );
    $capturedRequestsQuery->execute($loadIds);
    $capturedRequests = $capturedRequestsQuery->fetchAll(PDO::FETCH_ASSOC);

    $representedRequestIds = [];
    foreach ($incidentImages as $image) {
        $requestId = (int) ($image["request_id"] ?? 0);
        if ($requestId < 1
            && preg_match('/(?:^|\/)keyence-([1-9][0-9]*)-[a-f0-9]{64}\.(?:jpg|png)\z/i', (string) $image["path"], $matches) === 1) {
            $requestId = (int) $matches[1];
        }
        if ($requestId > 0) {
            $representedRequestIds[$requestId] = true;
        }
    }
    foreach ($capturedRequests as $request) {
        $requestId = (int) ($request["request_id"] ?? 0);
        $companyId = (int) ($request["company_id"] ?? 0);
        $equipmentId = (int) ($request["equipment_id"] ?? 0);
        $imageHash = strtolower(trim((string) ($request["image_sha256"] ?? "")));
        if ($requestId < 1 || isset($representedRequestIds[$requestId])
            || $companyId < 1 || $equipmentId < 1
            || preg_match('/\A[a-f0-9]{64}\z/', $imageHash) !== 1) {
            continue;
        }
        $incidentImages[] = [
            "image_id" => null,
            "carregamento_id" => (int) $request["carregamento_id"],
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
            "path" => "company_{$companyId}/equipment_{$equipmentId}/keyence-{$requestId}-{$imageHash}.jpg",
            "reason" => (string) $request["reason"],
            "captured_at" => (string) $request["captured_at"],
            "sensor_event_id" => (int) ($request["sensor_event_id"] ?? 0),
            "request_id" => $requestId,
            "image_sha256" => $imageHash,
            "reading_id" => $request["reading_id"] === null ? null : (int) $request["reading_id"],
        ];
        $representedRequestIds[$requestId] = true;
    }
    $selection = ConferidorEvidenciasRelatorio::selecionarDisponiveis($incidentImages, $capturedRequests);
    $incidentImages = $selection["images"];
    if (count(array_filter(
        $selection["issues"],
        static fn(array $issue): bool => (int) ($issue["request_id"] ?? 0) > 0,
    )) > 0) {
        $evidenceAttachmentFailure = new RuntimeException(
            "Uma foto de erro recebida não pôde ser identificada sem ambiguidade.",
        );
    }
    foreach ($selection["issues"] as $issue) {
        $evidenceWarnings[] = "Evento " . ($issue["sensor_event_id"] ?? "não confirmado")
            . " · Evidência " . $issue["request_id"] . " · " . $issue["reason"]
            . ": vínculo não confirmado; foto não anexada. " . $issue["detail"];
    }
    usort($incidentImages, static function (array $left, array $right): int {
        $timeOrder = strcmp((string) ($left["captured_at"] ?? ""), (string) ($right["captured_at"] ?? ""));
        if ($timeOrder !== 0) {
            return $timeOrder;
        }
        return ((int) ($left["image_id"] ?? $left["request_id"] ?? 0))
            <=> ((int) ($right["image_id"] ?? $right["request_id"] ?? 0));
    });
}

$previousEvidenceImagesByReference = [];
if (is_file($reportPath) && !is_link($reportPath)) {
    $previousPdfBytes = file_get_contents($reportPath);
    $previousManifestBytes = is_file($evidenceManifestPath) && !is_link($evidenceManifestPath)
        ? file_get_contents($evidenceManifestPath)
        : false;
    $previousManifest = is_string($previousManifestBytes)
        ? json_decode($previousManifestBytes, true)
        : null;
    $previousPdfHash = hash_file("sha256", $reportPath);
    if (is_string($previousPdfBytes) && str_starts_with($previousPdfBytes, "%PDF-")
        && is_array($previousManifest) && is_string($previousPdfHash)
        && hash_equals((string) ($previousManifest["pdf_sha256"] ?? ""), $previousPdfHash)) {
        try {
            $previousEvidenceImagesByReference = FontesEvidenciasRelatorio::indexar(
                $previousPdfBytes,
                $previousManifest,
                $capturedRequests ?? [],
                (int) (filemtime($reportPath) ?: 0),
            );
        } catch (RuntimeException $exception) {
            registrar_log_erro($exception, "relatorio_recuperacao_evidencia");
            $evidenceWarnings[] = "Não foi possível recuperar fotos do PDF anterior. As demais evidências foram conferidas individualmente.";
        }
    }
}

$evidenceImages = [];
if ($incidentImages !== []) {
    foreach ($incidentImages as $image) {
        try {
            $relativePath = ltrim((string) $image["path"], "/");
            if (str_starts_with($relativePath, "armazenamento/")) {
                $relativePath = substr($relativePath, strlen("armazenamento/"));
            }
            $absolutePath = trace_image_storage_path($storageRoot, $relativePath);
            $imageId = (int) ($image["image_id"] ?? 0);
            $requestId = (int) ($image["request_id"] ?? 0);
            if ($requestId < 1
                && preg_match('/(?:^|\/)keyence-([1-9][0-9]*)-[a-f0-9]{64}\.(?:jpg|png)\z/i', $relativePath, $matches) === 1) {
                $requestId = (int) $matches[1];
            }
            $expectedImageHash = strtolower(trim((string) ($image["image_sha256"] ?? "")));
            if ($expectedImageHash === ""
                && preg_match('/(?:^|\/)keyence-[1-9][0-9]*-([a-f0-9]{64})\.(?:jpg|png)\z/i', $relativePath, $matches) === 1) {
                $expectedImageHash = strtolower($matches[1]);
            }
            $sourceImages = [];
            if ($absolutePath === null) {
                $reference = $requestId > 0 ? "request:" . $requestId : "image:" . $imageId;
                $sourceImages = $previousEvidenceImagesByReference[$reference] ?? [];
                if ($expectedImageHash !== "" && preg_match('/\A[a-f0-9]{64}\z/', $expectedImageHash) === 1) {
                    $sourceImages = array_values(array_filter(
                        $sourceImages,
                        static fn(array $sourceImage): bool => hash_equals($expectedImageHash, $sourceImage["sha256"]),
                    ));
                }
                if ($sourceImages === []) {
                    throw new RuntimeException(
                        "A foto original não está no armazenamento e não pôde ser recuperada do PDF anterior.",
                    );
                }
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
                if ($mime === "application/pdf") {
                    $evidencePdf = file_get_contents($absolutePath);
                    if (!is_string($evidencePdf) || !str_starts_with($evidencePdf, "%PDF-")) {
                        throw new RuntimeException("Um PDF de evidência está inválido.");
                    }
                    $sourceImages = RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($evidencePdf);
                    if ($sourceImages === []) {
                        throw new RuntimeException("Um PDF de evidência não contém uma imagem recuperável.");
                    }
                } elseif (in_array($mime, ["image/jpeg", "image/png"], true)) {
                    $sourceImageBytes = file_get_contents($absolutePath);
                    if (!is_string($sourceImageBytes)) {
                        throw new RuntimeException("Não foi possível ler uma evidência do romaneio.");
                    }
                    $sourceImages = [["bytes" => $sourceImageBytes, "mime" => $mime]];
                } else {
                    throw new RuntimeException("Formato de evidência não suportado.");
                }
            }
            $capturedAt = $formatDateTime((string) $image["captured_at"]);
            $sensorEventId = (int) ($image["sensor_event_id"] ?? 0);
            $caption = (string) $image["reason"] . " · " . $capturedAt;
            if ($image["reason"] === "SEM_LEITURA" && $sensorEventId > 0) {
                $sackReference = $sackReferencesByEvent[$sensorEventId] ?? "passagem sem número";
                $caption = "Falha de leitura · {$sackReference} · {$capturedAt}";
            } elseif ($image["reason"] === "PRODUTO_INCORRETO" && $sensorEventId > 0) {
                // O motivo vem da passagem (não cadastrado, fora do romaneio ou acima
                // da quantidade prevista), sem repetir o texto na legenda.
                $captionType = isset($unregisteredCodeEvents[$sensorEventId])
                    ? "Código válido não cadastrado"
                    : ($wrongProductReasonByEvent[$sensorEventId] ?? "Produto fora do romaneio");
                $sackReference = "passagem não contabilizada";
                $caption = "{$captionType} · {$sackReference} · {$capturedAt}";
            } elseif ($image["reason"] === "EXCESSO" && $sensorEventId > 0) {
                $sackReference = $sackReferencesByEvent[$sensorEventId] ?? "passagem sem número";
                $caption = "Saca em excesso · {$sackReference} · {$capturedAt}";
            }
            $caption .= " · Evento {$sensorEventId} · Evidência {$requestId}";
            foreach ($sourceImages as $sourceImage) {
                $probe = new RelatorioAuditoriaPdf("", "");
                if (!$probe->inserirDadosDaImagemDaOcorrencia($sourceImage["bytes"], $sourceImage["mime"])) {
                    throw new RuntimeException("Imagem corrompida ou incompatível com o relatório.");
                }
                $sourceHash = hash("sha256", $sourceImage["bytes"]);
                if ($expectedImageHash !== "" && preg_match('/\A[a-f0-9]{64}\z/', $expectedImageHash) === 1
                    && !hash_equals($expectedImageHash, $sourceHash)) {
                    throw new RuntimeException("A evidência recuperada não corresponde ao hash da captura.");
                }
                $evidenceImages[] = [
                    "bytes" => $sourceImage["bytes"],
                    "mime" => $sourceImage["mime"],
                    "caption" => $caption,
                    "image_id" => $imageId > 0 ? $imageId : null,
                    "request_id" => $requestId > 0 ? $requestId : null,
                    "sha256" => $sourceHash,
                    "carregamento_id" => (int) $image["carregamento_id"],
                    "reading_id" => $image["reading_id"] ?? null,
                    "sensor_event_uuid" => $image["sensor_event_uuid"] ?? null,
                    "reason" => (string) $image["reason"],
                    "captured_at" => (string) $image["captured_at"],
                    "sensor_event_id" => $sensorEventId > 0 ? $sensorEventId : null,
                ];
            }
        } catch (RuntimeException $exception) {
            registrar_log_erro($exception, "relatorio_evidencia_indisponivel");
            $evidenceAttachmentFailure ??= $exception;
            $evidenceWarnings[] = "Evento " . ($image["sensor_event_id"] ?? "não confirmado")
                . " · Evidência " . ($image["request_id"] ?? "não confirmada")
                . ": foto indisponível ou inválida; não anexada. " . $exception->getMessage();
        }
    }
}
if ($evidenceAttachmentFailure instanceof RuntimeException) {
    $reportFailure(new RuntimeException(
        "Uma foto Keyence recebida não pôde ser validada e anexada; o relatório anterior foi preservado.",
        0,
        $evidenceAttachmentFailure,
    ));
}

$attachedEvents = [];
foreach ($evidenceImages as $image) {
    $attachedEvents[$image["reason"] . ":" . $image["sensor_event_id"]] = true;
}
$operationSummary["no_read_images"] = 0;
$operationSummary["wrong_product_images"] = 0;
foreach (["SEM_LEITURA" => &$noReadRows, "PRODUTO_INCORRETO" => &$wrongProductRows] as $reason => &$rows) {
    foreach ($rows as &$row) {
        $row["has_image"] = isset($attachedEvents[$reason . ":" . ($row["sensor_event_id"] ?? 0)]);
        if ($row["has_image"]) {
            $operationSummary[$reason === "SEM_LEITURA" ? "no_read_images" : "wrong_product_images"]++;
        } else {
            $evidenceWarnings[] = $row["sack_reference"] . " · Evento " . ($row["sensor_event_id"] ?? "não vinculado")
                . " · " . $reason . ": foto não recebida ou indisponível nesta emissão.";
        }
    }
    unset($row);
}
unset($rows);
$excessImagesCount = count(array_filter($evidenceImages, static fn(array $image): bool => $image["reason"] === "EXCESSO"));
$wrongProductImagesCount = (int) $operationSummary["wrong_product_images"];

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
if ($unresolvedPassageCount > 0) {
    $report->escreverParagrafo("{$unresolvedPassageCount} passagem(ns) com leitura ainda em processamento. Valores disponíveis nesta emissão.");
}
$resumoOp = [
    "Sacas detectadas pelo sensor M2052" => $operationSummary["passages"],
    "Leituras válidas" => $operationSummary["valid_readings"],
    "Sacas retornadas" => $operationSummary["returned_bags"],
    "Falhas sem leitura" => $operationSummary["no_reads"],
    "Divergências de produto/código" => $operationSummary["wrong_products"],
];
// Exibe "Sacas não identificadas" somente quando há SEM_LEITURA com product_id
// NULL — situação exclusiva de romaneios com múltiplos produtos. Em romaneios
// de produto único todas as SEM_LEITURA já carregam o product_id correto.
if (($operationSummary["no_reads_sem_produto"] ?? 0) > 0) {
    $resumoOp["Sacas não identificadas"] = $operationSummary["no_reads_sem_produto"];
}
$report->escreverCartoesResumo($resumoOp);
$sensorPassages = (int) ($operationSummary["passages"] ?? 0);
$scannerReadings = (int) ($operationSummary["scanner_readings"] ?? 0);
$excessImages = (int) ($operationSummary["excess_images"] ?? 0);
$excesses = (int) ($operationSummary["excesses"] ?? 0);
$report->escreverParagrafo(
    "Sensor: {$sensorPassages} passagem(ns) detectada(s) · " .
    "Scanner: {$scannerReadings} leitura(s) com código na carga · " .
    "Excesso com foto: {$excessImages} de {$excesses} passagem(ns).",
);
$report->escreverTitulo("Falhas de leitura do código de barras");
if ($noReadRows === []) {
    $report->escreverParagrafo("Nenhuma falha sem leitura foi registrada nesta operação.");
} else {
    $report->escreverParagrafo("Cada falha corresponde a uma passagem de saco registrada pelo sensor. Passagens ocorridas durante a pausa aparecem como não contabilizadas. Em romaneios com vários produtos, a identidade de uma saca sem código é registrada como \"Não identificado\".");
    $report->escreverTabela(
        ["Data/hora", "Produto", "Código recebido", "Passagem e tratamento"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["read_at"]),
                $row["product_name"] ?? "Não identificado",
                $row["barcode"] ?? "—",
                $row["sack_reference"] . (!empty($row["return_reason"]) ? " · Retirada: " . $row["return_reason"] : "")
                    . (array_key_exists("has_image", $row) && empty($row["has_image"])
                        ? " · O leitor não registrou falha nesta saca: o código chegou atrasado, se perdeu ou foi para outra saca"
                        : ""),
            ],
            $noReadRows,
        ),
        [90, 140, 110, 175],
    );
}
if (($unmatchedReaderRows ?? []) !== []) {
    $motivosSemPassagem = [
        "SEM_PASSAGEM" => "Sem passagem do sensor correspondente",
        "ESTEIRA_PARADA" => "Capturado com a esteira parada",
        "DESCARTADA_TELA" => "Descartado pela tela (sem foco ou fora da operação)",
    ];
    $report->escreverTitulo("Leituras do leitor sem passagem");
    $report->escreverParagrafo("Resultados que o leitor enviou e que não foram ligados a nenhuma saca registrada pelo sensor. Não entram na quantidade carregada nem na meta; servem para conferir sacas que passaram sem passagem registrada ou códigos que chegaram fora de hora.");
    $report->escreverTabela(
        ["Data/hora", "Produto", "Código recebido", "Situação"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["captured_at"]),
                (int) $row["no_read"] === 1 ? "Falha de leitura (ERROR)" : ($row["product_name"] ?? "Código sem cadastro"),
                $row["barcode"] ?? "—",
                $motivosSemPassagem[$row["motivo"]] ?? $row["motivo"],
            ],
            $unmatchedReaderRows ?? [],
        ),
        [90, 140, 110, 175],
    );
}
if ($wrongProductRows !== []) {
    $report->escreverTitulo("Divergências de produto ou código");
    $report->escreverParagrafo("Cada linha representa um produto reconhecido fora do romaneio, um produto lido depois de completar a quantidade prevista ou um código válido sem cadastro no catálogo. Essas sacas são retiradas e não entram na quantidade carregada, na meta do romaneio nem nos totais de leitura; as evidências disponíveis são apresentadas ao final.");
    $report->escreverTabela(
        ["Data/hora", "Produto reconhecido", "Código lido", "Passagem"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["read_at"]),
                $row["product_name"] ?? "Código válido não cadastrado",
                $row["barcode"] ?? "—",
                $row["sack_reference"],
            ],
            $wrongProductRows,
        ),
        [100, 135, 130, 150],
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
        "PRODUTO_INCORRETO" => "Produto incorreto",
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
    $embeddedImages = [];
    foreach ($evidenceImages as $image) {
        if (!$report->inserirDadosDaImagemDaOcorrencia(
            $image["bytes"],
            $image["mime"],
            $image["caption"],
            true,
        )) {
            $evidenceWarnings[] = $image["caption"] . ": imagem não incorporada nesta emissão.";
            $reportFailure(new RuntimeException("Uma imagem validada não pôde ser incorporada ao relatório."));
        } else {
            $embeddedImages[] = $image;
        }
    }
    $evidenceImages = $embeddedImages;
}
// R11: falhas de foto ficam somente no log; sem aviso ou espaço reservado no PDF.
foreach (array_unique($evidenceWarnings) as $warning) {
    registrar_log_erro(new RuntimeException($warning), "relatorio_pendencia_evidencia");
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
        "evidence_count" => count(array_filter($evidenceImages, static fn(array $image): bool => $image["reason"] === "SEM_LEITURA")),
        "excess_count" => $excessEvidenceCount,
        "excess_evidence_count" => count(array_filter($evidenceImages, static fn(array $image): bool => $image["reason"] === "EXCESSO")),
        "wrong_product_count" => $wrongProductCount,
        "wrong_product_evidence_count" => count(array_filter($evidenceImages, static fn(array $image): bool => $image["reason"] === "PRODUTO_INCORRETO")),
        "pending_passage_count" => $unresolvedPassageCount,
        "evidence_identity_sha256" => ConferidorEvidenciasRelatorio::assinaturaSolicitacoes($capturedRequests ?? []),
        "source_images" => array_map(
            static fn(array $image): array => [
                "image_id" => $image["image_id"],
                "request_id" => $image["request_id"],
                "sha256" => $image["sha256"],
                "carregamento_id" => $image["carregamento_id"],
                "reading_id" => $image["reading_id"],
                "sensor_event_uuid" => $image["sensor_event_uuid"],
                "reason" => $image["reason"],
                "captured_at" => $image["captured_at"],
                "sensor_event_id" => $image["sensor_event_id"],
            ],
            $evidenceImages,
        ),
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
$reportPublishFailed = false;
try {
    $reportPublisher->enfileirar((int) $user["company_id"], (int) $romaneioId, $reportRelativePath);
} catch (Throwable $exception) {
    $reportPublishFailed = true;
    registrar_log_erro($exception, "relatorio_auditoria_sync");
    header("X-Trace-Report-Sync: pending");
}
if (is_array($internalReport) && $reportPublishFailed) {
    // O worker tenta de novo: sem fila de publicação o central não recebe as fotos.
    $respondJson(["error" => "PDF salvo, mas não foi possível enfileirar a publicação no central."], 503);
}
$finishInternalReport([
    "romaneio_id" => (int) $romaneioId,
    "report" => $reportRelativePath,
    "evidence_count" => count($evidenceImages),
]);
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
