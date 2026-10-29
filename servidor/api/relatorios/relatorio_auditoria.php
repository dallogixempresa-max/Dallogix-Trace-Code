<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../operacoes/temporizacao_leitor.php";
require_once __DIR__ . "/../../src/Aplicacao/RelatorioAuditoriaPdf.php";
require_once __DIR__ . "/../../../scripts/image_storage_path.php";

use App\Aplicacao\RelatorioAuditoriaPdf;

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
if ($loadIds !== []) {
    expirar_solicitacoes_imagem_keyence($pdo, null, $loadIds);
    $pendingCaptures = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia
         WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ")
           AND status IN ('PENDENTE', 'CAPTURANDO')",
    );
    $pendingCaptures->execute($loadIds);
    if ((int) $pendingCaptures->fetchColumn() > 0) {
        $respondJson(["error" => "Aguarde o envio das imagens de erro do Keyence antes de gerar o PDF."], 409);
    }
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
if ($savedReport !== null) {
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
$formatDateTime = static function (?string $value): string {
    if (!$value) {
        return "—";
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date("d/m/Y H:i", $timestamp);
};
$equipmentLabels = array_values(array_unique(array_map(
    static fn(array $row): string => trim((string) ($row["equipment_name"] ?: $row["equipment_code"] ?: "Dala")),
    $loadRows,
)));
$firstLoad = $loadRows[0] ?? [];
$lastLoad = $loadRows !== [] ? $loadRows[array_key_last($loadRows)] : [];

$items = $pdo->prepare("SELECT p.name, COALESCE(MIN(pc.barcode), p.code, '—') AS barcode, SUM(ri.planned_quantity) AS planned_quantity,
    COALESCE((SELECT COUNT(*) FROM leituras l JOIN carregamentos c ON c.id = l.carregamento_id
      WHERE c.romaneio_id = ri.romaneio_id AND l.product_id = ri.product_id
        AND l.result = 'VALIDO'), 0) AS moved_quantity
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
$sackReferencesByEvent = [];
$loadOrderById = [];
$operationSummary = [
    "passages" => 0,
    "valid_readings" => 0,
    "no_reads" => 0,
    "no_read_images" => 0,
];
foreach ($loadIds as $index => $loadingId) {
    $loadOrderById[$loadingId] = $index + 1;
}
if ($loadIds !== []) {
    $passageQuery = $pdo->prepare(
        "SELECT id, carregamento_id, device_detected_at,
                CAST(ROUND(UNIX_TIMESTAMP(detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
         FROM eventos_sensor WHERE carregamento_id IN ({$placeholders})",
    );
    $passageQuery->execute($loadIds);
    $passages = array_map(static function (array $passage): array {
        $passage["event_at_epoch_ms"] = $passage["device_detected_at"] !== null
            ? trace_sql_datetime_to_epoch_ms((string) $passage["device_detected_at"])
            : (int) $passage["server_detected_at_epoch_ms"];
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
        $passageNumbers[$loadingId] = ($passageNumbers[$loadingId] ?? 0) + 1;
        $operationSummary["passages"]++;
        $sackReferencesByEvent[(int) $passage["id"]] =
            "Carga " . ($loadOrderById[$loadingId] ?? "—") . " · saco " . $passageNumbers[$loadingId];
    }

    $readingCounts = $pdo->prepare(
        "SELECT result, COUNT(*) AS quantity FROM leituras
         WHERE carregamento_id IN ({$placeholders}) GROUP BY result",
    );
    $readingCounts->execute($loadIds);
    foreach ($readingCounts->fetchAll(PDO::FETCH_ASSOC) as $readingCount) {
        if ($readingCount["result"] === "VALIDO") {
            $operationSummary["valid_readings"] = (int) $readingCount["quantity"];
        }
    }

    $noReadQuery = $pdo->prepare(
        "SELECT l.id, l.sensor_event_id, l.carregamento_id, l.read_at,
                r.status AS evidence_status,
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
         LEFT JOIN solicitacoes_imagens_evidencia r
           ON r.sensor_event_id = l.sensor_event_id
          AND r.carregamento_id = l.carregamento_id AND r.reason = 'SEM_LEITURA'
         WHERE l.carregamento_id IN ({$placeholders}) AND l.result = 'SEM_LEITURA'
         ORDER BY l.read_at, l.id",
    );
    $noReadQuery->execute($loadIds);
    $noReadRows = $noReadQuery->fetchAll(PDO::FETCH_ASSOC);
    $operationSummary["no_reads"] = count($noReadRows);
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
    }
    unset($noRead);

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
$report->escreverCartoesResumo([
    "Sacas detectadas pelo sensor M2052" => $operationSummary["passages"],
    "Leituras válidas" => $operationSummary["valid_readings"],
    "Falhas sem leitura" => $operationSummary["no_reads"],
    "Fotos de falha anexadas" => $operationSummary["no_read_images"],
]);
$report->escreverTitulo("Falhas de leitura do código de barras");
if ($noReadRows === []) {
    $report->escreverParagrafo("Nenhuma falha sem leitura foi registrada nesta operação.");
} else {
    $report->escreverParagrafo("Cada falha corresponde a uma passagem de saco registrada pelo sensor. A esteira continua operando; a foto é anexada quando recebida do Keyence.");
    $report->escreverTabela(
        ["Data/hora", "Passagem", "Evidência da imagem"],
        array_map(
            static fn(array $row): array => [
                $formatDateTime($row["read_at"]),
                $row["sack_reference"],
                $row["evidence_label"],
            ],
            $noReadRows,
        ),
        [145, 175, 195],
    );
}
if ($romaneio["status"] === "CANCELADO") {
    $report->escreverParagrafo("Valores registrados até o cancelamento.");
}
$report->escreverTitulo("Conferência dos itens");
$report->escreverTabela(
    ["Produto", "Código", "Previsto", "Movido", "Status"],
    array_map(static function (array $item): array {
        $planned = (int) $item["planned_quantity"];
        $moved = (int) $item["moved_quantity"];
        return [
            $item["name"],
            $item["barcode"],
            $planned,
            $moved,
            $moved === $planned
                ? "Concluído"
                : ($moved < $planned
                    ? "Parcial"
                    : "Excesso"),
        ];
    }, $itemRows),
    [205, 105, 65, 65, 75],
);
$divergences = array_values(
    array_filter(
        $itemRows,
        static fn(array $item): bool => (int) $item["planned_quantity"] !==
            (int) $item["moved_quantity"],
    ),
);
if ($divergences === []) {
    $report->escreverParagrafo("Sem divergências.");
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
try {
    trace_cleanup_report_evidence($pdo, $loadIds, (int) $user["company_id"], $storageRoot);
} catch (Throwable $exception) {
    $reportFailure($exception);
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
