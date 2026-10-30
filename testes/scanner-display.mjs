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
const equipmentGatewayApi = await readFile(new URL("../servidor/api/dalas/equipamentos_gateway.php", import.meta.url), "utf8");
const sensorEventsApi = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const finishLoadingApi = await readFile(new URL("../servidor/api/operacoes/encerrar_carregamento.php", import.meta.url), "utf8");
const loadingSummaryApi = await readFile(new URL("../servidor/api/operacoes/resumo_carregamento.php", import.meta.url), "utf8");
const observabilityService = await readFile(new URL("../interface/js/servicos/ServicoObservabilidade.js", import.meta.url), "utf8");
const summaryPageLoader = await readFile(new URL("../interface/js/controladores/carregador-paginas.js", import.meta.url), "utf8");
const summaryPage = await readFile(new URL("../interface/telas/resumo-final/resumo-final.js", import.meta.url), "utf8");
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
  assert.match(keyenceWorkerApi, /state IN \('CARREGANDO', 'FINALIZANDO', 'PAUSADO'\)/);
});

test("passagens em trânsito continuam vinculadas durante PAUSADO e falhas sem leitura são finalizadas", () => {
  assert.match(equipmentGatewayApi, /state IN \('CARREGANDO', 'FINALIZANDO', 'PAUSADO'\)/);
  assert.match(keyenceWorkerApi, /state IN \('CARREGANDO', 'FINALIZANDO', 'PAUSADO'\)/);
  assert.match(sensorEventsApi, /state IN \('CARREGANDO', 'FINALIZANDO', 'PAUSADO'\)/);
  assert.match(sensorEventsApi, /'SEM_LEITURA'/);
  assert.match(readingsEndpoint, /\$loading\["state"\] === "PAUSADO" && !\$sensorEventId/);
});

test("fila durável do M2052 é reprocessada após reinício e fechamento aguarda as confirmações", () => {
  const sensorResult = clpFlow.find((node) => node.id === "modbus-result");
  const enqueue = clpFlow.find((node) => node.id === "sensor-event-outbox-enqueue")?.func || "";
  const replay = clpFlow.find((node) => node.id === "sensor-event-outbox-replay")?.func || "";
  const retry = clpFlow.find((node) => node.id === "sensor-event-retry")?.func || "";
  const heartbeat = clpFlow.find((node) => node.id === "sensor-heartbeat-outbox")?.func || "";
  assert.equal(sensorResult?.wires?.[2]?.[0], "sensor-event-outbox-enqueue");
  assert.match(enqueue, /outbox\.enqueue\(event\)/);
  assert.match(replay, /outbox\.list\(\)/);
  assert.match(retry, /outbox\.acknowledge\(eventUuid\)/);
  assert.doesNotMatch(retry, /attempt >= 3/);
  assert.match(heartbeat, /sensor_event_outbox_pending = count/);
  assert.match(finishLoadingApi, /NOT EXISTS \(SELECT 1 FROM leituras l WHERE l\.sensor_event_id = s\.id\)/);
  assert.match(finishLoadingApi, /sensor_event_outbox_pending/);
  assert.match(loadingSummaryApi, /leituras_pendentes_sensor/);
  assert.match(observabilityService, /resumo_carregamento\.php\?carregamento_id=/);
  assert.match(summaryPageLoader, /\.then\(\(\) => store\.loadSummaryMonitoring\(\)\)/);
  assert.match(summaryPage, /sensorOutboxPending === 0/);
});

test("AutoID Clipboard entrega cada atualização ao Trace e registra pelo sensor M2052", () => {
  assert.match(desktopHost, /GetClipboardSequenceNumber/);
  assert.match(desktopHost, /AutoID Keyboard Wedge/);
  assert.match(desktopHost, /type = "trace-scanner-clipboard"/);
  assert.match(application, /installScannerClipboardReceiver/);
  assert.match(application, /store\.registerScannerReading\(barcode,\s*Number\(message\.capturedAtEpochMs\)\s*\|\|\s*Date\.now\(\)\)/);
  assert.match(application, /\["CARREGANDO", "FINALIZANDO", "PAUSADO"\]\.includes\(store\.state\.operationalState\)/);
  assert.match(operationalReadingService, /\["CARREGANDO", "FINALIZANDO", "PAUSADO"\]\.includes\(this\.state\.operationalState\)/);
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

test("leitura já vinculada ao M2052 ainda é registrada após ACK de parada", async () => {
  const requests = [];
  const service = new ServicoLeiturasOperacionais({
    api: {
      fetch: async (url, options) => {
        requests.push({ url, options });
        return { status: 201, ok: true, json: async () => ({ data: { id: 42, result: "VALIDO" } }) };
      },
    },
    state: { loadingId: 7, operationalState: "PAUSADO" },
    jsonHeaders: () => ({ "Content-Type": "application/json", "X-CSRF-Token": "csrf" }),
    jsonResponse: async (response) => response.json(),
    loadActiveLoading: async () => {},
    createEventId: () => "d4a00000-0000-4000-8000-000000000042",
  });

  const result = await service.registerScannerReading("7898538751531", 1793314343924);
  assert.equal(result.id, 42);
  assert.equal(requests.length, 1);
});
