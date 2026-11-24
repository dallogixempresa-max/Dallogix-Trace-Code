import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  aguardandoPassagemM2052,
  criarLeiturasAntecipadas,
  JANELA_LEITURA_ANTECIPADA_MS,
  MENSAGEM_AGUARDANDO_PASSAGEM,
} from "../interface/js/funcoes/leituras-antecipadas.js";
import { ErroApi } from "../interface/js/api/ClienteApi.js";
import { createHidScannerCapture, isScannerNoReadMarker } from "../interface/js/controladores/captura-leitor-hid.js";

const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const activeLoading = await readFile(new URL("../interface/js/servicos/ServicoCarregamentoAtivo.js", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const serviceWorker = await readFile(new URL("../interface/service-worker.js", import.meta.url), "utf8");

// Carregamento 136 (24/11/2026): capturas sem passagem 1,3 a 2,6 s antes da
// saca seguinte, que passou pelo sensor e teve a própria leitura válida.
test("captura antecipada confirmada pela mesma saca não vira leitura sem passagem", () => {
  const leituras = criarLeiturasAntecipadas();
  const antecipada = 1_795_521_214_900;
  leituras.registrar(136, "7898250782141", antecipada);
  assert.equal(leituras.confirmar(136, "7898250782141", antecipada + 2_300), 1);
  assert.deepEqual(leituras.expirar(antecipada + JANELA_LEITURA_ANTECIPADA_MS + 1), []);
});

test("a janela cobre o medido em produção e não mais que isso", () => {
  assert.equal(JANELA_LEITURA_ANTECIPADA_MS, 3500);
  const leituras = criarLeiturasAntecipadas();
  leituras.registrar(136, "A", 0);
  assert.equal(leituras.confirmar(136, "A", 3_500), 1, "0,35 a 2,6 s antes da passagem + até 0,9 s depois");
  // Saca que o sensor não viu, seguida de outra do mesmo produto 4 s depois:
  // a segunda não pode apagar a primeira.
  leituras.registrar(136, "A", 10_000);
  assert.equal(leituras.confirmar(136, "A", 14_000), 0);
  assert.deepEqual(leituras.expirar(13_501).map((leitura) => leitura.capturadaEmMs), [10_000]);
});

test("uma leitura registrada confirma uma única captura antecipada", () => {
  const leituras = criarLeiturasAntecipadas();
  leituras.registrar(136, "A", 0);
  leituras.registrar(136, "A", 500);
  assert.equal(leituras.confirmar(136, "A", 1_000), 1);
  assert.equal(leituras.quantidade(), 1);
  assert.deepEqual(leituras.expirar(10_000).map((leitura) => leitura.capturadaEmMs), [0]);
});

test("captura sem nenhuma saca do mesmo código depois entra no contador", () => {
  const leituras = criarLeiturasAntecipadas();
  const capturada = 1_795_520_836_000;
  leituras.registrar(136, "7898538751456", capturada);
  assert.equal(leituras.confirmar(136, "7898250782141", capturada + 1_000), 0, "outro código não confirma");
  assert.deepEqual(leituras.expirar(capturada + JANELA_LEITURA_ANTECIPADA_MS), []);
  const vencidas = leituras.expirar(capturada + JANELA_LEITURA_ANTECIPADA_MS + 1);
  assert.deepEqual(vencidas.map((leitura) => leitura.barcode), ["7898538751456"]);
  assert.equal(leituras.quantidade(), 0);
});

test("confirmação só vale para a mesma carga e depois da captura", () => {
  const leituras = criarLeiturasAntecipadas();
  leituras.registrar(136, "A", 1_000);
  assert.equal(leituras.confirmar(137, "A", 2_000), 0);
  assert.equal(leituras.confirmar(136, "A", 500), 0, "leitura anterior à captura não confirma");
  leituras.descartarOutrasCargas(137);
  assert.equal(leituras.quantidade(), 0);
});

test("o 409 de passagem ausente é reconhecido pela mensagem exata do servidor", () => {
  assert.ok(readingsEndpoint.includes(`"error" => "${MENSAGEM_AGUARDANDO_PASSAGEM}"`));
  assert.equal(aguardandoPassagemM2052(new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { status: 409 })), true);
  assert.equal(aguardandoPassagemM2052(new ErroApi("Leitura ambígua entre passagens do sensor; o código não foi associado.", { status: 409 })), false);
  assert.equal(aguardandoPassagemM2052(new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { status: 503 })), false);
});

// Executa o receptor HID real da tela com a API simulada. O relógio fica 60 s
// à frente para que o temporizador da janela encontre as capturas vencidas.
async function instalarLeitorHid(registerScannerReading, estado = {}) {
  const handlers = new Map();
  const state = { loadingId: 136, operationalState: "CARREGANDO", stopReason: null, scannerUnmatchedCount: 0, ...estado };
  const notificacoes = [];
  const avisos = [];
  const temporizadores = [];
  const scans = [];
  const document = {
    body: { dataset: {} },
    visibilityState: "visible",
    querySelector: (selector) => (selector === ".work-operation-screen" ? {} : null),
    addEventListener: (event, handler) => handlers.set(event, handler),
  };
  class RelogioAdiantado extends Date {
    static now() { return Date.now() + 60_000; }
  }
  const install = application.slice(
    application.indexOf("function installScannerHidReceiver()"),
    application.indexOf("function installInteractionGuards()"),
  );
  new Function(
    "store", "document", "window", "currentPage", "createHidScannerCapture", "isScannerNoReadMarker", "logFrontend",
    "notificar", "renderScreen", "refreshWorkLiveView", "aguardandoPassagemM2052", "criarLeiturasAntecipadas",
    "JANELA_LEITURA_ANTECIPADA_MS", "setTimeout", "Date",
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
    criarLeiturasAntecipadas,
    JANELA_LEITURA_ANTECIPADA_MS,
    (callback) => temporizadores.push(callback),
    RelogioAdiantado,
  );
  const capturar = (codigo) => {
    for (const digito of codigo) handlers.get("keydown")({ key: digito });
    handlers.get("keydown")({ key: "Enter" });
  };
  const ler = async (codigo) => {
    capturar(codigo);
    await Promise.all(scans);
  };
  const esperarFila = async () => {
    await Promise.all(scans);
    await new Promise((resolve) => setImmediate(resolve));
  };
  const vencerJanela = async () => {
    temporizadores.splice(0).forEach((callback) => callback());
    await new Promise((resolve) => setImmediate(resolve));
  };
  return { state, notificacoes, avisos, capturar, ler, esperarFila, vencerJanela };
}

const semPassagem = () => new ErroApi(MENSAGEM_AGUARDANDO_PASSAGEM, { code: "HTTP_409", status: 409 });

test("tela: captura antecipada seguida da leitura da mesma saca não mostra erro nem conta", async () => {
  let chamadas = 0;
  const leitor = await instalarLeitorHid(async () => {
    chamadas++;
    if (chamadas === 1) throw semPassagem();
    return { id: 2350, result: "VALIDO" };
  });
  await leitor.ler("7898250782141");
  await leitor.ler("7898250782141");
  await leitor.vencerJanela();
  assert.equal(chamadas, 2);
  assert.deepEqual(leitor.notificacoes, []);
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
  assert.deepEqual(leitor.avisos, []);
});

test("tela: leitura da mesma saca ainda na fila quando a janela vence não conta", async () => {
  let chamadas = 0;
  let liberar;
  const bloqueio = new Promise((resolve) => { liberar = resolve; });
  const leitor = await instalarLeitorHid(async () => {
    chamadas++;
    if (chamadas === 1) throw semPassagem();
    await bloqueio; // servidor lento na leitura da própria saca
    return { id: 2350, result: "VALIDO" };
  });
  await leitor.ler("7898250782141");
  leitor.capturar("7898250782141");
  await leitor.vencerJanela();
  assert.equal(leitor.state.scannerUnmatchedCount, 0, "a contagem espera a fila");
  liberar();
  await leitor.esperarFila();
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
  assert.deepEqual(leitor.notificacoes, []);
});

test("tela: captura sem saca depois vai para o contador discreto, sem aviso de erro", async () => {
  const leitor = await instalarLeitorHid(async () => { throw semPassagem(); });
  await leitor.ler("7898538751456");
  assert.equal(leitor.state.scannerUnmatchedCount, 0, "dentro da janela ainda aguarda a saca");
  await leitor.vencerJanela();
  assert.deepEqual(leitor.notificacoes, []);
  assert.equal(leitor.state.scannerUnmatchedCount, 1);
  assert.deepEqual(leitor.avisos, [["leitor.hid.sem-passagem", "Leitura 7898538751456 sem passagem M2052 correspondente."]]);
});

test("tela: com a esteira parada para retirada, a releitura do saco não conta nem avisa", async () => {
  const leitor = await instalarLeitorHid(async () => { throw semPassagem(); }, {
    operationalState: "PAUSADO",
    stopReason: "PRODUTO_INCORRETO",
  });
  await leitor.ler("7898538751456");
  await leitor.vencerJanela();
  assert.deepEqual(leitor.notificacoes, []);
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
  assert.notEqual(leitor.state.latestScannerReading?.result, "AGUARDANDO_PASSAGEM", "o visor volta à mensagem da parada");
});

test("tela: outros erros de leitura continuam avisando o operador", async () => {
  const ambigua = "Leitura ambígua entre passagens do sensor; o código não foi associado.";
  const leitor = await instalarLeitorHid(async () => { throw new ErroApi(ambigua, { status: 409 }); });
  await leitor.ler("7898250782141");
  await leitor.vencerJanela();
  assert.deepEqual(leitor.notificacoes, [["erro", ambigua]]);
  assert.equal(leitor.state.scannerUnmatchedCount, 0);
});

test("a tela não mostra erro para a captura antecipada e só conta a não confirmada", () => {
  const catchBlock = application.match(/\} catch \(error\) \{\s*if \(!noRead && aguardandoPassagemM2052\(error\)\) \{([\s\S]*?)\n {10}\}/)?.[1] || "";
  assert.ok(catchBlock, "o tratamento da captura antecipada deve vir antes do aviso de erro");
  assert.doesNotMatch(catchBlock, /notificar\(/);
  assert.match(catchBlock, /leiturasAntecipadas\.registrar\(loadingId, barcode, capturedAtEpochMs\)/);
  assert.match(catchBlock, /queue\.then\(contarLeiturasSemPassagem\)/);
  assert.match(application, /if \(reading && !noRead\) leiturasAntecipadas\.confirmar\(loadingId, barcode, capturedAtEpochMs\)/);
  assert.match(application, /notificar\(error\.message \|\| "Não foi possível associar a leitura ao saco detectado\.", "erro"\)/);
});

test("o visor mantém a captura pendente até chegar leitura nova do servidor", () => {
  assert.match(activeLoading, /const newServerReading = selectionChanged \|\| serverScannerReadAt !== this\.lastServerScannerReadAt/);
  assert.match(activeLoading, /\["SEM_PASSAGEM", "AGUARDANDO_PASSAGEM"\]\.includes\(this\.state\.latestScannerReading\?\.result\)/);
  assert.match(activeLoading, /if \(newServerReading \|\| !localPendingReading\)/);
});

test("o módulo novo entra no cache do aplicativo", () => {
  assert.match(serviceWorker, /"\/js\/funcoes\/leituras-antecipadas\.js"/);
});
