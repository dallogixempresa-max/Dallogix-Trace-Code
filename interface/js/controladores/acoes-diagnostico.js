/**
 * Encaminha ações de sincronização e diagnóstico sem misturar essas rotas
 * com navegação ou comandos operacionais da Dala.
 */
export function criarTratadorAcoesDiagnostico({
  store,
  render,
  notificar,
  solicitarTexto,
  confirmarAcao,
}) {
  async function tentarSincronizacao(node) {
    try {
      const result = await store.retrySync(node.dataset.id);
      notificar(
        result?.reason || (result?.processed ? "Evento enviado." : "Evento reprocessado."),
      );
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

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
        store.loadDeadLetters(),
      ]);
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function atualizarEventoPendente(node, action) {
    const requeue = action === "requeue-dead-letter";
    const verb = requeue ? "reenfileirar" : "resolver";
    const note = await solicitarTexto(
      `Informe o motivo para ${verb} este evento:`,
      { obrigatorio: true },
    );
    if (note === null || !note.trim()) return;
    if (!await confirmarAcao(`Confirma ${verb} este evento da fila morta?`)) return;

    try {
      await store.updateDeadLetter(node.dataset.id, requeue ? "requeue" : "resolve", note.trim());
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  return function tratarAcaoDiagnostico(action, node) {
    switch (action) {
      case "retry-sync":
        return tentarSincronizacao(node);
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
      case "requeue-dead-letter":
      case "resolve-dead-letter":
        return atualizarEventoPendente(node, action);
      default:
        return false;
    }
  };
}
