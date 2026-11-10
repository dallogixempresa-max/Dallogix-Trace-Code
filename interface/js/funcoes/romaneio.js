import { agora } from "./relogio.js?v=202611040002";
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
    const name = String(product.name || "Produto sem nome").trim();
    const code = String(product.code || "").trim();
    const canonicalValue = rotuloBuscaProduto(product);
    const values = [canonicalValue];
    if (code) values.push(`${code} — ${name}`);
    for (const value of values) {
      if (!value || seenValues.has(value)) continue;
      seenValues.add(value);
      choices.push({ productId: String(product.id), value, canonicalValue });
    }
  }
  return choices;
}

export function campoBuscaProduto(products, selectedId = "") {
  const selected = (products || []).find((product) => String(product.id) === String(selectedId));
  const label = selected
    ? `${rotuloBuscaProduto(selected)}${Number(selected.active) === 1 ? "" : " (inativo)"}`
    : "";
  return `<input class="product-search-input" type="search" name="item_product_search" list="manifest-product-options" value="${esc(label)}" placeholder="Buscar por nome ou código" autocomplete="off" aria-label="Buscar produto por nome ou código" data-product-search /><input type="hidden" name="item_product" value="${esc(selectedId)}" />`;
}

export function listaBuscaProdutos(products) {
  return `<datalist id="manifest-product-options">${opcoesBuscaProduto(products).map((choice) => `<option value="${esc(choice.value)}" data-product-id="${esc(choice.productId)}" data-canonical-value="${esc(choice.canonicalValue)}"></option>`).join("")}</datalist>`;
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
