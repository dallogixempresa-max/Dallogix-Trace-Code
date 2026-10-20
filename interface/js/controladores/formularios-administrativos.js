import { setFormFeedback } from "../componentes/form-feedback.js?v=202610190001";

function registrarFormulario({
  form,
  store,
  render,
  notify,
  setFeedback,
  submit,
  afterSave,
  successMessage,
  readForm,
  resetAfterSave = false,
}) {
  if (!form) return false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    form.dataset.submitting = "1";
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;
    setFeedback(form, "");

    try {
      await submit({ form, store, readForm });
      if (resetAfterSave) form.reset();
      if (afterSave) await afterSave({ form, store });
      render();
      notify(successMessage);
    } catch (error) {
      setFeedback(form, error.message, "error");
      notify(error.message);
    } finally {
      form.dataset.submitting = "0";
      if (submitButton) submitButton.disabled = false;
    }
  });

  return true;
}

export function bindAdministrativeForms({
  root = document,
  store,
  render,
  notify,
  setFeedback = setFormFeedback,
  readForm = (form) => Object.fromEntries(new FormData(form)),
} = {}) {
  registrarFormulario({
    form: root.querySelector("#user-create-form"),
    store,
    render,
    notify,
    setFeedback,
    readForm,
    submit: ({ form }) => store.createUser(readForm(form)),
    afterSave: () => store.loadUsers(),
    successMessage: "Login criado.",
    resetAfterSave: true,
  });

  registrarFormulario({
    form: root.querySelector("#company-create-form"),
    store,
    render,
    notify,
    setFeedback,
    readForm,
    submit: ({ form }) => store.createCompany(readForm(form)),
    afterSave: () => store.loadCompanies(),
    successMessage: "Empresa criada. Agora crie o login de administrador.",
    resetAfterSave: true,
  });

  registrarFormulario({
    form: root.querySelector("#company-rename-form"),
    store,
    render,
    notify,
    setFeedback,
    readForm,
    submit: ({ form }) => {
      const { name } = readForm(form);
      return store.renameCompany(form.dataset.companyId, name);
    },
    afterSave: () => store.loadCompanyDetail(),
    successMessage: "Empresa renomeada.",
  });

  registrarFormulario({
    form: root.querySelector("#industrial-pc-create-form"),
    store,
    render,
    notify,
    setFeedback,
    readForm,
    submit: ({ form }) => {
      const { name } = readForm(form);
      return store.createIndustrialInstallation(name);
    },
    afterSave: () => store.loadCompanyDetail(),
    successMessage: "PC industrial cadastrado. Agora gere o código individual para ativá-lo.",
  });

  const companySelector = root.querySelector("#users-company-selector");
  if (companySelector) {
    companySelector.addEventListener("change", async () => {
      store.state.selectedCompanyId = companySelector.value || null;
      await store.loadUsers();
      render();
    });
  }
}
