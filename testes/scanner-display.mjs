import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const operation = await readFile(new URL("../interface/telas/operacao/operacao.js", import.meta.url), "utf8");
const liveView = await readFile(new URL("../interface/js/controladores/estado-visualizacao.js", import.meta.url), "utf8");
const operationalForms = await readFile(new URL("../interface/js/controladores/formularios-operacionais.js", import.meta.url), "utf8");
const activeLoading = await readFile(new URL("../interface/js/servicos/ServicoCarregamentoAtivo.js", import.meta.url), "utf8");
const monitoringRepository = await readFile(new URL("../servidor/src/Persistencia/RepositorioMonitoramento.php", import.meta.url), "utf8");
const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");

test("visor do leitor não cria campo editável nem caminho de envio manual", () => {
  assert.match(operation, /<output id="automatic-scanner-barcode"[^>]*data-live="scanner-value"/);
  assert.doesNotMatch(operation, /<input[^>]+id="automatic-scanner-barcode"/);
  assert.doesNotMatch(operation, /manual-operation-reading-form/);
  assert.doesNotMatch(operationalForms, /registrarLeituraDaOperacao|registerScannerReading/);
  assert.doesNotMatch(application, /focusOperationScanner|updateOperationScannerFocus/);
});

test("última leitura do serviço Keyence alimenta o visor em atualizações ao vivo", () => {
  assert.match(monitoringRepository, /ROW_NUMBER\(\) OVER\s*\(\s*PARTITION BY l\.carregamento_id\s*ORDER BY l\.read_at DESC, l\.id DESC/s);
  assert.match(monitoringRepository, /lr\.barcode AS last_scanner_barcode/);
  assert.match(monitoringRepository, /lr\.result AS last_scanner_result/);
  assert.match(activeLoading, /latestScannerReading = loading\.last_scanner_read_at/);
  assert.match(activeLoading, /Falha na leitura \(sem código\)/);
  assert.match(liveView, /"scanner-value": store\.state\.latestScannerReading\?\.value/);
});
