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
