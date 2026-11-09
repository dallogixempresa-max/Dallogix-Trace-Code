import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const endpoint = await readFile(
  new URL("../servidor/api/operacoes/leituras.php", import.meta.url),
  "utf8",
);

test("código sem correspondência fica auditado como não identificado e pede foto", () => {
  assert.match(endpoint, /\$noReadEvidenceRequired = \$classification\["no_read_evidence_required"\]/);
  assert.match(endpoint, /"barcode_unmatched" => \$barcodeUnmatched/);
  assert.match(endpoint, /if \(\$noReadEvidenceRequired\) \{/);
  assert.match(
    endpoint,
    /if \(\$sensorEventId && \(\$noReadEvidenceRequired \|\| \$result === "EXCESSO"\)\)/,
  );
  assert.match(endpoint, /\? "Saco detectado pelo sensor com código sem correspondência no catálogo\."/);
});

test("produto reconhecido fora do romaneio mantém a parada de segurança", () => {
  assert.match(endpoint, /if \(\$result === "PRODUTO_INCORRETO"\)/);
  assert.match(endpoint, /solicitarParadaPorProdutoIncorreto/);
});
