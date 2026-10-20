import { button, esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202609201000";
import { deviceBadge, emergencyPanel, pageHeader, physicalStateBadge, progress } from "../../js/funcoes/view.js?v=202610061745";
import { rotuloEstado, rotuloOcorrencia, rotuloStatusSincronizacao } from "../../js/funcoes/rotulos.js";
export function products(store) {
  const search = (store.state.productSearch || "").toLowerCase();
  const all = store.state.products || [];
const products = search
    ? all.filter((product) =>
        `${product.name} ${product.code} ${product.category || ""} ${product.barcodes || ""}`
          .toLowerCase()
          .includes(search),
      )
    : all;
  const open = store.state.productFormOpen;
  const editingId = store.state.editingProductId;
  const editing = editingId
    ? all.find((product) => Number(product.id) === Number(editingId))
    : null;
  const form = open
    ? `<section class="panel"><div class="panel-heading"><h3>${editing ? "Editar produto" : "Cadastrar produto"}</h3></div>
<form id="product-form" data-editing="${editing ? editing.id : ""}"><div class="grid four">
<label>Nome <b class="required">*</b><input name="name" required value="${editing ? esc(editing.name) : ""}" /><small>Nome comercial do produto.</small></label>
<label>Código de Barras <b class="required">*</b><input name="barcode" required value="${editing ? esc((editing.barcodes || "").split(",")[0]) : ""}" /><small>Exemplo: 7898250782592.</small></label>
<label>SKU<input name="code" value="${editing ? esc(editing.code || "") : ""}" /><small>Opcional; gerado automaticamente se ficar vazio.</small></label>
<label>Categoria<input name="category" value="${editing ? esc(editing.category || "") : ""}" /><small>Opcional.</small></label>
</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p><div class="actions${editing ? " product-edit-actions" : ""}">${button("Salvar", "submit-product", editing ? "secondary" : "primary")}${editing ? button(Number(editing.active) ? "Desativar produto" : "Ativar produto", "toggle-product-active", "secondary", `data-id="${editing.id}" data-active="${Number(editing.active) ? "1" : "0"}"`) : ""}${editing ? button("Excluir produto", "delete-product-edit", "danger", `data-id="${editing.id}" data-name="${esc(editing.name)}"`) : ""}${button("Cancelar", "cancel-product", "ghost")}</div></form></section><br>`
    : "";
  return `<div class="title-row with-actions"><div><h2>Produtos</h2></div>${button(open ? "Fechar formulário" : "+ Novo produto", "toggle-product-form")}</div>
${form}
<section class="panel product-catalog-panel"><div class="search-row"><label class="product-search-label" for="product-search">Buscar produto<input id="product-search" placeholder="Nome, código ou categoria" aria-label="Buscar por nome, código ou categoria" value="${esc(store.state.productSearch || "")}" /></label></div>
  <div class="table-wrap"><table class="mobile-card-table"><thead><tr><th>Nome</th><th>Código de Barras</th><th>SKU</th><th>Categoria</th><th>Ativo</th><th>Ações</th></tr></thead><tbody data-status-region="product-list">${
    !store.state.productsLoaded
      ? '<tr><td colspan="6" class="empty-cell">Carregando produtos…</td></tr>'
      : products.length
      ? products
          .map(
            (product) => `<tr>
\t<td data-label="Nome"><strong>${esc(product.name)}</strong></td>
\t<td data-label="Código de barras">${esc(product.barcodes || "—")}</td>
\t<td data-label="SKU">${esc(product.code || "—")}</td>
\t<td data-label="Categoria">${esc(product.category || "—")}</td>
\t<td data-label="Ativo"><span class="badge ${Number(product.active) ? "green" : "red"}">${Number(product.active) ? "Sim" : "Não"}</span></td>
\t<td data-label="Ações"><div class="table-actions"><button class="text-link" data-action="edit-product" data-id="${product.id}" type="button">Editar</button></div></td>
\t</tr>`,
          )
          .join("")
      : '<tr><td colspan="6" class="empty-cell">Nenhum produto cadastrado.</td></tr>'
  }</tbody></table></div></section>`;
}
