import { button, esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202609201000";
import { deviceBadge, emergencyPanel, pageHeader, physicalStateBadge, progress } from "../../js/funcoes/view.js?v=202610280005";
import { rotuloEstado, rotuloOcorrencia, rotuloStatusSincronizacao } from "../../js/funcoes/rotulos.js";
export function summary(store) {
  const data = store.state.summaryMonitoring || {
    leituras: {},
    ocorrencias: [],
    sync_pendente: 0,
  };
  const loading = data.carregamento || {};
  const isFinalized = store.state.operationalState === "FINALIZADO";
  const sensorOutboxPending = loading.sensor_outbox_pendentes;
  const sensorOutboxRejected = Number(loading.sensor_outbox_rejeitados) || 0;
  const sensorReadingsPending = Number(loading.leituras_pendentes_sensor) || 0;
  const imagesPending = Number(loading.imagens_pendentes) || 0;
  const canFinish = store.state.operationalState === "PAUSADO"
    && !store.state.stopReason
    && Number(loading.id) === Number(store.state.loadingId)
    && sensorReadingsPending === 0
    && imagesPending === 0
    && sensorOutboxPending === 0;
  const pendingNotice = sensorReadingsPending > 0
    ? `Aguardando o leitor concluir ${numero(sensorReadingsPending)} passagem(ns) detectada(s).`
    : imagesPending > 0
      ? `Aguardando o envio de ${numero(imagesPending)} imagem(ns) de evidência.`
      : sensorOutboxPending === null || sensorOutboxPending === undefined
        ? "Aguardando confirmação recente do sensor e da fila local de passagens."
        : sensorOutboxPending > 0
          ? `Aguardando o envio de ${numero(sensorOutboxPending)} passagem(ns) preservada(s) no PC.`
          : "";
  const message = isFinalized
    ? "Carregamento já finalizado."
    : canFinish
      ? "A esteira está parada pelo CLP. Confira o balanço antes de encerrar."
      : store.state.stopReason
        ? "Resolva a ocorrência e confirme a parada antes de encerrar."
        : "Atingir a meta gera um aviso; pare a esteira pelo comando da máquina para encerrar.";
  const waitingForEvidence = store.state.operationalState === "PAUSADO" && !canFinish && !store.state.stopReason
    ? `<p role="status">${esc(pendingNotice || "Aguardando confirmação dos dispositivos antes de encerrar.")}</p>`
    : "";
  const rejectedNotice = sensorOutboxRejected > 0
    ? `<p role="alert">O PC mantém ${numero(sensorOutboxRejected)} passagem(ns) rejeitada(s) no histórico do sensor. Confira o registro local antes de encerrar.</p>`
    : "";
  return `<div class="title-row with-actions has-back"><button class="button secondary page-back" data-action="back-work" type="button">← Voltar</button><div><span class="kicker">Acompanhamento / encerramento</span><h2>Resumo final</h2><p>${message}</p></div><div class="actions">${button("Exportar CSV", "export")}</div></div><section class="panel" data-status-region="loading-summary"><h3>Conferência da carga</h3><p>Romaneio #${esc(store.state.romaneio)} • caminhão ${esc(store.state.truck)}</p>${progress(store)}${waitingForEvidence}${rejectedNotice}<div class="grid four"><div class="metric"><small>Leituras líquidas</small><strong>${numero(data.leituras_liquidas ?? data.leituras.VALIDO)}</strong><small>${numero(data.leituras.VALIDO)} válidas · ${numero(data.sacas_retornadas || 0)} retornadas</small></div><div class="metric"><small>Sem leitura</small><strong>${numero(data.leituras.SEM_LEITURA)}</strong></div><div class="metric"><small>Ocorrências</small><strong>${numero(data.ocorrencias.length)}</strong></div><div class="metric"><small>Envio ao servidor</small><strong>${numero(data.sync_pendente)}</strong></div></div>${canFinish ? button("Finalizar carregamento", "finish", "primary") : ""}</section>`;
}
