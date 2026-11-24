import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import outboxModule from "../integracoes/node-red/sensor-event-outbox.cjs";

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const byId = (id) => flow.find((node) => node.id === id);
const eventApi = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");
const compose = await readFile(new URL("../docker-compose.yml", import.meta.url), "utf8");

const run = (id, msg, envValues, store) => new Function("msg", "env", "flow", byId(id).func)(
  msg,
  { get: (name) => envValues[name] },
  { get: (key) => store.get(key), set: (key, value) => store.set(key, value) },
);

const baseEnv = { TRACE_API_URL: "http://trace.local/", TRACE_DEVICE_TOKEN: "token-de-teste" };
const equipment = { id: 17, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP", active_loading_id: 42 };

function counterResponse(value, transaction = 1) {
  const frame = Buffer.from([0, 0, 0, 0, 0, 5, 1, 3, 2, 0, 0]);
  frame.writeUInt16BE(transaction, 0);
  frame.writeUInt16BE(value, 9);
  return frame;
}

function counterSample(store, value, { now, loadingId = 42, env = baseEnv } = {}) {
  return run("modbus-result", {
    payload: counterResponse(value),
    traceToken: "token-de-teste",
    equipmentId: 17,
    traceEquipment: equipment,
    modbusHost: "10.0.0.10",
    modbusPort: 502,
    modbusUnit: 1,
    modbusFunction: 3,
    modbusRegister: 100,
    modbusPurpose: "sensor",
    sensorCounter: true,
    modbusTransaction: 1,
    modbusStartedAt: now - 40,
    modbusRequestSentAt: now - 30,
    modbusResponseAt: now,
    activeLoadingId: loadingId,
  }, env, store);
}

const asList = (output) => (output == null ? [] : Array.isArray(output) ? output : [output]);

test("configuração do contador gera consulta FC3 e mantém a sonda de diagnóstico separada", () => {
  const store = new Map();
  const env = { ...baseEnv, TRACE_MODBUS_SENSOR_COUNTER_REGISTER: "100" };
  const [probes] = run("heartbeat-dispatch", { statusCode: 200, traceToken: "token-de-teste", payload: { data: [equipment] } }, env, store);
  assert.equal(probes.length, 1, "com contador, o diagnóstico do CLP não reaproveita a leitura do sensor");
  assert.equal(probes[0].modbusRegister, 2052);
  assert.equal(store.get("trace_sensor_equipment_17").counterRegister, 100);
  assert.equal(store.get("trace_sensor_equipment_17").sharesSensorRead, false);

  const [polls] = run("sensor-poll-dispatch", {}, env, store);
  assert.equal(polls[0].modbusFunction, 3);
  assert.equal(polls[0].modbusRegister, 100);
  assert.equal(polls[0].sensorCounter, true);
});

test("sem contador configurado o sensor continua lendo o bit M2052", () => {
  const store = new Map();
  run("heartbeat-dispatch", { statusCode: 200, traceToken: "token-de-teste", payload: { data: [equipment] } }, baseEnv, store);
  assert.equal(store.get("trace_sensor_equipment_17").counterRegister, null);
  const [polls] = run("sensor-poll-dispatch", {}, baseEnv, store);
  assert.equal(polls[0].modbusFunction, 1);
  assert.equal(polls[0].modbusRegister, 2052);
  assert.equal(polls[0].sensorCounter, false);
});

test("cada incremento do contador vira uma passagem, inclusive após consultas perdidas", () => {
  const store = new Map();
  const t0 = 1795116546000;
  assert.equal(counterSample(store, 10, { now: t0 })[2], null, "a primeira amostra só sincroniza");

  const one = asList(counterSample(store, 11, { now: t0 + 200 })[2]);
  assert.equal(one.length, 1);
  assert.equal(one[0].payload.sensor_source, "COUNTER");
  assert.equal(one[0].payload.sensor_counter_value, 11);
  assert.equal(one[0].payload.detected_at_epoch_ms, t0 + 200);
  assert.equal(one[0].payload.modbus_passage_from_epoch_ms, t0 - 30);
  assert.equal(one[0].url, "http://trace.local/api/sensor_eventos.php");

  // Três sacas passaram enquanto as consultas falhavam: nenhuma se perde.
  const three = asList(counterSample(store, 14, { now: t0 + 1400 })[2]);
  assert.deepEqual(three.map((event) => event.payload.sensor_counter_value), [12, 13, 14]);
  const times = three.map((event) => event.payload.detected_at_epoch_ms);
  assert.ok(times[0] < times[1] && times[1] < times[2] && times[2] === t0 + 1400, "horários distintos e em ordem");
  assert.equal(new Set(three.map((event) => event.payload.event_uuid)).size, 3);
  assert.ok(three.every((event) => event.payload.modbus_passage_from_epoch_ms === undefined));

  assert.equal(counterSample(store, 14, { now: t0 + 1600 })[2], null, "sem incremento, sem passagem");
});

test("contador sobrevive à virada de 16 bits e ressincroniza quando é zerado", () => {
  const store = new Map();
  const t0 = 1795116546000;
  counterSample(store, 65534, { now: t0 });
  assert.deepEqual(asList(counterSample(store, 1, { now: t0 + 200 })[2]).map((e) => e.payload.sensor_counter_value), [65535, 0, 1]);

  const reset = counterSample(store, 900, { now: t0 + 400 });
  assert.equal(reset[2], null, "salto acima do limite não inventa passagens");
  assert.equal(reset[1].payload.details.counter_reset_detected, true);
  assert.equal(asList(counterSample(store, 901, { now: t0 + 600 })[2]).length, 1, "volta a contar a partir do novo valor");
});

test("sacas fora de um carregamento ativo atualizam o contador sem gerar passagem", () => {
  const store = new Map();
  const t0 = 1795116546000;
  counterSample(store, 5, { now: t0, loadingId: null });
  assert.equal(counterSample(store, 8, { now: t0 + 200, loadingId: null })[2], null);
  assert.equal(asList(counterSample(store, 9, { now: t0 + 400 })[2]).length, 1, "só a saca do carregamento ativo conta");
});

test("fila local preserva a origem do contador e o servidor não aplica debounce a ela", async () => {
  const directory = await mkdtemp(join(tmpdir(), "trace-counter-outbox-"));
  try {
    const outbox = outboxModule.createSensorEventOutbox({ directory });
    await outbox.enqueue({
      equipment_id: 17, carregamento_id: 42, event_uuid: "d4a00000-0000-4000-8000-000000000099",
      detected_at_epoch_ms: 1795116546000, sensor_source: "COUNTER", sensor_counter_value: 12,
    });
    const [stored] = await outbox.list();
    assert.equal(stored.sensor_source, "COUNTER");
    assert.equal(stored.sensor_counter_value, 12);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
  assert.match(eventApi, /\$counterPassage = \(\$payload\["sensor_source"\] \?\? null\) === "COUNTER";/);
  assert.match(eventApi, /\$debouncedId = \$counterPassage \? false : \$debounce->fetchColumn\(\);/);
  assert.match(compose, /TRACE_MODBUS_SENSOR_COUNTER_REGISTER: \$\{TRACE_MODBUS_SENSOR_COUNTER_REGISTER:-\}/);
});
