import { numero } from "./formato.js?v=202611120004";
import { resolverComandoAtual } from "./estado-comando.js?v=202611120004";
import { podeFinalizarCarregamento } from "./regras-carregamento.js?v=202611120004";

/** Uma apresentação para o primeiro render e as atualizações ao vivo. */
export function apresentacaoProgressoOperacao(state) {
  const plannedTotal = Number(state.planned) || 0;
  const detectedBags = Number(state.detectedBags) || 0;
  const validReadings = Number(state.loaded) || 0;
  const remainingTotal = Math.max(0, plannedTotal - detectedBags);
  const command = resolverComandoAtual(state.plcCommand, state.commandIntent);
  const pendingCommand = String(command?.command || "").toUpperCase();
  const pendingStatus = String(command?.status || "").toUpperCase();
  const quotaStopReason = ["COTA_ROMANEIO_ATINGIDA", "EXCESSO"].includes(state.stopReason);
  const finishReady = podeFinalizarCarregamento(state);
  const nearEndThreshold = plannedTotal > 0 ? Math.max(1, Math.ceil(plannedTotal * 0.1)) : 0;
  const nearEnd = plannedTotal > 0 && detectedBags > 0 && remainingTotal > 0 && remainingTotal <= nearEndThreshold;
  const complete = plannedTotal > 0 && detectedBags >= plannedTotal;
  const excess = plannedTotal > 0 && detectedBags > plannedTotal;
  const quotaPauseConfirmed = quotaStopReason
    && state.operationalState === "PAUSADO"
    && pendingCommand === "PAUSAR_CARREGAMENTO"
    && pendingStatus === "APLICADO";
  const endNotice = excess
    ? `Passagem acima da quantidade prevista registrada. ${quotaPauseConfirmed ? "O gateway confirmou o comando de pausa." : "Um comando de pausa foi solicitado ao gateway."} A finalização permanece bloqueada.`
    : complete
      ? finishReady
        ? `Quantidade prevista atingida e comando de pausa confirmado. Leituras e fotos conferidas; finalize o romaneio.`
        : `Quantidade prevista atingida. ${quotaPauseConfirmed ? "O gateway confirmou o comando de pausa." : "Aguardando o comando de pausa do gateway."} Aguarde a conferência das leituras e fotos para liberar a finalização.`
      : nearEnd
        ? "Atenção: o carregamento está próximo da quantidade prevista."
        : "";
  const endNoticeTone = excess ? "excess" : complete ? "complete" : "near-end";
  const remainingLabel = plannedTotal <= 0
    ? "Quantidade prevista não informada"
    : remainingTotal === 0
      ? `Quantidade física atingida · ${numero(validReadings)} códigos válidos`
      : `Falta${remainingTotal === 1 ? "" : "m"} ${numero(remainingTotal)} saca${remainingTotal === 1 ? "" : "s"} para a quantidade prevista`;
  return { endNotice, endNoticeTone, remainingLabel, complete, excess, nearEnd };
}
