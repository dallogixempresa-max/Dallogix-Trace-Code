import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

// Segunda barreira: se um INICIAR chegar ao node-red com stop_reason na
// reserva, a bobina do motor não é escrita. PAUSAR e reversão seguem iguais.

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const gateNode = flow.find((node) => node.id === "command-safe-gate");

const physicalEnv = {
  TRACE_API_URL: "http://nginx",
  TRACE_PHYSICAL_CLP_ENABLED: "1",
  TRACE_IO_MAP_STATUS: "APPROVED",
  TRACE_MODBUS_MAP_EQUIPMENT_ID: "3",
  TRACE_MODBUS_COIL_CONVEYOR_RUN: "2049",
  TRACE_MODBUS_COIL_REVERSAL: "2050",
};

function gate(envValues = physicalEnv) {
  const store = {};
  const ctx = { get: (key) => store[key], set: (key, value) => { store[key] = value; } };
  const env = { get: (key) => envValues[key] };
  const run = new Function("msg", "node", "context", "env", "global", "flow", gateNode.func);
  return (data) => run({
    statusCode: 200,
    traceToken: "t",
    traceEquipment: { id: 3, plc_connect_ip: "10.0.0.10", plc_port: 502, plc_protocol: "MODBUS_TCP" },
    payload: { data },
  }, { warn() {}, error() {} }, ctx, env, { get: () => null }, ctx);
}

test("INICIAR com parada por divergência pendente é recusado sem escrita", () => {
  const [write, complete] = gate()({ id: 21, command: "INICIAR_CARREGAMENTO", loading_state: "PAUSADO", stop_reason: "PRODUTO_INCORRETO" });
  assert.equal(write, null, "a bobina do motor não pode ser escrita");
  assert.equal(complete.payload.action, "COMPLETE");
  assert.equal(complete.payload.request_id, 21);
  assert.equal(complete.payload.status, "REJEITADO");
  assert.match(complete.payload.message, /PRODUTO_INCORRETO/);
  assert.match(complete.payload.message, /nenhuma escrita foi executada/);
});

test("reversão com parada pendente continua sendo escrita como antes", () => {
  for (const [command, value] of [["REVERSAO_ATIVAR", true], ["REVERSAO_DESATIVAR", false]]) {
    const [write, complete] = gate()({ id: 22, command, loading_state: "PAUSADO", stop_reason: "EXCESSO" });
    assert.equal(complete, null, `${command} não deve ser recusado por esta regra`);
    assert.equal(write.commandMap.coil, 2050);
    assert.equal(write.commandMap.value, value);
  }
});

test("PAUSAR com parada pendente continua sendo escrito", () => {
  const [write, complete] = gate()({ id: 23, command: "PAUSAR_CARREGAMENTO", loading_state: "PAUSADO", stop_reason: "PRODUTO_INCORRETO" });
  assert.equal(complete, null, "a parada não pode ser recusada");
  assert.equal(write.commandMap.coil, 2049);
  assert.equal(write.commandMap.value, false);
});

test("INICIAR sem parada pendente continua sendo escrito", () => {
  for (const stopReason of [null, undefined, ""]) {
    const [write, complete] = gate()({ id: 24, command: "INICIAR_CARREGAMENTO", loading_state: "PAUSADO", stop_reason: stopReason });
    assert.equal(complete, null);
    assert.equal(write.commandMap.coil, 2049);
    assert.equal(write.commandMap.value, true);
  }
});

test("a simulação local também recusa INICIAR com parada pendente", () => {
  const [write, complete] = gate({
    TRACE_API_URL: "http://nginx",
    TRACE_LOCAL_SIMULATION: "1",
    TRACE_SIMULATOR_ONLY_COMMANDS: "1",
    TRACE_INSTALLATION_MODE: "local",
  })({ id: 25, carregamento_id: 135, command: "INICIAR_CARREGAMENTO", loading_state: "PAUSADO", stop_reason: "COTA_PRODUTO_ATINGIDA" });
  assert.equal(write, null);
  assert.equal(complete.payload.status, "REJEITADO");
  assert.doesNotMatch(complete.payload.message, /SIMULAÇÃO LOCAL: INICIAR_CARREGAMENTO confirmado/);
});
