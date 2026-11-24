import assert from "node:assert/strict";
import { readdirSync } from "node:fs";
import { mkdtemp, readFile, readdir, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import outboxModule from "../integracoes/node-red/sensor-event-outbox.cjs";

const { createSensorEventOutbox } = outboxModule;
const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const TOKEN = "token-de-teste";
const ENV = { TRACE_API_URL: "http://nginx/", TRACE_DEVICE_TOKEN: TOKEN };

// Entrega como o Node.send do Node-RED: valor simples vai para a saída 1; numa lista,
// o item i vai para a saída i e itens além do número de saídas são descartados; um
// item que é lista entrega as mensagens em sequência pela mesma saída.
function deliverOutputs(node, value, receive) {
  if (value === null || value === undefined) return;
  const perOutput = Array.isArray(value) ? value : [value];
  const outputs = Number(node.outputs ?? node.wires.length);
  for (let index = 0; index < Math.min(outputs, perOutput.length); index += 1) {
    const item = perOutput[index];
    if (item === null || item === undefined) continue;
    for (const message of Array.isArray(item) ? item : [item]) {
      if (message === null || message === undefined) continue;
      for (const target of node.wires[index] || []) receive(target, structuredClone(message));
    }
  }
}

// Executa o func real dos nós de função; o "http request" é simulado pelo responder.
function createRuntime({ nodes, outbox, directory, responder, timeScale = 40 }) {
  const byId = new Map(nodes.map((node) => [node.id, node]));
  const contexts = new Map();
  const log = { posts: [], confirmed: [], errors: [], warns: [], other: [] };
  const timers = new Set();
  const scaledSetTimeout = (callback, ms) => {
    const handle = setTimeout(() => { timers.delete(handle); callback(); }, Math.ceil((Number(ms) || 0) / timeScale));
    timers.add(handle);
    return handle;
  };
  const contextFor = (id) => {
    if (!contexts.has(id)) {
      const store = {};
      contexts.set(id, { get: (key) => store[key], set: (key, value) => { store[key] = value; } });
    }
    return contexts.get(id);
  };
  const global = { get: (key) => (key === "traceSensorEventOutbox" ? outbox : undefined) };
  const env = { get: (key) => ENV[key] };

  function runFunction(node, msg) {
    const run = new Function("msg", "node", "context", "env", "global", "flow", "setTimeout", node.func);
    const api = {
      send: (value) => deliverOutputs(node, value, receive),
      warn: (message) => log.warns.push(message),
      error: (message) => log.errors.push(message),
    };
    deliverOutputs(node, run(msg, api, contextFor(node.id), env, global, contextFor("flow"), scaledSetTimeout), receive);
  }

  function receive(targetId, msg) {
    const target = byId.get(targetId);
    assert.ok(target, `nó ${targetId} não existe no fluxo`);
    if (targetId === "sensor-event-http") {
      const uuid = msg.payload.event_uuid;
      const pendingAtPost = readdirSync(directory).filter((name) => name.endsWith(".json")).map((name) => name.replace(/\.json$/, ""));
      log.posts.push({ uuid, attempt: msg.sensorEventAttempts, sensorEventReplay: msg.sensorEventReplay, url: msg.url, method: msg.method, headers: msg.headers, payload: msg.payload, pendingAtPost });
      const response = responder(uuid, log.posts.filter((post) => post.uuid === uuid).length);
      scaledSetTimeout(() => deliverOutputs(target, { ...msg, statusCode: response.statusCode, payload: response.body }, receive), 80);
      return;
    }
    if (targetId === "sensor-event-debug") {
      log.confirmed.push(msg.sensorEventPayload.event_uuid);
      return;
    }
    if (target.type === "function") {
      runFunction(target, msg);
      return;
    }
    log.other.push({ targetId, msg });
  }

  return {
    log,
    start: () => receive("sensor-event-outbox-replay", { payload: Date.now() }),
    stop: () => { timers.forEach(clearTimeout); timers.clear(); },
  };
}

async function waitFor(condition, label, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await condition()) return;
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  assert.fail(`tempo esgotado aguardando: ${label}`);
}

const uuid = (n) => `d4a00000-0000-4000-8000-${String(n).padStart(12, "0")}`;
// Detectadas em ordem diferente da ordem dos UUIDs e da gravação.
const backlog = [
  { equipment_id: 1, carregamento_id: 24, event_uuid: uuid(9), detected_at_epoch_ms: 1793314343100 },
  { equipment_id: 1, carregamento_id: 24, event_uuid: uuid(3), detected_at_epoch_ms: 1793314343500 },
  { equipment_id: 1, carregamento_id: 25, event_uuid: uuid(7), detected_at_epoch_ms: 1793314350000 },
  { equipment_id: 1, carregamento_id: 25, event_uuid: uuid(1), detected_at_epoch_ms: 1793314350300 },
];

async function withBacklog(callback) {
  const directory = await mkdtemp(join(tmpdir(), "trace-sensor-replay-"));
  const outbox = createSensorEventOutbox({ directory });
  for (const event of [backlog[2], backlog[0], backlog[3], backlog[1]]) await outbox.enqueue(event);
  try {
    await callback({ directory, outbox, rejectedDirectory: `${directory}-recusadas` });
  } finally {
    await rm(directory, { recursive: true, force: true });
    await rm(`${directory}-recusadas`, { recursive: true, force: true });
  }
}

async function readRejected(rejectedDirectory) {
  const names = (await readdir(rejectedDirectory)).filter((name) => name.endsWith(".json")).sort();
  return Promise.all(names.map(async (name) => JSON.parse(await readFile(join(rejectedDirectory, name), "utf8"))));
}

const accepted = (id) => ({ statusCode: 201, body: { data: { id, duplicate: false } } });
const replayNode = flow.find((node) => node.id === "sensor-event-outbox-replay");

test("o nó de recuperação tem uma única saída ligada ao POST da passagem", () => {
  assert.equal(replayNode.outputs, 1);
  assert.deepEqual(replayNode.wires, [["sensor-event-http"]]);
  assert.deepEqual(flow.find((node) => node.id === "sensor-event-outbox-startup")?.wires, [["sensor-event-outbox-replay"]]);
});

test("o harness reproduz o descarte de itens extras numa saída única (defeito anterior)", () => {
  const delivered = [];
  const singleOutput = { outputs: 1, wires: [["destino"]] };
  deliverOutputs(singleOutput, [{ n: 1 }, { n: 2 }, { n: 3 }], (target, msg) => delivered.push(msg.n));
  assert.deepEqual(delivered, [1], "node.send(lista) numa saída única entrega só o primeiro item");
  deliverOutputs(singleOutput, [[{ n: 4 }, { n: 5 }]], (target, msg) => delivered.push(msg.n));
  assert.deepEqual(delivered, [1, 4, 5]);
});

test("reinício reenvia toda a fila, uma passagem por vez e na ordem de detecção", async () => {
  await withBacklog(async ({ directory, outbox }) => {
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder: () => accepted(1) });
    try {
      runtime.start();
      await waitFor(async () => (await outbox.list()).length === 0, "fila vazia");
      const order = backlog.map((event) => event.event_uuid);
      assert.deepEqual(runtime.log.posts.map((post) => post.uuid), order);
      assert.deepEqual(runtime.log.confirmed, order);
      runtime.log.posts.forEach((post, index) => {
        assert.equal(post.url, "http://nginx/api/sensor_eventos.php");
        assert.equal(post.method, "POST");
        assert.deepEqual(post.headers, { "X-Device-Token": TOKEN, "Content-Type": "application/json" });
        assert.deepEqual(post.payload, backlog[index]);
        assert.equal(post.attempt, 0);
        assert.equal(post.sensorEventReplay, true);
        // A anterior já saiu da fila quando a próxima é enviada.
        assert.deepEqual(post.pendingAtPost.sort(), order.slice(index).sort());
      });
      assert.deepEqual(runtime.log.errors, []);
      assert.equal(runtime.log.warns.length, 1);
      assert.match(runtime.log.warns[0], /Recuperando 4 passagem/);
    } finally {
      runtime.stop();
    }
  });
});

test("falha temporária segura as seguintes até a retentativa ser confirmada", async () => {
  await withBacklog(async ({ directory, outbox }) => {
    const responder = (id, call) => (id === backlog[1].event_uuid && call <= 2
      ? { statusCode: 500, body: { error: "Erro interno." } }
      : accepted(1));
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder });
    try {
      runtime.start();
      await waitFor(async () => (await outbox.list()).length === 0, "fila vazia");
      const [first, second, third, fourth] = backlog.map((event) => event.event_uuid);
      assert.deepEqual(runtime.log.posts.map((post) => [post.uuid, post.attempt]), [
        [first, 0], [second, 0], [second, 1], [second, 2], [third, 0], [fourth, 0],
      ]);
      assert.deepEqual(runtime.log.confirmed, [first, second, third, fourth]);
    } finally {
      runtime.stop();
    }
  });
});

test("falha sem fim do servidor não libera as seguintes nem gera rajada", async () => {
  await withBacklog(async ({ directory, outbox }) => {
    const unavailable = { statusCode: 503, body: { error: "Indisponível." } };
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder: () => unavailable });
    try {
      runtime.start();
      await waitFor(() => runtime.log.posts.length >= 4, "retentativas da primeira passagem");
      assert.deepEqual([...new Set(runtime.log.posts.map((post) => post.uuid))], [backlog[0].event_uuid]);
      assert.equal((await outbox.list()).length, 4, "nenhuma passagem some da fila sem confirmação");
      assert.deepEqual(runtime.log.confirmed, []);
    } finally {
      runtime.stop();
    }
  });
});

const REJECTED_REASON = "O evento só pode ser registrado durante o carregamento informado.";
const rejected422 = { statusCode: 422, body: { error: REJECTED_REASON } };

test("passagem recusada com 422 na recuperação vai para as recusadas e a próxima segue", async () => {
  await withBacklog(async ({ directory, outbox, rejectedDirectory }) => {
    const responder = (id) => (id === backlog[1].event_uuid ? rejected422 : accepted(1));
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder });
    try {
      runtime.start();
      await waitFor(async () => (await outbox.list()).length === 0, "fila vazia");
      const order = backlog.map((event) => event.event_uuid);
      assert.deepEqual(runtime.log.posts.map((post) => [post.uuid, post.attempt]), order.map((id) => [id, 0]));
      assert.deepEqual(runtime.log.confirmed, [order[0], order[2], order[3]]);
      assert.equal(runtime.log.posts[2].pendingAtPost.includes(order[1]), false, "a recusada sai da fila antes da próxima");
      const [archived] = await readRejected(rejectedDirectory);
      assert.deepEqual(archived.event, backlog[1]);
      assert.equal(archived.version, 1);
      assert.equal(archived.rejected.http_status, 422);
      assert.equal(archived.rejected.reason, REJECTED_REASON);
      assert.ok(Number.isSafeInteger(archived.rejected.at_epoch_ms));
      assert.equal(runtime.log.warns.filter((warning) => /arquivada fora da fila local/.test(warning)).length, 1);
      assert.match(runtime.log.warns.find((warning) => /arquivada/.test(warning)), /carregamento 24/);
    } finally {
      runtime.stop();
    }
  });
});

test("fila só de cargas encerradas é esvaziada com um POST por passagem e nada some", async () => {
  await withBacklog(async ({ directory, outbox, rejectedDirectory }) => {
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder: () => rejected422 });
    try {
      runtime.start();
      await waitFor(async () => (await outbox.list()).length === 0, "fila vazia");
      await new Promise((resolve) => setTimeout(resolve, 100));
      assert.deepEqual(runtime.log.posts.map((post) => post.uuid), backlog.map((event) => event.event_uuid));
      assert.deepEqual(runtime.log.confirmed, []);
      assert.deepEqual((await readRejected(rejectedDirectory)).map((record) => record.event)
        .sort((left, right) => left.detected_at_epoch_ms - right.detected_at_epoch_ms), backlog);
      assert.deepEqual(runtime.log.errors, []);
    } finally {
      runtime.stop();
    }
  });
});

test("422 de passagem ao vivo continua em retentativa e não é arquivada", () => {
  const retryNode = flow.find((node) => node.id === "sensor-event-retry");
  const archived = [];
  const timers = [];
  const store = {};
  const ctx = { get: (key) => store[key], set: (key, value) => { store[key] = value; } };
  const fakeOutbox = { archive: (id) => { archived.push(id); return Promise.resolve(true); } };
  const run = new Function("msg", "node", "context", "env", "global", "flow", "setTimeout", retryNode.func);
  const call = (msg) => run(msg, { send() {}, warn() {}, error() {} }, ctx, { get: (key) => ENV[key] },
    { get: () => fakeOutbox }, ctx, (fn, ms) => timers.push(ms));
  const event = backlog[0];
  const response = { payload: { error: REJECTED_REASON }, sensorEventPayload: event, traceToken: TOKEN, statusCode: 422, sensorEventAttempts: 0 };

  assert.deepEqual(call({ ...response }), [null, null, null]);
  assert.deepEqual(archived, []);
  assert.deepEqual(timers, [1000], "sem a marca da recuperação a passagem segue em retentativa");

  delete store.pendingRetryUuids;
  call({ ...response, sensorEventReplay: true, statusCode: 500 });
  assert.deepEqual(archived, [], "só 422 é recusa definitiva");
  assert.equal(timers.length, 2);

  delete store.pendingRetryUuids;
  call({ ...response, sensorEventReplay: true });
  assert.deepEqual(archived, [event.event_uuid]);
  assert.equal(timers.length, 2, "a recusada da recuperação não agenda nova tentativa");
});

test("falha ao arquivar mantém a passagem na fila e em retentativa", async () => {
  const retryNode = flow.find((node) => node.id === "sensor-event-retry");
  const timers = [];
  const store = {};
  const ctx = { get: (key) => store[key], set: (key, value) => { store[key] = value; } };
  const fakeOutbox = { archive: () => Promise.reject(new Error("disco cheio")) };
  const warnings = [];
  const run = new Function("msg", "node", "context", "env", "global", "flow", "setTimeout", retryNode.func);
  run({ payload: { error: REJECTED_REASON }, sensorEventPayload: backlog[0], traceToken: TOKEN, statusCode: 422, sensorEventReplay: true },
    { send() {}, warn: (message) => warnings.push(message), error() {} }, ctx, { get: (key) => ENV[key] },
    { get: () => fakeOutbox }, ctx, (fn, ms) => timers.push(ms));
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(timers, [1000]);
  assert.match(warnings[0], /não foi possível arquivar: disco cheio/);
});

test("passagem nova gravada durante a recuperação fica com o envio normal", async () => {
  await withBacklog(async ({ directory, outbox }) => {
    const live = { equipment_id: 1, carregamento_id: 26, event_uuid: uuid(20), detected_at_epoch_ms: 1793314360000 };
    let enqueued = false;
    const runtime = createRuntime({
      nodes: flow, outbox, directory,
      responder: () => {
        if (!enqueued) { enqueued = true; outbox.enqueue(live); }
        return accepted(1);
      },
    });
    try {
      runtime.start();
      await waitFor(() => runtime.log.confirmed.length === 4, "fila antiga confirmada");
      await new Promise((resolve) => setTimeout(resolve, 100));
      assert.equal(runtime.log.posts.some((post) => post.uuid === live.event_uuid), false);
      assert.deepEqual((await outbox.list()).map((event) => event.event_uuid), [live.event_uuid]);
    } finally {
      runtime.stop();
    }
  });
});

test("novo início da recuperação substitui o anterior sem enviar duas vezes", async () => {
  await withBacklog(async ({ directory, outbox }) => {
    const runtime = createRuntime({ nodes: flow, outbox, directory, responder: () => accepted(1) });
    try {
      runtime.start();
      runtime.start();
      await waitFor(async () => (await outbox.list()).length === 0, "fila vazia");
      await new Promise((resolve) => setTimeout(resolve, 100));
      const posted = runtime.log.posts.map((post) => post.uuid);
      assert.deepEqual(posted, backlog.map((event) => event.event_uuid));
    } finally {
      runtime.stop();
    }
  });
});
