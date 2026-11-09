import assert from "node:assert/strict";
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
  const now = Date.now();
  const sensorState = new Map([["trace_sensor_last_value_17", false]]);
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
      modbusStartedAt: now,
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
  assert.deepEqual(sensorEvent.traceEquipment, { id: 17, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP" });
  assert.equal(sensorEvent.modbusHost, "10.0.0.10");
  assert.equal(sensorEvent.modbusPort, 502);
  assert.equal(sensorEvent.modbusUnit, 1);
  assert.equal(retryNode?.outputs, 3);
  assert.deepEqual(retryNode?.wires?.[2], ["command-claim-http"]);
  assert.deepEqual(byId("command-claim-http")?.wires?.[0], ["command-safe-gate"]);
  assert.match(eventApi, /"command_request_id" => \$excessStop\["command_request_id"\] \?\? null/);
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
  assert.match(gatewayService, /c\.state = 'PAUSADO'/);
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
