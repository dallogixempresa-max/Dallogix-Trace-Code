import { button, esc } from "../../js/funcoes/html.js";
import { data, numero } from "../../js/funcoes/formato.js?v=202609201000";
import { agora } from "../../js/funcoes/relogio.js?v=202609170015";
import { rotuloComando, rotuloEstado, rotuloStatusComando, rotuloStatusRomaneio } from "../../js/funcoes/rotulos.js?v=202609240001";
import {
  pageHeader,
  manifestsTable,
  emergencyPanel,
  statuses,
} from "../../js/funcoes/view.js?v=202610061745";

import { itemRow, industrialPcDate } from "../../js/funcoes/romaneio.js?v=202610190001";
export function manifestEdit(store) {
  const manifest = store.state.manifestDetail;
  if (!manifest) return `${pageHeader("Operação / romaneios", "Editar romaneio", "Carregando…")}`;
  const editable = ["IMPORTADO", "AGUARDANDO"].includes(manifest.status);
  if (!editable) return `${pageHeader("Operação / romaneios", "Romaneio não editável", "Este romaneio já foi iniciado ou encerrado e não pode mais ser alterado.", `<div class="actions">${button("Voltar", "goto-manifests", "secondary")}${button("Visualizar romaneio", "view-manifest", "primary", `data-id="${manifest.id}"`)}</div>`)}`;
  const today = industrialPcDate();
  const rows = (manifest.items || []).map((item) => itemRow(store.state.products || [], item.product_id, item.planned_quantity)).join("") || itemRow(store.state.products || []);
  return `<div class="title-row has-back"><button class="button secondary page-back" data-action="goto-manifests" type="button">← Voltar</button><div><h2>Editar romaneio ${esc(manifest.number)}</h2><p>Alterações permitidas somente antes do início do carregamento.</p></div></div>
  <form id="edit-manifest-form" data-id="${manifest.id}"><section class="panel"><div class="grid three">
  <label>Código<input name="number" required value="${esc(manifest.number)}" /></label>
  <label>Data do carregamento<input name="scheduled_date" type="date" min="${today}" value="${esc(manifest.scheduled_date)}" required /></label>
  <label>Placa do caminhão<input name="plate" required value="${esc(manifest.plate || "")}" /></label>
  <label>Expedidor<input name="expedidor" value="${esc(manifest.expedidor || "")}" /></label>
  <label>Motorista<input name="driver_name" value="${esc(manifest.driver_name || "")}" /></label>
  </div></section><br><section class="panel"><div class="panel-heading"><h3>Itens do romaneio</h3>${button("Adicionar item", "add-item", "secondary")}</div><div class="table-wrap"><table id="manifest-items" class="mobile-card-table"><thead><tr><th>Produto</th><th>Quantidade</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></section><br><div class="actions"><button class="button secondary" data-action="goto-manifests" type="button">Cancelar</button>${button("Salvar alterações", "submit-manifest-edit")}</div></form>`;
}
