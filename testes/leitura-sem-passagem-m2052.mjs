import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { aguardandoPassagemM2052, MENSAGEM_AGUARDANDO_PASSAGEM } from "../interface/js/funcoes/leituras-sem-passagem.js";
import { ErroApi } from "../interface/js/api/ClienteApi.js";
import { createHidScannerCapture, isScannerNoReadMarker } from "../interface/js/controladores/captura-leitor-hid.js";
import { ServicoLeiturasOperacionais } from "../interface/js/servicos/ServicoLeiturasOperacionais.js";

const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const activeLoading = await readFile(new URL("../interface/js/servicos/ServicoCarregamentoAtivo.js", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const serviceWorker = await readFile(new URL("../interface/service-worker.js", import.meta.url), "utf8");
const clpFlow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));

// Carregamento 138 (24/11/2026, bancada): o operador contava 44 Billy Dog e a
// tela mostrava 42; as duas leituras sem passagem eram as duas sacas que o
// gateway não registrou. O leitor dispara com o M2052 (PR #98: "7 códigos para
// 6 passagens"), então leitura sem passagem é saca perdida, não repetição.

test("o M2052 é consultado a cada 50 ms (pulso curto não cai entre consultas)", () => {
  const tick = clpFlow.find((node) => node.id === "sensor-poll-tick");
  assert.equal(tick.repeat, "0.05");
  assert.match(tick.name, /50 ms/);
  const result = clpFlow.find((node) => node.id === "modbus-result")?.func || "";
  assert.match(result, /poll_interval_ms: 50,/);
  // A consulta seguinte não começa enquanto a anterior está em andamento.
  const frame = clpFlow.find((node) => node.id === "modbus-frame")?.func || "";
  assert.match(frame, /if \(inFlightSince && Date\.now\(\) - inFlightSince < inFlightLimitMs\) return null;/);
  assert.match(frame, /flow\.set\(inFlightKey, Date\.now\(\)\);/);
  // Consulta presa é abandonada em 300 ms (o CLP responde em ~7 ms).
  const request = clpFlow.find((node) => node.id === "modbus-request")?.func || "";
  assert.match(request, /const timeoutMs = msg\.modbusPurpose === 'sensor' \? 300 : 5000;/);
});

test("uma marca de consulta em andamento esquecida expira e a leitura do sensor volta", () => {
  const frame = clpFlow.find((node) => node.id === "modbus-frame").func;
  const store = new Map();
  const flow = { get: (key) => store.get(key), set: (key, value) => store.set(key, value) };
  const env = { get: () => undefined };
  const msg = () => ({ modbusPurpose: "sensor", equipmentId: 1, modbusUnit: 1, modbusFunction: 1, modbusRegister: 2052, modbusHost: "x", modbusPort: 502 });
  const run = (realNow) => {
    const RealDate = Date;
    globalThis.Date = class extends RealDate { static now() { return realNow; } };
    try { return new Function("msg", "flow", "env", "Buffer", frame)(msg(), flow, env, Buffer); } finally { globalThis.Date = RealDate; }
  };
  store.set("modbus_sensor_inflight_1", true); // marca antiga (booleana) deixada por um deploy
  assert.ok(run(1_000_000), "marca booleana antiga não bloqueia");
  assert.equal(run(1_000_100), null, "consulta em andamento há 100 ms bloqueia a seguinte");
  assert.ok(run(1_000_900), "depois de 800 ms a marca expira");
});

test("o 409 de passagem ausente é reconhecido pela mensagem exata do servidor", () => {
  assert.ok(readingsEndpoint.includes(`"error" => "${MENSAGEM_AGUARDANDO_PASSAGEM}"`));
  assert.equal(aguardandoPassagemM2052(new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { status: 409 })), true);
  assert.equal(aguardandoPassagemM2052(new ErroApi("Leitura ambígua entre passagens do sensor; o código não foi associado.", { status: 409 })), false);
  assert.equal(aguardandoPassagemM2052(new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { status: 503 })), false);
});

test("a leitura espera até 3 s pela passagem atrasada (deadlock e reenvio do Node-RED)", async () => {
  const inicio = Date.now();
  let tentativas = 0;
  const service = new ServicoLeiturasOperacionais({
    api: { fetch: async () => {
      tentativas++;
      if (Date.now() - inicio < 2_200) {
        return { status: 409, clone: () => ({ json: async () => ({ error: MENSAGEM_AGUARDANDO_PASSAGEM }) }) };
      }
      return { status: 201, json: async () => ({ data: { id: 1, result: "VALIDO" } }) };
    } },
    state: { loadingId: 138, operationalState: "CARREGANDO" },
    jsonHeaders: () => ({}),
    jsonResponse: (response) => response.json(),
    loadActiveLoading: async () => {},
    createEventId: () => "d4a00000-0000-4000-8000-000000000138",
  });
  const result = await service.registerScannerReading("7898250782141", inicio);
  assert.equal(result.result, "VALIDO", "a passagem gravada 2,2 s depois ainda é associada");
  assert.ok(tentativas > 1);
});

// Executa o receptor HID real da tela com a API simulada.
async function instalarLeitorHid(registerScannerReading, estado = {}) {
  const handlers = new Map();
  const state = { loadingId: 138, operationalState: "CARREGANDO", stopReason: null, scannerUnmatchedCount: 0, ...estado };
  const notificacoes = [];
  const avisos = [];
  const scans = [];
  const document = {
    body: { dataset: {} },
    visibilityState: "visible",
    querySelector: (selector) => (selector === ".work-operation-screen" ? {} : null),
    addEventListener: (event, handler) => handlers.set(event, handler),
  };
  const install = application.slice(
    application.indexOf("function installScannerHidReceiver()"),
    application.indexOf("function installInteractionGuards()"),
  );
  new Function(
    "store", "document", "window", "currentPage", "createHidScannerCapture", "isScannerNoReadMarker", "logFrontend",
    "notificar", "renderScreen", "refreshWorkLiveView", "aguardandoPassagemM2052",
    `${install}\ninstallScannerHidReceiver();`,
  )(
    { state, registerScannerReading },
    document,
    { addEventListener() {} },
    "work",
    (options) => createHidScannerCapture({ ...options, onBarcode: (...args) => { scans.push(options.onBarcode(...args)); } }),
    isScannerNoReadMarker,
    { info() {}, aviso: (contexto, erro) => avisos.push([contexto, erro?.message]), erro(error) { throw error; } },
    (mensagem, tipo) => notificacoes.push([tipo, mensagem]),
    () => {},
    () => {},
    aguardandoPassagemM2052,
  );
  const ler = async (codigo) => {
    for (const digito of codigo) handlers.get("keydown")({ key: digito });
    handlers.get("keydown")({ key: "Enter" });
    await Promise.all(scans);
  };
  return { state, notificacoes, avisos, ler };
}

const semPassagem = () => new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { code: "HTTP_409", status: 409 });

test("tela: leitura sem passagem entra no contador na hora, sem aviso de erro", async () => {
  let chamadas = 0;
  const leitor = await instalarLeitorHid(async () => {
    chamadas++;
    if (chamadas === 1) throw semPassagem();
    return { id: 2582, result: "VALIDO" };
  });
  await leitor.ler("7898250782141");
  assert.equal(leitor.state.scannerUnmatchedCount, 1);
  assert.equal(leitor.state.latestScannerReading.result, "SEM_PASSAGEM");
  assert.equal(leitor.state.latestScannerReading.value, "7898250782141");
  // A saca seguinte do mesmo produto não apaga a anterior: era outra saca.
  await leitor.ler("7898250782141");
  assert.equal(leitor.state.scannerUnmatchedCount, 1);
  assert.deepEqual(leitor.notificacoes, []);
  assert.equal(leitor.avisos.length, 1);
  assert.equal(leitor.avisos[0][0], "leitor.hid.sem-passagem");
  assert.match(leitor.avisos[0][1], /^Leitura 7898250782141 sem passagem M2052 correspondente \(captura \d{4}-\d{2}-\d{2}T/);
});

test("captura que esperou na fila mais de 3 s tenta por no máximo 200 ms e libera a fila", async () => {
  let tentativas = 0;
  const service = new ServicoLeiturasOperacionais({
    api: { fetch: async () => {
      tentativas++;
      return { status: 409, clone: () => ({ json: async () => ({ error: MENSAGEM_AGUARDANDO_PASSAGEM }) }) };
    } },
    state: { loadingId: 138, operationalState: "CARREGANDO" },
    jsonHeaders: () => ({}),
    jsonResponse: async (_response, fallback) => { throw new Error(fallback); },
    loadActiveLoading: async () => {},
    createEventId: () => "d4a00000-0000-4000-8000-000000000139",
  });
  const inicio = Date.now();
  await assert.rejects(service.registerScannerReading("7898250782141", inicio - 5_000));
  assert.ok(tentativas >= 1 && tentativas <= 3, `tentativas: ${tentativas}`);
  assert.ok(Date.now() - inicio < 600, "não segura a fila esperando os 3 s de novo");
});

test("tela: esteira parada na captura não conta, mesmo se religar durante a espera", async () => {
  let leitor;
  leitor = await instalarLeitorHid(async () => {
    leitor.state.operationalState = "CARREGANDO";
    leitor.state.stopReason = null;
    throw semPassagem();
  }, { operationalState: "PAUSADO", stopReason: "PRODUTO_INCORRETO" });
  await leitor.ler("7898538751456");
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
});

test("tela: falha de leitura (ERROR) sem passagem também é saca perdida", async () => {
  const leitor = await instalarLeitorHid(async () => { throw semPassagem(); });
  await leitor.ler("ERROR");
  assert.equal(leitor.state.scannerUnmatchedCount, 1);
  assert.deepEqual(leitor.notificacoes, []);
  assert.match(leitor.avisos[0][1], /^Leitura sem código sem passagem M2052/);
});

test("tela: com a esteira parada para retirada, a releitura do saco não conta nem avisa", async () => {
  const leitor = await instalarLeitorHid(async () => { throw semPassagem(); }, {
    operationalState: "PAUSADO",
    stopReason: "PRODUTO_INCORRETO",
  });
  await leitor.ler("7898538751456");
  assert.deepEqual(leitor.notificacoes, []);
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
  assert.equal(leitor.state.latestScannerReading?.result, "DESCARTADA", "não pendente: a próxima atualização volta à mensagem da parada");
});

test("tela: outros erros de leitura continuam avisando o operador", async () => {
  const ambigua = "Leitura ambígua entre passagens do sensor; o código não foi associado.";
  const leitor = await instalarLeitorHid(async () => { throw new ErroApi(ambigua, { status: 409 }); });
  await leitor.ler("7898250782141");
  assert.deepEqual(leitor.notificacoes, [["erro", ambigua]]);
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
});

test("o visor mantém a leitura sem passagem até chegar leitura nova do servidor", () => {
  assert.match(activeLoading, /const newServerReading = selectionChanged \|\| serverScannerReadAt !== this\.lastServerScannerReadAt/);
  assert.match(activeLoading, /\["RECEBIDO", "SEM_PASSAGEM", "AGUARDANDO_PASSAGEM"\]\.includes\(this\.state\.latestScannerReading\?\.result\)/);
  assert.match(activeLoading, /if \(newServerReading \|\| !localPendingReading\)/);
});

test("o módulo entra no cache do aplicativo com o nome novo", () => {
  assert.match(serviceWorker, /"\/js\/funcoes\/leituras-sem-passagem\.js"/);
  assert.doesNotMatch(serviceWorker, /leituras-antecipadas/);
  assert.match(application, /from "\.\/funcoes\/leituras-sem-passagem\.js\?v=/);
});
