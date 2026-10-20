import { button, esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202609201000";
import { deviceBadge, emergencyPanel, pageHeader, physicalStateBadge, progress } from "../../js/funcoes/view.js?v=202610061745";
import { rotuloEstado, rotuloOcorrencia, rotuloStatusSincronizacao } from "../../js/funcoes/rotulos.js";
export function summary(store) {
  const data = store.state.summaryMonitoring || {
    leituras: {},
    ocorrencias: [],
    sync_pendente: 0,
  };
  const canFinish = ["FINALIZANDO", "CARREGANDO"].includes(store.state.operationalState);
  return `<div class="title-row with-actions has-back"><button class="button secondary page-back" data-action="back-work" type="button">← Voltar</button><div><span class="kicker">Acompanhamento / encerramento</span><h2>Resumo final</h2><p>${canFinish ? "Confira o balanço antes de finalizar a carga." : "Carregamento já finalizado."}</p></div><div class="actions">${button("Exportar CSV", "export")}</div></div><section class="panel" data-status-region="loading-summary"><h3>Conferência da carga</h3><p>Romaneio #${esc(store.state.romaneio)} • caminhão ${esc(store.state.truck)}</p>${progress(store)}<div class="grid four"><div class="metric"><small>Leituras válidas</small><strong>${numero(data.leituras.VALIDO)}</strong></div><div class="metric"><small>Sem leitura</small><strong>${numero(data.leituras.SEM_LEITURA)}</strong></div><div class="metric"><small>Ocorrências</small><strong>${numero(data.ocorrencias.length)}</strong></div><div class="metric"><small>Envio ao servidor</small><strong>${numero(data.sync_pendente)}</strong></div></div>${canFinish ? button("Finalizar carregamento", "finish", "primary") : ""}</section>`;
}
