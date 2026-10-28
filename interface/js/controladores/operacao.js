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

export function captureOperationScanner(root, activeElement = globalThis.document?.activeElement) {
  const selector = ".manual-operation-reading-form input[name=\"barcode\"]";
  if (!root?.contains?.(activeElement) || !activeElement?.matches?.(selector)) return null;
  return {
    value: activeElement.value,
    selectionStart: activeElement.selectionStart,
    selectionEnd: activeElement.selectionEnd,
    selectionDirection: activeElement.selectionDirection,
  };
}

export function restoreOperationScanner(root, snapshot) {
  if (!snapshot) return false;
  const input = root?.querySelector?.(".manual-operation-reading-form input[name=\"barcode\"]");
  if (!input || input.disabled) return false;
  input.value = snapshot.value;
  input.focus({ preventScroll: true });
  if (Number.isInteger(snapshot.selectionStart) && Number.isInteger(snapshot.selectionEnd)) {
    input.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd, snapshot.selectionDirection || "none");
  }
  return true;
}

export function focusOperationScanner(root, activeElement = globalThis.document?.activeElement) {
  const input = root?.querySelector?.(".manual-operation-reading-form input[name=\"barcode\"]");
  if (!input || input.disabled) return false;
  if (activeElement !== input) {
    const isEditable = activeElement?.matches?.(
      "input, select, textarea, [contenteditable='true']",
    );
    const isDialogControl = Boolean(
      activeElement?.closest?.("[role='dialog'], dialog, [aria-modal='true']"),
    );
    if (isEditable || isDialogControl) return false;
  }
  if (activeElement !== input) input.focus({ preventScroll: true });
  return true;
}

export function updateOperationScannerFocus(root, activeElement = globalThis.document?.activeElement) {
  const status = root?.querySelector?.("[data-scanner-focus]");
  const input = root?.querySelector?.(".manual-operation-reading-form input[name=\"barcode\"]");
  if (!status || !input) return false;
  const ready = !input.disabled && activeElement === input;
  status.textContent = input.disabled
    ? "Leitor indisponível enquanto a leitura estiver bloqueada."
    : ready
      ? "Leitor pronto para leitura."
      : "Leitor sem foco. Toque no campo Código de barras antes de ler.";
  status.classList.toggle("is-error", !ready);
  return ready;
}

export function instalarRecuperacaoFocoLeitor(root) {
  if (!root?.addEventListener || root.dataset?.scannerFocusRecovery === "installed") return false;
  if (root.dataset) root.dataset.scannerFocusRecovery = "installed";
  root.addEventListener("pointerup", (event) => {
    const target = event.target;
    if (target?.closest?.("input, select, textarea, [contenteditable='true'], [role='dialog'], dialog, [aria-modal='true']")) return;
    globalThis.setTimeout?.(() => {
      if (!root.isConnected) return;
      const input = root.querySelector?.(".manual-operation-reading-form input[name=\"barcode\"]");
      if (!input || input.disabled) return;
      const activeElement = globalThis.document?.activeElement;
      if (activeElement?.closest?.("[role='dialog'], dialog, [aria-modal='true']")) return;
      focusOperationScanner(root, activeElement);
      updateOperationScannerFocus(root, globalThis.document?.activeElement);
    }, 0);
  });
  return true;
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
