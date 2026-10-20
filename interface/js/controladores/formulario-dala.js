import { setFormFeedback } from "../componentes/form-feedback.js?v=202610190001";
import { validarDala } from "../utilitarios/Validadores.js?v=202610190030";

function definirBotoesDesabilitados(form, disabled) {
  form.querySelectorAll("button").forEach((button) => {
    button.disabled = disabled;
  });
}

function informarStatusDeConexao(store, equipmentId, notify, label) {
  return store.checkEquipmentStatus(equipmentId)
    .then((connection) => notify(`${label}. ${connection.message}`))
    .catch((error) => notify(`${label}, mas a verificação de conexão falhou: ${error.message}`));
}

export function bindDalaCreationForm({
  form,
  store,
  render,
  notify,
  readForm = (target) => Object.fromEntries(new FormData(target)),
} = {}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    const raw = readForm(form);
    const validationError = validarDala(raw);
    if (validationError) {
      setFormFeedback(form, validationError, "error");
      notify(validationError);
      return;
    }

    form.dataset.submitting = "1";
    definirBotoesDesabilitados(form, true);
    let created;
    try {
      created = await store.createEquipment(raw);
    } catch (error) {
      notify(error.message);
      return;
    } finally {
      form.dataset.submitting = "0";
      definirBotoesDesabilitados(form, false);
    }

    store.state.dalaFormOpen = false;
    render();
    await informarStatusDeConexao(store, created.id, notify, "Dala cadastrada");
  });

  return true;
}

/** Liga a edição de Dala e mantém o status de conectividade como etapa separada. */
export function bindDalaEditForm({
  form,
  store,
  navigate,
  returnPage,
  notify,
  readForm = (target) => Object.fromEntries(new FormData(target)),
} = {}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    const raw = readForm(form);
    const validationError = validarDala(raw);
    if (validationError) {
      setFormFeedback(form, validationError, "error");
      notify(validationError);
      return;
    }

    const equipmentId = form.dataset.id;
    form.dataset.submitting = "1";
    definirBotoesDesabilitados(form, true);
    try {
      try {
        await store.updateEquipment({ id: equipmentId, ...raw });
      } catch (error) {
        notify(error.message);
        return;
      }

      await navigate("dala", `?id=${equipmentId}&from=${returnPage()}`);
    } finally {
      form.dataset.submitting = "0";
      definirBotoesDesabilitados(form, false);
    }

    await informarStatusDeConexao(store, equipmentId, notify, "Dala atualizada");
  });

  return true;
}
