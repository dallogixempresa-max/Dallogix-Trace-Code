import { setFormFeedback } from "../componentes/form-feedback.js?v=202610190001";
import { agora } from "../funcoes/relogio.js?v=202609170015";
import { validarCadastroRomaneio } from "../funcoes/romaneio.js?v=202610190001";

function dataLocalAtual(now) {
  const date = now();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${day}`;
}

function itensDoFormulario(form) {
  return [...form.querySelectorAll("#manifest-items tbody tr")].map((row) => ({
    product_id: row.querySelector('[name="item_product"]')?.value,
    quantity: row.querySelector('[name="item_quantity"]')?.value,
  }));
}

function bloquearBotoes(form, disabled) {
  form.querySelectorAll("button").forEach((button) => {
    button.disabled = disabled;
  });
}

export function bindManifestCreationForm({
  form,
  store,
  navigate,
  onUnauthorized,
  now = agora,
  readForm = (target) => Object.fromEntries(new FormData(target)),
  readItems = itensDoFormulario,
} = {}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    const raw = readForm(form);
    const scheduledDate = raw.scheduled_date;
    const items = readItems(form);
    const validationError = validarCadastroRomaneio({
      scheduledDate,
      todayString: dataLocalAtual(now),
      items,
    });
    if (validationError) {
      setFormFeedback(form, validationError, "error");
      return;
    }

    form.dataset.submitting = "1";
    bloquearBotoes(form, true);
    setFormFeedback(form, "Salvando romaneio…");

    try {
      await store.createManifest({
        number: raw.number,
        scheduled_date: scheduledDate,
        plate: raw.plate,
        expedidor: raw.expedidor,
        driver_name: raw.driver_name,
        items: items.map((item) => ({
          product_id: item.product_id,
          quantity: Number(item.quantity),
        })),
      });
    } catch (error) {
      if (error.status === 401) {
        onUnauthorized(error);
        return;
      }
      setFormFeedback(
        form,
        error.message || "Não foi possível salvar o romaneio. Confira os dados e tente novamente.",
        "error",
      );
      return;
    } finally {
      form.dataset.submitting = "0";
      bloquearBotoes(form, false);
    }

    if (form.isConnected) {
      setFormFeedback(form, "Romaneio cadastrado com sucesso.");
      await navigate("manifests");
    }
  });

  return true;
}

/** Liga a edição de romaneio preservando os itens separados do mesmo produto. */
export function bindManifestEditForm({
  form,
  store,
  navigate,
  notify,
  now = agora,
  readForm = (target) => Object.fromEntries(new FormData(target)),
  readItems = itensDoFormulario,
} = {}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const raw = readForm(form);
    const todayString = dataLocalAtual(now);
    if (!raw.scheduled_date || raw.scheduled_date < todayString) {
      notify("Informe uma data igual ou posterior ao dia atual do PC industrial.");
      return;
    }

    const items = readItems(form)
      .filter((item) => item.product_id && Number(item.quantity) >= 1);
    if (!items.length) {
      notify("Adicione ao menos um item com produto e quantidade.");
      return;
    }
    if (form.dataset.submitting === "1") return;

    form.dataset.submitting = "1";
    bloquearBotoes(form, true);
    try {
      await store.updateManifest({
        romaneio_id: form.dataset.id,
        number: raw.number,
        scheduled_date: raw.scheduled_date,
        plate: raw.plate,
        expedidor: raw.expedidor,
        driver_name: raw.driver_name,
        items,
      });
      notify("Romaneio atualizado.");
      await navigate("manifest", `?id=${form.dataset.id}`);
    } catch (error) {
      notify(error.message);
    } finally {
      form.dataset.submitting = "0";
      bloquearBotoes(form, false);
    }
  });

  return true;
}
