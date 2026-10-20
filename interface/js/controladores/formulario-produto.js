import { setFormFeedback } from "../componentes/form-feedback.js?v=202610190001";
import { validarProduto } from "../utilitarios/Validadores.js?v=202610060003";

function definirBotoesDesabilitados(form, disabled) {
  form.querySelectorAll("button").forEach((button) => {
    button.disabled = disabled;
  });
}

export function bindProductForm({
  form,
  store,
  render,
  notify,
  onUnauthorized,
  readForm = (target) => Object.fromEntries(new FormData(target)),
} = {}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    const raw = readForm(form);
    const validationError = validarProduto(raw);
    if (validationError) {
      setFormFeedback(form, validationError, "error");
      notify(validationError);
      return;
    }

    form.dataset.submitting = "1";
    definirBotoesDesabilitados(form, true);
    const editingId = form.dataset.editing;
    try {
      if (editingId) {
        await store.updateProduct({ id: editingId, ...raw });
        notify("Produto atualizado.");
      } else {
        await store.createProduct(raw);
        notify("Produto cadastrado.");
      }
      store.state.productFormOpen = false;
      store.state.editingProductId = null;
      render();
    } catch (error) {
      if (error.status === 401) {
        onUnauthorized(error);
        return;
      }
      notify(error.message);
    } finally {
      form.dataset.submitting = "0";
      definirBotoesDesabilitados(form, false);
    }
  });

  return true;
}
