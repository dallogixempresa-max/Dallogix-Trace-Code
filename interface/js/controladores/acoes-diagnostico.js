/**
 * Encaminha ações de sincronização e diagnóstico sem misturar essas rotas
 * com navegação ou comandos operacionais da Dala.
 */
export function criarTratadorAcoesDiagnostico({
  store,
  render,
  notificar,
}) {
  async function recarregarPainelMaster() {
    try {
      await Promise.all([store.loadCompanies(), store.loadErrorLogs()]);
      render();
    } catch (error) {
      notificar(error.message || "Não foi possível atualizar a visão geral.");
    }
  }

  async function recarregarHistoricoComandos(node) {
    try {
      await store.loadDalaCommandHistory(node.dataset.id);
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function selecionarPaginaHistoricoComandos(node) {
    try {
      await store.loadDalaCommandHistory(node.dataset.id, node.dataset.page);
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function selecionarPaginaLogs(node) {
    try {
      await store.loadErrorLogs(node.dataset.page);
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function recarregarDiagnosticos() {
    try {
      await Promise.all([
        store.loadErrorLogs(1),
        store.loadTechnicalDiagnostics(),
      ]);
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  return function tratarAcaoDiagnostico(action, node) {
    switch (action) {
      case "reload-master-home":
        return recarregarPainelMaster();
      case "reload-dala-diagnostics":
        return recarregarHistoricoComandos(node);
      case "dala-command-page":
        return selecionarPaginaHistoricoComandos(node);
      case "error-logs-page":
        return selecionarPaginaLogs(node);
      case "reload-error-logs":
        return recarregarDiagnosticos();
      default:
        return false;
    }
  };
}
