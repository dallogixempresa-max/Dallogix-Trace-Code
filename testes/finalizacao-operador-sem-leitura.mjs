import assert from "node:assert/strict";
import test from "node:test";
import { divergenciaCobertaPorEvidencia, podeFinalizarCarregamento } from "../interface/js/funcoes/regras-carregamento.js";

function state({
  detected = 15,
  noRead = 1,
  captured = true,
  incorrect = 0,
  wrongPhotos = 0,
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
      sem_leitura_com_foto: captured ? noRead : 0,
      produtos_incorretos: incorrect,
      produtos_incorretos_com_foto: wrongPhotos,
      sacas_retornadas: returned,
      leituras_pendentes_sensor: 2,
      imagens_pendentes: captured ? 0 : 1,
      imagens_com_falha: 0,
      sensor_outbox_pendentes: 0,
    } },
  };
}

test("operador pode finalizar na meta física mesmo com pendências de leitura ou relatório", () => {
  const current = state();
  assert.equal(divergenciaCobertaPorEvidencia(current), true);
  assert.equal(podeFinalizarCarregamento(current), true);
  assert.equal(podeFinalizarCarregamento({
    ...current,
    stopReason: "COTA_ROMANEIO_ATINGIDA",
    summaryMonitoring: { carregamento: { ...current.summaryMonitoring.carregamento, parada_automatica_solicitada: true } },
  }), true);
  const stoppedAtQuota = {
    ...current,
    stopReason: "COTA_ROMANEIO_ATINGIDA",
    summaryMonitoring: { carregamento: { ...current.summaryMonitoring.carregamento, parada_automatica_solicitada: true } },
    plcCommand: { command: "PAUSAR_CARREGAMENTO", status: "PENDENTE" },
  };
  assert.equal(podeFinalizarCarregamento(stoppedAtQuota), true);
  assert.equal(podeFinalizarCarregamento({
    ...stoppedAtQuota,
    plcCommand: { command: "REVERSAO_ATIVAR", status: "PENDENTE" },
  }), false);
});

test("a mesma regra permite um romaneio de produto único", () => {
  const current = state();
  current.loadingItems = [{ planned_quantity: 15, loaded_quantity: 14 }];
  assert.equal(divergenciaCobertaPorEvidencia(current), true);
  assert.equal(podeFinalizarCarregamento(current), true);
});

test("conferência da evidência mantém divergências, conclusão operacional não espera foto", () => {
  assert.equal(divergenciaCobertaPorEvidencia(state({ detected: 16 })), false);
  assert.equal(divergenciaCobertaPorEvidencia(state({ excessEvidenceRequests: 1 })), false);
  assert.equal(divergenciaCobertaPorEvidencia(state({ incorrect: 1, returned: 1 })), false);
  assert.equal(podeFinalizarCarregamento(state({ captured: false })), true);
});

test("produto fora do romaneio não consome a meta; sua foto é conferida depois", () => {
  assert.equal(divergenciaCobertaPorEvidencia(state({ incorrect: 1, wrongPhotos: 1 })), true);
  assert.equal(podeFinalizarCarregamento(state({ incorrect: 1 })), true);
  assert.equal(podeFinalizarCarregamento(state({ incorrect: 1, wrongPhotos: 1 })), true);
  const withoutPhoto = state();
  withoutPhoto.summaryMonitoring.carregamento.sem_leitura_com_foto = 0;
  assert.equal(divergenciaCobertaPorEvidencia(withoutPhoto), false);
  assert.equal(podeFinalizarCarregamento(withoutPhoto), true);
});

test("divergências de quantidade ou produto não bloqueiam o relatório após a meta física", () => {
  const current = state({ noRead: 0, captured: true });
  current.loadingItems = [
    { planned_quantity: 5, loaded_quantity: 5 },
    { planned_quantity: 5, loaded_quantity: 5 },
    { planned_quantity: 5, loaded_quantity: 5 },
  ];
  assert.equal(podeFinalizarCarregamento(current), true);
  assert.equal(podeFinalizarCarregamento(state({ detected: 16 })), true);
  assert.equal(podeFinalizarCarregamento({
    ...current,
    stopReason: "EXCESSO",
    plcCommand: { command: "PAUSAR_CARREGAMENTO", status: "APLICADO" },
  }), true);
  assert.equal(podeFinalizarCarregamento({
    ...current,
    excessEvidenceRequests: 1,
  }), true);
  assert.equal(podeFinalizarCarregamento({
    ...current,
    loadingItems: [{ planned_quantity: 5, loaded_quantity: 6 }],
  }), true);
});
