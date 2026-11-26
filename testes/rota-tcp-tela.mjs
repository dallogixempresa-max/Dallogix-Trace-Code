import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  AVISO_CODIGO_NAO_CADASTRADO,
  AVISO_COTA_PRODUTO,
  AVISO_EXCESSO,
  AVISO_PRODUTO_INCORRETO,
  descreverRotaTcp,
  leituraDoVisor,
  totalLeiturasSemPassagem,
} from "../interface/js/funcoes/leitor-rota-tcp.js";
import { ServicoCarregamentoAtivo } from "../interface/js/servicos/ServicoCarregamentoAtivo.js";
import { ServicoLeiturasOperacionais } from "../interface/js/servicos/ServicoLeiturasOperacionais.js";
import { work } from "../interface/telas/operacao/operacao.js";
import { apresentacaoStatusDispositivo, resumoStatusDispositivos } from "../interface/telas/configuracoes/configuracoes.js";
import { createLiveViewController } from "../interface/js/controladores/estado-visualizacao.js";

const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const serviceWorker = await readFile(new URL("../interface/service-worker.js", import.meta.url), "utf8");

// Rota TCP: o leitor entrega o código ao worker do PC, que grava no Trace sem
// passar pelo navegador. Códigos sem passagem vêm do heartbeat (tcp_route).
const AGORA = "2026-11-26T17:05:00.000Z";

function rota(overrides = {}) {
  return {
    connected: true,
    connections_open: 1,
    last_connected_at: "2026-11-26T16:00:00.000Z",
    last_disconnected_at: null,
    last_disconnect_reason: null,
    last_frame_at: AGORA,
    frames_received: 10,
    frames_rejected: 0,
    scans_registered: 8,
    scans_unassociated: 2,
    scans_rejected_by_trace: 0,
    failed_scans_pending: 0,
    scan_error: null,
    scan_error_at: null,
    unassociated_by_loading: [
      { loading_id: 42, count: 2, last: { barcode: "7898250782141", no_read: false, reason: "sem passagem disponível", received_at: AGORA, loading_state: "CARREGANDO" } },
      { loading_id: 41, count: 5, last: { barcode: "OUTRA", no_read: false, reason: "sem passagem disponível", received_at: AGORA, loading_state: "CARREGANDO" } },
    ],
    stale: false,
    stale_since: null,
    ...overrides,
  };
}

function estadoOperacao(overrides = {}) {
  return {
    selectedLoadingId: 42,
    loadingId: 42,
    activeLoadings: [],
    loadingItems: [],
    operationalState: "CARREGANDO",
    userRole: "USUARIO",
    running: true,
    equipmentId: 1,
    equipmentCode: "DALA-01",
    detectedBags: 3,
    planned: 10,
    loaded: 2,
    scannerUnmatchedCount: 0,
    latestScannerReading: { barcode: "7898538751531", result: "VALIDO", readAt: "2026-11-26 17:04:50.000", value: "7898538751531" },
    deviceStatusesUpdatedAt: "2026-11-26T17:05:01.000Z",
    deviceStatuses: [
      { device_type: "CLP", equipment_id: 1, status: "ONLINE" },
      { device_type: "SCANNER", equipment_id: 1, status: "ONLINE", tcp_route: rota() },
      { device_type: "SCANNER", equipment_id: 2, status: "ONLINE", tcp_route: rota({ unassociated_by_loading: [{ loading_id: 42, count: 9, last: null }] }) },
    ],
    ...overrides,
  };
}

test("contador soma a rota HID desta tela e a rota TCP do carregamento ativo", () => {
  assert.equal(totalLeiturasSemPassagem(estadoOperacao()), 2, "só a Dala e o carregamento atuais");
  assert.equal(totalLeiturasSemPassagem(estadoOperacao({ scannerUnmatchedCount: 3 })), 5);
  assert.equal(totalLeiturasSemPassagem(estadoOperacao({ loadingId: 43 })), 0, "outro carregamento não herda a contagem");
  // Worker anterior sem tcp_route: só a contagem HID, como antes.
  const semRota = estadoOperacao({ scannerUnmatchedCount: 1 });
  semRota.deviceStatuses = semRota.deviceStatuses.map(({ tcp_route: _rota, ...device }) => device);
  assert.equal(totalLeiturasSemPassagem(semRota), 1);
  // Sem status ao vivo ainda, usa o snapshot do monitoramento.
  const monitoramento = estadoOperacao({ deviceStatusesUpdatedAt: null });
  monitoramento.monitoring = { dispositivos: monitoramento.deviceStatuses };
  monitoramento.deviceStatuses = [];
  assert.equal(totalLeiturasSemPassagem(monitoramento), 2);
});

test("visor mostra o último código sem passagem do worker quando é o mais recente", () => {
  const recente = leituraDoVisor(estadoOperacao());
  assert.deepEqual(recente, { barcode: "7898250782141", result: "SEM_PASSAGEM", readAt: AGORA, value: "7898250782141" });

  const falha = estadoOperacao();
  falha.deviceStatuses[1].tcp_route.unassociated_by_loading[0].last = { barcode: "", no_read: true, reason: "x", received_at: AGORA, loading_state: "CARREGANDO" };
  assert.equal(leituraDoVisor(falha).value, "Falha na leitura (sem código)");
  assert.equal(leituraDoVisor(falha).result, "SEM_PASSAGEM");

  // Leitura registrada depois (horário UTC do MySQL sem sufixo) volta a valer.
  const registradaDepois = estadoOperacao({
    latestScannerReading: { barcode: "789", result: "VALIDO", readAt: "2026-11-26 17:05:02.000", value: "789" },
  });
  assert.equal(leituraDoVisor(registradaDepois).value, "789");
  const semRota = estadoOperacao({ deviceStatuses: [] });
  assert.equal(leituraDoVisor(semRota), semRota.latestScannerReading);
});

test("esteira parada: o visor não mostra o código sem passagem recebido fora de CARREGANDO", () => {
  // Mesma regra da rota HID, que descarta a captura com a esteira parada; o
  // worker também não soma esse código em count.
  for (const loading_state of ["PAUSADO", "FINALIZANDO", "EMERGENCIA", "FINALIZADO", null, undefined]) {
    const parado = estadoOperacao();
    const entrada = parado.deviceStatuses[1].tcp_route.unassociated_by_loading[0];
    entrada.last = { ...entrada.last, loading_state };
    if (loading_state === undefined) delete entrada.last.loading_state;
    assert.equal(leituraDoVisor(parado), parado.latestScannerReading, `loading_state ${loading_state}`);
    const html = work({ state: parado });
    assert.match(html, /<output id="automatic-scanner-barcode" class="work-scanner-display-value" data-live="scanner-value"[^>]*>7898538751531</);
  }
  // O contador é o count do worker, que já exclui o que chegou fora de CARREGANDO.
  const parado = estadoOperacao();
  parado.deviceStatuses[1].tcp_route.unassociated_by_loading[0] = {
    loading_id: 42,
    count: 2,
    last: { barcode: "789", no_read: false, reason: "sem passagem disponível", received_at: AGORA, loading_state: "PAUSADO" },
  };
  assert.equal(totalLeiturasSemPassagem(parado), 2);
});

test("operação renderiza o contador e o visor SEM_PASSAGEM vindos da rota TCP", () => {
  const html = work({ state: estadoOperacao({ scannerUnmatchedCount: 1 }) });
  assert.match(html, /data-live="scanner-unmatched-notice" role="status">Leituras sem passagem neste carregamento: <strong data-live="scanner-unmatched-count">3<\/strong>/);
  assert.match(html, /<output id="automatic-scanner-barcode" class="[^"]*is-error" data-live="scanner-value"[^>]*>7898250782141</);

  // Rota HID sem rota TCP: a tela continua igual à de hoje.
  const hid = estadoOperacao({ deviceStatuses: [], scannerUnmatchedCount: 0 });
  const hidHtml = work({ state: hid });
  assert.match(hidHtml, /data-live="scanner-unmatched-notice" role="status" hidden>/);
  assert.match(hidHtml, /<output id="automatic-scanner-barcode" class="work-scanner-display-value" data-live="scanner-value"[^>]*>7898538751531</);
});

test("atualização ao vivo aplica o total e o visor SEM_PASSAGEM da rota TCP", () => {
  const nodes = new Map();
  const node = (key) => {
    if (!nodes.has(key)) {
      const classes = new Set();
      nodes.set(key, {
        textContent: "",
        hidden: true,
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)), contains: (name) => classes.has(name) },
      });
    }
    return nodes.get(key);
  };
  const keyOf = (selector) => selector.match(/data-live="([^"]+)"/)?.[1];
  const previousDocument = globalThis.document;
  globalThis.document = {
    querySelector: (selector) => {
      const key = keyOf(selector);
      return ["scanner-unmatched-notice", "scanner-value"].includes(key) ? node(key) : null;
    },
    querySelectorAll: (selector) => {
      const key = keyOf(selector);
      return ["scanner-unmatched-count", "scanner-value"].includes(key) ? [node(key)] : [];
    },
  };
  try {
    const store = { state: estadoOperacao({ scannerUnmatchedCount: 1 }), clpDisponivel: () => true };
    createLiveViewController({ store, getCurrentPage: () => "work", screens: {} }).refreshWorkLiveView();
    assert.equal(node("scanner-unmatched-count").textContent, "3");
    assert.equal(node("scanner-unmatched-notice").hidden, false);
    assert.equal(node("scanner-value").textContent, "7898250782141");
    assert.equal(node("scanner-value").classList.contains("is-error"), true);
  } finally {
    globalThis.document = previousDocument;
  }
});

// Relógio dos testes do avaliador; a leitura padrão foi gravada 1 s antes.
const INICIO_RELOGIO = Date.parse("2026-11-26T17:04:51.000Z");
let relogioTeste = INICIO_RELOGIO;
// Horário UTC do MySQL, sem sufixo, como last_scanner_read_at.
const horarioMysql = (ms) => new Date(ms).toISOString().replace("T", " ").replace("Z", "");

function carregamento(overrides = {}) {
  return {
    id: 42,
    state: "CARREGANDO",
    equipment_id: 1,
    planned_quantity: 10,
    valid_readings: 2,
    detected_bags: 3,
    scanner_readings: 2,
    last_scanner_reading_id: 10,
    last_scanner_barcode: "7898538751531",
    last_scanner_product_id: 7,
    last_scanner_result: "VALIDO",
    last_scanner_read_at: horarioMysql(relogioTeste - 1000),
    last_scanner_catalog_product: 1,
    operator_alert: null,
    items: [{ product_id: 7, planned_quantity: 5, loaded_quantity: 2, no_read_quantity: 0 }],
    ...overrides,
  };
}

function servicoCarregamento(state = {}) {
  const avisos = [];
  relogioTeste = INICIO_RELOGIO;
  const servico = new ServicoCarregamentoAtivo({
    state: { activeLoadings: [], latestScannerReading: null, ...state },
    hasPendingCommand: () => false,
    applyCommandIntent: () => {},
    notifyServerReading: (lista) => avisos.push(...lista),
    now: () => relogioTeste,
  });
  return {
    servico,
    avisos,
    aplicar: (loading) => servico._applyList([loading], 42),
    avancar: (ms) => { relogioTeste += ms; },
  };
}

test("leitura nova gravada pela rota TCP mostra o mesmo aviso da rota HID, uma vez", () => {
  const { aplicar, avisos, avancar } = servicoCarregamento();
  aplicar(carregamento({ last_scanner_result: "PRODUTO_INCORRETO", last_scanner_reading_id: 9 }));
  assert.deepEqual(avisos, [], "primeira carga é histórico: sem aviso");

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 11, last_scanner_result: "PRODUTO_INCORRETO", operator_alert: "Produto fora do romaneio. Retire a saca." }));
  assert.deepEqual(avisos, [{ mensagem: "Produto fora do romaneio. Retire a saca.", tipo: "erro" }]);
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 11, last_scanner_result: "PRODUTO_INCORRETO" }));
  assert.equal(avisos.length, 1, "a mesma leitura não avisa de novo");

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 12, last_scanner_result: "PRODUTO_INCORRETO", last_scanner_product_id: null, last_scanner_catalog_product: 0 }));
  assert.deepEqual(avisos.at(-1), { mensagem: AVISO_CODIGO_NAO_CADASTRADO, tipo: "erro" });

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 13, last_scanner_result: "PRODUTO_INCORRETO", last_scanner_product_id: 7 }));
  assert.deepEqual(avisos.at(-1), { mensagem: AVISO_PRODUTO_INCORRETO, tipo: "erro" });

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 14, last_scanner_result: "EXCESSO" }));
  assert.deepEqual(avisos.at(-1), { mensagem: AVISO_EXCESSO, tipo: "erro" });

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 15, items: [{ product_id: 7, planned_quantity: 5, loaded_quantity: 4, no_read_quantity: 1 }] }));
  assert.deepEqual(avisos.at(-1), { mensagem: AVISO_COTA_PRODUTO, tipo: "aviso" });

  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 16, last_scanner_result: "VALIDO" }));
  aplicar(carregamento({ last_scanner_reading_id: 17, last_scanner_result: "SEM_LEITURA", last_scanner_product_id: null }));
  assert.equal(avisos.length, 5, "leitura válida comum e falha de leitura não geram aviso imediato");
});

test("troca de carregamento e volta à tela depois de muito tempo não avisam histórico", () => {
  const { servico, aplicar, avisos, avancar } = servicoCarregamento();
  aplicar(carregamento({ last_scanner_reading_id: 10 }));
  servico._applyList([carregamento({ id: 43, last_scanner_reading_id: 30, last_scanner_result: "PRODUTO_INCORRETO" })], 43);
  assert.deepEqual(avisos, []);
  avancar(60_000);
  servico._applyList([carregamento({ id: 43, last_scanner_reading_id: 31, last_scanner_result: "PRODUTO_INCORRETO" })], 43);
  assert.deepEqual(avisos, [], "sem consulta por 60 s, a referência é refeita sem avisar");
  avancar(2000);
  servico._applyList([carregamento({ id: 43, last_scanner_reading_id: 32, last_scanner_result: "EXCESSO" })], 43);
  assert.equal(avisos.length, 1);
});

const AVISO_COTA = { mensagem: AVISO_COTA_PRODUTO, tipo: "aviso" };
const itens = (contados7, contados8 = 0, semLeitura8 = 0) => [
  { product_id: 7, planned_quantity: 5, loaded_quantity: contados7, no_read_quantity: 0 },
  { product_id: 8, planned_quantity: 3, loaded_quantity: contados8, no_read_quantity: semLeitura8 },
];

test("cota do produto: duas leituras TCP entre consultas não escondem a que atingiu a quantidade prevista", () => {
  const { aplicar, avisos, avancar } = servicoCarregamento();
  aplicar(carregamento({ last_scanner_reading_id: 10, items: itens(4) }));
  assert.deepEqual(avisos, []);

  // Leitura 11 (VALIDO) atingiu a cota do produto 7; a 12 (produto incorreto)
  // é a última quando a tela consulta. A cota vem antes: o erro fica visível.
  avancar(2000);
  aplicar(carregamento({
    last_scanner_reading_id: 12,
    last_scanner_result: "PRODUTO_INCORRETO",
    last_scanner_product_id: 9,
    items: itens(5),
  }));
  assert.deepEqual(avisos, [AVISO_COTA, { mensagem: AVISO_PRODUTO_INCORRETO, tipo: "erro" }]);

  // Sem travessia nova, nada a avisar; uma vez por item, mesmo se a contagem
  // cair (retorno) e voltar à quantidade prevista.
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 13, items: itens(5, 1) }));
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 13, items: itens(4, 1) }));
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 14, items: itens(5, 1) }));
  assert.equal(avisos.length, 2);

  // Passagem sem leitura vinculada ao produto também conta (válidas + sem leitura).
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 15, last_scanner_result: "SEM_LEITURA", items: itens(5, 2) }));
  assert.equal(avisos.length, 2);
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 16, last_scanner_result: "SEM_LEITURA", items: itens(5, 2, 1) }));
  assert.deepEqual(avisos.at(-1), AVISO_COTA);
  assert.equal(avisos.length, 3);
});

test("cota do produto: dois itens atingidos na mesma consulta geram um aviso", () => {
  const { aplicar, avisos, avancar } = servicoCarregamento();
  aplicar(carregamento({ last_scanner_reading_id: 10, items: itens(4, 2) }));
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 12, items: itens(5, 3) }));
  assert.deepEqual(avisos, [AVISO_COTA]);
  // Já acima da quantidade na primeira consulta: histórico, sem aviso.
  const outro = servicoCarregamento();
  outro.aplicar(carregamento({ last_scanner_reading_id: 10, items: itens(5, 3) }));
  outro.avancar(2000);
  outro.aplicar(carregamento({ last_scanner_reading_id: 11, items: itens(5, 3) }));
  assert.deepEqual(outro.avisos, []);
});

test("leitura do servidor com mais de 30 s não vira aviso (lote sincronizado depois)", () => {
  const { aplicar, avisos, avancar } = servicoCarregamento();
  aplicar(carregamento({ last_scanner_reading_id: 10, items: itens(4) }));
  avancar(2000);
  aplicar(carregamento({
    last_scanner_reading_id: 20,
    last_scanner_result: "PRODUTO_INCORRETO",
    last_scanner_read_at: horarioMysql(relogioTeste - 31_000),
    items: itens(5),
  }));
  assert.deepEqual(avisos, [], "produto incorreto e cota de leitura antiga não avisam");
  // A travessia antiga não volta a avisar com a próxima leitura recente.
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 21, items: itens(5) }));
  assert.deepEqual(avisos, []);
  // Sem horário da leitura, não há como saber a idade: sem aviso.
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 22, last_scanner_result: "EXCESSO", last_scanner_read_at: null }));
  assert.deepEqual(avisos, []);
  // Dentro de 30 s continua avisando.
  avancar(2000);
  aplicar(carregamento({ last_scanner_reading_id: 23, last_scanner_result: "EXCESSO", last_scanner_read_at: horarioMysql(relogioTeste - 29_000) }));
  assert.deepEqual(avisos, [{ mensagem: AVISO_EXCESSO, tipo: "erro" }]);
});

test("rota HID: cota atingida por leitura desta tela não repete o aviso do POST", async () => {
  const state = {
    loadingId: 42,
    selectedLoadingId: 42,
    operationalState: "CARREGANDO",
    activeLoadings: [],
    latestScannerReading: null,
  };
  const { servico, avisos, avancar } = servicoCarregamento();
  servico.state = state;
  servico._applyList([carregamento({ last_scanner_reading_id: 10, items: itens(4, 2) })], 42);
  const leituras = new ServicoLeiturasOperacionais({
    api: { fetch: async () => ({ status: 201, json: async () => ({ data: { id: 11, result: "VALIDO", product_id: 7, product_quota_reached: true } }) }) },
    state,
    jsonHeaders: () => ({}),
    jsonResponse: (response) => response.json(),
    loadActiveLoading: async () => {
      avancar(100);
      servico._applyList([carregamento({ last_scanner_reading_id: 11, items: itens(5, 2) })], 42);
    },
    createEventId: () => "d4a00000-0000-4000-8000-000000000203",
  });
  const reading = await leituras.registerScannerReading("7898250782141", Date.now());
  assert.equal(reading.product_quota_reached, true);
  assert.deepEqual(state.scannerQuotaWarnedHere, ["42:7"]);
  assert.deepEqual(avisos, [], "a rota HID já avisou a cota pela resposta do POST");

  // A cota do produto 8 atingida por leitura TCP continua avisando.
  avancar(2000);
  servico._applyList([carregamento({ last_scanner_reading_id: 12, items: itens(5, 3) })], 42);
  assert.deepEqual(avisos, [AVISO_COTA]);
});

test("rota HID: leitura enviada por esta tela não duplica o aviso da resposta do POST", async () => {
  const state = {
    loadingId: 42,
    selectedLoadingId: 42,
    operationalState: "CARREGANDO",
    activeLoadings: [],
    latestScannerReading: null,
    scannerReadingsInFlight: 0,
    scannerReadingIdsPostedHere: [],
  };
  const { servico, avisos, avancar } = servicoCarregamento();
  servico.state = state;
  servico._applyList([carregamento({ last_scanner_reading_id: 10 })], 42);

  let liberarPost;
  const postPendente = new Promise((resolve) => { liberarPost = resolve; });
  const recargas = [];
  const leituras = new ServicoLeiturasOperacionais({
    api: { fetch: async () => { await postPendente; return { status: 201, json: async () => ({ data: { id: 11, result: "PRODUTO_INCORRETO" } }) }; } },
    state,
    jsonHeaders: () => ({}),
    jsonResponse: (response) => response.json(),
    loadActiveLoading: async () => {
      recargas.push(state.scannerReadingsInFlight);
      avancar(100);
      servico._applyList([carregamento({ last_scanner_reading_id: 11, last_scanner_result: "PRODUTO_INCORRETO" })], 42);
    },
    createEventId: () => "d4a00000-0000-4000-8000-000000000201",
  });
  const envio = leituras.registerScannerReading("7898250782141", Date.now());
  // Atualização ao vivo vê a leitura antes de o POST responder.
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(state.scannerReadingsInFlight, 1);
  avancar(100);
  servico._applyList([carregamento({ last_scanner_reading_id: 11, last_scanner_result: "PRODUTO_INCORRETO" })], 42);
  liberarPost();
  const reading = await envio;
  assert.equal(reading.id, 11);
  assert.deepEqual(recargas, [0], "o id é registrado antes de recarregar o carregamento");
  assert.equal(state.scannerReadingsInFlight, 0);
  assert.deepEqual(state.scannerReadingIdsPostedHere, [11]);
  avancar(2000);
  servico._applyList([carregamento({ last_scanner_reading_id: 11, last_scanner_result: "PRODUTO_INCORRETO" })], 42);
  assert.deepEqual(avisos, [], "a rota HID avisa pela resposta do POST, sem repetir pela atualização ao vivo");

  // Uma leitura TCP depois do POST continua avisando.
  avancar(2000);
  servico._applyList([carregamento({ last_scanner_reading_id: 12, last_scanner_result: "EXCESSO" })], 42);
  assert.equal(avisos.length, 1);
});

test("POST da rota HID que falha libera a contagem de envios em andamento", async () => {
  const state = { loadingId: 42, operationalState: "CARREGANDO" };
  const leituras = new ServicoLeiturasOperacionais({
    api: { fetch: async () => ({ status: 422, json: async () => ({ error: "x" }) }) },
    state,
    jsonHeaders: () => ({}),
    jsonResponse: async () => { throw new Error("Leitura recusada."); },
    loadActiveLoading: async () => {},
    createEventId: () => "d4a00000-0000-4000-8000-000000000202",
  });
  await assert.rejects(leituras.registerScannerReading("7898250782141", Date.now()), /Leitura recusada/);
  assert.equal(state.scannerReadingsInFlight, 0);
  assert.equal(state.scannerReadingIdsPostedHere, undefined);
});

test("avisos da rota TCP usam os mesmos textos da rota HID", () => {
  for (const texto of [AVISO_PRODUTO_INCORRETO, AVISO_EXCESSO, AVISO_COTA_PRODUTO]) {
    assert.ok(application.includes(`"${texto}"`), `aplicacao.js deve conter: ${texto}`);
  }
  assert.ok(readingsEndpoint.includes(`"${AVISO_CODIGO_NAO_CADASTRADO}"`));
  assert.match(application, /store\.aoAvisarLeituraDoServidor = \(avisos\) => \{\s*if \(currentPage !== "work"\) return;/);
  assert.match(serviceWorker, /"\/js\/funcoes\/leitor-rota-tcp\.js"/);
});

test("cartão do leitor mostra a rota TCP sem trocar a regra ONLINE/OFFLINE", () => {
  const agoraMs = Date.parse("2026-11-26T18:00:00.000Z");
  const scanner = (status, tcp_route) => ({ equipment_id: 1, device_type: "SCANNER", status, reader_probe_method: "ICMP_PING", tcp_route });

  const naRede = { leitorRespondeNaRede: true };

  assert.deepEqual(descreverRotaTcp(rota(), agoraMs, naRede), {
    texto: "Rota TCP conectada · último código recebido às 14:05 · 2 códigos sem passagem",
    atencao: false,
  });
  // Conexão aberta sem resposta do leitor à sondagem: com o Docker Desktop a
  // conexão vem do gateway e pode seguir aberta depois que o leitor some.
  assert.equal(
    descreverRotaTcp(rota(), agoraMs).texto,
    "Rota TCP aberta, mas o leitor não responde na rede · último código recebido às 14:05 · 2 códigos sem passagem",
  );
  assert.equal(
    descreverRotaTcp(rota(), agoraMs, { leitorRespondeNaRede: false }).texto.startsWith("Rota TCP aberta, mas o leitor não responde na rede"),
    true,
  );
  assert.equal(
    descreverRotaTcp(rota({ connected: false, last_connected_at: null, last_frame_at: null, scans_unassociated: 0 }), agoraMs, naRede).texto,
    "Rota TCP aguardando a primeira leitura",
  );
  assert.equal(
    descreverRotaTcp(rota({ connected: false, last_disconnected_at: "2026-11-26T16:40:00.000Z", scans_unassociated: 1 }), agoraMs, naRede).texto,
    "Rota TCP sem conexão desde 13:40 · último código recebido às 14:05 · 1 código sem passagem",
  );
  assert.equal(descreverRotaTcp(null), null);

  // Leituras retidas no PC são histórico (failed-scans): informação neutra.
  assert.deepEqual(descreverRotaTcp(rota({ failed_scans_pending: 1, scans_unassociated: 0 }), agoraMs, naRede), {
    texto: "Rota TCP conectada · último código recebido às 14:05 · 1 leitura retida no PC para conferência",
    atencao: false,
  });
  // Só o erro recente pede atenção.
  assert.equal(descreverRotaTcp(rota({ scan_error: "Trace indisponível" }), agoraMs, naRede).atencao, true);
  assert.equal(descreverRotaTcp(rota({ scan_error: "   " }), agoraMs, naRede).atencao, false);

  // Heartbeat vencido (stale): nada de "conectada", só a falta de informação recente.
  assert.deepEqual(
    descreverRotaTcp(rota({ stale: true, stale_since: "2026-11-26 17:52:00.000", scan_error: "Trace indisponível" }), agoraMs, naRede),
    { texto: "Sem informação recente do worker do leitor (desde 14:52)", atencao: false },
  );
  assert.equal(
    descreverRotaTcp(rota({ stale: true, stale_since: null }), agoraMs, naRede).texto,
    "Sem informação recente do worker do leitor",
  );

  const normal = apresentacaoStatusDispositivo("ONLINE", "SCANNER", scanner("ONLINE", rota()));
  assert.equal(normal.tone, "online");
  assert.equal(normal.label, "REDE ONLINE");
  assert.equal(normal.attention, false);
  assert.match(normal.route, /^Rota TCP conectada/);

  const comErro = apresentacaoStatusDispositivo("ONLINE", "SCANNER", scanner("ONLINE", rota({ scan_error: "Trace indisponível durante a associação" })));
  assert.equal(comErro.online, true, "erro na rota não vira OFFLINE");
  assert.equal(comErro.tone, "attention");
  assert.equal(comErro.attention, true);
  assert.match(comErro.route, /Erro: Trace indisponível durante a associação$/);

  // Leituras retidas não deixam o cartão em atenção para sempre.
  const retidas = apresentacaoStatusDispositivo("ONLINE", "SCANNER", scanner("ONLINE", rota({ failed_scans_pending: 3 })));
  assert.equal(retidas.attention, false);
  assert.equal(retidas.tone, "online");
  assert.match(retidas.route, /3 leituras retidas no PC para conferência/);
  assert.deepEqual(resumoStatusDispositivos([retidas]), { online: 1, attention: 0, unmonitored: 0 });

  const semRede = apresentacaoStatusDispositivo("OFFLINE", "SCANNER", scanner("OFFLINE", rota({ connected: false, last_disconnected_at: "2026-11-26T16:40:00.000Z" })));
  assert.equal(semRede.tone, "offline");
  assert.match(semRede.route, /Rota TCP sem conexão desde/);

  // Ping sem resposta com a conexão ainda aberta no worker.
  const abertaSemRede = apresentacaoStatusDispositivo("OFFLINE", "SCANNER", scanner("OFFLINE", rota()));
  assert.equal(abertaSemRede.tone, "offline");
  assert.match(abertaSemRede.route, /^Rota TCP aberta, mas o leitor não responde na rede/);
  assert.doesNotMatch(abertaSemRede.route, /conectada/);

  // Worker sem heartbeat recente: servidor marca DESCONHECIDO e stale.
  const vencido = apresentacaoStatusDispositivo("DESCONHECIDO", "SCANNER", scanner("DESCONHECIDO", rota({ stale: true, stale_since: "2026-11-26 17:52:00.000" })));
  assert.equal(vencido.label, "NÃO CONFIRMADO");
  assert.match(vencido.route, /^Sem informação recente do worker do leitor \(desde /);
  assert.doesNotMatch(vencido.route, /conectada/);

  // Worker anterior sem tcp_route: o cartão fica como hoje.
  const antigo = apresentacaoStatusDispositivo("ONLINE", "SCANNER", scanner("ONLINE", null));
  assert.equal(antigo.tone, "online");
  assert.equal(antigo.route, "");
  assert.equal(antigo.detail, "Leitor acessível na rede");
});
