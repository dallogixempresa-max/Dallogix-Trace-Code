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

test("sugere produtos ativos pelo nome e pelo código e exclui inativos novos", () => {
  const choices = opcoesBuscaProduto(products);
  assert.deepEqual(choices.map(({ value, productId }) => [value, productId]), [
    ["Ração Cão Adulto — 7890007", "7"],
    ["7890007 — Ração Cão Adulto", "7"],
    ["Ração Gato — 7890008", "8"],
    ["7890008 — Ração Gato", "8"],
  ]);
  assert.ok(choices.some(({ value, productId }) => value === "7890008 — Ração Gato" && productId === "8"));
  assert.ok(choices.some(({ value }) => value.startsWith("Ração Gato")));
});

test("campo mantém o ID existente e gera opções sem inserir texto do catálogo como HTML", () => {
  const row = itemRow([{ id: 12, name: "Ração <especial>", code: "X&Y", active: 1 }], "12", 3);
  const options = listaBuscaProdutos([{ id: 12, name: "Ração <especial>", code: "X&Y", active: 1 }]);
  assert.match(row, /name="item_product_search"/);
  assert.match(row, /name="item_product" value="12"/);
  assert.match(row, /name="item_quantity"[^>]*value="3"/);
  assert.match(options, /Ração &lt;especial&gt; — X&amp;Y/);
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

test("escolher uma sugestão grava o ID; editar o texto sem escolher limpa a seleção anterior", () => {
  const callbacks = {};
  const productField = { value: "7" };
  const row = { querySelector: () => productField };
  const input = {
    value: "7890008 — Ração Gato",
    closest(selector) {
      if (selector === "[data-product-search]") return this;
      if (selector === "tr") return row;
      return null;
    },
  };
  const options = [
    { value: "Ração Cão Adulto — 7890007", dataset: { productId: "7", canonicalValue: "Ração Cão Adulto — 7890007" } },
    { value: "7890008 — Ração Gato", dataset: { productId: "8", canonicalValue: "Ração Gato — 7890008" } },
  ];
  const form = {
    dataset: {},
    addEventListener(type, callback) { callbacks[type] = callback; },
    contains: () => true,
    querySelectorAll: () => options,
  };

  bindManifestCreationForm({ form });
  callbacks.input({ target: input });
  assert.equal(productField.value, "8");
  assert.equal(input.value, "Ração Gato — 7890008");

  input.value = "Ração Gato (incompleto)";
  callbacks.input({ target: input });
  assert.equal(productField.value, "");
});
