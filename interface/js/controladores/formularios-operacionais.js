import { setFormFeedback } from "../componentes/form-feedback.js?v=202611040001";

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

function confirmarRemocaoDeParada({ root, store, render, notify, setFeedback }) {
  const dialog = root.querySelector("#work-stop-dialog");
  if (dialog) {
    if (dialog.dataset.modalBound !== "1") {
      dialog.addEventListener("cancel", (event) => event.preventDefault());
      dialog.dataset.modalBound = "1";
    }
    if (!dialog.open) dialog.showModal();
  }

  root.querySelectorAll(".confirm-stop-removal-form").forEach((form) => {
    const confirmation = form.querySelector('[name="confirmar_remocao"]');
    if (confirmation) {
      confirmation.checked = store.state.stopRemovalAcknowledged === true;
      if (confirmation.dataset.changeBound !== "1") {
        confirmation.addEventListener("change", () => {
          store.state.stopRemovalAcknowledged = confirmation.checked;
        });
        confirmation.dataset.changeBound = "1";
      }
    }
    if (form.dataset.submitBound !== "1") {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (form.dataset.submitting === "1") return;
        const stopReason = store.state.stopReason;
        const quotaConfirmation = stopReason === "COTA_PRODUTO_ATINGIDA";
        if (!confirmation?.checked) {
          setFeedback(
            form,
            quotaConfirmation
              ? "Confirme a parada física antes de continuar com outro produto."
              : "Confirme a remoção da unidade e a parada física antes de continuar.",
            "error",
          );
          return;
        }
        store.state.stopRemovalAcknowledged = true;

        form.dataset.submitting = "1";
        form.querySelectorAll("button").forEach((button) => {
          button.disabled = true;
        });
        setFeedback(form, "Registrando confirmação…");
        try {
          await store.confirmStopRemoval();
          notify(quotaConfirmation
            ? "Parada confirmada. Você pode continuar o carregamento com outro produto."
            : "Confirmação registrada. A operação continua pausada; use Ligar esteira quando estiver pronta.");
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
      form.dataset.submitBound = "1";
    }
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
  readForm = (form) => Object.fromEntries(new FormData(form)),
} = {}) {
  prepararCarregamento({ root, store, navigate, loadingId, dashboardQuery, notify, readForm });
  vincularDalaCarregamento({ root, store, render, setFeedback, readForm });
  confirmarRemocaoDeParada({ root, store, render, notify, setFeedback });
}
