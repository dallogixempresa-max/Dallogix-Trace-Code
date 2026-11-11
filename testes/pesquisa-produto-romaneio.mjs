import assert from "node:assert/strict";
import test from "node:test";

import {
  campoBuscaProduto,
  itemRow,
  listaBuscaProdutos,
  opcoesBuscaProduto,
} from "../interface/js/funcoes/romaneio.js?v=202611100001";
import { bindManifestCreationForm } from "../interface/js/controladores/formulario-romaneio.js?v=202611100001";
import { linhaItemRomaneio } from "../interface/js/controladores/operacao.js?v=202611100001";

const products = [
  { id: 7, name: "Ração Cão Adulto", code: "7890007", active: 1 },
  { id: 8, name: "Ração Gato", code: "7890008", active: 1 },
  { id: 9, name: "Ração Inativa", code: "7890009", active: 0 },
];

test("sugere uma opção canônica por produto ativo e exclui inativos novos", () => {
  const choices = opcoesBuscaProduto(products);
  assert.deepEqual(choices.map(({ value, productId }) => [value, productId]), [
    ["Ração Cão Adulto — 7890007", "7"],
    ["Ração Gato — 7890008", "8"],
  ]);
  assert.ok(choices.some(({ value, productId }) => value === "Ração Gato — 7890008" && productId === "8"));
});

test("seletor mantém o ID em campo oculto e gera catálogo sem inserir texto como HTML", () => {
  const row = itemRow([{ id: 12, name: "Ração <especial>", code: "X&Y", active: 1 }], "12", 3);
  const options = listaBuscaProdutos([{ id: 12, name: "Ração <especial>", code: "X&Y", active: 1 }]);
  assert.match(row, /data-product-search/);
  assert.match(row, /name="item_product" value="12"/);
  assert.match(row, /name="item_quantity"[^>]*value="3"/);
  assert.match(options, /data-value="Ração &lt;especial&gt; — X&amp;Y"/);
  assert.equal((options.match(/data-product-id=/g) || []).length, 1);
  assert.doesNotMatch(options, /<especial>/);
  assert.match(campoBuscaProduto(products, "9"), /Ração Inativa — 7890009 \(inativo\)/);
});

test("linha de item reconhecido pelo PDF permite busca e destaca produto sem correspondência", () => {
  const row = linhaItemRomaneio(
    { state: { products } },
    "",
    2,
    { identifier: "SKU-<42>", description: "Ração especial" },
  );
  assert.match(row, /data-product-search/);
  assert.match(row, /name="item_product" value=""/);
  assert.match(row, /is-unmatched/);
  assert.match(row, /Código no PDF: SKU-&lt;42&gt;/);
  assert.match(row, /name="item_quantity"[^>]*value="2"/);
});

test("escolher uma opção grava o ID e atualiza o rótulo do produto", () => {
  const callbacks = {};
  const productField = { value: "7" };
  const trigger = {
    title: "Ração Cão Adulto — 7890007",
    setAttribute() {},
    focus() {},
  };
  const label = {
    textContent: "Ração Cão Adulto — 7890007",
    classList: { remove() {} },
  };
  const menu = { dataset: {}, classList: { remove() {} }, hidden: false };
  const results = { id: "manifest-product-results-1", replaceChildren() {} };
  const input = {
    value: "gato",
    closest(selector) {
      if (selector === "[data-product-search]") return this;
      if (selector === "[data-product-combobox]") return wrapper;
      return null;
    },
    setAttribute() {},
    removeAttribute() {},
  };
  const wrapper = {
    querySelector(selector) {
      return {
        "[name=\"item_product\"]": productField,
        "[data-product-trigger]": trigger,
        "[data-product-label]": label,
        "[data-product-search]": input,
        "[data-product-menu]": menu,
        ".product-search-results": results,
      }[selector] || null;
    },
    contains: () => true,
  };
  const option = {
    dataset: { productId: "8", value: "Ração Gato — 7890008" },
    closest(selector) {
      if (selector === "[data-product-option]") return this;
      if (selector === "[data-product-combobox]") return wrapper;
      return null;
    },
  };
  const form = {
    dataset: {},
    ownerDocument: {
      defaultView: { addEventListener() {}, removeEventListener() {} },
    },
    addEventListener(type, callback) { callbacks[type] = callback; },
    contains: (element) => element === option,
  };

  bindManifestCreationForm({ form });
  callbacks.click({ target: option });
  assert.equal(productField.value, "8");
  assert.equal(label.textContent, "Ração Gato — 7890008");
  assert.equal(trigger.title, "Ração Gato — 7890008");
});
