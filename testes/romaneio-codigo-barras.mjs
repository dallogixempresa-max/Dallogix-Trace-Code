import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const manifestScreen = await readFile(new URL("../interface/telas/romaneio/romaneio.js", import.meta.url), "utf8");
const manifestApi = await readFile(new URL("../servidor/api/romaneios/romaneios.php", import.meta.url), "utf8");
const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");

test("a coluna Código de Barras mostra o código de barras, não o SKU interno", () => {
  // A tela recebia barcode no payload e renderizava item.code, exibindo o código
  // interno do produto sob um cabeçalho que promete o código de barras.
  assert.match(manifestScreen, /<th>Código de Barras<\/th>/);
  assert.match(manifestScreen, /esc\(item\.barcode \|\| item\.code \|\| "—"\)/);
  assert.doesNotMatch(manifestScreen, /data-label="Código"><code>\$\{esc\(item\.code \|\| "—"\)\}/);
});

test("tela e auditoria escolhem o MESMO código quando o produto tem vários", () => {
  // Um produto pode ter N linhas em codigos_produtos. Se a tela e o PDF usarem
  // critérios de desempate diferentes, o mesmo item aparece com códigos distintos
  // nos dois lugares. Não basta cada lado resolver "algum" barcode: o critério
  // precisa ser literalmente o mesmo nos dois arquivos.
  const screenPick = manifestApi.match(/SELECT (MIN\(pc\.barcode\)|pc\.barcode)[\s\S]{0,200}?(?=\) *, *""\)|AS barcode)/);
  assert.ok(screenPick, "não encontrei a seleção de barcode em romaneios.php");
  assert.match(manifestApi, /SELECT MIN\(pc\.barcode\) FROM codigos_produtos pc/);
  assert.match(reportEndpoint, /COALESCE\(MIN\(pc\.barcode\), p\.code, '—'\) AS barcode/);

  // Desempate por ordem de cadastro diverge de MIN() e não pode voltar.
  assert.doesNotMatch(manifestApi, /FROM codigos_produtos pc[\s\S]{0,160}?ORDER BY pc\.id LIMIT 1/);
});

test("ambos caem para o código interno quando não há código de barras", () => {
  // A API devolve "" na ausência de barcode e a tela encadeia para item.code,
  // equivalente ao fallback p.code do PDF.
  assert.match(manifestApi, /\), ""\) AS barcode/);
  assert.match(manifestScreen, /item\.barcode \|\| item\.code/);
  assert.match(reportEndpoint, /MIN\(pc\.barcode\), p\.code/);
});
