import {
  configurarSessaoPorAba,
} from "./sessao.js?v=202609222100";
import { ClienteApi } from "./api/ClienteApi.js?v=202610140003";
import { confirmarAcao, limparNotificacao, notificar, solicitarTexto } from "./componentes/notificacoes.js?v=202610160002";
import { setFormFeedback } from "./componentes/form-feedback.js?v=202610190001";
import { ServicoEmergencia } from "./servicos/ServicoEmergencia.js?v=202610060001";
import { ServicoOperacao } from "./servicos/ServicoOperacao.js?v=202610060001";
import { ArmazenamentoTrace } from "./classes/ArmazenamentoTrace.js?v=202610280004";
import { FORM_ACTIONS } from "./constantes/acoes.js?v=202609140210";
import { captureOperationScanner, restoreOperationScanner, focusOperationScanner, updateOperationScannerFocus, instalarRecuperacaoFocoLeitor, changeOperationLoading, linhaItemRomaneio } from "./controladores/operacao.js?v=202610280002";
import { createOperationalRealtimeController } from "./controladores/tempo-real.js?v=202610280002";
import { createPageRefreshController } from "./controladores/page-refresh.js?v=202610130001";
import { createDalaStatusRealtimeController } from "./controladores/status-dalas-tempo-real.js?v=202610200003";
import { createMachineCommandQueue } from "./controladores/comandos-maquina.js?v=202610070203";
import { criarRegistroTelas } from "./controladores/registro-telas.js?v=202610190046";
import { criarTratadorAcoesNavegacao } from "./controladores/acoes-navegacao.js?v=202610190001";
import { criarTratadorAcoesAdministracao } from "./controladores/acoes-administracao.js?v=202610190001";
import { criarTratadorAcoesOperacionais } from "./controladores/acoes-operacionais.js?v=202610190040";
import { criarTratadorAcoesDiagnostico } from "./controladores/acoes-diagnostico.js?v=202610190001";
import { criarTratadorAcoesCadastros } from "./controladores/acoes-cadastros.js?v=202610190042";
import { criarTratadorAcoesInterface, instalarInteracoesGlobaisInterface } from "./controladores/acoes-interface.js?v=202610190042";
import { criarTratadorAcoesRelatorios } from "./controladores/acoes-relatorios.js?v=202610190043";
import { criarDelegacaoAcoes, criarDespachanteAcoes } from "./controladores/despachante-acoes.js?v=202610190059";
import { criarCarregadorPaginas } from "./controladores/carregador-paginas.js?v=202610280002";
import { createLiveViewController } from "./controladores/estado-visualizacao.js?v=202610280002";
import { createLocalConnectivityController } from "./controladores/conectividade-local.js?v=202610190029";
import { bindManifestCreationForm, bindManifestEditForm } from "./controladores/formulario-romaneio.js?v=202610190001";
import { bindProductForm } from "./controladores/formulario-produto.js?v=202610190001";
import { bindDalaCreationForm, bindDalaEditForm } from "./controladores/formulario-dala.js?v=202610190030";
import { bindCsvManifestImportForm, bindPdfManifestImportForm } from "./controladores/formulario-importacao-romaneio.js?v=202610190001";
import { bindAdministrativeForms } from "./controladores/formularios-administrativos.js?v=202610190001";
import { bindOperationalForms } from "./controladores/formularios-operacionais.js?v=202610280004";
import { bindPageForms } from "./controladores/formularios-telas.js?v=202610190001";
import { el, esc } from "./funcoes/html.js";
import { rotuloEstado } from "./funcoes/rotulos.js?v=202609240001";
import { logFrontend, configurarTransporteLogs } from "./utilitarios/LogFrontend.js?v=202610160005";
import { estadoErro } from "./componentes/estados.js?v=202610060002";
import {
  LEGACY_PAGE_IDS,
  NAV_GROUPS,
  PAGE_IDS_BY_PATH,
  PAGE_LABELS,
  PAGE_PATHS,
  ROLE_LABELS,
  ROLE_PAGES,
} from "./configuracaoAplicacao.js?v=202610060005";

configurarSessaoPorAba();

const store = new ArmazenamentoTrace();
let sessionRedirectStarted = false;
const api = new ClienteApi({
  onUnauthorized: () => {
    if (sessionRedirectStarted || currentPage === "login") return;
    sessionRedirectStarted = true;
    try {
      sessionStorage.setItem("trace-login-message", "Sua sessão expirou. Entre novamente no sistema.");
    } catch (error) {
      logFrontend.aviso("sessao.expirada.mensagem", error);
    }
    window.location.replace(pagePath("login"));
  },
});
const servicoEmergencia = new ServicoEmergencia(store);
const servicoOperacao = new ServicoOperacao(store);
let renderRequestId = 0;
let workViewSignature = "";
let companyViewSignature = "";
let sidebarPreferenceWarningReported = false;
const registroTelas = criarRegistroTelas({
  dashboard: () =>
    import("../telas/painel/painel.js?v=202610190046").then(({ dashboard }) => dashboard),
  manifests: () =>
    import("../telas/romaneios/romaneios.js?v=202610190046").then(({ manifests }) => manifests),
  import: () =>
    import("../telas/importar-romaneio/importar-romaneio.js?v=202610190046").then(({ importScreen }) => importScreen),
  division: () =>
    import("../telas/divisao-carga/divisao-carga.js?v=202610190046").then(({ division }) => division),
  work: () =>
    import("../telas/operacao/operacao.js?v=202610280002").then(({ work }) => work),
  occurrences: () =>
    import("../telas/ocorrencias/ocorrencias.js?v=202610190046").then(({ occurrences }) => occurrences),
  summary: () =>
    import("../telas/resumo-final/resumo-final.js?v=202610190046").then(({ summary }) => summary),
  products: () =>
    import("../telas/produtos/produtos.js?v=202610190046").then(({ products }) => products),
  alerts: () =>
    import("../telas/alertas/alertas.js?v=202610190046").then(({ alerts }) => alerts),
  emergency: () =>
    import("../telas/emergencia/emergencia.js?v=202610190046").then(({ emergency }) => emergency),
  settings: () =>
    import("../telas/configuracoes/configuracoes.js?v=202610280001").then(({ settings }) => settings),
  dalas: () =>
    import("../telas/dalas/dalas.js?v=202610190046").then(({ dalas }) => dalas),
  dala: () =>
    import("../telas/visualizar-dala/visualizar-dala.js?v=202610190046").then(({ dalaView }) => dalaView),
  "dala-edit": () =>
    import("../telas/editar-dala/editar-dala.js?v=202610190046").then(({ dalaEdit }) => dalaEdit),
  manifest: () =>
    import("../telas/romaneio/romaneio.js?v=202610190046").then(({ manifestView }) => manifestView),
  "manifest-edit": () =>
    import("../telas/editar-romaneio/editar-romaneio.js?v=202610190046").then(({ manifestEdit }) => manifestEdit),
  companies: () =>
    import("../telas/empresas/empresas.js?v=202610190046").then(({ companies }) => companies),
  "master-home": () =>
    import("../telas/painel-dallogix/painel-dallogix.js?v=202610190046").then(({ masterHome }) => masterHome),
  company: () =>
    import("../telas/empresa/empresa.js?v=202610230001").then(({ company }) => company),
  users: () =>
    import("../telas/usuarios/usuarios.js?v=202610190046").then(({ users }) => users),
  "error-logs": () =>
    import("../telas/logs-erros/logs-erros.js?v=202610190046").then(({ errorLogs }) => errorLogs),
});
const screens = registroTelas.telas;
const FAST_STATUS_PAGES = new Set([
  "dashboard",
  "division",
  "manifest",
  "occurrences",
  "summary",
  "alerts",
  "settings",
  "dalas",
  "dala",
]);
const PAGE_REFRESHERS = new Map([
  "dashboard",
  "manifests",
  "manifest",
  "division",
  "occurrences",
  "summary",
  "products",
  "alerts",
  "settings",
  "dalas",
  "dala",
  "users",
  "companies",
  "master-home",
  "company",
  "error-logs",
].map((page) => [page, {
  load: () => pageDataLoader.obterConsultasAoVivo(page),
  refresh: (freshness) => refreshLiveStatusView(page, freshness),
  intervalMs: page === "products" ? 60000 : FAST_STATUS_PAGES.has(page) ? 5000 : 15000,
  hiddenIntervalMs: page === "products"
    ? 300000
    : FAST_STATUS_PAGES.has(page)
      ? 15000
      : ["companies", "master-home"].includes(page) ? 60000 : 30000,
}]));
// Os arquivos HTML continuam acessíveis diretamente, mas, após o primeiro carregamento,
// as mudanças de tela usam o History API para não reiniciar toda a aplicação.
const initialPage =
  document.querySelector("script[data-page]")?.dataset.page || "";
let currentPage = initialPage;
let authenticatedUser = null;
const pageDataLoader = criarCarregadorPaginas({
  store,
  obterPerfil: () => authenticatedUser?.role,
  obterModoInstalacao: () => currentInstallationMode,
  idDaConsulta: queryId,
  registrarAviso: (...args) => logFrontend.aviso(...args),
});
const liveView = createLiveViewController({
  store,
  getCurrentPage: () => currentPage,
  screens,
  renderScreen: () => renderScreen(),
  bindActions: () => bindActions(),
  getCompanyViewSignature: () => companyViewSignature,
  setCompanyViewSignature: (signature) => { companyViewSignature = signature; },
});
const {
  companyStatusViewSignature,
  workStructureSignature,
  refreshWorkLiveView,
  refreshSettingsStatusView,
  refreshLiveStatusView,
  refreshStatusRefreshFeedback,
  installRelativeTimeRefresh,
} = liveView;

// Notificações flutuantes foram desativadas globalmente; mensagens operacionais
// permanecem disponíveis nos estados próprios das telas.
function notificarForaDaOperacao(mensagem, tipo = "informacao") {
  if (currentPage === "work") return;
  notificar(mensagem, tipo);
}

const machineCommandQueue = createMachineCommandQueue({
  onDrained: (loadingId) => {
    // Faz uma única leitura autoritativa depois que os cliques enfileirados
    // forem enviados; leituras intermediárias poderiam devolver estado antigo.
    if (currentPage === "work") {
      Promise.allSettled([
        store.loadActiveLoading(loadingId),
        store.loadDeviceStatuses(),
      ]).then(() => {
        if (currentPage === "work") render();
      });
    }
  },
});

async function executarComandoDeOperacao(node, action) {
  const loadingId = Number(node.dataset.loadingId || store.state.loadingId) || null;
  const commands = {
    "start-machine": "INICIAR_CARREGAMENTO",
    run: "INICIAR_CARREGAMENTO",
    "stop-machine": "PAUSAR_CARREGAMENTO",
    stop: "PAUSAR_CARREGAMENTO",
    "reverse-machine": "REVERSAO_ATIVAR",
    "reverse-on": "REVERSAO_ATIVAR",
    "reverse-off": "REVERSAO_DESATIVAR",
    emergency: "EMERGENCIA",
  };
  const command = action === "reverse-toggle"
    ? (store.state.returnMode ? "REVERSAO_DESATIVAR" : "REVERSAO_ATIVAR")
    : commands[action];
  try {
    await machineCommandQueue.enqueue(loadingId, command, async () => {
      if (command === "INICIAR_CARREGAMENTO") return servicoOperacao.iniciar(loadingId);
      if (command === "PAUSAR_CARREGAMENTO") return servicoOperacao.parar(loadingId);
      if (command === "REVERSAO_ATIVAR") return servicoOperacao.reversao(true, loadingId);
      if (command === "REVERSAO_DESATIVAR") return servicoOperacao.reversao(false, loadingId);
      if (command === "EMERGENCIA") return store.requestMachineEmergency(loadingId);
      throw new Error("Comando operacional inválido.");
    });
    if (currentPage === "work") render();
  } catch (error) {
    if (currentPage === "work") {
      logFrontend.erro("operacao.comando.falhou", error, {
        acao: action,
        carregamento_id: loadingId,
      });
      store.state.commandFeedback = "";
      render();
    } else {
      notificarForaDaOperacao(error.message, "erro");
    }
  }
}

const workRealtime = createOperationalRealtimeController({
  store,
  getPage: () => currentPage,
  render: () => render(),
  refreshWorkLiveView: () => refreshWorkLiveView(),
  refreshOtherLiveView: (page) => refreshLiveStatusView(page),
  setFreshness: (page, freshness) => {
    if (currentPage === page) {
      refreshStatusRefreshFeedback(el("#screen-root"), freshness);
    }
  },
  workStructureSignature: () => workStructureSignature(),
  getViewSignature: () => workViewSignature,
});
const statusScreensRealtime = createPageRefreshController({
  getPage: () => currentPage,
  pages: PAGE_REFRESHERS,
});
const dalaStatusRealtime = createDalaStatusRealtimeController({
  store,
  getPage: () => currentPage,
  isAuthenticated: () => Number(authenticatedUser?.company_id) > 0,
  refresh: refreshLiveStatusView,
});

function sidebarCollapsed() {
  try {
    return localStorage.getItem("trace-sidebar-collapsed") === "1";
  } catch (error) {
    reportSidebarPreferenceFailure(error);
    return false;
  }
}
function reportSidebarPreferenceFailure(error) {
  if (sidebarPreferenceWarningReported) return;
  sidebarPreferenceWarningReported = true;
  logFrontend.aviso("navegacao.preferencia_menu_indisponivel", error);
}
function saveSidebarCollapsedPreference(collapsed) {
  try {
    localStorage.setItem("trace-sidebar-collapsed", collapsed ? "1" : "0");
  } catch (error) {
    reportSidebarPreferenceFailure(error);
  }
}
function normalizeRole(role) {
  return typeof role === "string" ? role.toUpperCase() : "";
}
function allowedPages() {
  return ROLE_PAGES[normalizeRole(authenticatedUser?.role)] || [];
}
function defaultPage() {
  const normalizedRole = normalizeRole(authenticatedUser?.role);
  if (normalizedRole === "ADMIN_DALLOGIX") return "master-home";
  const permittedPages = allowedPages();
  return permittedPages.includes("dashboard") ? "dashboard" : permittedPages[0] || "manifests";
}
function isAllowedPage(page) {
  return typeof page === "string" && allowedPages().includes(page);
}
function resolveAuthorizedPage(page, fallbackPage = defaultPage()) {
  if (typeof page !== "string" || !isAllowedPage(page)) return fallbackPage;
  return page;
}
function pagePath(page, query = "") {
  return `${PAGE_PATHS[page] || PAGE_PATHS.login}${query}`;
}
function pageFromPath(pathname = window.location.pathname) {
  if (PAGE_IDS_BY_PATH[pathname]) return PAGE_IDS_BY_PATH[pathname];
  const file = pathname.split("/").pop() || "index.html";
  return LEGACY_PAGE_IDS[file] || file.replace(/\.html$/, "");
}
async function navigate(page, query = "", { replace = false } = {}) {
  const safePage = resolveAuthorizedPage(page, defaultPage());
  page = safePage;
  if (!screens[page]) {
    page = defaultPage();
    query = "";
  }
  const path = pagePath(page, query);
  if (currentPage === page && window.location.search === query) return;
  currentPage = page;
  if (replace) window.history.replaceState({ page }, "", path);
  else window.history.pushState({ page }, "", path);
  await renderPage();
  window.scrollTo({ top: 0, behavior: "auto" });
}
function queryId() {
  return new URLSearchParams(window.location.search).get("id");
}
function isDashboardReturn() {
  return new URLSearchParams(window.location.search).get("from") === "dashboard";
}
function dashboardReturnQuery() {
  return isDashboardReturn() ? "?from=dashboard" : "";
}
function queryReturnPage() {
  const page = new URLSearchParams(window.location.search).get("from");
  return ["dashboard", "dalas", "master-home", "companies"].includes(page || "")
    ? page
    : "dalas";
}

function navigationIcon(page) {
  const icons = {
    manifests:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4.5h6M9 10h6M9 14h4"/></svg>',
    dashboard:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>',
    products:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg>',
    dalas:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h10v9H3zM13 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>',
    settings:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.1 2.1-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.55v.1h-3v-.1A1.7 1.7 0 0 0 10.7 18.6a1.7 1.7 0 0 0-1.88.34l-.06.06-2.1-2.1.06-.06A1.7 1.7 0 0 0 7.06 15a1.7 1.7 0 0 0-1.55-1.03h-.1v-3h.1A1.7 1.7 0 0 0 7.06 9.94 1.7 1.7 0 0 0 6.72 8.06L6.66 8l2.1-2.1.06.06a1.7 1.7 0 0 0 1.88.34 1.7 1.7 0 0 0 1.03-1.55v-.1h3v.1A1.7 1.7 0 0 0 15.76 6.3a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.1 2.1-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.55 1.03h.1v3h-.1A1.7 1.7 0 0 0 19.4 15Z"/></svg>',
    work: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8h18v8H3zM7 8V5h10v3M7 16v3h10v-3"/><path d="M8 12h8"/></svg>',
    occurrences:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg>',
    summary:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
    alerts:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
    companies:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5l8-3 8 3v16M4 10h16M8 7h.01M12 7h.01M16 7h.01M8 14h.01M12 14h.01M16 14h.01"/></svg>',
    "master-home":
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V5h16v15M8 9h8M8 13h4M8 17h8"/><path d="M16 13h3v4h-3z"/></svg>',
    users:
      '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2"/><path d="M3 20c.7-4 10.3-4 12 0M15 15c3.2-.4 5.3 1.1 6 3.2"/></svg>',
  };
  return icons[page] || icons.dashboard;
}

let currentInstallationMode = ["localhost", "127.0.0.1", "::1"].includes(window.location.hostname)
  ? "local"
  : "central";

const conectividadeLocal = createLocalConnectivityController({
  store,
  api,
  getCurrentPage: () => currentPage,
  getInstallationMode: () => currentInstallationMode,
  setInstallationMode: (mode) => { currentInstallationMode = mode; },
  refreshWorkView: () => refreshWorkLiveView(),
  refreshSettingsView: () => refreshSettingsStatusView(),
  render: () => render(),
  focusScanner: (root) => focusOperationScanner(root),
  getScreenRoot: () => el("#screen-root"),
  logWarning: (...args) => logFrontend.aviso(...args),
  serviceWorkerUrl: "/service-worker.js?v=202610230001",
});

function waitForDocumentStyles() {
  const links = [...document.querySelectorAll('link[rel="stylesheet"]')];
  return Promise.all(
    links.map((link) => {
      if (link.sheet) return Promise.resolve();
      return new Promise((resolve) => {
        const finish = () => resolve();
        link.addEventListener("load", finish, { once: true });
        link.addEventListener("error", finish, { once: true });
      });
    }),
  );
}

// A aplicação troca telas com History API sem recarregar o documento. Cada
// tela que tem CSS próprio precisa garantir seu arquivo e sua versão antes do
// primeiro render, inclusive quando a rota inicial veio de outra página.
const SCREEN_STYLES = {
  work: "/telas/operacao/operacao.css?v=202610200002",
  import: "/telas/importar-romaneio/importar-romaneio.css?v=202610071845",
  settings: "/telas/configuracoes/configuracoes.css?v=202610280001",
  dalas: "/telas/dalas/dalas.css?v=202610160005",
  dala: "/telas/dalas/dalas.css?v=202610160005",
  "dala-edit": "/telas/dalas/dalas.css?v=202610160005",
  company: "/telas/empresa/empresa.css?v=202610020006",
};

async function ensureScreenStyles(page) {
  const href = SCREEN_STYLES[page];
  if (!href) return;
  const expectedUrl = new URL(href, window.location.href);
  const existing = [...document.querySelectorAll('link[rel="stylesheet"]')].find(
    (link) => new URL(link.href, window.location.href).pathname === expectedUrl.pathname,
  );
  if (existing?.href === expectedUrl.href) {
    if (existing.sheet) return;
    await new Promise((resolve) => {
      existing.addEventListener("load", resolve, { once: true });
      existing.addEventListener("error", resolve, { once: true });
    });
    return;
  }
  existing?.remove();
  const link = document.createElement("link");
  link.rel = "stylesheet";
  link.href = expectedUrl.href;
  document.head.append(link);
  await new Promise((resolve) => {
    link.addEventListener("load", resolve, { once: true });
    link.addEventListener("error", resolve, { once: true });
  });
  if (!link.sheet) logFrontend.aviso("tela.css", new Error(`Não foi possível carregar ${expectedUrl.pathname}.`));
}

function isMobileMenuViewport() {
  return window.matchMedia(
    "(max-width: 760px), (orientation: landscape) and (max-width: 900px)",
  ).matches;
}

function setMobileMenuState(open) {
  document.body.classList.toggle("mobile-menu-open", open);
  document.documentElement.classList.toggle("mobile-menu-open", open);
}

function hydrateChrome() {
  document.body.classList.toggle("work-page", currentPage === "work");
  const shell = document.getElementById("shell");
  const menuToggle = document.querySelector('[data-action="toggle-menu"]');
  const nav = document.querySelector(".sidebar nav");
  if (nav) nav.id = "trace-sidebar-nav";
  if (menuToggle) {
    menuToggle.setAttribute("aria-controls", "trace-sidebar-nav");
    const mobileOpen = shell?.classList.contains("mobile-menu-open") || false;
    menuToggle.setAttribute("aria-expanded", String(mobileOpen));
    menuToggle.setAttribute("title", mobileOpen ? "Fechar menu" : "Abrir menu");
  }
  if (shell && sidebarCollapsed()) shell.classList.add("sidebar-collapsed");
  if (nav)
    nav.innerHTML = NAV_GROUPS.map(([group, items]) => {
      const permitted = items.filter(([page]) => isAllowedPage(page));
      if (!permitted.length) return "";
      return `<span class="nav-label">${esc(group)}</span>${permitted.map(([page, label]) => `<a class="nav-item${page === currentPage ? " active" : ""}${["dashboard", "dalas"].includes(page) ? " nav-section-end" : ""}" data-page="${page}" data-label="${esc(label)}" href="${pagePath(page)}"><span class="nav-icon">${navigationIcon(page)}</span><span class="nav-text">${esc(label)}</span></a>`).join("")}`;
    }).join("");
  if (nav) nav.classList.add("is-hydrated");
  const pageLabel = PAGE_LABELS[currentPage] || "Dallogix Trace";
  document.title = `${pageLabel} • Dallogix Trace`;
  const pageHeading = document.querySelector(".topbar h1");
  if (pageHeading) {
    pageHeading.innerHTML = `<span class="brand-context">TracePlatform</span><small>/ Dallogix Trace</small><span class="page-context"> · ${esc(pageLabel)}</span>`;
  }
  conectividadeLocal.installIndicator();
  const userRole = normalizeRole(authenticatedUser?.role);
  el("#user-avatar").textContent = (authenticatedUser?.name || "A")
    .slice(0, 1)
    .toUpperCase();
  el("#user-name").textContent = authenticatedUser?.name || "";
  el("#user-role").textContent = ROLE_LABELS[userRole] || (authenticatedUser?.role || "");
}
function renderScreen() {
  const screenRoot = el("#screen-root");
  const scannerSnapshot = currentPage === "work"
    ? captureOperationScanner(screenRoot)
    : null;
  hydrateChrome();
  screenRoot.innerHTML = screens[currentPage](store);
  if (scannerSnapshot) restoreOperationScanner(screenRoot, scannerSnapshot);
  else if (currentPage === "work") focusOperationScanner(screenRoot);
  if (currentPage === "work") {
    instalarRecuperacaoFocoLeitor(screenRoot);
    updateOperationScannerFocus(screenRoot);
  }
  bindActions();
  bindForms();
  installRelativeTimeRefresh();
  workViewSignature = currentPage === "work" ? workStructureSignature() : "";
  if (["work", "emergency"].includes(currentPage)) startWorkPolling();
  else stopWorkPolling();
  statusScreensRealtime.start();
  if (Number(authenticatedUser?.company_id) > 0) dalaStatusRealtime.start();
  else dalaStatusRealtime.stop();
  if (currentPage === "master-home") companyViewSignature = companyStatusViewSignature();
}
function render() {
  renderScreen();
}
function stopWorkPolling() {
  workRealtime.stop();
}
function startWorkPolling() {
  workRealtime.start();
}
function installInteractionGuards() {
  document.addEventListener("dragstart", (event) => {
    if (event.target.closest("a, button, img, svg, [data-action]"))
      event.preventDefault();
  });
  document.addEventListener("dragover", (event) => {
    if (event.target.closest("a, button, img, svg, [data-action]"))
      event.preventDefault();
  });
  document.addEventListener("drop", (event) => {
    if (event.target.closest("a, button, img, svg, [data-action]"))
      event.preventDefault();
  });
}
let atualizarDelegacaoAcoes = null;
function bindActions() {
  const renderContextId = renderRequestId;
  // Uma resposta assíncrona pode chegar depois que o operador trocou de tela.
  // Nesse caso, a resposta pertence à tela anterior e não deve sobrescrever o
  // conteúdo que já foi aberto. O identificador só muda em renderPage().
  const render = () => {
    if (renderContextId !== renderRequestId) return;
    renderScreen();
  };
  const tratarAcaoNavegacao = criarTratadorAcoesNavegacao({
    navegar: navigate,
    paginaAtual: () => currentPage,
    buscaAtual: () => window.location.search,
    store,
    estaVoltandoAoDashboard: isDashboardReturn,
    consultaRetornoDashboard: dashboardReturnQuery,
    paginaDeRetorno: queryReturnPage,
    idDaConsulta: queryId,
  });
  const tratarAcaoAdministracao = criarTratadorAcoesAdministracao({
    store,
    confirmarAcao,
    solicitarTexto,
    notificar,
    render,
    copiarTexto: (texto) => navigator.clipboard.writeText(texto),
  });
  const tratarAcaoOperacional = criarTratadorAcoesOperacionais({
    store,
    render,
    navegar: navigate,
    paginaAtual: () => currentPage,
    estaVoltandoAoDashboard: isDashboardReturn,
    idDaConsulta: queryId,
    solicitarTexto,
    confirmarAcao,
    notificar,
    notificarForaDaOperacao,
    registrarAviso: (...args) => logFrontend.aviso(...args),
    servicoEmergencia,
    executarComandoDeOperacao,
    changeOperationLoading,
  });
  const tratarAcaoDiagnostico = criarTratadorAcoesDiagnostico({
    store,
    render,
    notificar,
    solicitarTexto,
    confirmarAcao,
  });
  const tratarAcaoRelatorios = criarTratadorAcoesRelatorios({
    api,
    store,
    notificar,
    redirecionarParaLogin: () => { window.location.href = pagePath("login"); },
    registrarAviso: (...args) => logFrontend.aviso(...args),
    paginaAtual: () => currentPage,
  });
  const tratarAcaoCadastro = criarTratadorAcoesCadastros({
    store,
    render,
    notificar,
    confirmarAcao,
    paginaAtual: () => currentPage,
    criarLinhaItemRomaneio: () => linhaItemRomaneio(store),
  });
  const tratarAcaoInterface = criarTratadorAcoesInterface({
    isMobileMenuViewport,
    setMobileMenuState,
    saveSidebarCollapsedPreference,
  });
  instalarInteracoesGlobaisInterface(setMobileMenuState);
  const despacharAcao = criarDespachanteAcoes({
    api,
    store,
    telas: screens,
    navegar: navigate,
    paginaPadrao: defaultPage,
    caminhoPagina: pagePath,
    renderPagina: renderPage,
    render,
    acoesDeFormulario: FORM_ACTIONS,
    notificar,
    tratadores: {
      navegacao: tratarAcaoNavegacao,
      administracao: tratarAcaoAdministracao,
      operacional: tratarAcaoOperacional,
      diagnostico: tratarAcaoDiagnostico,
      relatorios: tratarAcaoRelatorios,
      cadastros: tratarAcaoCadastro,
      interface: tratarAcaoInterface,
    },
  });
  if (!atualizarDelegacaoAcoes) atualizarDelegacaoAcoes = criarDelegacaoAcoes(document);
  atualizarDelegacaoAcoes(despacharAcao);
  document.querySelectorAll(".sidebar .nav-item[data-page]").forEach((item) => {
    if (item.dataset.navigationBound === "1") return;
    item.dataset.navigationBound = "1";
    item.addEventListener("click", (event) => {
      if (
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.button !== 0
      )
        return;
      event.preventDefault();
      if (window.matchMedia("(max-width: 760px)").matches) {
        document.querySelector(".shell")?.classList.remove("mobile-menu-open");
        setMobileMenuState(false);
        document.querySelector('[data-action="toggle-menu"]')?.setAttribute("aria-expanded", "false");
        document.querySelector('[data-action="toggle-menu"]')?.setAttribute("title", "Abrir menu");
      }
      navigate(item.dataset.page);
    });
  });
  document.querySelectorAll('input[data-action="open-users"]').forEach((node) => {
    node.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") node.click();
    });
  });
}
function redirectToLoginWithMessage(error, source) {
  try {
    sessionStorage.setItem("trace-login-message", error.message);
  } catch (storageError) {
    logFrontend.aviso(`${source}.sessao.expirada.mensagem`, storageError);
  }
  window.location.replace(pagePath("login"));
}

function bindForms() {
  const renderContextId = renderRequestId;
  // Mesma proteção dos cliques: salvar/importar/filtrar não pode redesenhar
  // uma tela antiga quando o usuário já navegou para outro endereço.
  const render = () => {
    if (renderContextId !== renderRequestId) return;
    renderScreen();
  };
  bindPageForms({
    root: document,
    store,
    render,
    notify: notificar,
    onUnauthorized: (error, source) => redirectToLoginWithMessage(error, source),
  });
  bindProductForm({
    form: document.querySelector("#product-form"),
    store,
    render,
    notify: notificar,
    onUnauthorized: (error) => redirectToLoginWithMessage(error, "produto"),
  });
  bindDalaCreationForm({
    form: document.querySelector("#dala-create-form"),
    store,
    render,
    notify: notificar,
  });
  bindDalaEditForm({
    form: document.querySelector("#dala-edit-form"),
    store,
    navigate,
    returnPage: queryReturnPage,
    notify: notificar,
  });
  bindManifestCreationForm({
    form: document.querySelector("#new-manifest-form"),
    store,
    navigate,
    onUnauthorized: (error) => redirectToLoginWithMessage(error, "romaneio"),
  });
  bindManifestEditForm({
    form: document.querySelector("#edit-manifest-form"),
    store,
    navigate,
    notify: notificar,
  });
  bindPdfManifestImportForm({
    form: document.querySelector("#pdf-form"),
    store,
    renderManifestItem: linhaItemRomaneio,
  });
  bindCsvManifestImportForm({
    form: document.querySelector("#csv-form"),
    api,
    store,
  });
  bindAdministrativeForms({ root: document, store, render, notify: notificar });
  bindOperationalForms({
    root: document,
    store,
    navigate,
    loadingId: queryId,
    dashboardQuery: dashboardReturnQuery,
    render,
    notify: notificar,
    updateScannerFocus: updateOperationScannerFocus,
    logWarning: (...args) => logFrontend.aviso(...args),
  });
}
async function renderPage() {
  const root = el("#screen-root");
  limparNotificacao();
  const requestId = ++renderRequestId;
  const page = currentPage;
  api.beginNavigation();

  // Mantém a tela atual visível enquanto as consultas terminam. Exibir um
  // painel intermediário de carregamento a cada navegação fazia o operador
  // perder o contexto e criava uma troca visual desnecessária no WebView2.
  // A nova tela substitui a anterior apenas quando os dados necessários
  // estiverem prontos; erros continuam sendo mostrados no mesmo espaço.
  try {
    await Promise.all([
      ensureScreenStyles(page),
      registroTelas.carregar(page),
      pageDataLoader.carregarDadosIniciais(page),
    ]);
    if (requestId !== renderRequestId) return;
    render();
  } catch (error) {
    if (requestId !== renderRequestId) return;
    const missingRecord = error?.message === "Registro não informado.";
    const returnPage = missingRecord
      ? ["manifest", "manifest-edit", "division"].includes(currentPage)
        ? "manifests"
        : ["dala", "dala-edit"].includes(currentPage)
          ? "dalas"
          : currentPage === "company"
            ? "companies"
            : null
      : ["manifest", "manifest-edit", "dala-edit", "company"].includes(currentPage)
        ? ["manifest", "manifest-edit"].includes(currentPage)
          ? "manifests"
          : currentPage === "company"
            ? "companies"
            : "dalas"
        : null;
    if (returnPage) {
      await navigate(
        returnPage,
        "",
        { replace: true },
      );
      return;
    }
    logFrontend.erro("tela.renderizacao", error);
    if (root)
      root.innerHTML = estadoErro("Não foi possível atualizar esta tela", error.message || "Verifique a conexão local e tente novamente.", { label: "Tentar novamente", acao: "reload-page", tom: "primary" });
    bindActions();
  }
}

async function bootstrap() {
  installInteractionGuards();
  conectividadeLocal.installOfflineShell();
  currentPage = initialPage || pageFromPath();
  if (!screens[currentPage]) {
    window.location.replace(pagePath("login"));
    return;
  }
  const response = await api.fetch("/api/me.php");
  if (!response.ok) {
    let result = {};
    try {
      result = await response.json();
    } catch (parseError) {
      logFrontend.aviso("sessao.resposta_me_nao_json", parseError);
    }
    try {
      sessionStorage.setItem(
        "trace-login-message",
        result.error || "O acesso ao sistema foi bloqueado.",
      );
    } catch (storageError) {
      logFrontend.aviso("sessao.acesso_bloqueado.mensagem_indisponivel", storageError);
    }
    window.location.replace(pagePath("login"));
    return;
  }
  const result = await response.json();
  authenticatedUser = result.user;
  store.setUser(authenticatedUser);
  store.setCsrfToken(result.csrf_token);
  configurarTransporteLogs((registro) => store.persistFrontendLog(registro));
  const requestedPage = resolveAuthorizedPage(currentPage, defaultPage());
  if (requestedPage !== currentPage) {
    currentPage = requestedPage;
    window.location.replace(pagePath(currentPage));
    return;
  }
  await waitForDocumentStyles();
  await renderPage();
  window.addEventListener("popstate", async () => {
    const page = pageFromPath();
    if (!screens[page] || !isAllowedPage(page)) {
      window.location.replace(pagePath(defaultPage()));
      return;
    }
    currentPage = page;
    await renderPage();
  });
}
bootstrap();
