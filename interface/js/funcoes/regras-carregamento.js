/** Explica a pendência que ainda impede o encerramento seguro do carregamento. */
export function motivoBloqueioFinalizacao(state) {
  const loading = state?.summaryMonitoring?.carregamento || {};
  const operationalState = String(state?.operationalState || "").toUpperCase();
  if (operationalState !== "PAUSADO") {
    return "A esteira precisa estar pausada antes de finalizar.";
  }
  const pauseCommandInFlight = [state?.plcCommand, state?.commandIntent].some((command) =>
    String(command?.command || "").toUpperCase() === "PAUSAR_CARREGAMENTO"
      && ["PENDENTE", "PROCESSANDO"].includes(String(command?.status || "").toUpperCase()),
  );
  const pendingNonPauseCommand = [state?.plcCommand, state?.commandIntent].some((command) =>
    ["PENDENTE", "PROCESSANDO"].includes(String(command?.status || "").toUpperCase())
      && String(command?.command || "").toUpperCase() !== "PAUSAR_CARREGAMENTO",
  ) || (state?.commandInFlight === true && !pauseCommandInFlight);
  if (pendingNonPauseCommand) {
    return "Aguarde o CLP concluir o outro comando da máquina antes de finalizar.";
  }
  const stopRequested = loading.parada_automatica_solicitada === true
    || Number(loading.parada_automatica_solicitada) === 1
    || [state?.plcCommand, state?.commandIntent].some((command) =>
      String(command?.command || "").toUpperCase() === "PAUSAR_CARREGAMENTO"
        && ["PENDENTE", "PROCESSANDO", "APLICADO"].includes(String(command?.status || "").toUpperCase()),
    );
  if (state?.stopReason && !stopRequested) {
    return "A solicitação de pausa da esteira não foi registrada. Solicite a pausa antes de finalizar.";
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

/** Libera o fechamento na meta física após a pausa ser solicitada; leituras pendentes seguem para auditoria. */
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
    loading.parada_automatica_solicitada ?? null,
    loading.leituras_pendentes_sensor ?? null,
    loading.imagens_pendentes ?? null,
    loading.imagens_com_falha ?? null,
    loading.sensor_outbox_pendentes ?? null,
    loading.sem_leitura_evidencias_obrigatorias ?? null,
    loading.sem_leitura_evidencias_com_imagem ?? null,
    motivoBloqueioFinalizacao(state),
  ];
}
