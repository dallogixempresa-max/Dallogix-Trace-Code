import { agora } from "./relogio.js?v=202609170015";
import { button, esc } from "./html.js";

export function produtosDisponiveisParaRomaneio(products, selectedId = "") {
  return products.filter(
    (product) => Number(product.active) === 1 || String(product.id) === String(selectedId),
  );
}

export function validarCadastroRomaneio({ scheduledDate, todayString, items }) {
  if (!scheduledDate || scheduledDate < todayString) {
    return "Informe uma data igual ou posterior ao dia atual do PC industrial.";
  }
  if (!Array.isArray(items) || items.length === 0) {
    return "Adicione ao menos um item com produto e quantidade.";
  }

  for (const [index, item] of items.entries()) {
    if (!String(item?.product_id || "").trim()) {
      return `Selecione um produto para o item ${index + 1}.`;
    }
    const quantity = Number(item?.quantity);
    if (!Number.isSafeInteger(quantity) || quantity < 1) {
      return `Informe uma quantidade inteira maior que zero para o item ${index + 1}.`;
    }
  }

  return "";
}

export const itemRow = (products, selectedId = "", quantity = 1) => `<tr class="manifest-item">
  <td data-label="Produto"><select name="item_product"><option value="">Selecionar produto…</option>${produtosDisponiveisParaRomaneio(products, selectedId).map((product) => `<option value="${product.id}"${String(product.id) === String(selectedId) ? " selected" : ""}>${esc(product.name)}${product.code ? ` (${esc(product.code)})` : ""}${Number(product.active) !== 1 ? " (inativo — selecione um produto ativo)" : ""}</option>`).join("")}</select></td>
  <td data-label="Quantidade"><input name="item_quantity" type="number" min="1" value="${Number(quantity) || 1}" /></td>
  <td data-label="Ações">${button("Remover", "remove-item", "ghost")}</td>
</tr>`;

export function industrialPcDate() {
  const now = agora();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}
