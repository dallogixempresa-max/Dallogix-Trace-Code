import { button, esc } from "../../js/funcoes/html.js";
import { data, numero } from "../../js/funcoes/formato.js?v=202609201000";
import { agora } from "../../js/funcoes/relogio.js?v=202609170015";
import { rotuloComando, rotuloEstado, rotuloStatusComando, rotuloStatusRomaneio } from "../../js/funcoes/rotulos.js?v=202609240001";
import {
  pageHeader,
  manifestsTable,
  emergencyPanel,
  statuses,
} from "../../js/funcoes/view.js?v=202610280005";

export function manifestView(store) {
  const manifest = store.state.manifestDetail;
  if (!manifest)
    return `${pageHeader("Operação / romaneios", "Romaneio", "Carregando…")}`;
  const items = manifest.items || [];
  const status = String(manifest.status || "").toUpperCase();
  const activeLoadingId = Number(manifest.active_loading_id) || null;
  const canManage = ["ADMIN_EMPRESA", "SUPERVISOR"].includes(store.state.userRole);
  const canPrepare = canManage && !activeLoadingId && ["IMPORTADO", "AGUARDANDO"].includes(status);
  const loadingCount = Number(manifest.loading_count) || 0;
  const pendingEvidenceCount = Number(manifest.pending_evidence_count) || 0;
  const canFinishManifest = ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"].includes(store.state.userRole) && status === "EM_ANDAMENTO" && !activeLoadingId && loadingCount > 0;
  const canAccessLoading = Boolean(activeLoadingId) && !["FINALIZADO", "CANCELADO"].includes(status);
  const statusTone = {
    IMPORTADO: "blue",
    AGUARDANDO: "yellow",
    EM_ANDAMENTO: "blue",
    FINALIZADO: "green",
    CANCELADO: "red",
  }[status] || "yellow";
  const auditReport =
    ["FINALIZADO", "CANCELADO"].includes(status)
      ? `<button class="button secondary" data-action="download-audit-report" data-id="${Number(manifest.id)}" type="button">Baixar relatório de auditoria (PDF)</button>`
      : "";
  const canCancel = canManage && ["IMPORTADO", "AGUARDANDO"].includes(status);
  const canCancelInProgress = canManage && status === "EM_ANDAMENTO" && activeLoadingId;
  const actionFeedback = store.state.manifestActionFeedback;
  const actionFeedbackRole = actionFeedback?.type === "error" ? "alert" : "status";
  const actionFeedbackHtml = `<p class="form-feedback manifest-action-feedback${actionFeedback?.type === "error" ? " is-error" : ""}" data-manifest-action-feedback role="${actionFeedbackRole}" aria-live="${actionFeedback?.type === "error" ? "assertive" : "polite"}"${actionFeedback?.message ? "" : " hidden"}>${esc(actionFeedback?.message || "")}</p>`;
  const prepareAction = canPrepare
    ? `<button class="button primary" data-action="prepare-manifest" data-id="${Number(manifest.id)}" type="button">Preparar carregamento</button>`
    : "";
  const accessAction = canAccessLoading
    ? `<button class="button primary" data-action="resume-loading" data-loading-id="${activeLoadingId}" type="button">Acessar operação</button>`
    : "";
  const cancelLoadingAction = canCancelInProgress
    ? `<button class="button danger" data-action="cancel-manifest-progress" data-id="${Number(manifest.id)}" type="button"${store.state.manifestActionPending ? " disabled aria-busy=\"true\"" : ""}>${store.state.manifestActionPending ? "Cancelando operação…" : "Cancelar operação"}</button>`
    : "";
  const finishManifestPanel = canFinishManifest
    ? `<section class="panel manifest-finish-panel" aria-labelledby="manifest-finish-title"><div><span class="kicker">Carregamentos concluídos</span><h3 id="manifest-finish-title">Encerrar romaneio</h3><p>${pendingEvidenceCount > 0
      ? `Aguarde o leitor enviar ${numero(pendingEvidenceCount)} imagem(ns) de falha para liberar o encerramento.`
      : "Todos os carregamentos terminaram. Encerre o romaneio para fechar a operação e liberar o relatório final."}</p></div><button class="button primary" data-action="finish-manifest" data-id="${Number(manifest.id)}" type="button"${pendingEvidenceCount > 0 || store.state.manifestActionPending ? " disabled" : ""}>${store.state.manifestActionPending ? "Encerrando…" : "Encerrar romaneio"}</button></section>`
    : "";
  const returnButton = `<button class="button secondary" data-action="goto-manifests" type="button">← Voltar</button>`;
  const quantitySummary = `<div class="manifest-detail-quantity"><span><small>Programado</small><strong>${numero(manifest.planned_quantity)}</strong></span><span><small>Sacas detectadas</small><strong>${numero(manifest.detected_quantity)}</strong></span><span><small>Códigos válidos</small><strong>${numero(manifest.loaded_quantity)}</strong></span></div>`;
  return `<div class="manifest-detail-screen" data-status-region="manifest-detail">
<header class="manifest-detail-header"><h2>Romaneio ${esc(manifest.number)}</h2><div class="manifest-detail-actions">${auditReport}${prepareAction}${accessAction}${cancelLoadingAction}${returnButton}</div></header>${actionFeedbackHtml}
<div class="manifest-detail-grid">
<article class="manifest-detail-card"><small>Data do carregamento</small><strong>${data(manifest.scheduled_date)}</strong></article>
<article class="manifest-detail-card"><small>Expedidor</small><strong>${esc(manifest.expedidor || "—")}</strong></article>
<article class="manifest-detail-card"><small>Status</small><strong><span class="badge ${statusTone}">${esc(rotuloStatusRomaneio(status))}</span></strong></article>
<article class="manifest-detail-card"><small>Motorista</small><strong>${esc(manifest.driver_name || "—")}</strong></article>
<article class="manifest-detail-card"><small>Placa</small><strong>${esc(manifest.plate || "—")}</strong></article>
<article class="manifest-detail-card manifest-detail-quantity-card"><small>Carregamento</small>${quantitySummary}</article>
</div>
<section class="panel manifest-items-panel"><h3>Itens do Romaneio</h3><div class="table-wrap"><table class="mobile-card-table manifest-items-table"><thead><tr><th>Produto</th><th>Código de Barras</th><th>Qtd. Prevista</th></tr></thead><tbody>
${items.length ? items.map((item) => `<tr><td data-label="Produto"><strong>${esc(item.name)}</strong></td><td data-label="Código"><code>${esc(item.code || "—")}</code></td><td data-label="Quantidade">${numero(item.planned_quantity)}</td></tr>`).join("") : '<tr><td colspan="3" class="empty-cell">Nenhum item cadastrado.</td></tr>'}
</tbody></table></div></section>${finishManifestPanel}${canCancel ? `<section class="panel manifest-cancel-panel"><div><strong>Cancelamento do romaneio</strong><p>Use somente se o carregamento ainda não tiver começado.</p></div>${button("Cancelar romaneio", "cancel-manifest", "danger")}</section>` : ""}</div>`;
}
