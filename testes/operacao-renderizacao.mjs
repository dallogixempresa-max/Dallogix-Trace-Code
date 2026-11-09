import assert from "node:assert/strict";
import test from "node:test";
import { work } from "../interface/telas/operacao/operacao.js";

test("renderiza a operação quando há pedido pendente de liberação de emergência", () => {
  const html = work({
    state: {
      selectedLoadingId: 1,
      activeLoadings: [],
      loadingId: 1,
      loadingItems: [],
      operationalState: "EMERGENCIA",
      userRole: "SUPERVISOR",
      emergency: true,
      running: false,
      plcCommand: {
        id: 1,
        command: "DESBLOQUEAR_MAQUINA",
        status: "PENDENTE",
      },
      equipmentId: 1,
      equipmentCode: "DALA-01",
      detectedBags: 0,
      planned: 4,
      loaded: 0,
    },
  });

  assert.match(html, /work-operation-screen/);
  assert.match(html, /Aguardando confirmação do gateway para o desbloqueio/);
});

test("renderiza a finalização após a pausa confirmada na cota sem erro de inicialização", () => {
  const html = work({
    state: {
      selectedLoadingId: 1,
      activeLoadings: [],
      loadingId: 1,
      loadingItems: [{ planned_quantity: 2, loaded_quantity: 1, no_read_quantity: 1 }],
      operationalState: "PAUSADO",
      stopReason: "COTA_ROMANEIO_ATINGIDA",
      userRole: "USUARIO",
      emergency: false,
      running: false,
      plcCommand: { id: 2, command: "PAUSAR_CARREGAMENTO", status: "APLICADO" },
      equipmentId: 1,
      equipmentCode: "DALA-01",
      detectedBags: 2,
      planned: 2,
      loaded: 1,
      excessEvidenceRequests: 0,
      summaryMonitoring: { carregamento: {
        id: 1,
        leituras_pendentes_sensor: 0,
        sensor_outbox_pendentes: 0,
        imagens_pendentes: 0,
        imagens_com_falha: 0,
        sem_leitura: 1,
        sem_leitura_com_foto: 1,
      } },
    },
  });

  assert.match(html, /data-action="finish" type="button"/);
  assert.match(html, /Quantidade prevista atingida e comando de pausa confirmado/);
  assert.doesNotMatch(html.match(/<button[^>]*data-action="finish"[^>]*>/)?.[0] || "", /disabled/);
});

test("mantém a finalização bloqueada quando há passagem acima da meta", () => {
  const html = work({
    state: {
      selectedLoadingId: 1,
      activeLoadings: [],
      loadingId: 1,
      loadingItems: [{ planned_quantity: 1, loaded_quantity: 1 }],
      operationalState: "PAUSADO",
      stopReason: "EXCESSO",
      userRole: "USUARIO",
      emergency: false,
      running: false,
      plcCommand: { id: 3, command: "PAUSAR_CARREGAMENTO", status: "APLICADO" },
      equipmentId: 1,
      equipmentCode: "DALA-01",
      detectedBags: 2,
      planned: 1,
      loaded: 1,
      excessEvidenceRequests: 1,
      summaryMonitoring: { carregamento: {
        id: 1,
        leituras_pendentes_sensor: 0,
        sensor_outbox_pendentes: 0,
        imagens_pendentes: 0,
        imagens_com_falha: 0,
        sem_leitura: 0,
      } },
    },
  });

  const finishButton = html.match(/<button[^>]*data-action="finish"[^>]*>/)?.[0] || "";
  assert.match(finishButton, /disabled/);
  assert.match(html, /A finalização permanece bloqueada/);
});
