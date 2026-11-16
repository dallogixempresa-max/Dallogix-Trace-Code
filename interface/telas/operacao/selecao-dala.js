import { esc } from "../../js/funcoes/html.js";
import { numero } from "../../js/funcoes/formato.js?v=202611120004";
import { rotuloEstado } from "../../js/funcoes/rotulos.js?v=202611120004";
import { pageHeader } from "../../js/funcoes/view.js?v=202611120004";
import { podeVincularDala } from "../../js/funcoes/controles-operacao.js?v=202611150003";

export function loadingSelection(store) {
  const activeLoadings = store.state.activeLoadings || [];
  const canReassignLoading = podeVincularDala(store.state.userRole);
  const occupiedEquipmentIds = new Set(
    activeLoadings
      .filter((loading) => loading.equipment_id)
      .map((loading) => Number(loading.equipment_id)),
  );
  const availableEquipments = (store.state.equipments || []).filter(
    (equipment) => !occupiedEquipmentIds.has(Number(equipment.id)),
  );
  const detached = activeLoadings
    .filter((loading) => !loading.equipment_id)
    .map((loading) => {
      const options = availableEquipments
        .map((equipment) => `<option value="${equipment.id}">${esc(equipment.name)} • ${esc(equipment.equipment_code)}</option>`)
        .join("");
      const reassignControl = canReassignLoading
        ? `<form class="reassign-loading-form" data-loading-id="${loading.id}"><label>Selecionar nova Dala<select name="equipment_id" required ${options ? "" : "disabled"}>${options || "<option>Nenhuma Dala disponível</option>"}</select></label><button class="button primary" type="submit" ${options ? "" : 'disabled title="Nenhuma Dala disponível para vincular."'}>Vincular Dala</button><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form>`
        : '<p class="form-feedback" role="status">Solicite a um administrador da empresa ou supervisor para vincular uma nova Dala.</p>';
      return `<article class="panel detached-loading-card"><span class="kicker">Dala removida</span><h3>${esc(loading.romaneio_number || "Sem romaneio")}</h3><p>Caminhão ${esc(loading.plate || "—")}. O carregamento foi interrompido e aguarda uma nova máquina.</p>${reassignControl}</article>`;
    })
    .join("");
  const cards = activeLoadings
    .filter((loading) => loading.equipment_id)
    .map(
      (loading) =>
        `<button class="dala-selection-card" data-action="select-loading" data-id="${loading.id}" type="button"><span class="kicker">${esc(loading.equipment_code || "Dala")}</span><strong>${esc(loading.romaneio_number || "Sem romaneio")}</strong><span>Caminhão ${esc(loading.plate || "—")}</span><b>${esc(rotuloEstado(loading.state))}</b><span class="dala-selection-count">${numero(loading.detected_bags)} sacas detectadas</span><small>Selecionar esta Dala</small></button>`,
    )
    .join("");
  const feedback = store.state.commandFeedback
    ? `<p class="work-selection-error" role="alert">${esc(store.state.commandFeedback)}</p>`
    : "";
  return `${pageHeader("Operação", "Selecionar Dala", "Escolha a Dala que será acompanhada e controlada nesta tela.")}<section class="dala-selection-screen">${feedback}<div class="dala-selection-heading"><span class="kicker">Operações disponíveis</span><h3>Qual Dala você deseja operar?</h3><p>Cada seleção mantém contagem, comandos e emergência separados.</p></div>${detached ? `<div class="detached-loading-list">${detached}</div>` : ""}<div class="dala-selection-grid">${cards || (detached ? "" : '<p class="empty-cell">Nenhum carregamento disponível.</p>')}</div></section>`;
}
