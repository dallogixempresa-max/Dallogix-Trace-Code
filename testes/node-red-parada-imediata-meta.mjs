import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import { readFile } from "node:fs/promises";
import test from "node:test";

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const byId = (id) => flow.find((node) => node.id === id);
const modbusResultNode = byId("modbus-result");
const retryNode = byId("sensor-event-retry");
const eventApi = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");
const gatewayApi = await readFile(new URL("../servidor/api/dalas/plc_gateway.php", import.meta.url), "utf8");
const gatewayService = await readFile(new URL("../servidor/src/Aplicacao/ServicoGatewayClp.php", import.meta.url), "utf8");

test("a quota do sensor dispara claim imediato pelo mesmo gateway físico", async () => {
  assert.match(
    eventApi,
    /\$detectedBags >= \$plannedQuantity[\s\S]*?solicitarParadaAoAtingirMetaRomaneio/,
    "a parada da meta total deve ser solicitada na igualdade, sem aguardar a próxima passagem",
  );

  const now = Date.now();
  const sensorState = new Map([[
    "trace_sensor_sample_17", { source: "10.0.0.10|502|1|2052|rising_edge|1", active: false, requestSentAt: now - 200 },
  ]]);
  const processModbusResult = new Function("msg", "env", "flow", modbusResultNode.func);
  const sensorOutputs = processModbusResult(
    {
      payload: Buffer.from([0, 1, 0, 0, 0, 4, 1, 1, 1, 1]),
      traceToken: "token-de-teste",
      equipmentId: 17,
      traceEquipment: { id: 17, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP" },
      modbusHost: "10.0.0.10",
      modbusPort: 502,
      modbusUnit: 1,
      modbusFunction: 1,
      modbusRegister: 2052,
      modbusPurpose: "sensor",
      modbusTransaction: 1,
      modbusStartedAt: now - 100,
      modbusRequestSentAt: now - 90,
      modbusResponseAt: now,
      activeLoadingId: 42,
    },
    { get: (name) => ({ TRACE_API_URL: "http://trace.local/", TRACE_SENSOR_ACTIVE_LEVEL: "1", TRACE_SENSOR_SIGNAL_MODE: "rising_edge" })[name] },
    {
      get: (key) => sensorState.get(key),
      set: (key, value) => sensorState.set(key, value),
    },
  );

  assert.equal(modbusResultNode.wires[2][0], "sensor-event-outbox-enqueue");
  const sensorEvent = sensorOutputs[2];
  assert.ok(sensorEvent);
  assert.equal(sensorEvent.payload.detected_at_epoch_ms, now);
  assert.equal(sensorEvent.payload.modbus_passage_from_epoch_ms, now - 200);
  assert.deepEqual(sensorEvent.traceEquipment, { id: 17, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP" });
  assert.equal(sensorEvent.modbusHost, "10.0.0.10");
  assert.equal(sensorEvent.modbusPort, 502);
  assert.equal(sensorEvent.modbusUnit, 1);
  assert.equal(retryNode?.outputs, 3);
  assert.deepEqual(retryNode?.wires?.[2], ["command-claim-http"]);
  assert.deepEqual(byId("command-claim-http")?.wires?.[0], ["command-safe-gate"]);
  assert.match(eventApi, /"command_request_id" => \$targetStop\["command_request_id"\] \?\? null/);
  assert.match(retryNode.func, /physical_stop_request_status/);
  assert.match(retryNode.func, /\['PENDENTE', 'PROCESSANDO'\]/);

  const outputs = [];
  const message = {
    ...sensorEvent,
    statusCode: 201,
    sensorEventPayload: { ...sensorEvent.payload, event_uuid: "d4a00000-0000-4000-8000-000000000071" },
    payload: { ...sensorEvent.payload, event_uuid: "d4a00000-0000-4000-8000-000000000071" },
  };
  message.payload = {
    data: { id: 91, command_request_id: 83, physical_stop_request_status: "PENDENTE" },
  };
  const run = new Function("msg", "env", "global", "node", "setTimeout", retryNode.func);
  run(
    message,
    { get: (name) => name === "TRACE_API_URL" ? "http://trace.local/" : "" },
    { get: () => ({ acknowledge: async () => true }) },
    { send: (value) => outputs.push(value), warn() {}, error() {} },
    () => 1,
  );
  await new Promise((resolve) => setImmediate(resolve));

  const immediateClaim = outputs.find((value) => value?.[2]);
  assert.equal(immediateClaim?.[2].url, "http://trace.local/api/plc_gateway.php");
  assert.equal(immediateClaim?.[2].payload.action, "CLAIM");
  assert.equal(immediateClaim?.[2].payload.equipment_id, 17);
  assert.equal(immediateClaim?.[2].payload.priority_stop_request_id, 83);
  assert.match(gatewayApi, /priority_stop_request_id/);
  assert.match(gatewayService, /r\.id = :priority_stop_request_id/);
  assert.match(gatewayService, /r\.command = 'PAUSAR_CARREGAMENTO'/);
  assert.match(gatewayService, /r\.command = 'EMERGENCIA'/);
  assert.match(gatewayService, /c\.state IN \('PAUSADO', 'FINALIZADO'\)/);
});

test("a passagem sem leitura além da meta não fica contabilizada", () => {
  assert.match(
    eventApi,
    /if \(\$physicalExcess\) \{[\s\S]*?ServicoPausasCarregamento::reclassificarPassagens\(\$pdo, \(int\) \$loadingId\)/,
  );
});

test("evento duplicado ou sem pausa pendente não cria claim de máquina", async () => {
  for (const data of [
    { duplicate: true },
    { command_request_id: 0, physical_stop_request_status: "PENDENTE" },
    { command_request_id: 83, physical_stop_request_status: "NAO_SOLICITADA" },
  ]) {
    const outputs = [];
    const run = new Function("msg", "env", "global", "node", "setTimeout", retryNode.func);
    run(
      {
        statusCode: 200,
        traceToken: "token-de-teste",
        equipmentId: 17,
        traceEquipment: { id: 17 },
        sensorEventPayload: { event_uuid: "d4a00000-0000-4000-8000-000000000072" },
        payload: { data },
      },
      { get: () => "http://trace.local" },
      { get: () => ({ acknowledge: async () => true }) },
      { send: (value) => outputs.push(value), warn() {}, error() {} },
      () => 1,
    );
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(outputs.some((value) => value?.[2]), false);
  }
});


test("uma consulta M2052 sem resposta libera a próxima amostra no limite aceito", () => {
  const now = 1795116546200;
  const fixedDate = { now: () => now };
  let timer;
  let timerDelay;
  let destroyed = false;
  const errors = [];
  const socket = new EventEmitter();
  socket.connect = () => {};
  socket.destroy = () => { destroyed = true; };
  const frame = Buffer.from([0, 1, 0, 0, 0, 6, 1, 1, 8, 4, 0, 1]);
  const state = new Map([["modbus_sensor_inflight_17", true]]);
  const request = new Function("msg", "global", "node", "setTimeout", "clearTimeout", "Date", byId("modbus-request").func);
  request(
    { payload: frame, host: "10.0.0.10", port: 502, modbusPurpose: "sensor", equipmentId: 17, modbusStartedAt: now },
    { get: () => () => socket },
    { error: (error, msg) => errors.push({ error, msg }) },
    (callback, delay) => { timer = callback; timerDelay = delay; return 1; },
    () => {},
    fixedDate,
  );
  assert.equal(timerDelay, 300); // o CLP responde em ~7 ms; consulta presa é abandonada em 300 ms
  timer();
  assert.equal(destroyed, true);
  assert.equal(errors.length, 1);
  const fail = new Function("msg", "env", "flow", "Date", byId("heartbeat-request-failed").func);
  const failureOutputs = fail(errors[0].msg, new Map(), state, fixedDate);
  assert.deepEqual(failureOutputs, [null, null]);
  assert.equal(state.get("modbus_sensor_inflight_17"), false);
});
