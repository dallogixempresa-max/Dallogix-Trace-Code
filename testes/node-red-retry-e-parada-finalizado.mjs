import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const byId = (id) => flow.find((node) => node.id === id);

function harness(node, envValues = {}) {
  const store = {};
  const sends = [];
  const timers = [];
  const ctx = { get: (key) => store[key], set: (key, value) => { store[key] = value; } };
  const nodeApi = { send: (value) => sends.push(value), warn: () => {}, error: () => {} };
  const env = { get: (key) => envValues[key] };
  const fakeSetTimeout = (fn, ms) => { timers.push({ fn, ms }); };
  const run = new Function("msg", "node", "context", "env", "global", "flow", "setTimeout", node.func);
  return {
    sends,
    timers,
    call: (msg, global = { get: () => null }) => run(msg, nodeApi, ctx, env, global, ctx, fakeSetTimeout),
    flushTimers: () => { const pending = timers.splice(0); pending.forEach(({ fn }) => fn()); },
  };
}

test("falha de transporte que chega pela saída e pelo Catch agenda um único reenvio", () => {
  const retry = harness(byId("sensor-event-retry"), { TRACE_API_URL: "http://nginx" });
  const event = { event_uuid: "uuid-1" };
  const failure = { payload: event, sensorEventPayload: event, traceToken: "t", statusCode: "ECONNREFUSED" };
  retry.call({ ...failure });
  retry.call({ ...failure, error: { message: "ECONNREFUSED" } });
  assert.equal(retry.timers.length, 1, "as duas entregas da mesma falha devem gerar um só timer");

  retry.flushTimers();
  assert.equal(retry.sends.length, 1);
  assert.equal(retry.sends[0][1].sensorEventAttempts, 1);

  // A nova tentativa também falha pelos dois caminhos: continua sendo um só reenvio.
  const second = { ...failure, sensorEventAttempts: 1 };
  retry.call({ ...second });
  retry.call({ ...second, error: { message: "ECONNREFUSED" } });
  assert.equal(retry.timers.length, 1);
});

test("COMPLETE sem resposta é reenviado uma vez por falha e para no 2xx ou 404", () => {
  const retry = harness(byId("command-complete-retry"));
  const body = { action: "COMPLETE", request_id: 42, status: "APLICADO", message: "ok" };
  const out1 = retry.call({ payload: body, statusCode: "ETIMEDOUT" });
  const out2 = retry.call({ payload: body, statusCode: "ETIMEDOUT", error: { message: "timeout" } });
  assert.deepEqual(out1, [null, null]);
  assert.deepEqual(out2, [null, null]);
  assert.equal(retry.timers.length, 1);
  retry.flushTimers();
  assert.equal(retry.sends.length, 1);
  assert.equal(retry.sends[0][1].payload.request_id, 42);
  assert.equal(retry.sends[0][1].completeAttempts, 1);

  const done = retry.call({ ...retry.sends[0][1], statusCode: 200, payload: { data: { id: 42 } } });
  assert.equal(done[1], null, "resposta 200 não deve reenviar");
  const already = retry.call({ payload: body, completePayload: body, completeAttempts: 2, statusCode: 404 });
  assert.equal(already[1], null, "404 significa que o comando não está mais PROCESSANDO");
});

test("COMPLETE desiste dentro da reserva de 2 minutos", () => {
  const retry = harness(byId("command-complete-retry"));
  const body = { action: "COMPLETE", request_id: 7, status: "APLICADO", message: "ok" };
  let total = 0;
  let msg = { payload: body, statusCode: 502 };
  for (let attempt = 0; attempt < 20; attempt += 1) {
    retry.call(msg);
    if (!retry.timers.length) break;
    total += retry.timers[0].ms;
    retry.flushTimers();
    msg = { ...retry.sends.at(-1)[1], statusCode: 502 };
  }
  assert.ok(total < 120000, `soma dos intervalos ${total} ms deve caber na reserva`);
  assert.ok(retry.sends.length >= 5, "deve tentar algumas vezes antes de desistir");
});

test("PAUSAR pendente é executado mesmo com o carregamento já FINALIZADO", () => {
  const gate = harness(byId("command-safe-gate"), {
    TRACE_API_URL: "http://nginx",
    TRACE_PHYSICAL_CLP_ENABLED: "1",
    TRACE_IO_MAP_STATUS: "APPROVED",
    TRACE_MODBUS_MAP_EQUIPMENT_ID: "3",
    TRACE_MODBUS_COIL_CONVEYOR_RUN: "2049",
    TRACE_MODBUS_COIL_REVERSAL: "2050",
  });
  const [write, complete] = gate.call({
    statusCode: 200,
    traceToken: "t",
    traceEquipment: { id: 3, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP" },
    payload: { data: { id: 9, command: "PAUSAR_CARREGAMENTO", loading_state: "FINALIZADO" } },
  });
  assert.equal(complete, null, "não deve rejeitar a parada");
  assert.ok(write, "deve seguir para a escrita da bobina");
  assert.equal(write.commandMap.value, false);
  assert.equal(write.commandMap.coil, 2049);

  const [startWrite, startComplete] = gate.call({
    statusCode: 200,
    traceToken: "t",
    traceEquipment: { id: 3 },
    payload: { data: { id: 10, command: "INICIAR_CARREGAMENTO", loading_state: "FINALIZADO" } },
  });
  assert.equal(startWrite, null, "ligar a esteira continua proibido após finalizar");
  assert.equal(startComplete.payload.status, "REJEITADO");
});
