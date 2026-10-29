import { esc } from "../funcoes/html.js";
import { produtosDisponiveisParaRomaneio } from "../funcoes/romaneio.js?v=202610190001";
import { renderStatusDala } from "../funcoes/status-dala.js?v=202610160004";

export function operationStructureSignature(state, clpAvailable) {
  const unselectedLoadings = state.selectedLoadingId
    ? []
    : (state.activeLoadings || []).map((loading) => [
        loading.id,
        loading.equipment_id,
        loading.state,
        loading.detected_bags,
      ]);
  const loadingItems = (state.loadingItems || []).map((item) => [
    item.product_id,
    item.loaded_quantity,
    item.planned_quantity,
  ]);
  const command = state.plcCommand;
  const intent = state.commandIntent;
  return JSON.stringify([
    state.selectedLoadingId || null,
    state.loadingId || null,
    (state.activeLoadings || []).length,
    unselectedLoadings,
    state.emergency,
    state.operationalState,
    state.running,
    state.reversalCommand,
    state.returnMode,
    state.equipmentCode,
    state.stopReason || null,
    state.operatorAlert || "",
    state.truck,
    state.romaneio,
    state.userRole,
    command?.id || null,
    command?.command || null,
    command?.status || null,
    command?.response_message || null,
    intent?.id || null,
    intent?.loadingId || null,
    intent?.command || null,
    intent?.status || null,
    state.commandInFlight === true,
    state.commandFeedback || "",
    clpAvailable === true,
    loadingItems,
  ]);
}

export function linhaItemRomaneio(store, selectedId = "", quantity = 1) {
  const products = store.state.products || [];
  const selectableProducts = produtosDisponiveisParaRomaneio(products, selectedId);
  return `<tr class="manifest-item"><td><select name="item_product"><option value="">Selecionar produto…</option>${selectableProducts.map((product) => `<option value="${product.id}"${String(product.id) === String(selectedId) ? " selected" : ""}>${esc(product.name)}${product.code ? ` (${esc(product.code)})` : ""}${Number(product.active) !== 1 ? " (inativo — selecione um produto ativo)" : ""}</option>`).join("")}</select></td><td><input name="item_quantity" type="number" min="1" value="${Number(quantity) || 1}" /></td><td><button class="button ghost" data-action="remove-item" type="button">Remover</button></td></tr>`;
}

export function renderDalaStatuses(store, error = null) {
  const cells = document.querySelectorAll(".dala-status[data-equipment-id]");
  const byEquipmentId = new Map(
    (store.state.dalaStatuses || []).map((status) => [String(status.equipment_id), status]),
  );
  cells.forEach((cell) => {
    const status = byEquipmentId.get(String(cell.dataset.equipmentId));
    cell.innerHTML = renderStatusDala(status, error?.message || "");
  });
  const viewStatus = document.querySelector("#dala-view-status[data-equipment-id]");
  if (!viewStatus) return;
  const status = (store.state.dalaStatuses || []).find(
    (item) => String(item.equipment_id) === String(viewStatus.dataset.equipmentId),
  );
  if (status) {
    viewStatus.innerHTML = renderStatusDala(status, error?.message || "");
  }
}

export async function changeOperationLoading(store, render) {
  store.state.commandFeedback = "";
  let changed = true;
  try {
    await store.loadActiveLoading(null);
  } catch (error) {
    changed = false;
    store.state.commandFeedback = error?.message || "Não foi possível atualizar a lista de Dalas.";
  }
  render();
  return changed;
}

export async function atualizarStatusDasDalas(store) {
  try {
    await store.loadDalaStatuses();
    renderDalaStatuses(store);
  } catch (error) {
    renderDalaStatuses(store, error);
  }
}
