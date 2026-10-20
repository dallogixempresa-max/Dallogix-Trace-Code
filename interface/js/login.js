import { configurarSessaoPorAba } from "./sessao.js?v=202609222100";
import { ClienteApi, erroRespostaHttp } from "./api/ClienteApi.js?v=202610140003";
import { notificar } from "./componentes/notificacoes.js?v=202610070024";
import { logFrontend, configurarTransporteLogs } from "./utilitarios/LogFrontend.js?v=202610160005";
import { ServicoAtivacaoLocal } from "./servicos/ServicoAtivacaoLocal.js?v=202610190001";
import { ServicoObservabilidade } from "./servicos/ServicoObservabilidade.js?v=202610190001";
import {
  bindLoginForm,
  bindLocalActivationForm,
  renderLogin,
  renderLocalActivation,
} from "../telas/acesso/acesso.js?v=202610190036";

configurarSessaoPorAba();

const api = new ClienteApi({ retry: 1 });
const estado = { localActivation: null };
const sessao = { csrfToken: "", user: null };
const jsonHeaders = () => ({
  "Content-Type": "application/json",
  Accept: "application/json",
  ...(sessao.csrfToken ? { "X-CSRF-Token": sessao.csrfToken } : {}),
});
async function jsonResponse(response, fallback) {
  if (!response.ok) {
    const error = await erroRespostaHttp(response, fallback);
    throw error || new Error(fallback);
  }
  return response.json().catch(() => ({}));
}

const servicoAtivacao = new ServicoAtivacaoLocal({
  api,
  state: estado,
  jsonHeaders,
  jsonResponse,
});
const servicoObservabilidade = new ServicoObservabilidade({
  api,
  state: estado,
  jsonHeaders,
  jsonResponse,
});
const DEFAULT_PAGE_BY_ROLE = Object.freeze({
  ADMIN_DALLOGIX: "/telas/painel-dallogix/painel-dallogix.html",
  ADMIN_EMPRESA: "/telas/painel/painel.html",
  SUPERVISOR: "/telas/painel/painel.html",
  USUARIO: "/telas/painel/painel.html",
});

function defaultPageForUser(user) {
  return DEFAULT_PAGE_BY_ROLE[String(user?.role || "").toUpperCase()]
    || "/telas/romaneios/romaneios.html";
}

async function bootstrapLogin() {
  if (window.location.search) {
    window.history.replaceState(null, "", window.location.pathname);
  }

  let loginMessage = "";
  try {
    loginMessage = sessionStorage.getItem("trace-login-message") || "";
    sessionStorage.removeItem("trace-login-message");
  } catch (error) {
    logFrontend.aviso("sessao.login.mensagem_indisponivel", error);
  }

  let activation = { active: false };
  let activationLookupError = "";
  try {
    activation = await servicoAtivacao.loadLocalActivation();
  } catch (error) {
    activationLookupError = error.message || "Não foi possível consultar a ativação local.";
    logFrontend.aviso("ativacao.local.carregamento", error);
  }

  const loopbackHosts = ["localhost", "127.0.0.1", "[::1]"];
  const isLoopback = loopbackHosts.includes(window.location.hostname);
  const activationMessage = activationLookupError && isLoopback
    ? `Não foi possível confirmar a ativação deste PC. Confira se o Trace terminou de iniciar e tente novamente. Detalhe: ${activationLookupError}`
    : "";
  renderLocalActivation(activation, activationMessage);
  renderLogin(loginMessage);

  bindLocalActivationForm({
    form: document.querySelector("#local-activation-form"),
    activate: (payload) => servicoAtivacao.activateLocalInstallation(payload),
    render: renderLocalActivation,
    notify: notificar,
    documentRef: document,
    logWarning: (...args) => logFrontend.aviso(...args),
  });

  bindLoginForm({
    form: document.querySelector("#login-form"),
    api,
    onAuthenticated: (user, csrfToken) => {
      sessao.user = user;
      sessao.csrfToken = csrfToken || "";
    },
    persistLog: (record) => servicoObservabilidade.persistFrontendLog(record),
    configureLogTransport: configurarTransporteLogs,
    redirectAfterLogin: () => defaultPageForUser(sessao.user),
    logWarning: (...args) => logFrontend.aviso(...args),
  });
}

bootstrapLogin();
