import { esc } from "../../js/funcoes/html.js";

/** Atualiza a mensagem de autenticação sem inserir texto da API como HTML. */
export function renderLogin(message = "", documentRef = document) {
  const box = documentRef.querySelector("#login-error");
  if (!box) return;
  box.setAttribute("role", "alert");
  box.setAttribute("aria-live", "assertive");
  box.innerHTML = message ? `<div class="login-error">${esc(message)}</div>` : "";
  if (message) box.focus();
}

/** Mostra o estado da licença local e do formulário de ativação. */
export function renderLocalActivation(data = { active: false }, message = "", documentRef = document) {
  const status = documentRef.querySelector("#local-license-status");
  const panel = documentRef.querySelector("#local-activation");
  const error = documentRef.querySelector("#local-activation-error");
  const enabled = Boolean(data?.enabled);
  const licenseStatus = String(data?.license_status || (data?.active ? "ATIVA" : ""));
  const active = enabled && Boolean(data?.active) && licenseStatus === "ATIVA";
  const blocked = enabled && Boolean(data?.active) && licenseStatus !== "ATIVA";
  if (status) {
    status.hidden = !active && !blocked;
    status.classList.toggle("is-blocked", blocked);
    status.innerHTML = active
      ? `<strong>Licença ativa</strong><small>${esc(data.company_name || "Empresa configurada")} · @${esc(data.login_domain || "—")}</small>`
      : blocked
        ? `<strong>Licença bloqueada</strong><small>${esc(data.license_reason || "A empresa precisa ser liberada no servidor central.")}</small>`
        : "";
  }
  if (panel) panel.hidden = !enabled || active || blocked;
  if (error) {
    error.textContent = message;
    error.hidden = !message;
  }
}

/** Liga o formulário de login à autenticação e ao ciclo de sessão do Trace. */
export function bindLoginForm({
  form,
  api,
  render = renderLogin,
  onAuthenticated = () => {},
  persistLog = async () => {},
  configureLogTransport,
  redirectAfterLogin,
  logWarning,
  windowRef = window,
  readForm = (target) => Object.fromEntries(new FormData(target)),
} = {}) {
  if (!form || form.dataset.bound === "1") return false;

  form.dataset.bound = "1";
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');
    const originalLabel = submit?.textContent || "Entrar no sistema";
    if (submit) {
      submit.disabled = true;
      submit.textContent = "Entrando…";
    }
    render("");
    try {
      const response = await api.fetch("/api/login.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(readForm(form)),
      });
      let result = {};
      try {
        result = await response.json();
      } catch (error) {
        logWarning?.("login.resposta_nao_json", error);
      }
      if (!response.ok || result.authenticated !== true) {
        render(result.error || "Login ou senha inválidos.");
        return;
      }
      onAuthenticated(result.user, result.csrf_token);
      configureLogTransport?.((record) => persistLog(record));
      windowRef.location.replace(redirectAfterLogin());
    } catch (error) {
      logWarning?.("login.conexao", error);
      render(
        "Não foi possível conectar ao servidor local. Verifique se o sistema está em execução.",
      );
    } finally {
      if (submit) {
        submit.disabled = false;
        submit.textContent = originalLabel;
      }
    }
  });

  return true;
}

/** Liga o formulário de ativação local sem permitir envios concorrentes. */
export function bindLocalActivationForm({
  form = document.querySelector("#local-activation-form"),
  activate,
  render = renderLocalActivation,
  notify,
  documentRef = document,
  logWarning,
  readForm = (target) => Object.fromEntries(new FormData(target)),
} = {}) {
  if (!form || form.dataset.bound === "1") return false;

  form.dataset.bound = "1";
  const activationCode = form.elements?.namedItem?.("activation_code");
  activationCode?.addEventListener("input", () => {
    const start = activationCode.selectionStart;
    const end = activationCode.selectionEnd;
    activationCode.value = activationCode.value.toUpperCase();
    if (start !== null && end !== null) activationCode.setSelectionRange(start, end);
  });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;
    form.dataset.submitting = "1";
    const submit = form.querySelector('button[type="submit"]');
    if (submit) {
      submit.disabled = true;
      submit.textContent = "Ativando…";
    }
    render({ enabled: true, active: false }, "", documentRef);
    try {
      const data = readForm(form);
      const activation = await activate(data);
      render(activation, "", documentRef);
      const login = documentRef.querySelector('#login-form [name="email"]');
      if (login) login.value = data.email || "";
      form.reset();
      notify(`Instalação ativada para ${activation.company_name}.`, "sucesso");
    } catch (error) {
      logWarning?.("ativacao.local.envio", error);
      render(
        { enabled: true, active: false },
        error.message || "Não foi possível ativar esta instalação.",
        documentRef,
      );
    } finally {
      form.dataset.submitting = "0";
      if (submit) {
        submit.disabled = false;
        submit.textContent = "Ativar este PC";
      }
    }
  });

  return true;
}
