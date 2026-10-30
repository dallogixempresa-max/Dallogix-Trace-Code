import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import outboxModule from "../integracoes/node-red/sensor-event-outbox.cjs";

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const retryCode = flow.find((node) => node.id === "sensor-event-retry").func;
const sensorCode = flow.find((node) => node.id === "modbus-result").func;
const endpoint = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");

async function runResponse(status, payload = {}, firstAttemptAt = null) {
  const event = { equipment_id: 1, carregamento_id: null,
    event_uuid: "d4a00000-0000-4000-8000-000000000051", detected_at_epoch_ms: Date.now() };
  const calls = { rejected: [], acknowledged: [], scheduled: [], sent: [] };
  const outbox = {
    reject: async (...args) => { calls.rejected.push(args); return true; },
    acknowledge: async (...args) => { calls.acknowledged.push(args); return true; },
  };
  const node = { warn: () => {}, error: () => {}, send: (msg) => calls.sent.push(msg) };
  const execute = new Function("msg", "node", "global", "setTimeout", retryCode);
  execute({ statusCode: status, payload, sensorEventPayload: event,
    ...(firstAttemptAt ? { sensorEventFirstAttemptAt: firstAttemptAt } : {}) }, node,
    { get: () => outbox }, (callback, delay) => calls.scheduled.push({ callback, delay }));
  await new Promise((resolve) => setImmediate(resolve));
  return calls;
}

test("422 definitivo arquiva a passagem e libera a fila", async () => {
  const calls = await runResponse(422, { error: "Evento inválido" });
  assert.deepEqual(calls.rejected[0].slice(0, 2), ["d4a00000-0000-4000-8000-000000000051", 422]);
  assert.equal(calls.scheduled.length, 0);
  assert.equal(calls.sent.length, 1);
});

test("422 de troca de carregamento recebe janela curta de repetição", async () => {
  const calls = await runResponse(422, { code: "loading_not_ready", error: "Aguardando carga" });
  assert.equal(calls.rejected.length, 0);
  assert.equal(calls.scheduled.length, 1);
});

test("422 persistente e 409 são arquivados para não bloquear o encerramento", async () => {
  const expired = await runResponse(422, { code: "loading_not_ready" }, Date.now() - 6000);
  const conflict = await runResponse(409, { error: "UUID em outro contexto" });
  assert.equal(expired.rejected.length, 1);
  assert.equal(conflict.rejected.length, 1);
  assert.equal(expired.scheduled.length + conflict.scheduled.length, 0);
});

test("503 preserva a passagem na fila para nova tentativa", async () => {
  const calls = await runResponse(503, { error: "Banco indisponível" });
  assert.equal(calls.rejected.length, 0);
  assert.equal(calls.scheduled.length, 1);
});

test("200 confirma a passagem sem repetir", async () => {
  const calls = await runResponse(200, { data: { id: 1 } });
  assert.equal(calls.acknowledged.length, 1);
  assert.equal(calls.scheduled.length, 0);
});

test("CLP virtual preserva a borda antes do heartbeat informar o carregamento", () => {
  const state = new Map();
  const flowState = { get: (key) => state.get(key), set: (key, value) => state.set(key, value) };
  const envState = { get: (key) => key === "TRACE_API_URL" ? "http://local" : "" };
  const node = { warn: () => {} };
  const processFrame = new Function("msg", "flow", "env", "node", "Buffer", sensorCode);
  const sample = (bit) => {
    const frame = Buffer.from([0x12, 0x34, 0, 0, 0, 4, 1, 1, 1, bit]);
    return processFrame({ payload: frame, modbusPurpose: "sensor", equipmentId: 1,
      modbusStartedAt: Date.now(), modbusFunction: 1, modbusTransaction: 0x1234,
      modbusUnit: 1, modbusRegister: 2052, traceToken: "teste", activeLoadingId: null },
    flowState, envState, node, Buffer);
  };
  assert.equal(sample(0)[2], null);
  const event = sample(1)[2];
  assert.equal(event.sensorEventPayload.carregamento_id, null);
  assert.match(event.sensorEventPayload.event_uuid, /^[0-9a-f-]{36}$/);
  assert.match(endpoint, /c\.started_at <= :detected_at/);
  assert.match(endpoint, /c\.equipment_id = :equipment_id AND c\.company_id = :company_id/);
});

test("CLP virtual em emergência arquiva 422 e libera a fila durável", async () => {
  const directory = await mkdtemp(join(tmpdir(), "trace-emergency-outbox-"));
  const outbox = outboxModule.createSensorEventOutbox({ directory });
  const event = { equipment_id: 1, carregamento_id: 34,
    event_uuid: "d4a00000-0000-4000-8000-000000000052", detected_at_epoch_ms: Date.now() };
  try {
    await outbox.enqueue(event);
    const execute = new Function("msg", "node", "global", "setTimeout", retryCode);
    const finished = new Promise((resolve) => {
      const node = { warn: () => {}, error: () => {}, send: resolve };
      execute({ statusCode: 422, payload: { code: "loading_not_ready", error: "EMERGENCIA" },
        sensorEventPayload: event, sensorEventFirstAttemptAt: Date.now() - 6000 },
      node, { get: () => outbox }, () => assert.fail("Rejeição definitiva não deve repetir"));
    });
    await finished;
    assert.equal(await outbox.count(), 0);
    assert.equal(await outbox.rejectedCount(), 1);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
