/** A tela só libera o encerramento após confirmar as filas locais do carregamento. */
export function podeFinalizarCarregamento(state) {
  const loading = state?.summaryMonitoring?.carregamento || {};
  const outboxPending = loading.sensor_outbox_pendentes;
  return String(state?.operationalState || "").toUpperCase() === "PAUSADO"
    && !state?.stopReason
    && Number(loading.id) > 0
    && Number(loading.id) === Number(state?.loadingId)
    && Number(loading.leituras_pendentes_sensor) === 0
    && Number(loading.imagens_pendentes) === 0
    && outboxPending !== null
    && outboxPending !== undefined
    && Number(outboxPending) === 0;
}

/** Permite conferência normal quando a diferença é apenas saca sem código, com foto exigida no servidor. */
export function divergenciaCobertaPorSemLeitura(state) {
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
    && Number(summary.produtos_incorretos || 0) === 0
    && Number(summary.sacas_retornadas || 0) === 0
    && Number(state?.excessEvidenceRequests || 0) === 0;
}

export function assinaturaProntidaoFinalizacao(state) {
  const loading = state?.summaryMonitoring?.carregamento || {};
  return [
    Number(loading.id) || null,
    loading.leituras_pendentes_sensor ?? null,
    loading.imagens_pendentes ?? null,
    loading.sensor_outbox_pendentes ?? null,
    podeFinalizarCarregamento(state),
  ];
}
