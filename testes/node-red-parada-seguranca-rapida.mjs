import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

// Bancada de 24/11/2026 (v1.0.281): a parada por produto acima da quantidade
// nasce da leitura do Keyence e esperava a consulta de comandos de 2 s; as
// reservas levaram 0,2 a 2,0 s (comandos 535 a 559) e a saca seguinte passava.
// A consulta longa de parada de segurança fica aberta no servidor durante a
// carga (até 1,5 s) e é refeita em até 100 ms depois de cada resposta.

const flow = JSON.parse(await readFile(new URL("../integracoes/node-red/trace-clp-bridge.flow.json", import.meta.url), "utf8"));
const byId = (id) => flow.find((node) => node.id === id);
const gatewayApi = await readFile(new URL("../servidor/api/dalas/plc_gateway.php", import.meta.url), "utf8");

const env = (values) => ({ get: (name) => values[name] });
const flowStore = (initial = {}) => {
  const values = new Map(Object.entries(initial));
  return { get: (key) => values.get(key), set: (key, value) => values.set(key, value), values };
};
const build = (store, now) => new Function("msg", "env", "flow", "Date", byId("command-stop-claim-build").func)(
  {},
  env({ TRACE_API_URL: "http://trace.local/", TRACE_DEVICE_TOKEN: "token-de-teste" }),
  store,
  { now: () => now },
);
const send = (store, now) => {
  const result = build(store, now);
  return result ? result[0] : [];
};
const release = (msg, store, now) => new Function("msg", "flow", "Date", byId("command-stop-claim-release").func)(
  msg,
  store,
  { now: () => now },
);
const reply = (claim, statusCode, payload = undefined) => ({ ...claim, statusCode, payload });
const equipment = { id: 1, plc_connect_ip: "192.168.1.10", plc_port: 502, plc_protocol: "MODBUS_TCP" };
const config = (activeLoadingId) => ({
  trace_sensor_equipment_ids: [1],
  trace_sensor_equipment_1: { equipmentId: 1, host: "192.168.1.10", port: 502, unit: 1, register: 2052, activeLoadingId, traceEquipment: equipment },
});
const T0 = 1_000_000;

test("a consulta longa de parada de segurança é refeita a cada 100 ms e chega à mesma escrita física", () => {
  const tick = byId("command-stop-tick");
  assert.equal(tick.type, "inject");
  assert.equal(tick.repeat, "0.1");
  assert.deepEqual(tick.wires, [["command-stop-claim-build"]]);
  assert.deepEqual(byId("command-stop-claim-build").wires, [["command-stop-claim-http"]]);
  assert.equal(byId("command-stop-claim-http").type, "http request");
  assert.deepEqual(byId("command-stop-claim-http").wires, [["command-stop-claim-release"]]);
  assert.deepEqual(byId("command-stop-claim-release").wires, [["command-safe-gate"]]);
  // A consulta normal de 2 s continua: ela liga a esteira e atende a parada manual.
  assert.equal(byId("command-tick").repeat, "2");
  assert.match(gatewayApi, /priority_stop_only/);
  assert.match(gatewayApi, /wait_until_epoch_ms/);
});

test("com carga ativa, monta um CLAIM só de pausa de segurança com prazo e sequência", () => {
  const store = flowStore(config(142));
  const [claim, ...rest] = send(store, T0);
  assert.equal(rest.length, 0);
  assert.equal(claim.url, "http://trace.local/api/plc_gateway.php");
  assert.equal(claim.method, "POST");
  assert.deepEqual(claim.payload, { action: "CLAIM", equipment_id: 1, priority_stop_only: true, wait_until_epoch_ms: T0 + 1500 });
  assert.equal(claim.requestTimeout, 3500, "a requisição desiste antes de a trava de 4 s vencer");
  assert.equal(claim.stopClaimSeq, 1);
  assert.equal(claim.stopClaimSentAt, T0);
  assert.equal(claim.headers["X-Device-Token"], "token-de-teste");
  assert.equal(claim.traceToken, "token-de-teste");
  assert.equal(claim.modbusHost, "192.168.1.10");
  assert.equal(claim.modbusPort, 502);
  assert.equal(claim.modbusUnit, 1);
  assert.deepEqual(claim.traceEquipment, equipment);
  // Resposta ainda não chegou (o servidor segura até 1,5 s): não manda outra.
  assert.equal(send(store, T0 + 100).length, 0);
  assert.equal(send(store, T0 + 3900).length, 0);
  // Resposta perdida: libera sozinha depois de 4 s.
  assert.equal(send(store, T0 + 4001)[0].stopClaimSeq, 2);
});

test("relógio que voltou não trava a consulta", () => {
  const store = flowStore(config(142));
  send(store, T0);
  assert.equal(send(store, T0 - 10_000).length, 1);
});

test("sem carga ativa não consulta", () => {
  assert.equal(build(flowStore(config(null)), T0), null);
  assert.equal(build(flowStore({}), T0), null);
});

test("resposta atrasada de uma consulta antiga não libera a que está em voo", () => {
  const store = flowStore(config(142));
  const [old] = send(store, T0);
  const [current] = send(store, T0 + 4001);
  assert.equal(release(reply(old, 204), store, T0 + 4500), null);
  assert.equal(send(store, T0 + 4600).length, 0, "a consulta 2 continua sendo a única em voo");
  assert.equal(release(reply(old, "ETIMEDOUT"), store, T0 + 4700), null);
  assert.equal(send(store, T0 + 7000).length, 0, "erro antigo também não mexe na espera da consulta 2");
  // Pausa reservada pela consulta antiga ainda segue para a escrita.
  const lateReserved = reply(old, 200, { data: { id: 547 } });
  assert.equal(release(lateReserved, store, T0 + 4800), lateReserved);
  assert.equal(send(store, T0 + 4900).length, 0);
  // A resposta da consulta atual libera.
  assert.equal(release(reply(current, 204), store, T0 + 5502), null);
  assert.equal(send(store, T0 + 5602).length, 1);
});

test("204 respeita 1,6 s entre consultas; falha espera 2 s; 200 libera e segue para a escrita", () => {
  const store = flowStore(config(142));
  const [first] = send(store, T0);
  // 204 depois da espera normal de 1,5 s: a próxima sai no tick seguinte.
  assert.equal(release(reply(first, 204), store, T0 + 1510), null);
  assert.equal(send(store, T0 + 1599).length, 0);
  const [second] = send(store, T0 + 1601);
  // 204 imediato (servidor com prazo vencido): não vira consulta a cada 100 ms.
  assert.equal(release(reply(second, 204), store, T0 + 1610), null);
  assert.equal(send(store, T0 + 3100).length, 0);
  const [third] = send(store, T0 + 3201);
  // Falha de rede: 2 s.
  assert.equal(release(reply(third, "ECONNREFUSED"), store, T0 + 3250), null);
  assert.equal(send(store, T0 + 5200).length, 0, "PHP fora do ar: não gera erro a cada 100 ms");
  const [fourth] = send(store, T0 + 5251);
  // Pausa reservada: libera na hora e segue para o portão.
  const reserved = reply(fourth, 200, { data: { id: 561 } });
  assert.equal(release(reserved, store, T0 + 5300), reserved);
  assert.equal(store.get("trace_safety_stop_claim_until_1"), 0);
  assert.equal(send(store, T0 + 5400).length, 1);
});

test("a pausa reservada pela consulta longa passa pelo portão e vira escrita FC5 da bobina de marcha", () => {
  const store = flowStore(config(142));
  const [claim] = send(store, T0);
  const response = reply(claim, 200, {
    data: { id: 561, command: "PAUSAR_CARREGAMENTO", loading_state: "PAUSADO", stop_reason: "PRODUTO_INCORRETO", carregamento_id: 143 },
  });
  const released = release(response, store, T0 + 40);
  const gate = new Function("msg", "env", "flow", byId("command-safe-gate").func);
  const [write, complete] = gate(released, env({
    TRACE_API_URL: "http://trace.local/",
    TRACE_PHYSICAL_CLP_ENABLED: "1",
    TRACE_IO_MAP_STATUS: "APPROVED",
    TRACE_MODBUS_MAP_EQUIPMENT_ID: "1",
    TRACE_MODBUS_COIL_CONVEYOR_RUN: "2049",
    TRACE_MODBUS_COIL_REVERSAL: "2050",
  }), flowStore());
  assert.equal(complete, null);
  assert.equal(write.commandMap.coil, 2049);
  assert.equal(write.commandMap.value, false);
  assert.equal(write.modbusHost, "192.168.1.10");
  assert.equal(write.modbusPort, 502);
  assert.equal(write.modbusUnit, 1);
});
