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
