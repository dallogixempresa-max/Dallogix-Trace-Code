/**
 * Configuração estática da aplicação: perfis, navegação, rótulos e rotas.
 *
 * Mantemos estes dados fora do orquestrador para que uma alteração de menu
 * não precise tocar na lógica de carregamento, sessão ou renderização.
 */
export const ROLE_LABELS = Object.freeze({
  ADMIN_DALLOGIX: "Master Dallogix",
  ADMIN_EMPRESA: "Administrador da empresa",
  SUPERVISOR: "Supervisor",
  USUARIO: "Operador",
});

export const ROLE_PAGES = Object.freeze({
  ADMIN_DALLOGIX: ["master-home", "companies", "company", "users", "error-logs"],
  ADMIN_EMPRESA: [
    "dashboard", "manifests", "manifest", "manifest-edit", "import", "division",
    "work", "occurrences", "summary", "products", "alerts", "emergency", "settings",
    "dalas", "dala", "dala-edit", "users", "error-logs",
  ],
  SUPERVISOR: [
    "dashboard", "manifests", "manifest", "manifest-edit", "import", "division",
    "work", "occurrences", "summary", "products", "alerts", "emergency", "dala",
  ],
  USUARIO: [
    "dashboard", "manifests", "manifest", "dala", "work", "summary", "occurrences",
    "alerts", "emergency",
  ],
});

export const NAV_GROUPS = Object.freeze([
  ["Operação", [["manifests", "Romaneios"], ["dashboard", "Dashboard"]]],
  ["Administração", [["master-home", "Visão geral"], ["companies", "Empresas"]]],
  ["Cadastros", [["products", "Produtos"], ["dalas", "Dalas"]]],
  ["Sistema", [["settings", "Configurações"], ["error-logs", "Logs de erros"]]],
]);

export const PAGE_LABELS = Object.freeze({
  dashboard: "Dashboard",
  manifests: "Romaneios",
  import: "Importar romaneio",
  division: "Divisão de carga",
  work: "Operação",
  occurrences: "Ocorrências",
  summary: "Resumo final",
  products: "Produtos",
  alerts: "Alertas",
  emergency: "Emergência",
  settings: "Configurações",
  dalas: "Dalas",
  dala: "Visualizar Dala",
  "dala-edit": "Editar Dala",
  manifest: "Visualizar romaneio",
  "manifest-edit": "Editar romaneio",
  companies: "Empresas",
  "master-home": "Visão geral",
  company: "Empresa",
  users: "Usuários",
  "error-logs": "Logs de erros",
});

export const PAGE_PATHS = Object.freeze({
  login: "/telas/acesso/acesso.html",
  dashboard: "/telas/painel/painel.html",
  manifests: "/telas/romaneios/romaneios.html",
  manifest: "/telas/romaneio/romaneio.html",
  "manifest-edit": "/telas/editar-romaneio/editar-romaneio.html",
  import: "/telas/importar-romaneio/importar-romaneio.html",
  division: "/telas/divisao-carga/divisao-carga.html",
  work: "/telas/operacao/operacao.html",
  occurrences: "/telas/ocorrencias/ocorrencias.html",
  summary: "/telas/resumo-final/resumo-final.html",
  products: "/telas/produtos/produtos.html",
  alerts: "/telas/alertas/alertas.html",
  emergency: "/telas/emergencia/emergencia.html",
  settings: "/telas/configuracoes/configuracoes.html",
  dalas: "/telas/dalas/dalas.html",
  dala: "/telas/visualizar-dala/visualizar-dala.html",
  "dala-edit": "/telas/editar-dala/editar-dala.html",
  companies: "/telas/empresas/empresas.html",
  company: "/telas/empresa/empresa.html",
  "master-home": "/telas/painel-dallogix/painel-dallogix.html",
  users: "/telas/usuarios/usuarios.html",
  "error-logs": "/telas/logs-erros/logs-erros.html",
});

export const LEGACY_PAGE_IDS = Object.freeze({
  "index.html": "login", "dashboard.html": "dashboard", "manifests.html": "manifests",
  "manifest.html": "manifest", "manifest-edit.html": "manifest-edit", "import.html": "import",
  "division.html": "division", "work.html": "work", "occurrences.html": "occurrences",
  "summary.html": "summary", "products.html": "products", "alerts.html": "alerts",
  "emergency.html": "emergency", "settings.html": "settings", "dalas.html": "dalas",
  "dala.html": "dala", "dala-edit.html": "dala-edit", "companies.html": "companies",
  "company.html": "company", "master-home.html": "master-home", "users.html": "users",
  "error-logs.html": "error-logs",
});

export const PAGE_IDS_BY_PATH = Object.freeze(Object.fromEntries(
  Object.entries(PAGE_PATHS).map(([page, path]) => [path, page]),
));
