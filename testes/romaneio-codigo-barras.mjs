import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const manifestScreen = await readFile(new URL("../interface/telas/romaneio/romaneio.js", import.meta.url), "utf8");
const manifestApi = await readFile(new URL("../servidor/api/romaneios/romaneios.php", import.meta.url), "utf8");
const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");

test("a API do romaneio entrega o código de barras do produto", () => {
  assert.match(manifestApi, /SELECT pc\.barcode FROM codigos_produtos pc/);
  assert.match(manifestApi, /AS barcode/);
});

test("a coluna Código de Barras mostra o código de barras, não o SKU interno", () => {
  // A tela recebia barcode no payload e renderizava item.code, exibindo o código
  // interno do produto sob um cabeçalho que promete o código de barras.
  assert.match(manifestScreen, /<th>Código de Barras<\/th>/);
  assert.match(manifestScreen, /esc\(item\.barcode \|\| item\.code \|\| "—"\)/);
  assert.doesNotMatch(manifestScreen, /data-label="Código"><code>\$\{esc\(item\.code \|\| "—"\)\}/);
});

test("tela e relatório de auditoria usam a mesma precedência de código", () => {
  // O PDF já resolvia barcode primeiro e caía para o código interno na ausência dele.
  assert.match(reportEndpoint, /COALESCE\(MIN\(pc\.barcode\), p\.code, '—'\) AS barcode/);
  assert.match(manifestScreen, /item\.barcode \|\| item\.code/);
});
