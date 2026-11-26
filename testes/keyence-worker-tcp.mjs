import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { chmod, mkdtemp, mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { createServer as createHttpServer } from "node:http";
import { tmpdir } from "node:os";
import { delimiter, join } from "node:path";
import { createConnection, createServer as createNetServer } from "node:net";
import { fileURLToPath } from "node:url";
import test from "node:test";
import {
  CLOSE_REMAINDER_CAUSE,
  REPLACED_CONNECTION_REASON,
  createKeyenceTcpReceiver,
  createTcpRouteStats,
  drainScanQueue,
  normalizeLoadingState,
  recoverInFlightScans,
  rejectedFrameRecord,
  scanRetryDelayMs,
  traceRejectionReason,
  writeInFlightScan,
  writeScanRecord,
} from "../integracoes/keyence-worker/keyence-core.mjs";

const core = await readFile(new URL("../integracoes/keyence-worker/keyence-core.mjs", import.meta.url), "utf8");
const worker = await readFile(new URL("../integracoes/keyence-worker/keyence-worker.mjs", import.meta.url), "utf8");

const TCP_ROUTE_KEYS = [
  "connected", "connections_open", "last_connected_at", "last_disconnected_at", "last_disconnect_reason",
  "last_frame_at", "frames_received", "frames_rejected", "scans_registered", "scans_unassociated",
  "scans_rejected_by_trace", "failed_scans_pending", "scan_error", "scan_error_at", "unassociated_by_loading",
];

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitFor(predicate, message, timeoutMs = 3000) {
  const startedAt = Date.now();
  while (!predicate()) {
    if (Date.now() - startedAt > timeoutMs) throw new Error("Tempo esgotado: " + message);
    await delay(10);
  }
}

async function startReceiver(context, options = {}) {
  const frames = [];
  const rejected = [];
  const serverSockets = [];
  const receiver = createKeyenceTcpReceiver({
    allowedAddresses: ["127.0.0.1"],
    onFrame: (frame) => frames.push(frame),
    onRejectedFrame: (frame) => rejected.push(frame),
    ...options,
  });
  receiver.server.on("connection", (socket) => serverSockets.push(socket));
  const { port } = await receiver.listen(0, "127.0.0.1");
  context.after(() => receiver.close());
  return { receiver, port, frames, rejected, serverSockets };
}

async function connect(context, port) {
  const client = createConnection({ host: "127.0.0.1", port });
  client.setNoDelay(true);
  // client.closed (do stream) fica true depois do evento "close".
  client.on("error", () => {});
  await new Promise((resolve, reject) => { client.once("connect", resolve); client.once("error", reject); });
  context.after(() => client.destroy());
  return client;
}

test("a rota TCP não encerra a conexão ociosa do leitor e aceita o código depois da pausa", async (context) => {
  const { receiver, port, frames, serverSockets } = await startReceiver(context);
  const client = await connect(context, port);
  await waitFor(() => receiver.state().connections_open === 1, "conexão registrada");
  // Sem socket.setTimeout: nenhuma ociosidade derruba a conexão persistente.
  assert.equal(serverSockets[0].timeout, undefined);
  await delay(1200);
  assert.equal(client.closed, false);
  assert.equal(receiver.state().connected, true);
  client.write("7891234567895\r");
  await waitFor(() => frames.length === 1, "código depois da pausa");
  assert.equal(frames[0].barcode, "7891234567895");
  assert.equal(frames[0].noRead, false);
  assert.doesNotMatch(core, /socket\.setTimeout\(/);
  assert.doesNotMatch(worker, /setTimeout\(30000/);
  assert.match(core, /socket\.setKeepAlive\(true, keepAliveInitialDelayMs\)/);
  assert.match(core, /socket\.setNoDelay\(true\)/);
  assert.match(worker, /keepAliveInitialDelayMs: TCP_KEEPALIVE_INITIAL_DELAY_MS/);
  assert.match(worker, /const TCP_KEEPALIVE_INITIAL_DELAY_MS = 10000;/);
});

test("enquadra por CR, CRLF dividido entre pacotes e STX/ETX, e reconhece ERROR", async (context) => {
  let clock = 1000;
  const { receiver, port, frames, rejected, serverSockets } = await startReceiver(context, { now: () => clock });
  const client = await connect(context, port);
  const writes = ["ABC123\r", "DEF4", "56\r", "\nGHI789\r\n", "\x02JKL012\x03", "ERROR\r"];
  for (const chunk of writes) {
    client.write(chunk);
    await delay(20);
    clock += 100;
  }
  await waitFor(() => frames.length === 5, "cinco quadros");
  assert.deepEqual(frames.map((frame) => frame.barcode), ["ABC123", "DEF456", "GHI789", "JKL012", "ERROR"]);
  assert.deepEqual(frames.map((frame) => frame.noRead), [false, false, false, false, true]);
  assert.equal(rejected.length, 0);
  assert.equal(receiver.state().frames_received, 5);
  assert.equal(receiver.state().frames_rejected, 0);

  // O resto sem terminador no fechamento pode ser um código cortado no meio
  // da transmissão: é recusado (não vira código nem produto incorreto) e usa
  // o horário do último pacote recebido, não o do fechamento da conexão.
  clock = 50000;
  client.write("PARCIAL1");
  await waitFor(() => serverSockets[0].bytesRead >= 45, "pacote final recebido");
  clock = 99000;
  client.end();
  await waitFor(() => rejected.length === 1, "resto recusado no fechamento");
  await waitFor(() => receiver.state().connections_open === 0, "conexão encerrada");
  assert.equal(frames.length, 5);
  assert.equal(rejected[0].cause, "quadro sem terminador no fechamento da conexão");
  assert.equal(rejected[0].cause, CLOSE_REMAINDER_CAUSE);
  assert.deepEqual(rejected[0].raw, Buffer.from("PARCIAL1"));
  assert.equal(rejected[0].receivedAtMs, 50000);
  assert.equal(receiver.state().frames_received, 5);
  assert.equal(receiver.state().frames_rejected, 1);
  const record = rejectedFrameRecord(rejected[0]);
  assert.equal(record.cause, CLOSE_REMAINDER_CAUSE);
  assert.equal(record.raw_preview, "PARCIAL1");
  assert.equal(record.received_at_epoch_ms, 50000);
});

test("uma rajada de 200 quadros num único pacote é enfileirada inteira", async (context) => {
  const { receiver, port, frames, rejected } = await startReceiver(context);
  const client = await connect(context, port);
  const codes = Array.from({ length: 200 }, (_, index) => "78912345" + String(index).padStart(5, "0"));
  const payload = codes.map((code) => code + "\r").join("");
  assert.ok(Buffer.byteLength(payload) > 2048);
  client.write(payload);
  await waitFor(() => frames.length === 200, "200 quadros");
  assert.deepEqual(frames.map((frame) => frame.barcode), codes);
  assert.equal(rejected.length, 0);
  assert.equal(client.closed, false);
  const state = receiver.state();
  assert.equal(state.connections_open, 1);
  assert.equal(state.frames_received, 200);
  assert.equal(state.frames_rejected, 0);
});

test("quadros recusados e o resto acima do limite ficam em failed-scans e são contados", async (context) => {
  const failedDirectory = await mkdtemp(join(tmpdir(), "trace-keyence-tcp-failed-"));
  context.after(() => rm(failedDirectory, { recursive: true, force: true }));
  const writes = [];
  const { receiver, port, frames, rejected } = await startReceiver(context, {
    onRejectedFrame: (frame) => {
      rejected.push(frame);
      writes.push(writeScanRecord(failedDirectory, ".rejected-frame.json", rejectedFrameRecord(frame)));
    },
  });
  const client = await connect(context, port);
  client.write(Buffer.concat([
    Buffer.from("AB\r0101\x1D2345\r12\t345\r", "latin1"),
    Buffer.from([0xc3, 0xa9, 0x41, 0x42, 0x43, 0x0d]),
  ]));
  await waitFor(() => rejected.length === 4, "quatro quadros recusados");
  client.write("Z".repeat(2100));
  await waitFor(() => rejected.length === 5, "resto sem terminador acima do limite");
  client.write("TAIL\rOK1234\r");
  await waitFor(() => rejected.length === 6 && frames.length === 1, "continuação recusada e código seguinte aceito");

  assert.deepEqual(rejected.map((frame) => frame.cause), [
    "código com menos de 3 caracteres",
    "caractere de controle no quadro",
    "caractere de controle no quadro",
    "caractere fora do ASCII imprimível",
    "quadro sem terminador acima de 2048 bytes",
    "continuação de quadro acima do limite",
  ]);
  assert.equal(frames[0].barcode, "OK1234");
  assert.equal(client.closed, false);
  assert.equal(receiver.state().frames_rejected, 6);
  assert.equal(receiver.state().frames_received, 1);

  await Promise.all(writes);
  const names = (await readdir(failedDirectory)).sort();
  assert.equal(names.length, 6);
  const records = await Promise.all(names.map(async (name) => JSON.parse(await readFile(join(failedDirectory, name), "utf8"))));
  assert.ok(names.every((name) => name.endsWith(".rejected-frame.json")));
  assert.ok(records.every((record) => record.reason === "quadro inválido" && record.cause && record.received_at));
  const gs = records.find((record) => record.raw_preview === "0101?2345");
  assert.ok(gs);
  assert.deepEqual(Buffer.from(gs.raw_base64, "base64"), Buffer.from("0101\x1D2345", "latin1"));
  const oversize = records.find((record) => record.cause.startsWith("quadro sem terminador"));
  assert.equal(oversize.raw_length, 2100);
  assert.match(worker, /writeScanRecord\(FAILED_SCAN_DIR, "\.rejected-frame\.json", rejectedFrameRecord\(rejected\)\)/);
  assert.match(worker, /setScanError\("Quadro TCP do Keyence rejeitado \(" \+ rejected\.cause \+ "\)\."\)/);
});

test("estado da conexão: origem recusada, conexões abertas, motivo do encerramento e fechamento do worker", async (context) => {
  const blocked = await startReceiver(context, { allowedAddresses: ["10.255.255.1"] });
  const refused = await connect(context, blocked.port);
  await waitFor(() => refused.closed, "conexão de origem não permitida encerrada");
  assert.equal(blocked.receiver.state().connections_open, 0);
  assert.equal(blocked.receiver.state().last_connected_at, null);

  const { receiver, port } = await startReceiver(context);
  assert.deepEqual(receiver.state(), {
    connected: false,
    connections_open: 0,
    last_connected_at: null,
    last_disconnected_at: null,
    last_disconnect_reason: null,
    last_frame_at: null,
    frames_received: 0,
    frames_rejected: 0,
  });
  const first = await connect(context, port);
  await waitFor(() => receiver.state().connections_open === 1, "primeira conexão");
  assert.equal(receiver.state().connected, true);
  assert.ok(Date.parse(receiver.state().last_connected_at) > 0);
  first.write("CODE001\r");
  await waitFor(() => receiver.state().frames_received === 1, "quadro contado");
  assert.ok(Date.parse(receiver.state().last_frame_at) > 0);

  first.end();
  await waitFor(() => receiver.state().connections_open === 0, "primeira conexão encerrada");
  assert.equal(receiver.state().connected, false);
  assert.equal(receiver.state().last_disconnect_reason, "conexão encerrada pelo leitor");
  assert.ok(Date.parse(receiver.state().last_disconnected_at) > 0);
  assert.equal(receiver.state().frames_rejected, 0);

  const second = await connect(context, port);
  await waitFor(() => receiver.state().connections_open === 1, "segunda conexão");
  await receiver.close();
  await waitFor(() => second.closed, "conexão encerrada pelo worker");
  assert.equal(receiver.state().connected, false);
  assert.equal(receiver.state().connections_open, 0);
  assert.equal(receiver.state().last_disconnect_reason, "worker encerrando");
});

test("o prazo da nova tentativa conta do início da tentativa e uma leitura presa numa emergência é reenviada em até 10 s", async (context) => {
  const root = await mkdtemp(join(tmpdir(), "trace-keyence-retry-"));
  context.after(() => rm(root, { recursive: true, force: true }));
  const queueDirectory = join(root, "scans");
  const failedDirectory = join(root, "failed-scans");
  await mkdir(queueDirectory, { recursive: true });
  await mkdir(failedDirectory, { recursive: true });
  await writeFile(join(queueDirectory, "1-a.json"), JSON.stringify({ id: "a" }));
  let clock = 0;
  const emergencyUntil = 40000;
  const attemptsAt = [];
  const retries = new Map();
  const drain = () => drainScanQueue({
    queueDirectory,
    failedDirectory,
    retries,
    now: () => clock,
    register: async () => {
      attemptsAt.push(clock);
      // A resposta leva 300 ms: o prazo não pode somar esse tempo.
      clock += 300;
      if (clock < emergencyUntil) throw Object.assign(new Error("Emergência ativa."), { status: 423 });
      return { data: { id: 1 } };
    },
  });
  // O laço principal chama a fila a cada 100 ms.
  while ((await readdir(queueDirectory)).length > 0 && clock < 120000) {
    await drain();
    clock = Math.ceil((clock + 1) / 100) * 100;
  }
  assert.deepEqual(await readdir(queueDirectory), []);
  // Primeiras 10 tentativas a cada 500 ms (início a início).
  assert.deepEqual(attemptsAt.slice(0, 10), [0, 500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500]);
  const gaps = attemptsAt.slice(1).map((at, index) => at - attemptsAt[index]);
  assert.ok(gaps.every((gap) => gap <= 10000), "nenhum intervalo acima de 10 s: " + gaps.join(","));
  // Emergência encerrada em 40 s: o reenvio sai em até 10 s depois.
  assert.ok(attemptsAt.at(-1) >= 40000 - 300 && attemptsAt.at(-1) <= 50000, "último envio em " + attemptsAt.at(-1));
  assert.equal(retries.size, 0);
});

test("uma conexão nova do leitor substitui a anterior (zumbi atrás do proxy do Docker) depois de processar o que ela entregou", async (context) => {
  const logs = [];
  const { receiver, port, frames, rejected, serverSockets } = await startReceiver(context, {
    log: (level, message, details) => logs.push({ level, message, details }),
  });
  // Atrás do proxy do Docker Desktop um leitor desligado deixa a conexão
  // aberta: o keepalive só enxerga o proxy.
  const zombie = await connect(context, port);
  zombie.write("ZUMBI01\r");
  await waitFor(() => frames.length === 1, "código da conexão antiga");

  // O último pacote da conexão antiga e a conexão nova chegam juntos. A
  // leitura que o laço de eventos faz na mesma fase do accept é simulada com
  // push logo depois do handler do receptor: com a destruição imediata ela se
  // perderia. O resto sem terminador vira quadro recusado, não código.
  zombie.write("ANTES01\r");
  await waitFor(() => frames.length === 2, "segundo código da conexão antiga");

  // Uma conexão que só abre e fica calada (sonda local, teste) não derruba a
  // do leitor: a troca só acontece quando a conexão nova entrega dados.
  const fresh = createConnection({ host: "127.0.0.1", port });
  fresh.on("error", () => {});
  context.after(() => fresh.destroy());
  await new Promise((resolve, reject) => { fresh.once("connect", resolve); fresh.once("error", reject); });
  await waitFor(() => serverSockets.length === 2, "conexão nova aceita");
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(zombie.closed, false);
  assert.equal(receiver.state().connections_open, 2);

  // O último pacote da conexão antiga chega na mesma fase do primeiro pacote
  // da nova (simulado com push logo depois do handler do receptor): com a
  // destruição imediata ele se perderia. O resto sem terminador vira quadro
  // recusado, não código.
  serverSockets[1].once("data", () => serverSockets[0].push(Buffer.from("ULTIMO1\rCORTA")));
  fresh.write("NOVA001\r");
  await waitFor(() => zombie.closed, "conexão antiga encerrada pelo worker");
  await waitFor(() => rejected.length === 1, "resto da conexão antiga preservado");
  await waitFor(() => frames.length === 4, "código da conexão nova");

  const porConexao = (id) => frames.filter((frame) => frame.connectionId === id).map((frame) => frame.barcode);
  assert.deepEqual(porConexao(1), ["ZUMBI01", "ANTES01", "ULTIMO1"]);
  assert.deepEqual(porConexao(2), ["NOVA001"]);
  assert.equal(rejected[0].cause, CLOSE_REMAINDER_CAUSE);
  assert.deepEqual(rejected[0].raw, Buffer.from("CORTA"));
  assert.equal(fresh.closed, false);
  let state = receiver.state();
  assert.equal(state.connected, true);
  assert.equal(state.connections_open, 1);
  assert.equal(state.last_disconnect_reason, "substituída por nova conexão do leitor");
  assert.equal(state.last_disconnect_reason, REPLACED_CONNECTION_REASON);
  assert.ok(logs.some((entry) => entry.level === "warn"
    && entry.message === "Conexão TCP anterior do leitor substituída pela nova."
    && entry.details.connection_id === 1 && entry.details.replaced_by === 2));

  // Reconexões seguidas não acumulam conexões abertas.
  const third = await connect(context, port);
  third.write("TERCEIRA1\r");
  await waitFor(() => fresh.closed, "segunda conexão substituída");
  await waitFor(() => frames.length === 5, "código da terceira conexão");
  state = receiver.state();
  assert.equal(state.connections_open, 1);
  assert.equal(state.frames_received, 5);
  assert.equal(state.frames_rejected, 1);
  assert.equal(state.last_disconnect_reason, REPLACED_CONNECTION_REASON);
  assert.equal(third.closed, false);
});

test("tcp_route traz sempre as mesmas chaves, limita os carregamentos e mantém scan_error até registro ou 10 minutos", () => {
  let clock = Date.parse("2026-11-26T10:00:00.000Z");
  const stats = createTcpRouteStats({ now: () => clock });
  const empty = stats.snapshot(undefined, 0);
  assert.deepEqual(Object.keys(empty), TCP_ROUTE_KEYS);
  assert.equal(empty.connected, false);
  assert.equal(empty.frames_received, 0);
  assert.equal(empty.scan_error, null);
  assert.deepEqual(empty.unassociated_by_loading, []);

  for (let loadingId = 1; loadingId <= 7; loadingId += 1) {
    stats.recordUnassociated({ loadingId, loadingState: "CARREGANDO", barcode: "COD" + loadingId, reason: "sem passagem disponível", receivedAtMs: clock + loadingId });
  }
  stats.recordUnassociated({ loadingId: 5, loadingState: "CARREGANDO", barcode: "X\x1D".repeat(100), noRead: false, reason: "r".repeat(200), receivedAtMs: clock + 99 });
  stats.recordUnassociated({ loadingId: null, loadingState: null, barcode: "", noRead: true, reason: "sem carregamento ativo" });
  const snapshot = stats.snapshot({ connected: true, connections_open: 1, frames_received: 3 }, 4);
  assert.deepEqual(Object.keys(snapshot), TCP_ROUTE_KEYS);
  assert.equal(snapshot.scans_unassociated, 9);
  assert.equal(snapshot.failed_scans_pending, 4);
  assert.equal(snapshot.connected, true);
  assert.deepEqual(snapshot.unassociated_by_loading.map((entry) => entry.loading_id), [null, 5, 7, 6, 4]);
  const loadingFive = snapshot.unassociated_by_loading[1];
  assert.equal(loadingFive.count, 2);
  assert.deepEqual(Object.keys(loadingFive.last), ["barcode", "no_read", "reason", "received_at", "loading_state"]);
  assert.equal(loadingFive.last.barcode.length, 128);
  assert.match(loadingFive.last.barcode, /^[\x20-\x7E]+$/);
  assert.equal(loadingFive.last.reason.length, 120);
  assert.equal(loadingFive.last.received_at, new Date(clock + 99).toISOString());
  assert.equal(loadingFive.last.loading_state, "CARREGANDO");
  // Sem carregamento ativo: preservado e contado em scans_unassociated, mas
  // fora do contador ("count") da tela de operação.
  assert.equal(snapshot.unassociated_by_loading[0].last.no_read, true);
  assert.equal(snapshot.unassociated_by_loading[0].count, 0);
  assert.equal(snapshot.unassociated_by_loading[0].last.loading_state, null);

  // Fora de CARREGANDO (pausa para retirar a saca, finalização) o código
  // atualiza o "last" com o estado, mas não aumenta o "count".
  for (const loadingState of ["PAUSADO", "FINALIZANDO", "FINALIZADO", "EMERGENCIA", "OUTRO", null, undefined, 7]) {
    stats.recordUnassociated({ loadingId: 5, loadingState, barcode: "PAUSA01", reason: "sem passagem disponível", receivedAtMs: clock + 200 });
  }
  let loadingFiveAfter = stats.snapshot().unassociated_by_loading[0];
  assert.equal(loadingFiveAfter.loading_id, 5);
  assert.equal(loadingFiveAfter.count, 2);
  assert.equal(loadingFiveAfter.last.barcode, "PAUSA01");
  assert.equal(loadingFiveAfter.last.loading_state, null);
  stats.recordUnassociated({ loadingId: 5, loadingState: "PAUSADO", barcode: "PAUSA02", receivedAtMs: clock + 201 });
  loadingFiveAfter = stats.snapshot().unassociated_by_loading[0];
  assert.equal(loadingFiveAfter.count, 2);
  assert.equal(loadingFiveAfter.last.loading_state, "PAUSADO");
  assert.equal(stats.snapshot().scans_unassociated, 18);
  stats.recordUnassociated({ loadingId: 5, loadingState: "CARREGANDO", barcode: "CARGA01", receivedAtMs: clock + 202 });
  assert.equal(stats.snapshot().unassociated_by_loading[0].count, 3);

  // Leitura associada e recusada pelo Trace: entra no carregamento dela (com
  // o motivo da recusa) e em scans_rejected_by_trace, não em scans_unassociated.
  stats.recordRejectedByTrace({
    loadingId: 6,
    loadingState: "CARREGANDO",
    barcode: "7891234567895",
    reason: traceRejectionReason(Object.assign(new Error("Passagem já registrada."), { status: 409 })),
    receivedAtMs: clock + 300,
  });
  stats.recordRejectedByTrace({ loadingId: 6, loadingState: "PAUSADO", barcode: "7891234567896", reason: "recusada pelo Trace: HTTP 422", receivedAtMs: clock + 301 });
  let rejectedSnapshot = stats.snapshot();
  assert.equal(rejectedSnapshot.scans_rejected_by_trace, 2);
  assert.equal(rejectedSnapshot.scans_unassociated, 19);
  const loadingSix = rejectedSnapshot.unassociated_by_loading[0];
  assert.equal(loadingSix.loading_id, 6);
  assert.equal(loadingSix.count, 2);
  assert.equal(loadingSix.last.barcode, "7891234567896");
  assert.equal(loadingSix.last.loading_state, "PAUSADO");
  stats.recordRejectedByTrace({ loadingId: 6, loadingState: "CARREGANDO", barcode: "7891234567897", reason: traceRejectionReason(Object.assign(new Error("Passagem já registrada."), { status: 409 })), receivedAtMs: clock + 302 });
  rejectedSnapshot = stats.snapshot();
  assert.equal(rejectedSnapshot.unassociated_by_loading[0].count, 3);
  assert.equal(rejectedSnapshot.unassociated_by_loading[0].last.reason, "recusada pelo Trace: HTTP 409 - Passagem já registrada.");
  // Sem detalhes, só o contador geral muda (compatível com o uso anterior).
  stats.recordRejectedByTrace();
  assert.equal(stats.snapshot().scans_rejected_by_trace, 4);
  assert.deepEqual(stats.snapshot().unassociated_by_loading, rejectedSnapshot.unassociated_by_loading);

  assert.equal(traceRejectionReason(Object.assign(new Error("Trace respondeu HTTP 404 em /api/leituras.php"), { status: 404 })), "recusada pelo Trace: HTTP 404");
  assert.equal(traceRejectionReason(new Error("Código\x1D\x1Dinválido\n")), "recusada pelo Trace: erro - Código inválido");
  assert.ok(traceRejectionReason(Object.assign(new Error("m".repeat(300)), { status: 422 })).length <= 120);
  assert.equal(normalizeLoadingState(" CARREGANDO "), "CARREGANDO");
  assert.equal(normalizeLoadingState(""), null);
  assert.equal(normalizeLoadingState(null), null);
  assert.equal(normalizeLoadingState(5), null);
  assert.equal(normalizeLoadingState("X".repeat(60)).length, 40);

  stats.setScanError("Código Keyence não associado (sem passagem disponível).");
  assert.equal(stats.snapshot().scan_error, "Código Keyence não associado (sem passagem disponível).");
  assert.equal(stats.snapshot().scan_error_at, new Date(clock).toISOString());
  stats.recordRegistered();
  assert.equal(stats.snapshot().scan_error, null);
  assert.equal(stats.snapshot().scans_registered, 1);

  stats.setScanError("Leitura Keyence rejeitada pelo Trace (HTTP 409).");
  stats.recordRejectedByTrace();
  clock += 599999;
  assert.equal(stats.snapshot().scan_error, "Leitura Keyence rejeitada pelo Trace (HTTP 409).");
  clock += 1;
  assert.equal(stats.snapshot().scan_error, null);
  assert.equal(stats.snapshot().scan_error_at, null);
  assert.equal(stats.snapshot().scans_rejected_by_trace, 5);

  assert.match(worker, /tcp_route: tcpRouteSnapshot\(\),\n\s+\.\.\.\(archiveUnavailable/);
  assert.match(worker, /scan_queue_directory: SCAN_QUEUE_DIR,\n\s+tcp_route: tcpRouteSnapshot\(\),/);
  assert.match(worker, /await refreshFailedScansPending\(\);\n\s+await apiRequest\("\/api\/device_heartbeat\.php"/);
});

test("quadros em trânsito sobrevivem ao reinício e vão para failed-scans", async (context) => {
  const root = await mkdtemp(join(tmpdir(), "trace-keyence-in-flight-"));
  context.after(() => rm(root, { recursive: true, force: true }));
  const inFlightDirectory = join(root, "in-flight-scans");
  const failedDirectory = join(root, "failed-scans");
  const queueDirectory = join(root, "scans");
  await mkdir(queueDirectory, { recursive: true });
  const pending = await writeInFlightScan(inFlightDirectory, {
    offlineId: "11111111-1111-4111-8111-111111111111", barcode: "7891234567895", noRead: false, receivedAtMs: 1795116546864, connectionId: 3,
  });
  await writeInFlightScan(inFlightDirectory, {
    offlineId: "22222222-2222-4222-8222-222222222222", barcode: "ignorado", noRead: true, receivedAtMs: 1795116547000,
  });
  // Este já chegou à fila; só a cópia em trânsito ficou para trás.
  await writeInFlightScan(inFlightDirectory, {
    offlineId: "33333333-3333-4333-8333-333333333333", barcode: "JAFILA1", noRead: false, receivedAtMs: 1795116548000,
  });
  await writeFile(join(queueDirectory, "1795116548100-33333333-3333-4333-8333-333333333333.json"),
    JSON.stringify({ offline_id: "33333333-3333-4333-8333-333333333333", barcode: "JAFILA1", loading_id: 9, sensor_event_id: 70 }));
  // O worker parou entre gravar o .tmp e o rename: a leitura nunca chegou à
  // fila. O .tmp órfão é removido e a cópia em trânsito vai para failed-scans.
  await writeInFlightScan(inFlightDirectory, {
    offlineId: "44444444-4444-4444-8444-444444444444", barcode: "SOTMP01", noRead: false, receivedAtMs: 1795116548500,
  });
  await writeFile(join(queueDirectory, "1795116548600-44444444-4444-4444-8444-444444444444.json.tmp"),
    JSON.stringify({ offline_id: "44444444-4444-4444-8444-444444444444", barcode: "SOTMP01" }));
  // Gravação interrompida em failed-scans (.json truncado) e cópia .invalid
  // não substituem a cópia em trânsito.
  await mkdir(failedDirectory, { recursive: true });
  await writeInFlightScan(inFlightDirectory, {
    offlineId: "55555555-5555-4555-8555-555555555555", barcode: "TRUNCA1", noRead: false, receivedAtMs: 1795116548700,
  });
  await writeFile(join(failedDirectory, "1795116548800-55555555-5555-4555-8555-555555555555.unassociated.json"), "{\"offline_id\":\"5555");
  await writeInFlightScan(inFlightDirectory, {
    offlineId: "66666666-6666-4666-8666-666666666666", barcode: "INVALI1", noRead: false, receivedAtMs: 1795116548900,
  });
  await writeFile(join(failedDirectory, "1795116549000-66666666-6666-4666-8666-666666666666.json.invalid"),
    JSON.stringify({ offline_id: "66666666-6666-4666-8666-666666666666" }));
  await writeFile(join(inFlightDirectory, "1795116549000-corrompido.json"), "{\"barc");
  assert.ok(pending.endsWith("1795116546864-11111111-1111-4111-8111-111111111111.json"));

  const logs = [];
  const recovered = await recoverInFlightScans({
    inFlightDirectory, failedDirectory, queueDirectory, reason: "worker reiniciado antes da associação",
    log: (level, message, details) => logs.push({ level, message, details }),
  });
  assert.equal(recovered.length, 6);
  assert.deepEqual(await readdir(inFlightDirectory), []);
  assert.deepEqual(await readdir(queueDirectory), ["1795116548100-33333333-3333-4333-8333-333333333333.json"]);
  assert.deepEqual(logs, [{
    level: "warn",
    message: "Arquivo temporário órfão removido da fila de leituras Keyence.",
    details: { file: "1795116548600-44444444-4444-4444-8444-444444444444.json.tmp" },
  }]);
  const names = (await readdir(failedDirectory)).sort();
  assert.equal(names.filter((name) => name.endsWith(".interrupted.json")).length, 5);
  assert.equal(names.filter((name) => name.endsWith(".interrupted.invalid")).length, 1);
  const records = await Promise.all(names.filter((name) => name.endsWith(".interrupted.json"))
    .map(async (name) => JSON.parse(await readFile(join(failedDirectory, name), "utf8"))));
  const code = records.find((record) => record.barcode === "7891234567895");
  assert.equal(code.reason, "worker reiniciado antes da associação");
  assert.equal(code.offline_id, "11111111-1111-4111-8111-111111111111");
  assert.equal(code.received_at_epoch_ms, 1795116546864);
  const noRead = records.find((record) => record.no_read === true);
  assert.equal(noRead.barcode, "");
  assert.deepEqual(records.map((record) => record.barcode).sort(), ["", "7891234567895", "INVALI1", "SOTMP01", "TRUNCA1"]);
  assert.ok(!records.some((record) => record.barcode === "JAFILA1"));
  assert.deepEqual(await recoverInFlightScans({
    inFlightDirectory, failedDirectory, queueDirectory, reason: "worker reiniciado antes da associação",
  }), []);
  assert.match(worker, /reason: "worker reiniciado antes da associação",\n\s+log,\n\s+\}\);/);

  assert.match(worker, /writeInFlightScan\(IN_FLIGHT_SCAN_DIR, scan\)/);
  assert.match(worker, /reason: "worker reiniciado antes da associação"/);
  assert.ok(worker.indexOf("recoverInFlightScans({") < worker.indexOf("await startTcpReceiver(tcpAllowedAddresses)"));
  assert.match(worker, /if \(preserved\) await releaseInFlightScan\(inFlightWrite\);/);
  assert.match(worker, /reservedSensorEventIds\.add\(sensorEventId\);\n\s+await releaseInFlightScan\(inFlightWrite\);/);
});

test("um erro temporário mantém só aquela leitura na fila e as seguintes são registradas", async (context) => {
  const root = await mkdtemp(join(tmpdir(), "trace-keyence-queue-"));
  context.after(() => rm(root, { recursive: true, force: true }));
  const queueDirectory = join(root, "scans");
  const failedDirectory = join(root, "failed-scans");
  await mkdir(queueDirectory, { recursive: true });
  await mkdir(failedDirectory, { recursive: true });
  const enqueue = (name, event) => writeFile(join(queueDirectory, name), JSON.stringify(event));
  const httpError = (status) => Object.assign(new Error("HTTP " + status), { status });
  let clock = 10000;
  const retries = new Map();
  const outcomes = {};
  const attempted = [];
  const drain = () => drainScanQueue({
    queueDirectory,
    failedDirectory,
    retries,
    now: () => clock,
    register: async (event) => {
      attempted.push(event.id);
      const outcome = outcomes[event.id];
      if (typeof outcome === "number") throw httpError(outcome);
      return { data: { id: event.id } };
    },
  });

  await enqueue("1-a.json", { id: "a" });
  await enqueue("2-b.json", { id: "b" });
  await enqueue("3-c.json", { id: "c" });
  outcomes.a = 503;
  assert.deepEqual(await drain(), { registered: 2, rejected: 0, retryable: 1, deferred: 0, invalid: 0 });
  assert.deepEqual(attempted, ["a", "b", "c"]);
  assert.deepEqual(await readdir(queueDirectory), ["1-a.json"]);
  assert.deepEqual(retries.get("1-a.json"), { attempts: 1, nextAttemptAt: 10500 });

  // Dentro do prazo de nova tentativa, o arquivo preso não consome requisições.
  attempted.length = 0;
  assert.equal((await drain()).deferred, 1);
  assert.deepEqual(attempted, []);
  clock = 10500;
  outcomes.a = undefined;
  assert.equal((await drain()).registered, 1);
  assert.deepEqual(await readdir(queueDirectory), []);
  assert.equal(retries.size, 0);

  // 429 é o limite do nginx: a passada para sem insistir nos demais.
  attempted.length = 0;
  await enqueue("4-d.json", { id: "d" });
  await enqueue("5-e.json", { id: "e" });
  outcomes.d = 429;
  assert.equal((await drain()).retryable, 1);
  assert.deepEqual(attempted, ["d"]);

  // Falhas temporárias seguidas indicam Trace indisponível: a passada para.
  clock = 20000;
  attempted.length = 0;
  outcomes.d = 502;
  outcomes.e = 502;
  await enqueue("6-f.json", { id: "f" });
  assert.equal((await drain()).retryable, 2);
  assert.deepEqual(attempted, ["d", "e"]);

  // Recusa definitiva vai para failed-scans e não bloqueia a fila.
  clock = 30000;
  attempted.length = 0;
  outcomes.d = 409;
  outcomes.e = undefined;
  assert.deepEqual(await drain(), { registered: 2, rejected: 1, retryable: 0, deferred: 0, invalid: 0 });
  assert.deepEqual(await readdir(failedDirectory), ["4-d.json"]);
  assert.deepEqual(await readdir(queueDirectory), []);

  // 500 ms enquanto a passagem pode estar aberta (~5 s), depois backoff até 10 s.
  assert.deepEqual([1, 5, 10, 11, 12, 13, 14, 15, 30, 2000].map(scanRetryDelayMs),
    [500, 500, 500, 1000, 2000, 4000, 8000, 10000, 10000, 10000]);
  assert.equal(Array.from({ length: 10 }, (_, index) => scanRetryDelayMs(index + 1)).reduce((sum, value) => sum + value, 0), 5000);
  assert.doesNotMatch(worker, /setError\(error\);\n\s+break;/);
  assert.match(worker, /await drainScanQueue\(\{/);
  assert.match(worker, /tcpRouteStats\.recordRegistered\(\);/);
  assert.match(worker, /tcpRouteStats\.recordRejectedByTrace\(\{\n\s+loadingId,\n\s+loadingState,/);
  assert.match(worker, /reason: traceRejectionReason\(error\),/);
});

async function freePort(host = "127.0.0.1") {
  const server = createNetServer();
  await new Promise((resolve, reject) => { server.once("error", reject); server.listen(0, host, resolve); });
  const { port } = server.address();
  await new Promise((resolve) => server.close(resolve));
  return port;
}

async function portAvailable(port, host) {
  const server = createNetServer();
  try {
    await new Promise((resolve, reject) => { server.once("error", reject); server.listen(port, host, resolve); });
    await new Promise((resolve) => server.close(resolve));
    return true;
  } catch {
    return false;
  }
}

test("worker real: sem carregamento ou fora de CARREGANDO não gera scan_error nem conta; a recusa do Trace entra no carregamento", async (context) => {
  if (process.platform === "win32") {
    context.skip("o ping falso do teste usa /bin/sh");
    return;
  }
  // A saúde do worker usa a porta fixa 8091 (a mesma do healthcheck do compose).
  if (!(await portAvailable(8091, "0.0.0.0"))) {
    context.skip("porta 8091 ocupada: o worker real não pode abrir a saúde HTTP");
    return;
  }
  const root = await mkdtemp(join(tmpdir(), "trace-keyence-worker-real-"));
  context.after(() => rm(root, { recursive: true, force: true }));
  // ping falso: a verificação ICMP do leitor não depende da rede do teste.
  await mkdir(join(root, "bin"));
  await writeFile(join(root, "bin", "ping"), "#!/bin/sh\nexit 0\n");
  await chmod(join(root, "bin", "ping"), 0o755);

  let status = { active_loading_id: null, active_loading_state: null, pending_sensor_event_ids: [] };
  let readingResponse = { status: 409, body: { error: "Passagem já registrada." } };
  const statusRequests = [];
  const readings = [];
  const api = createHttpServer(async (request, response) => {
    let body = "";
    for await (const chunk of request) body += chunk;
    const reply = (code, payload) => {
      response.writeHead(code, { "Content-Type": "application/json" });
      response.end(JSON.stringify(payload));
    };
    if (request.url === "/api/keyence_worker.php") {
      const payload = JSON.parse(body || "{}");
      if (payload.action !== "STATUS") return reply(200, { data: [], meta: { next_after_id: null } });
      statusRequests.push(payload);
      return reply(200, { data: { equipment_id: 2, pending_sensor_event_id: status.pending_sensor_event_ids[0] ?? null, ...status } });
    }
    if (request.url === "/api/leituras.php") {
      readings.push(JSON.parse(body));
      return reply(readingResponse.status, readingResponse.body);
    }
    if (request.url === "/api/device_heartbeat.php") return reply(200, { data: { accepted: true } });
    return reply(404, { error: "rota desconhecida" });
  });
  await new Promise((resolve) => api.listen(0, "127.0.0.1", resolve));
  context.after(() => { api.closeAllConnections(); api.close(); });

  const tcpPort = await freePort();
  const ftpPort = await freePort();
  const passivePort = await freePort();
  const inbox = join(root, "inbox");
  const output = [];
  const child = spawn(process.execPath, [fileURLToPath(new URL("../integracoes/keyence-worker/keyence-worker.mjs", import.meta.url))], {
    env: {
      ...process.env,
      PATH: join(root, "bin") + delimiter + (process.env.PATH || ""),
      KEYENCE_ENABLED: "1",
      TRACE_API_URL: `http://127.0.0.1:${api.address().port}`,
      KEYENCE_DEVICE_TOKEN: "token-de-teste",
      KEYENCE_FTP_PASSWORD: "senha-de-teste",
      KEYENCE_FTP_PASV_ADDRESS: "127.0.0.1",
      KEYENCE_FTP_ALLOWED_ADDRESS: "127.0.0.1",
      KEYENCE_TCP_ALLOWED_ADDRESS: "127.0.0.1",
      KEYENCE_TCP_PORT: String(tcpPort),
      KEYENCE_FTP_PORT: String(ftpPort),
      KEYENCE_FTP_PASSIVE_START: String(passivePort),
      KEYENCE_FTP_PASSIVE_END: String(passivePort),
      KEYENCE_SERVICE_BIND: "127.0.0.1",
      KEYENCE_INBOX_DIR: inbox,
      KEYENCE_IMAGE_ARCHIVE_DIRS: "",
      KEYENCE_IMAGE_ARCHIVE_DIR: "",
      KEYENCE_ARCHIVE_RECOVERY_ENABLED: "0",
      KEYENCE_POLL_MS: "100",
      KEYENCE_HEARTBEAT_MS: "60000",
      KEYENCE_ASSOCIATION_WAIT_MS: "100",
      KEYENCE_ASSOCIATION_UNAVAILABLE_WAIT_MS: "1000",
    },
    stdio: ["ignore", "pipe", "pipe"],
  });
  child.stdout.on("data", (chunk) => output.push(String(chunk)));
  child.stderr.on("data", (chunk) => output.push(String(chunk)));
  const exited = new Promise((resolve) => child.once("exit", resolve));
  context.after(async () => {
    child.kill("SIGTERM");
    const timer = setTimeout(() => child.kill("SIGKILL"), 3000);
    await exited;
    clearTimeout(timer);
  });
  await waitFor(() => output.join("").includes("Entrada TCP e recepção FTP do Keyence ativas."), "worker iniciado: " + output.join(""), 10000);

  let route = null;
  const refreshRoute = async () => {
    const health = await (await fetch("http://127.0.0.1:8091/health")).json();
    route = health.tcp_route;
    return route;
  };
  const waitRoute = async (predicate, message) => {
    const startedAt = Date.now();
    for (;;) {
      if (predicate(await refreshRoute())) return route;
      if (Date.now() - startedAt > 8000) throw new Error("Tempo esgotado: " + message + " " + JSON.stringify(route));
      await delay(50);
    }
  };
  const entry = (loadingId) => route.unassociated_by_loading.find((item) => item.loading_id === loadingId);
  const failedRecords = async () => Promise.all((await readdir(join(inbox, "failed-scans"))).sort()
    .map(async (name) => ({ name, ...JSON.parse(await readFile(join(inbox, "failed-scans", name), "utf8")) })));
  // O contador é atualizado antes de a cópia em failed-scans terminar de ser gravada.
  const failedRecord = async (barcode) => {
    const startedAt = Date.now();
    for (;;) {
      const found = (await failedRecords().catch(() => [])).find((record) => record.barcode === barcode);
      if (found) return found;
      if (Date.now() - startedAt > 5000) throw new Error("Tempo esgotado: " + barcode + " em failed-scans");
      await delay(20);
    }
  };

  const reader = await connect(context, tcpPort);

  // 1. Sem carregamento ativo: preservado, sem scan_error e fora do contador.
  reader.write("SEMCARGA1\r");
  await waitRoute((current) => current.scans_unassociated === 1, "código sem carregamento");
  assert.equal(route.scan_error, null);
  assert.equal(entry(null).count, 0);
  assert.equal(entry(null).last.loading_state, null);
  assert.equal(entry(null).last.reason, "sem carregamento ativo");
  const noLoadingRecord = await failedRecord("SEMCARGA1");
  assert.match(noLoadingRecord.name, /\.unassociated\.json$/);
  assert.equal(noLoadingRecord.reason, "sem carregamento ativo");
  assert.equal(noLoadingRecord.loading_state, null);

  // 2. Carregamento PAUSADO (retirada de saca): idem, com o estado no "last".
  status = { active_loading_id: 42, active_loading_state: "PAUSADO", pending_sensor_event_ids: [] };
  reader.write("PAUSA0001\r");
  await waitRoute((current) => current.scans_unassociated === 2, "código com o carregamento pausado");
  assert.equal(route.scan_error, null);
  assert.equal(entry(42).count, 0);
  assert.equal(entry(42).last.barcode, "PAUSA0001");
  assert.equal(entry(42).last.loading_state, "PAUSADO");
  assert.equal(entry(42).last.reason, "sem passagem disponível");
  assert.equal((await failedRecord("PAUSA0001")).loading_state, "PAUSADO");
  assert.ok(statusRequests.some((request) => Number.isSafeInteger(request.scanner_received_at_epoch_ms)));

  // 3. Em CARREGANDO sem passagem: falha operacional, conta e gera scan_error.
  status = { active_loading_id: 42, active_loading_state: "CARREGANDO", pending_sensor_event_ids: [] };
  reader.write("CARGA0001\r");
  await waitRoute((current) => current.scans_unassociated === 3, "código sem passagem em CARREGANDO");
  assert.equal(entry(42).count, 1);
  assert.equal(entry(42).last.loading_state, "CARREGANDO");
  assert.equal(route.scan_error, "Código Keyence não associado (sem passagem disponível).");

  // 4. Associada e recusada pelo Trace (409) em CARREGANDO: entra no contador
  // do carregamento com o motivo, e não em scans_unassociated.
  status = { active_loading_id: 42, active_loading_state: "CARREGANDO", pending_sensor_event_ids: [7] };
  reader.write("7891234567895\r");
  await waitRoute((current) => current.scans_rejected_by_trace === 1, "leitura recusada pelo Trace");
  assert.equal(route.scans_unassociated, 3);
  assert.equal(entry(42).count, 2);
  assert.equal(entry(42).last.barcode, "7891234567895");
  assert.equal(entry(42).last.reason, "recusada pelo Trace: HTTP 409 - Passagem já registrada.");
  assert.equal(entry(42).last.loading_state, "CARREGANDO");
  assert.equal(readings.length, 1);
  assert.equal(readings[0].carregamento_id, 42);
  assert.equal(readings[0].sensor_event_id, 7);
  assert.equal(readings[0].barcode, "7891234567895");
  assert.equal(Object.hasOwn(readings[0], "loading_state"), false);
  const rejectedQueueFile = await failedRecord("7891234567895");
  assert.equal(rejectedQueueFile.loading_state, "CARREGANDO");
  assert.equal(rejectedQueueFile.sensor_event_id, 7);

  // 5. Recusa de leitura recebida em FINALIZANDO: atualiza o "last", não conta.
  status = { active_loading_id: 42, active_loading_state: "FINALIZANDO", pending_sensor_event_ids: [8] };
  readingResponse = { status: 422, body: { error: "Carregamento em finalização." } };
  reader.write("7891234567896\r");
  await waitRoute((current) => current.scans_rejected_by_trace === 2, "segunda leitura recusada");
  assert.equal(entry(42).count, 2);
  assert.equal(entry(42).last.loading_state, "FINALIZANDO");
  assert.equal(entry(42).last.reason, "recusada pelo Trace: HTTP 422 - Carregamento em finalização.");
  assert.equal(route.scans_unassociated, 3);
  assert.equal(route.connections_open, 1);
});
