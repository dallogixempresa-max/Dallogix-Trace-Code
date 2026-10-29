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

const STATUS_OPTIONS = [
  ["", "Todos os status"],
  ["IMPORTADO", "Importado"],
  ["AGUARDANDO", "Aguardando"],
  ["EM_ANDAMENTO", "Em andamento"],
  ["FINALIZADO", "Finalizado"],
  ["CANCELADO", "Cancelado"],
];

function equipmentLabel(store) {
  return store.state.equipmentCode && store.state.equipmentCode !== "—"
    ? store.state.equipmentCode
    : store.state.equipments?.[0]?.equipment_code || "Esteira";
}

export function manifests(store) {
  const filters = store.state.manifestFilters || {};
  const options = STATUS_OPTIONS.map(
    ([value, label]) =>
      `<option value="${value}"${filters.status === value ? " selected" : ""}>${label}</option>`,
  ).join("");
  const canCreate = ["ADMIN_EMPRESA", "SUPERVISOR"].includes(store.state.userRole);
  const meta = store.state.manifestMeta || {};
  const currentPage = Number(meta.page || 1);
  const pages = Number(meta.pages || 0);
  const pagination = pages > 1
    ? `<nav class="table-pagination" aria-label="Paginação de romaneios"><span>${numero(meta.total)} romaneio(s) · página ${currentPage} de ${pages}</span><div class="actions"><button class="button secondary small" data-action="manifest-page" data-page="${currentPage - 1}" type="button" ${currentPage <= 1 ? "disabled" : ""}>Anterior</button><button class="button secondary small" data-action="manifest-page" data-page="${currentPage + 1}" type="button" ${currentPage >= pages ? "disabled" : ""}>Próxima</button></div></nav>`
    : "";
  const actionFeedback = store.state.manifestActionFeedback;
  const actionFeedbackRole = actionFeedback?.type === "error" ? "alert" : "status";
  const actionFeedbackHtml = actionFeedback?.message
    ? `<p class="form-feedback manifest-action-feedback${actionFeedbackRole === "alert" ? " is-error" : ""}" data-manifest-action-feedback role="${actionFeedbackRole}" aria-live="${actionFeedbackRole === "alert" ? "assertive" : "polite"}">${esc(actionFeedback.message)}</p>`
    : "";
  return `${pageHeader("Operação", "Romaneios", "Consulte os carregamentos e abra uma operação.", canCreate ? button("Novo romaneio", "new-manifest") : "")}
${actionFeedbackHtml}
<div class="filter-toolbar"><button class="button secondary filter-toggle" data-action="toggle-manifest-filters" aria-expanded="true" aria-controls="manifest-filters-panel" type="button">Filtros de pesquisa</button></div>
<section class="panel filters" id="manifest-filters-panel"><form id="manifest-filters"><div class="filter-grid">
<label>Data inicial<input name="date_from" type="date" value="${esc(filters.date_from || "")}" /></label>
<label>Data final<input name="date_to" type="date" value="${esc(filters.date_to || "")}" /></label>
<label>Código do romaneio<input name="number" value="${esc(filters.number || "")}" /></label>
<label>Expedidor<input name="expedidor" value="${esc(filters.expedidor || "")}" /></label>
<label>Status<select name="status">${options}</select></label>
</div></form></section><br><div data-status-region="manifest-list">${manifestsTable(store.manifests)}${pagination}</div>`;
}

// Detalhe do romaneio (tela Visualizar).
