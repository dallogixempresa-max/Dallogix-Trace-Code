import { agora } from "./relogio.js?v=202611120002";
import { button, esc } from "./html.js";

export function produtosDisponiveisParaRomaneio(products, selectedId = "") {
  return products.filter(
    (product) => Number(product.active) === 1 || String(product.id) === String(selectedId),
  );
}

function rotuloBuscaProduto(product) {
  const name = String(product?.name || "Produto sem nome").trim();
  const code = String(product?.code || "").trim();
  return code ? `${name} — ${code}` : `${name} — ID ${product.id}`;
}

export function opcoesBuscaProduto(products) {
  const choices = [];
  const seenValues = new Set();
  for (const product of products || []) {
    if (Number(product.active) !== 1) continue;
    const canonicalValue = rotuloBuscaProduto(product);
    if (!canonicalValue || seenValues.has(canonicalValue)) continue;
    seenValues.add(canonicalValue);
    choices.push({
      productId: String(product.id),
      value: canonicalValue,
      canonicalValue,
      name: String(product.name || "Produto sem nome").trim(),
      code: String(product.code || "").trim() || `ID ${product.id}`,
    });
  }
  return choices;
}

let productSearchSequence = 0;

export function campoBuscaProduto(products, selectedId = "") {
  const selected = (products || []).find((product) => String(product.id) === String(selectedId));
  const label = selected
    ? `${rotuloBuscaProduto(selected)}${Number(selected.active) === 1 ? "" : " (inativo)"}`
    : "";
  const resultsId = `manifest-product-results-${++productSearchSequence}`;
  return `<div class="product-search-combobox" data-product-combobox><input class="product-picker-search${label ? "" : " is-placeholder"}" type="search" value="${esc(label)}" data-selected-value="${esc(label)}" placeholder="Digite o nome ou código do produto" autocomplete="off" aria-label="Produto do romaneio" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="${resultsId}" data-product-search /><input type="hidden" name="item_product" value="${esc(selectedId)}" /><div class="product-search-menu" data-product-menu popover="manual"><div class="product-search-results" id="${resultsId}" role="listbox"></div></div></div>`;
}

export function listaBuscaProdutos(products) {
  return `<div class="product-search-catalog" id="manifest-product-options" hidden aria-hidden="true">${opcoesBuscaProduto(products).map((choice) => `<span data-product-id="${esc(choice.productId)}" data-value="${esc(choice.value)}" data-name="${esc(choice.name)}" data-code="${esc(choice.code)}"></span>`).join("")}</div>`;
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
  <td data-label="Produto">${campoBuscaProduto(products, selectedId)}</td>
  <td data-label="Quantidade"><input name="item_quantity" type="number" min="1" value="${Number(quantity) || 1}" /></td>
  <td data-label="Ações">${button("Remover", "remove-item", "ghost")}</td>
</tr>`;

export function industrialPcDate() {
  const now = agora();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}
