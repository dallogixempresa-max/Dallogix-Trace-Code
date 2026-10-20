import { setFormFeedback } from "../componentes/form-feedback.js?v=202610190001";

function desabilitarBotaoEnviar(form, disabled) {
  const button = form.querySelector('button[type="submit"]');
  if (button) button.disabled = disabled;
}

function prepararCarregamento({ root, store, navigate, loadingId, dashboardQuery, notify, readForm }) {
  const form = root.querySelector("#prepare-loading-form");
  if (!form) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    desabilitarBotaoEnviar(form, true);
    try {
      const raw = readForm(form);
      await store.prepareLoading({
        romaneio_id: loadingId(),
        truck_id: raw.truck_id,
        equipment_id: raw.equipment_id,
      });
      await navigate("work", dashboardQuery());
    } catch (error) {
      notify(error.message);
    } finally {
      desabilitarBotaoEnviar(form, false);
    }
  });
}

function vincularDalaCarregamento({ root, store, render, setFeedback, readForm }) {
  root.querySelectorAll(".reassign-loading-form").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      desabilitarBotaoEnviar(form, true);
      setFeedback(form, "Vinculando a nova Dala…");
      try {
        const raw = readForm(form);
        await store.reassignLoading(form.dataset.loadingId, raw.equipment_id);
        render();
      } catch (error) {
        setFeedback(form, error.message || "Não foi possível vincular a nova Dala.", "error");
      } finally {
        desabilitarBotaoEnviar(form, false);
      }
    });
  });
}

function registrarLeituraDaOperacao({
  root,
  store,
  render,
  setFeedback,
  updateScannerFocus,
  logWarning,
  schedule,
  readForm,
}) {
  root.querySelectorAll(".manual-operation-reading-form").forEach((form) => {
    const barcodeInput = form.querySelector('[name="barcode"]');
    barcodeInput?.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" || barcodeInput.disabled) return;
      event.preventDefault();
      if (typeof form.requestSubmit === "function") form.requestSubmit();
    });
    const refreshScannerFocus = () => updateScannerFocus(root.querySelector("#screen-root"));
    barcodeInput?.addEventListener("focus", refreshScannerFocus);
    barcodeInput?.addEventListener("blur", () => schedule(refreshScannerFocus, 0));

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (form.dataset.submitting === "1") return;
      form.dataset.submitting = "1";
      const inputWasDisabled = barcodeInput?.disabled === true;
      if (barcodeInput) barcodeInput.disabled = true;
      setFeedback(form, "Registrando leitura…");
      try {
        const raw = readForm(form);
        const result = await store.registerManualReading(raw.barcode);
        const message = mensagemDaLeitura(result);
        if (result.refreshWarning) {
          logWarning("leitura.atualizacao", new Error(result.refreshWarning));
        }
        barcodeInput.value = "";
        render();
        const currentForm = root.querySelector(".manual-operation-reading-form");
        if (currentForm) {
          const isError = result.queued || result.refreshWarning || ["PRODUTO_INCORRETO", "SEM_LEITURA"].includes(result.result);
          setFeedback(currentForm, message, isError ? "error" : "");
          currentForm.querySelector('[name="barcode"]')?.focus();
        }
      } catch (error) {
        setFeedback(form, error.message || "Não foi possível registrar a leitura manual.", "error");
      } finally {
        if (form.isConnected) {
          form.dataset.submitting = "";
          if (barcodeInput) barcodeInput.disabled = inputWasDisabled;
        }
      }
    });
  });
}

function mensagemDaLeitura(result) {
  if (result.queued) return "Leitura guardada para envio. A validação está pendente; não repita o código.";
  if (result.refreshWarning) return "Leitura registrada. A atualização falhou; confira o status antes de repetir.";
  const messages = {
    VALIDO: result.load_status === "COMPLETO"
      ? "Código registrado e quantidade programada atingida."
      : "Código registrado e produto validado.",
    PRODUTO_INCORRETO: result.operator_alert || "Código não previsto para o carregamento; a operação foi pausada.",
    EXCESSO: result.operator_alert || "Quantidade prevista para este produto atingida. Confira a leitura excedente.",
    SEM_LEITURA: "Leitura registrada sem código.",
  };
  return messages[result.result] || "Leitura registrada.";
}

function confirmarLeiturasManuais({ root, store, render, setFeedback, readForm }) {
  root.querySelectorAll(".manual-reading-form").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (form.dataset.submitting === "1") return;
      form.dataset.submitting = "1";
      desabilitarBotaoEnviar(form, true);
      setFeedback(form, "Confirmando código…");
      try {
        const raw = readForm(form);
        await store.identifyReading(form.dataset.readingId, raw.barcode);
        render();
      } catch (error) {
        setFeedback(form, error.message || "Não foi possível confirmar o código.", "error");
      } finally {
        if (form.isConnected) {
          form.dataset.submitting = "";
          desabilitarBotaoEnviar(form, false);
        }
      }
    });
  });
}

function confirmarRemocaoDeParada({ root, store, render, notify, setFeedback }) {
  root.querySelectorAll(".confirm-stop-removal-form").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (form.dataset.submitting === "1") return;
      const confirmation = form.querySelector('[name="confirmar_remocao"]');
      if (!confirmation?.checked) {
        setFeedback(form, "Confirme a remoção da unidade e a parada física antes de continuar.", "error");
        return;
      }

      form.dataset.submitting = "1";
      form.querySelectorAll("button").forEach((button) => {
        button.disabled = true;
      });
      setFeedback(form, "Registrando confirmação…");
      try {
        await store.confirmStopRemoval();
        notify("Confirmação registrada. A operação continua pausada; use Ligar esteira quando estiver pronta.");
        render();
      } catch (error) {
        setFeedback(form, error.message || "Não foi possível registrar a confirmação de segurança.", "error");
      } finally {
        if (form.isConnected) {
          form.dataset.submitting = "";
          form.querySelectorAll("button").forEach((button) => {
            button.disabled = false;
          });
        }
      }
    });
  });
}

export function bindOperationalForms({
  root = document,
  store,
  navigate,
  loadingId,
  dashboardQuery,
  render,
  notify,
  setFeedback = setFormFeedback,
  updateScannerFocus = () => {},
  logWarning = () => {},
  schedule = (callback, delay) => window.setTimeout(callback, delay),
  readForm = (form) => Object.fromEntries(new FormData(form)),
} = {}) {
  prepararCarregamento({ root, store, navigate, loadingId, dashboardQuery, notify, readForm });
  vincularDalaCarregamento({ root, store, render, setFeedback, readForm });
  registrarLeituraDaOperacao({
    root,
    store,
    render,
    setFeedback,
    updateScannerFocus,
    logWarning,
    schedule,
    readForm,
  });
  confirmarLeiturasManuais({ root, store, render, setFeedback, readForm });
  confirmarRemocaoDeParada({ root, store, render, notify, setFeedback });
}
