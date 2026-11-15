<?php

declare(strict_types=1);

require_once __DIR__ . "/../servidor/src/Aplicacao/RelatorioAuditoriaPdf.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/FontesEvidenciasRelatorio.php";
require_once __DIR__ . "/../scripts/image_prune_policy.php";

use App\Aplicacao\FontesEvidenciasRelatorio;
use App\Aplicacao\RelatorioAuditoriaPdf;

function assertion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pngChunk(string $type, string $data): string
{
    $payload = $type . $data;
    return pack("N", strlen($data)) . $payload . pack("N", crc32($payload));
}

$png = "\x89PNG\r\n\x1a\n"
    . pngChunk("IHDR", pack("NNC5", 1, 1, 8, 6, 0, 0, 0))
    . pngChunk("IDAT", gzcompress("\x00\xff\x00\x00\xff"))
    . pngChunk("IEND", "");

$report = new RelatorioAuditoriaPdf("Dallogix", "Auditoria de teste");
$report->escreverTitulo("Evidências");
assertion(
    $report->inserirDadosDaImagemDaOcorrencia($png, "image/png", "Falha de leitura · Saca 1", true),
    "O PDF de teste precisa incorporar a imagem original.",
);
$pdf = $report->obterArquivoPdf();
$sameReport = new RelatorioAuditoriaPdf("Dallogix", "Auditoria de teste");
$sameReport->escreverTitulo("Evidências");
assertion(
    $sameReport->inserirDadosDaImagemDaOcorrencia($png, "image/png", "Falha de leitura · Saca 1", true),
    "A repetição precisa incorporar a mesma imagem original.",
);
$pdfAgain = $sameReport->obterArquivoPdf();
assertion(
    hash_equals(hash("sha256", $pdf), hash("sha256", $pdfAgain)),
    "Sem mudança de conteúdo, a geração do PDF precisa manter o mesmo SHA-256.",
);
$extracted = RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($pdf);
assertion(count($extracted) === 1, "O PDF precisa preservar uma imagem original recuperável.");
assertion(hash_equals(hash("sha256", $png), hash("sha256", $extracted[0]["bytes"])), "O PDF precisa preservar os bytes originais.");

$sourceHash = hash("sha256", $png);
$sourceCandidate = [
    "image_id" => 45,
    "request_id" => 17,
    "image_sha256" => $sourceHash,
    "path" => "company_1/equipment_1/keyence-17-{$sourceHash}.png",
];
$sourceManifest = ["source_images" => [[
    "image_id" => 45,
    "request_id" => 17,
    "sha256" => $sourceHash,
]]];
assertion(
    trace_prune_candidate_is_in_report($sourceManifest, $sourceCandidate, $sourceHash),
    "A retenção precisa aceitar os bytes exatos vinculados ao PDF sincronizado.",
);
assertion(
    !trace_prune_candidate_is_in_report($sourceManifest, $sourceCandidate, str_repeat("b", 64)),
    "A retenção não pode apagar um arquivo alterado depois da geração do PDF.",
);
assertion(
    !trace_prune_candidate_is_in_report($sourceManifest, ["image_id" => 99, "request_id" => 99], $sourceHash),
    "A retenção não pode apagar um arquivo sem vínculo com o PDF.",
);

$manifest = [
    "version" => 3,
    "pdf_sha256" => hash("sha256", $pdf),
    "no_read_count" => 1,
    "evidence_count" => 1,
    "excess_count" => 0,
    "excess_evidence_count" => 0,
    "wrong_product_count" => 0,
    "wrong_product_evidence_count" => 0,
    "source_images" => [[
        "image_id" => 45,
        "request_id" => 17,
        "sha256" => $sourceHash,
        "reason" => "SEM_LEITURA",
        "captured_at" => "2026-11-14 11:00:00.000",
        "sensor_event_id" => 201,
    ]],
];
$indexed = FontesEvidenciasRelatorio::indexar($pdf, $manifest, [], strtotime("2026-11-14 12:00:00 UTC"));
assertion(isset($indexed["request:17"][0]), "A foto recuperada precisa ser vinculada à solicitação preservada.");
assertion(isset($indexed["image:45"][0]), "A foto recuperada precisa ser vinculada à linha de imagem preservada.");

$legacyManifest = $manifest;
unset($legacyManifest["source_images"]);
$legacyRequests = [
    [
        "request_id" => 17,
        "reason" => "SEM_LEITURA",
        "captured_at" => "2026-11-14 11:00:00.000",
        "image_sha256" => $sourceHash,
    ],
    [
        "request_id" => 18,
        "reason" => "SEM_LEITURA",
        "captured_at" => "2026-11-15 11:00:00.000",
        "image_sha256" => str_repeat("a", 64),
    ],
];
$legacyIndexed = FontesEvidenciasRelatorio::indexar(
    $pdf,
    $legacyManifest,
    $legacyRequests,
    strtotime("2026-11-14 12:00:00 UTC"),
);
assertion(isset($legacyIndexed["request:17"][0]), "A versão anterior precisa recuperar a foto pela ordem e hash.");
assertion(!isset($legacyIndexed["request:18"]), "Uma captura posterior não pode ser anexada ao PDF antigo.");

$ambiguousRequests = $legacyRequests;
$ambiguousRequests[0]["image_sha256"] = str_repeat("b", 64);
$ambiguousIndexed = FontesEvidenciasRelatorio::indexar(
    $pdf,
    $legacyManifest,
    $ambiguousRequests,
    strtotime("2026-11-14 12:00:00 UTC"),
);
assertion($ambiguousIndexed === [], "Hash divergente deve deixar a recuperação sem vínculo.");

echo "Report evidence retention and recovery checks passed.\n";
