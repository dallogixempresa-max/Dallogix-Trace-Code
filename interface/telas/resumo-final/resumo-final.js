import { button, esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202611040002";
import { deviceBadge, emergencyPanel, pageHeader, physicalStateBadge, progress } from "../../js/funcoes/view.js?v=202611040002";
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
        : Number(store.state.planned) > 0 && Number(store.state.detectedBags) >= Number(store.state.planned)
          ? "Quantidade prevista atingida. Aguarde a confirmação da parada automática e confira as passagens pendentes antes de encerrar."
          : "A carga ainda não atingiu a quantidade total prevista.";
  const waitingForEvidence = store.state.operationalState === "PAUSADO" && !canFinish && !store.state.stopReason
    ? `<p role="status">${esc(pendingNotice || "Aguardando confirmação dos dispositivos antes de encerrar.")}</p>`
    : "";
  // Sacas sem identificação: passaram pelo sensor, não tiveram leitura e o
  // romaneio tem múltiplos produtos — product_id ficou NULL. São rastreáveis
  // pelas fotos do Keyence e aparecem separadas para não distorcer a coluna
  // "Sem leitura" que inclui falhas com produto identificado.
  // leituras_sem_produto vem de resumo_carregamento.php → carregamento.*
  const semProduto = Number(loading.leituras_sem_produto) || 0;
  const semProdutoMetric = semProduto > 0
    ? `<div class="metric metric--warning"><small>Sem identificação</small><strong>${numero(semProduto)}</strong><small>produto não lido</small></div>`
    : "";
  const excessRequests = Number(loading.excessos_detectados) || 0;
  const excessCaptured = Number(loading.excessos_com_foto) || 0;
  const excessPhotoDetail = [
    Number(loading.fotos_excesso_pendentes) > 0 ? `${numero(loading.fotos_excesso_pendentes)} pendente(s)` : "",
    Number(loading.fotos_excesso_com_erro) > 0 ? `${numero(loading.fotos_excesso_com_erro)} falha(s)` : "",
  ].filter(Boolean).join(" · ");
  return `<div class="title-row with-actions has-back"><button class="button secondary page-back" data-action="back-work" type="button">← Voltar</button><div><span class="kicker">Acompanhamento / encerramento</span><h2>Resumo final</h2><p>${message}</p></div><div class="actions">${button("Exportar CSV", "export")}</div></div><section class="panel" data-status-region="loading-summary"><h3>Conferência da carga</h3><p>Romaneio #${esc(store.state.romaneio)} • caminhão ${esc(store.state.truck)}</p>${progress(store)}${waitingForEvidence}<div class="grid four"><div class="metric"><small>Sacas detectadas pelo sensor</small><strong>${numero(loading.sacas_detectadas)}</strong></div><div class="metric"><small>Sacas lidas pelo scanner</small><strong>${numero(loading.leituras_scanner)}</strong></div><div class="metric"><small>Excesso com evidência de foto</small><strong>${numero(excessCaptured)} / ${numero(excessRequests)}</strong>${excessPhotoDetail ? `<small>${esc(excessPhotoDetail)}</small>` : ""}</div><div class="metric"><small>Leituras válidas líquidas</small><strong>${numero(data.leituras_liquidas ?? data.leituras.VALIDO)}</strong><small>${numero(data.leituras.VALIDO)} válidas · ${numero(data.sacas_retornadas || 0)} retornadas</small></div><div class="metric"><small>Sem leitura</small><strong>${numero(data.leituras.SEM_LEITURA)}</strong></div>${semProdutoMetric}<div class="metric"><small>Ocorrências</small><strong>${numero(data.ocorrencias.length)}</strong></div><div class="metric"><small>Envio ao servidor</small><strong>${numero(data.sync_pendente)}</strong></div></div>${canFinish ? button("Finalizar carregamento", "finish", "primary") : ""}</section>`;
}
