/** Explica a pendência que ainda impede o encerramento seguro do carregamento. */
export function motivoBloqueioFinalizacao(state) {
  const loading = state?.summaryMonitoring?.carregamento || {};
  const operationalState = String(state?.operationalState || "").toUpperCase();
  if (operationalState !== "PAUSADO") {
    return "Confirme a parada física da esteira antes de finalizar.";
  }
  const hasPendingMachineCommand = state?.commandInFlight === true
    || [state?.plcCommand, state?.commandIntent].some((command) =>
      ["PENDENTE", "PROCESSANDO"].includes(String(command?.status || "").toUpperCase()),
    );
  if (hasPendingMachineCommand) {
    return "Aguarde o CLP confirmar o comando da máquina antes de finalizar.";
  }
  const stopConfirmed = loading.parada_automatica_confirmada === true
    || Number(loading.parada_automatica_confirmada) === 1
    || (String(state?.plcCommand?.command || "").toUpperCase() === "PAUSAR_CARREGAMENTO"
      && String(state?.plcCommand?.status || "").toUpperCase() === "APLICADO");
  if (state?.stopReason && !stopConfirmed) {
    return "Confirme a parada física solicitada antes de finalizar.";
  }
  if (Number(loading.id) <= 0 || Number(loading.id) !== Number(state?.loadingId)) {
    return "Atualizando os dados do carregamento. Aguarde e tente novamente.";
  }
  const planned = Number(state?.planned) || 0;
  const detected = Number(state?.detectedBags) || 0;
  if (planned <= 0) {
    return "A quantidade prevista do romaneio não está informada.";
  }
  if (detected < planned) {
    return "Aguarde o sensor atingir a quantidade prevista do romaneio.";
  }
  return "";
}

/** Libera o fechamento na meta física com parada confirmada; divergências seguem para o relatório. */
export function podeFinalizarCarregamento(state) {
  return motivoBloqueioFinalizacao(state) === "";
}

/** Permite ao operador fechar apenas a divergência de saca sem leitura com foto. */
export function divergenciaCobertaPorEvidencia(state) {
  const summary = state?.summaryMonitoring?.carregamento || {};
  const planned = Number(state?.planned) || 0;
  const detected = Number(state?.detectedBags) || 0;
  const noRead = Number(summary.sem_leitura) || 0;
  const items = state?.loadingItems || [];
  const shortfall = items.reduce((sum, item) => sum + Math.max(0,
    Number(item.planned_quantity || 0) - Number(item.loaded_quantity || 0)), 0);
  const overfill = items.some((item) => Number(item.loaded_quantity || 0) > Number(item.planned_quantity || 0));
  return planned > 0 && detected === planned && noRead > 0
    && shortfall === noRead && !overfill
    && Number(summary.sacas_retornadas || 0) === 0
    && Number(summary.sem_leitura_com_foto) >= noRead
    && Number(state?.excessEvidenceRequests || 0) === 0
    && Number(summary.imagens_pendentes || 0) === 0
    && Number(summary.imagens_com_falha || 0) === 0
    && Number(state?.excessEvidencePending || 0) === 0
    && Number(state?.excessEvidenceFailed || 0) === 0;
}

export const divergenciaCobertaPorSemLeitura = divergenciaCobertaPorEvidencia;

export function assinaturaProntidaoFinalizacao(state) {
  const loading = state?.summaryMonitoring?.carregamento || {};
  return [
    Number(loading.id) || null,
    loading.leituras_pendentes_sensor ?? null,
    loading.imagens_pendentes ?? null,
    loading.imagens_com_falha ?? null,
    loading.sensor_outbox_pendentes ?? null,
    loading.sem_leitura_evidencias_obrigatorias ?? null,
    loading.sem_leitura_evidencias_com_imagem ?? null,
    motivoBloqueioFinalizacao(state),
  ];
}
