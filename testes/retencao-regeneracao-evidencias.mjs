import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(path, import.meta.url), "utf8");
const report = await read("../servidor/api/relatorios/relatorio_auditoria.php");
const retention = await read("../scripts/prune_images.php");
const operationalRetention = await read("../scripts/prune_operational_data.php");
const pdfSources = await read("../servidor/src/Aplicacao/FontesEvidenciasRelatorio.php");
const prunePolicy = await read("../scripts/image_prune_policy.php");

test("gerar ou baixar o PDF não remove as fotos nem os vínculos de evidência", () => {
  assert.doesNotMatch(report, /trace_cleanup_report_evidence/);
  assert.doesNotMatch(report, /DELETE i FROM imagens/);
  assert.doesNotMatch(report, /evidence_pdf_path = NULL/);
  assert.match(report, /\"source_images\" => array_map/);
  assert.match(report, /inserirDadosDaImagemDaOcorrencia\([\s\S]*?\$image\[\"caption\"\],[\s\n]*true/);
});

test("retenção só remove evidência depois do PDF correspondente ser confirmado no Central", () => {
  assert.match(retention, /status = 'ENVIADO'/);
  assert.match(retention, /RELATORIO_AUDITORIA_PUBLICADO/);
  assert.match(retention, /\$\.data\.sha256/);
  assert.match(retention, /trace_prune_candidate_is_in_report/);
  assert.match(retention, /hash_file\("sha256", \$absolutePath\)/);
  assert.match(prunePolicy, /hash_equals\(\$sourceHash, \$currentFileHash\)/);
  assert.doesNotMatch(retention, /DELETE FROM imagens/);
  assert.doesNotMatch(retention, /UPDATE solicitacoes_imagens_evidencia/);
  assert.match(
    operationalRetention,
    /COALESCE\(JSON_UNQUOTE\(JSON_EXTRACT\(payload, '\$\.action'\)\), ''\) <> 'RELATORIO_AUDITORIA_PUBLICADO'/,
  );
});

test("PDF anterior recupera a foto pelo identificador e pelo SHA-256", () => {
  assert.match(report, /FontesEvidenciasRelatorio::indexar/);
  assert.match(report, /hash_equals\(\$expectedImageHash, \$sourceImage\[\"sha256\"\]\)/);
  assert.match(report, /\"request_id\" => \$requestId/);
  assert.match(pdfSources, /extrairImagensOriginaisDoPdf/);
  assert.match(pdfSources, /indexarManifestoLegado/);
  assert.match(pdfSources, /\$countsByReason !== \$expectedByReason/);
});

test("evidência Keyence capturada continua válida para regenerar o relatório", () => {
  assert.match(report, /r\.status = 'CAPTURADA' AND r\.image_sha256 REGEXP/);
  assert.match(report, /r\.status = 'CAPTURADA'[\s\S]*?r\.reason IN \('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO'\)/);
  assert.match(report, /Foto não disponível nesta emissão/);
  assert.match(report, /capturedRequestsQuery/);
});

test("cache do relatório valida totais sem exigir uma foto por incidente", () => {
  assert.match(report, /\$manifestData\["evidence_count"\][\s\S]*?<= \$noReadCount/);
  assert.match(report, /\$manifestData\["excess_evidence_count"\][\s\S]*?<= \$excessEvidenceCount/);
  assert.match(report, /\$manifestData\["wrong_product_evidence_count"\][\s\S]*?<= \$wrongProductCount/);
});
