import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { ServicoLeiturasOperacionais } from "../interface/js/servicos/ServicoLeiturasOperacionais.js";

const operation = await readFile(new URL("../interface/telas/operacao/operacao.js", import.meta.url), "utf8");
const liveView = await readFile(new URL("../interface/js/controladores/estado-visualizacao.js", import.meta.url), "utf8");
const operationalForms = await readFile(new URL("../interface/js/controladores/formularios-operacionais.js", import.meta.url), "utf8");
const activeLoading = await readFile(new URL("../interface/js/servicos/ServicoCarregamentoAtivo.js", import.meta.url), "utf8");
const monitoringRepository = await readFile(new URL("../servidor/src/Persistencia/RepositorioMonitoramento.php", import.meta.url), "utf8");
const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const keyenceWorkerApi = await readFile(new URL("../servidor/api/keyence_worker.php", import.meta.url), "utf8");
const machineCommandService = await readFile(new URL("../servidor/src/Aplicacao/ServicoComandoClp.php", import.meta.url), "utf8");
const clpFlow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const desktopHost = await readFile(new URL("../desktop/DallogixTrace/Program.cs", import.meta.url), "utf8");
const operationalReadingService = await readFile(new URL("../interface/js/servicos/ServicoLeiturasOperacionais.js", import.meta.url), "utf8");

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

test("visor chama o dispositivo de leitor e início não exige comando de desligamento inexistente", () => {
  assert.match(operation, /Aguardando leitura…/);
  assert.match(operation, /recebido automaticamente do leitor/);
  assert.doesNotMatch(operation, /Keyence/i);
  assert.match(operation, /state\.reversalCommand === "REVERSAO_ATIVAR"/);
  assert.match(machineCommandService, /if \(\$reversalCommand === "REVERSAO_ATIVAR"\)/);
  assert.doesNotMatch(machineCommandService, /\$reversalCommand !== "REVERSAO_DESATIVAR"/);
  const commandGate = clpFlow.find((node) => node.id === "command-safe-gate")?.func || "";
  assert.match(commandGate, /response\.reversal_command === 'REVERSAO_ATIVAR'/);
  assert.match(commandGate, /simulated\.reversal_active === true/);
});

test("leitor continua recebendo evento pendente durante finalização do carregamento", () => {
  assert.match(keyenceWorkerApi, /state IN \('CARREGANDO', 'FINALIZANDO'\)/);
});

test("AutoID Clipboard entrega cada atualização ao Trace e registra pelo sensor M2052", () => {
  assert.match(desktopHost, /GetClipboardSequenceNumber/);
  assert.match(desktopHost, /AutoID Keyboard Wedge/);
  assert.match(desktopHost, /type = "trace-scanner-clipboard"/);
  assert.match(application, /installScannerClipboardReceiver/);
  assert.match(application, /store\.registerScannerReading\(barcode,\s*Number\(message\.capturedAtEpochMs\)\s*\|\|\s*Date\.now\(\)\)/);
  assert.match(operationalReadingService, /X-Trace-Offline-ID/);
  assert.match(operationalReadingService, /Leitura aguardando o evento de passagem do sensor M2052\./);
  assert.doesNotMatch(operation, /<input[^>]+id="automatic-scanner-barcode"/);
});

test("leitura automática aguarda o pulso M2052 e atualiza o carregamento", async () => {
  const requests = [];
  const refreshedLoadings = [];
  const successfulResponse = {
    status: 201,
    ok: true,
    json: async () => ({ data: { id: 41, result: "VALIDO" } }),
  };
  const service = new ServicoLeiturasOperacionais({
    api: {
      fetch: async (url, options) => {
        requests.push({ url, options });
        if (requests.length === 1) {
          return {
            status: 409,
            ok: false,
            clone: () => ({ json: async () => ({ error: "Leitura aguardando o evento de passagem do sensor M2052." }) }),
          };
        }
        return successfulResponse;
      },
    },
    state: { loadingId: 7, operationalState: "CARREGANDO" },
    jsonHeaders: () => ({ "Content-Type": "application/json", "X-CSRF-Token": "csrf" }),
    jsonResponse: async (response) => response.json(),
    loadActiveLoading: async (loadingId) => { refreshedLoadings.push(loadingId); },
    createEventId: () => "d4a00000-0000-4000-8000-000000000041",
  });

  const scannerReceivedAtEpochMs = 1793314343923;
  const result = await service.registerScannerReading("7898538751531", scannerReceivedAtEpochMs);

  assert.equal(result.id, 41);
  assert.equal(requests.length, 2);
  assert.equal(requests[0].url, "/api/leituras.php");
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    carregamento_id: 7,
    barcode: "7898538751531",
    scanner_received_at_epoch_ms: scannerReceivedAtEpochMs,
  });
  assert.equal(requests[0].options.headers["X-Trace-Offline-ID"], "d4a00000-0000-4000-8000-000000000041");
  assert.equal(requests[1].options.headers["X-Trace-Offline-ID"], requests[0].options.headers["X-Trace-Offline-ID"]);
  assert.deepEqual(refreshedLoadings, [7]);
});
