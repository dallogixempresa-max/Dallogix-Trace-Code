import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const endpoint = await readFile(
  new URL("../servidor/api/operacoes/leituras.php", import.meta.url),
  "utf8",
);
const imageEndpoint = await readFile(
  new URL("../servidor/api/keyence_worker.php", import.meta.url),
  "utf8",
);
const reportEndpoint = await readFile(
  new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url),
  "utf8",
);
const reportReceiver = await readFile(
  new URL("../servidor/api/sincronizacao/receber_relatorio_auditoria.php", import.meta.url),
  "utf8",
);

test("código sem correspondência fica auditado como não identificado e pede foto", () => {
  assert.match(endpoint, /\$noReadEvidenceRequired = \$classification\["no_read_evidence_required"\]/);
  assert.match(endpoint, /"barcode_unmatched" => \$barcodeUnmatched/);
  assert.match(endpoint, /if \(\$noReadEvidenceRequired\) \{/);
  assert.match(
    endpoint,
    /if \(\$sensorEventId && \(\$noReadEvidenceRequired \|\| \$result === "PRODUTO_INCORRETO"\)\)/,
  );
  assert.match(endpoint, /\$evidenceReason = \$result === "PRODUTO_INCORRETO" \? "PRODUTO_INCORRETO" : "SEM_LEITURA"/);
  assert.match(endpoint, /\? "Saco detectado pelo sensor com código sem correspondência no catálogo\."/);
});

test("produto reconhecido fora do romaneio mantém ocorrência e parada apropriada", () => {
  assert.match(endpoint, /\$wrongProductDetected = \$classification\["wrong_product_detected"\]/);
  assert.match(
    endpoint,
    /if \(\$wrongProductDetected\) \{[\s\S]*?if \(\$result === "PRODUTO_INCORRETO"\) \{/,
  );
  assert.match(endpoint, /solicitarParadaPorProdutoIncorreto/);
  assert.match(endpoint, /solicitarParadaPorExcesso/);
  assert.match(imageEndpoint, /"PRODUTO_INCORRETO" => \["PRODUTO_INCORRETO"\]/);
  assert.match(imageEndpoint, /r\.reason IN \('SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO'\)/);
  assert.match(reportEndpoint, /l\.result = 'PRODUTO_INCORRETO'[\s\S]*?r\.status <> 'CAPTURADA'/);
  assert.match(reportEndpoint, /Produto fora do romaneio · \{\$sackReference\} · \{\$capturedAt\}/);
  assert.match(reportEndpoint, /"version" => 3/);
  assert.match(reportEndpoint, /"wrong_product_count" => \$wrongProductCount/);
  assert.match(reportReceiver, /HTTP_X_TRACE_REPORT_WRONG_PRODUCT_COUNT/);
  assert.match(reportReceiver, /"wrong_product_evidence_count" => \(int\) \$wrongProductEvidenceCount/);
});

test("só produto previsto ou sem leitura ocupa a meta; produto incorreto fica fora", () => {
  assert.match(
    endpoint,
    /\$countsTowardPlan = in_array\(\$result, \["VALIDO", "SEM_LEITURA"\], true\)/,
  );
  assert.match(
    endpoint,
    /if \(\(\$physicalExcess \|\| in_array\(\$result, \["PRODUTO_INCORRETO", "EXCESSO"\], true\)\) && \$sensorEventId\) \{[\s\S]*?ServicoPausasCarregamento::reclassificarPassagens/,
  );
});
