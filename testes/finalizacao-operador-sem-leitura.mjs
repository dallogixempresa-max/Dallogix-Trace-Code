import assert from "node:assert/strict";
import test from "node:test";
import { divergenciaCobertaPorSemLeitura, podeFinalizarCarregamento } from "../interface/js/funcoes/regras-carregamento.js";

function state({
  detected = 15,
  noRead = 1,
  captured = true,
  incorrect = 0,
  returned = 0,
  excessEvidenceRequests = 0,
} = {}) {
  return {
    userRole: "USUARIO",
    loadingId: 7,
    operationalState: "PAUSADO",
    stopReason: null,
    plcCommand: null,
    excessEvidenceRequests,
    planned: 15,
    detectedBags: detected,
    loadingItems: [
      { planned_quantity: 5, loaded_quantity: 5 },
      { planned_quantity: 5, loaded_quantity: 5 },
      { planned_quantity: 5, loaded_quantity: 4 },
    ],
    summaryMonitoring: { carregamento: {
      id: 7,
      sem_leitura: noRead,
      produtos_incorretos: incorrect,
      sacas_retornadas: returned,
      leituras_pendentes_sensor: 0,
      imagens_pendentes: captured ? 0 : 1,
      imagens_com_falha: 0,
      sensor_outbox_pendentes: 0,
    } },
  };
}

test("Operador pode finalizar 15 passagens de três produtos com uma sem leitura após conferência", () => {
  const current = state();
  assert.equal(divergenciaCobertaPorSemLeitura(current), true);
  assert.equal(podeFinalizarCarregamento(current), true);
  assert.equal(podeFinalizarCarregamento({ ...current, stopReason: "COTA_ROMANEIO_ATINGIDA" }), false);
  const stoppedAtQuota = {
    ...current,
    stopReason: "COTA_ROMANEIO_ATINGIDA",
    plcCommand: { command: "PAUSAR_CARREGAMENTO", status: "APLICADO" },
  };
  assert.equal(podeFinalizarCarregamento(stoppedAtQuota), true);
  assert.equal(podeFinalizarCarregamento({
    ...stoppedAtQuota,
    plcCommand: { command: "PAUSAR_CARREGAMENTO", status: "PENDENTE" },
  }), false);
});

test("a mesma regra permite um romaneio de produto único", () => {
  const current = state();
  current.loadingItems = [{ planned_quantity: 15, loaded_quantity: 14 }];
  assert.equal(divergenciaCobertaPorSemLeitura(current), true);
  assert.equal(podeFinalizarCarregamento(current), true);
});

test("excesso, foto pendente, retorno e produto incorreto não entram na exceção", () => {
  assert.equal(divergenciaCobertaPorSemLeitura(state({ detected: 16 })), false);
  assert.equal(divergenciaCobertaPorSemLeitura(state({ excessEvidenceRequests: 1 })), false);
  assert.equal(divergenciaCobertaPorSemLeitura(state({ incorrect: 1 })), false);
  assert.equal(divergenciaCobertaPorSemLeitura(state({ returned: 1 })), false);
  assert.equal(podeFinalizarCarregamento(state({ captured: false })), false);
});
