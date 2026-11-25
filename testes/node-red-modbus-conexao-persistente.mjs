import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import { readFile } from "node:fs/promises";
import test from "node:test";

// A leitura do M2052 mantém uma conexão Modbus TCP aberta por CLP em vez de
// abrir e fechar uma a cada consulta (~7–17 ms e dezenas de conexões/s no CLP).

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const requestNode = flow.find((node) => node.id === "modbus-request");

function harness() {
  const sockets = [];
  const sent = [];
  const errors = [];
  const timers = [];
  const store = new Map();
  // O context do Node-RED trata pontos na chave como caminho aninhado; recuse
  // qualquer chave com ponto para o teste pegar esse erro.
  const guard = (key) => { if (String(key).includes(".")) throw new Error(`chave de context com ponto: ${key}`); };
  const context = {
    get: (key) => { guard(key); return store.get(key); },
    set: (key, value) => { guard(key); return value === undefined ? store.delete(key) : store.set(key, value); },
    keys: () => [...store.keys()],
  };
  const openConnections = () => store.get("modbusSensorConnections")?.size ?? 0;
  const createSocket = () => {
    const socket = new EventEmitter();
    socket.writes = [];
    socket.destroyed = false;
    socket.connect = (port, host, onConnect) => { socket.target = `${host}:${port}`; socket.onConnect = onConnect; };
    socket.write = (data, callback) => { socket.writes.push(Buffer.from(data)); callback?.(); };
    socket.destroy = () => { socket.destroyed = true; };
    socket.setNoDelay = () => {};
    socket.setKeepAlive = () => {};
    sockets.push(socket);
    return socket;
  };
  const run = new Function("msg", "global", "node", "setTimeout", "clearTimeout", "Date", "context", requestNode.func);
  const finalize = new Function("context", requestNode.finalize);
  const node = { send: (msg) => sent.push(msg), error: (error, msg) => errors.push({ error, msg }) };
  const setTimer = (callback, delay) => { const timer = { callback, delay, cleared: false }; timers.push(timer); return timer; };
  const clearTimer = (timer) => { if (timer) timer.cleared = true; };
  const request = (transaction, extra = {}) => run(
    {
      payload: Buffer.from([transaction >> 8, transaction & 0xff, 0, 0, 0, 6, 1, 1, 8, 4, 0, 1]),
      host: "10.0.0.10", port: 502, modbusPurpose: "sensor", equipmentId: 17, modbusStartedAt: Date.now(), ...extra,
    },
    { get: () => createSocket },
    node,
    setTimer,
    clearTimer,
    Date,
    context,
  );
  const response = (transaction, value = 1) => Buffer.from([transaction >> 8, transaction & 0xff, 0, 0, 0, 4, 1, 1, 1, value]);
  return { sockets, sent, errors, timers, store, openConnections, request, response, finalize: () => finalize(context) };
}

test("a leitura do sensor reaproveita a mesma conexão entre consultas", () => {
  const h = harness();
  h.request(1);
  assert.equal(h.sockets.length, 1);
  assert.equal(h.sockets[0].writes.length, 0, "só escreve depois de conectar");
  h.sockets[0].onConnect();
  assert.equal(h.sockets[0].writes.length, 1);
  h.sockets[0].emit("data", h.response(1));
  assert.equal(h.sent.length, 1);
  assert.equal(h.sent[0].payload.readUInt16BE(0), 1);
  assert.ok(Number.isFinite(h.sent[0].modbusRequestSentAt));
  assert.ok(Number.isFinite(h.sent[0].modbusResponseAt));

  h.request(2);
  h.sockets[0].emit("data", h.response(2));
  h.request(3);
  assert.equal(h.sockets.length, 1, "nenhuma conexão nova por consulta");
  assert.equal(h.sockets[0].writes.length, 3);
  assert.equal(h.sockets[0].destroyed, false);
  assert.equal(h.timers.filter((timer) => !timer.cleared).length, 1);
});

test("resposta partida no TCP é remontada antes de ser entregue", () => {
  const h = harness();
  h.request(10);
  h.sockets[0].onConnect();
  const frame = h.response(10, 1);
  h.sockets[0].emit("data", frame.subarray(0, 4));
  h.sockets[0].emit("data", frame.subarray(4, 7));
  assert.equal(h.sent.length, 0);
  h.sockets[0].emit("data", frame.subarray(7));
  assert.equal(h.sent.length, 1);
  assert.equal(h.sent[0].payload[9], 1);
  assert.equal(h.timers.every((timer) => timer.cleared), true);
});

test("duas Dalas no mesmo CLP: uma consulta por vez na conexão", () => {
  const h = harness();
  h.request(60, { equipmentId: 17 });
  h.request(61, { equipmentId: 18 });
  h.sockets[0].onConnect();
  assert.equal(h.sockets.length, 1);
  assert.equal(h.sockets[0].writes.length, 1, "a segunda espera a resposta da primeira");
  h.sockets[0].emit("data", h.response(60, 1));
  assert.equal(h.sockets[0].writes.length, 2);
  assert.equal(h.sockets[0].writes[1].readUInt16BE(0), 61);
  h.sockets[0].emit("data", h.response(61, 0));
  assert.deepEqual(h.sent.map((msg) => [msg.equipmentId, msg.payload[9]]), [[17, 1], [18, 0]]);
});

test("CLPs em redes diferentes têm conexões separadas", () => {
  const h = harness();
  h.request(70, { host: "192.168.0.10" });
  h.request(71, { host: "10.0.0.5" });
  assert.equal(h.sockets.length, 2);
  assert.deepEqual(h.sockets.map((socket) => socket.target), ["192.168.0.10:502", "10.0.0.5:502"]);
  assert.equal(h.openConnections(), 2);
  h.finalize();
  assert.equal(h.sockets.every((socket) => socket.destroyed), true);
});

test("consulta sem resposta derruba a conexão e a próxima reconecta", () => {
  const h = harness();
  h.request(20);
  h.sockets[0].onConnect();
  assert.equal(h.timers[0].delay <= 300, true);
  h.timers[0].callback();
  assert.equal(h.errors.length, 1);
  assert.match(h.errors[0].error.message, /sem resposta Modbus após 300 ms/);
  assert.equal(h.errors[0].msg.modbusTimeout, true);
  assert.equal(h.sockets[0].destroyed, true);
  assert.equal(h.openConnections(), 0);

  h.request(21);
  assert.equal(h.sockets.length, 2, "conexão nova depois da descartada");
  h.sockets[1].onConnect();
  h.sockets[1].emit("data", h.response(21));
  assert.equal(h.sent.length, 1);
});

test("queda da conexão falha as consultas pendentes uma vez e libera a próxima", () => {
  const h = harness();
  h.request(30);
  h.request(31);
  h.sockets[0].emit("error", new Error("ECONNREFUSED"));
  h.sockets[0].emit("close");
  assert.equal(h.errors.length, 2);
  assert.equal(h.sockets[0].writes.length, 0, "nada é escrito numa conexão que não abriu");
  h.request(32);
  assert.equal(h.sockets.length, 2);
});

test("resposta com MBAP inválido descarta a conexão", () => {
  const h = harness();
  h.request(40);
  h.sockets[0].onConnect();
  h.sockets[0].emit("data", Buffer.from([0, 40, 0, 0, 0x01, 0x00, 1]));
  assert.equal(h.sockets[0].destroyed, true);
  assert.equal(h.errors.length, 1);
  assert.equal(h.sent.length, 0);
});

test("ao reimplantar o fluxo a conexão aberta é fechada", () => {
  const h = harness();
  h.request(50);
  h.sockets[0].onConnect();
  h.finalize();
  assert.equal(h.sockets[0].destroyed, true);
  assert.equal(h.store.size, 0);
  h.request(51);
  assert.equal(h.sockets.length, 2, "depois do deploy a leitura reconecta normalmente");
});

test("o sinal de vida do CLP continua com conexão própria por consulta", () => {
  assert.match(requestNode.func, /if \(msg\.modbusPurpose === 'sensor'\) \{/);
  const tail = requestNode.func.slice(requestNode.func.lastIndexOf("return null;\n}") + 1);
  assert.match(tail, /socket = createSocket\(\)/);
  assert.match(tail, /socket\.destroy\(\);/);
});
