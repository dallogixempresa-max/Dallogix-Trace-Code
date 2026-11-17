function definirFeedback(element, message, tone = "") {
  if (!element) return;
  element.className = `import-feedback${tone ? ` is-${tone}` : ""}`;
  element.textContent = message;
}

function nomeDoArquivo(form) {
  const file = form.querySelector(".file-input");
  const fileName = form.querySelector("[data-file-name]");
  file?.addEventListener("change", () => {
    if (fileName) fileName.textContent = file.files?.[0]?.name || "Nenhum arquivo escolhido";
  });
}

export function bindPdfManifestImportForm({
  form,
  store,
  documentRef = document,
  renderManifestItem,
  formDataFactory = (target) => new FormData(target),
} = {}) {
  if (!form) return false;

  const feedback = documentRef.querySelector("#pdf-import-feedback");
  const showFeedback = (message, tone = "") => definirFeedback(feedback, message, tone);
  nomeDoArquivo(form);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button.button");
    if (button) button.disabled = true;
    showFeedback("Lendo o PDF…");
    try {
      const respostaPdf = await store.importPdf(formDataFactory(form));
      const camposPdf = respostaPdf.fields || {};
      for (const [name, value] of Object.entries({
        number: camposPdf.codigo,
        scheduled_date: camposPdf.data_iso,
        plate: camposPdf.placa,
        expedidor: camposPdf.expedidor,
        driver_name: camposPdf.motorista,
      })) {
        const input = documentRef.querySelector(`#new-manifest-form [name="${name}"]`);
        if (input && value) input.value = value;
      }

      const itensImportados = Array.isArray(respostaPdf.items) ? respostaPdf.items : [];
      const itensRomaneio = documentRef.querySelector("#manifest-items tbody");
      if (itensRomaneio && itensImportados.length > 0) {
        itensRomaneio.innerHTML = "";
        for (const item of itensImportados) {
          itensRomaneio.insertAdjacentHTML(
            "beforeend",
            renderManifestItem(store, item.product_id || "", item.quantity, item),
          );
        }
      }

      const labelsCampos = {
        codigo: "código do pedido",
        data: "data de carregamento (a data de hoje foi mantida)",
        expedidor: "expedidor",
        placa: "placa do caminhão",
        motorista: "motorista",
      };
      const camposAusentes = (respostaPdf.missing || []).map((key) => labelsCampos[key] || key);
      const itensSemProduto = Number(respostaPdf.unmatched_items || 0);
      const quantidadeTotal = Number(respostaPdf.quantity_total || 0);
      const partesMensagem = [
        `Leitura concluída: ${itensImportados.length} item(ns), ${quantidadeTotal} sacas.`,
      ];
      if (itensSemProduto > 0) {
        partesMensagem.push(`${itensSemProduto} item(ns) precisam de seleção manual do produto.`);
      }
      if (camposAusentes.length > 0) {
        partesMensagem.push(`Não encontrados no PDF: ${camposAusentes.join(", ")}.`);
      }
      partesMensagem.push("Confira os dados e clique em Cadastrar para criar o romaneio.");
      showFeedback(partesMensagem.join(" "), itensSemProduto || camposAusentes.length ? "warning" : "success");
    } catch (error) {
      showFeedback(error.message || "Não foi possível importar o PDF.", "error");
    } finally {
      if (button) button.disabled = false;
    }
  });

  return true;
}

export function bindCsvManifestImportForm({
  form,
  api,
  store,
  documentRef = document,
  formDataFactory = (target) => new FormData(target),
} = {}) {
  if (!form) return false;

  const feedback = documentRef.querySelector("#csv-import-feedback");
  const showFeedback = (message, tone = "") => definirFeedback(feedback, message, tone);
  nomeDoArquivo(form);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button.button");
    if (button) button.disabled = true;
    showFeedback("Validando o CSV…");
    try {
      const response = await api.fetch("/api/importar_csv.php", {
        method: "POST",
        headers: store.csrfToken ? { "X-CSRF-Token": store.csrfToken } : {},
        body: formDataFactory(form),
      });
      const respostaCsv = await response.json();
      if (!response.ok) throw new Error(respostaCsv.error || "Falha ao importar CSV.");
      await store.loadManifests();
      const {
        romaneios,
        items: itens,
        linhas,
      } = respostaCsv.data;
      showFeedback(
        `${romaneios} romaneio(s), ${itens} item(ns) consolidados em ` +
          `${linhas} linha(s) importada(s).`,
        "success",
      );
    } catch (error) {
      showFeedback(error.message || "Falha ao importar CSV.", "error");
    } finally {
      if (button) button.disabled = false;
    }
  });

  return true;
}
