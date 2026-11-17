import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const controllerSource = await readFile(
  new URL("../interface/js/controladores/formulario-importacao-romaneio.js", import.meta.url),
  "utf8",
);
const { bindPdfManifestImportForm } = await import(
  `data:text/javascript;base64,${Buffer.from(controllerSource).toString("base64")}`
);

test("a prévia do PDF preenche campos e cria linhas reconhecidas e pendentes para revisão", async () => {
  const handlers = {};
  const renderedRows = [];
  const fields = {
    number: { value: "" },
    scheduled_date: { value: "2026-11-05" },
    expedidor: { value: "" },
  };
  const feedback = { className: "", textContent: "" };
  const tableBody = {
    innerHTML: "linha inicial",
    insertAdjacentHTML(_position, markup) {
      renderedRows.push(markup);
    },
  };
  const button = { disabled: false };
  const fileInput = { addEventListener() {} };
  const fileName = { textContent: "" };
  const form = {
    querySelector(selector) {
      if (selector === ".file-input") return fileInput;
      if (selector === "[data-file-name]") return fileName;
      if (selector === "button.button") return button;
      return null;
    },
    addEventListener(type, handler) {
      handlers[type] = handler;
    },
  };
  const documentRef = {
    querySelector(selector) {
      if (selector === "#pdf-import-feedback") return feedback;
      if (selector === "#manifest-items tbody") return tableBody;
      const match = selector.match(/^#new-manifest-form \[name="([^"]+)"\]$/);
      return match ? fields[match[1]] || null : null;
    },
  };
  const importedItems = [
    { product_id: 11, quantity: 10, identifier: "7890000000001", description: "Billy Dog" },
    { product_id: null, quantity: 5, identifier: "7890000000999", description: "Produto sem cadastro" },
  ];
  const store = {
    async importPdf() {
      return {
        fields: { codigo: "3978", data_iso: "2026-11-06", expedidor: "Nutridani" },
        missing: ["placa"],
        items: importedItems,
        unmatched_items: 1,
        quantity_total: 15,
      };
    },
  };
  const rendererCalls = [];

  assert.equal(
    bindPdfManifestImportForm({
      form,
      store,
      documentRef,
      renderManifestItem: (...args) => {
        rendererCalls.push(args.slice(1));
        return `<tr>${args[2]}</tr>`;
      },
      formDataFactory: () => ({}),
    }),
    true,
  );

  await handlers.submit({ preventDefault() {} });

  assert.deepEqual(fields, {
    number: { value: "3978" },
    scheduled_date: { value: "2026-11-06" },
    expedidor: { value: "Nutridani" },
  });
  assert.equal(tableBody.innerHTML, "");
  assert.equal(renderedRows.length, 2);
  assert.deepEqual(rendererCalls, [
    [11, 10, importedItems[0]],
    ["", 5, importedItems[1]],
  ]);
  assert.match(feedback.textContent, /2 item\(ns\), 15 sacas/);
  assert.match(feedback.textContent, /seleção manual do produto/);
  assert.match(feedback.textContent, /clique em Cadastrar/);
  assert.equal(feedback.className, "import-feedback is-warning");
  assert.equal(button.disabled, false);
});
