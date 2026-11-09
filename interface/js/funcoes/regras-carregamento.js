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
  const automaticQuotaStop = ["COTA_ROMANEIO_ATINGIDA", "EXCESSO"].includes(state?.stopReason)
    && String(state?.plcCommand?.command || "").toUpperCase() === "PAUSAR_CARREGAMENTO"
    && String(state?.plcCommand?.status || "").toUpperCase() === "APLICADO";
  if (state?.stopReason && !automaticQuotaStop) {
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
  const excessDetected = String(state?.stopReason || "").toUpperCase() === "EXCESSO"
    || detected > planned
    || Number(state?.excessEvidenceRequests ?? loading.excess_evidence_requests ?? loading.excessos_detectados ?? 0) > 0
    || (state?.loadingItems || []).some((item) =>
      Number(item.loaded_quantity || 0) > Number(item.planned_quantity || 0),
    );
  if (excessDetected) {
    return "O sensor registrou passagem acima da quantidade prevista. Não é possível finalizar este carregamento.";
  }
  if (detected < planned) {
    return "Aguarde o sensor atingir a quantidade prevista do romaneio.";
  }
  const pendingSensor = Number(loading.leituras_pendentes_sensor);
  if (loading.leituras_pendentes_sensor === null
    || loading.leituras_pendentes_sensor === undefined
    || !Number.isFinite(pendingSensor)
    || pendingSensor > 0) {
    return "Aguarde o leitor concluir a conferência das passagens detectadas.";
  }
  const outboxPending = loading.sensor_outbox_pendentes;
  const pendingOutboxCount = Number(outboxPending);
  if (outboxPending === null || outboxPending === undefined
    || !Number.isFinite(pendingOutboxCount)
    || pendingOutboxCount > 0) {
    return "Aguarde o sensor confirmar o envio de todas as passagens.";
  }
  const pendingImagesValue = loading.imagens_pendentes;
  const pendingImages = Number(pendingImagesValue);
  if (pendingImagesValue === null || pendingImagesValue === undefined || !Number.isFinite(pendingImages)) {
    return "Aguarde o recebimento das fotos de evidência.";
  }
  if (pendingImages > 0) {
    const photoLabel = pendingImages === 1 ? "foto" : "fotos";
    return `Aguarde o recebimento de ${pendingImages} ${photoLabel} de evidência.`;
  }
  if (Number(loading.imagens_com_falha) > 0) {
    return "Há foto(s) de evidência com falha. Verifique o envio do Keyence/FTP antes de finalizar.";
  }
  const noReadCount = Number(loading.sem_leitura) || 0;
  if (noReadCount > 0) {
    const photographedNoReads = Number(loading.sem_leitura_com_foto);
    if (!Number.isFinite(photographedNoReads) || photographedNoReads < noReadCount) {
      return "Aguarde a foto Keyence de cada saca sem leitura antes de finalizar.";
    }
  }
  return "";
}

/** Só libera o fechamento com meta física atingida, parada confirmada e filas concluídas. */
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
    && Number(summary.produtos_incorretos || 0) === 0
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
    motivoBloqueioFinalizacao(state),
  ];
}
