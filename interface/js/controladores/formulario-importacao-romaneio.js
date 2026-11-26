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

function plural(quantidade, singular, pluralTexto) {
  return `${quantidade} ${quantidade === 1 ? singular : pluralTexto}`;
}

/**
 * Relatório por romaneio da importação CSV: importados (verde, ou âmbar com
 * pendências do que não veio), rejeitados (vermelho, com linha e motivo) e
 * ignorados (cinza). Usa textContent para não interpretar dados do arquivo.
 */
export function renderizarRelatorioCsv(element, resumo, documentRef = document) {
  if (!element) return;
  const importados = Array.isArray(resumo?.importados) ? resumo.importados : [];
  const rejeitados = Array.isArray(resumo?.rejeitados) ? resumo.rejeitados : [];
  const ignorados = Array.isArray(resumo?.ignorados) ? resumo.ignorados : [];
  const comPendencia = importados.filter((item) => (item.pendencias || []).length > 0);

  const rejeitadosPorRomaneio = new Map();
  for (const rejeitado of rejeitados) {
    const chave = rejeitado.romaneio || "";
    if (!rejeitadosPorRomaneio.has(chave)) rejeitadosPorRomaneio.set(chave, []);
    rejeitadosPorRomaneio.get(chave).push(rejeitado);
  }

  const tom = importados.length === 0
    ? "error"
    : comPendencia.length || rejeitados.length || ignorados.length
      ? "warning"
      : "success";
  definirFeedback(element, "", tom);

  const resumoTexto = documentRef.createElement("p");
  resumoTexto.className = "csv-import-summary";
  const partes = [`${plural(importados.length, "romaneio importado", "romaneios importados")}`];
  if (comPendencia.length) partes.push(`${comPendencia.length} com pendências`);
  if (rejeitadosPorRomaneio.size) {
    partes.push(plural(rejeitadosPorRomaneio.size, "rejeitado", "rejeitados"));
  }
  if (ignorados.length) partes.push(plural(ignorados.length, "ignorado", "ignorados"));
  resumoTexto.textContent = `${partes.join(", ")}.`;
  element.append(resumoTexto);

  const lista = documentRef.createElement("ul");
  lista.className = "csv-import-report";
  const adicionar = (classe, titulo, detalhes) => {
    const linha = documentRef.createElement("li");
    linha.className = `csv-import-report-item ${classe}`;
    const nome = documentRef.createElement("strong");
    nome.textContent = titulo;
    linha.append(nome);
    if (detalhes.length === 1) {
      linha.append(` — ${detalhes[0]}`);
    } else if (detalhes.length > 1) {
      const sublista = documentRef.createElement("ul");
      for (const detalhe of detalhes) {
        const item = documentRef.createElement("li");
        item.textContent = detalhe;
        sublista.append(item);
      }
      linha.append(sublista);
    }
    lista.append(linha);
  };

  for (const item of importados) {
    const pendencias = item.pendencias || [];
    const itens = plural(Number(item.itens || 0), "item", "itens");
    adicionar(
      pendencias.length ? "is-pending" : "is-imported",
      `${item.romaneio}: importado (${itens})${pendencias.length ? " com pendências" : ""}`,
      pendencias,
    );
  }
  for (const [romaneio, motivos] of rejeitadosPorRomaneio) {
    adicionar(
      "is-rejected",
      `${romaneio || "Sem número"}: rejeitado`,
      motivos.map((motivo) => `linha ${motivo.linha}: ${motivo.motivo}`),
    );
  }
  for (const item of ignorados) {
    adicionar("is-ignored", `${item.romaneio}: ignorado`, [item.motivo || "já cadastrado"]);
  }
  if (lista.children.length) element.append(lista);
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
      const resumo = respostaCsv?.data;
      const temResumo = resumo && Array.isArray(resumo.importados);
      if (!temResumo) {
        throw new Error(respostaCsv?.error || "Falha ao importar CSV.");
      }
      if (resumo.importados.length > 0) await store.loadManifests();
      renderizarRelatorioCsv(feedback, resumo, documentRef);
    } catch (error) {
      showFeedback(error.message || "Falha ao importar CSV.", "error");
    } finally {
      if (button) button.disabled = false;
    }
  });

  return true;
}
