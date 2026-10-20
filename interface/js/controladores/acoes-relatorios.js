import { downloadBlob, downloadCsv } from "../funcoes/downloads.js?v=202610190043";

/** Encaminha exportações e downloads de relatórios sem ampliar o roteador da aplicação. */
export function criarTratadorAcoesRelatorios({
  api,
  store,
  notificar,
  redirecionarParaLogin,
  registrarAviso,
  paginaAtual,
  browser = globalThis,
}) {
  async function baixarRelatorioAuditoria(node) {
    try {
      const id = Number(node.dataset.id);
      if (!Number.isSafeInteger(id) || id <= 0) throw new Error("Romaneio inválido.");

      const response = await api.fetch(`/api/relatorio_auditoria.php?romaneio_id=${id}`, {
        credentials: "same-origin",
        headers: { Accept: "application/pdf, application/json" },
      });
      if (response.status === 401) {
        notificar("Sua sessão expirou. Entre novamente para baixar o relatório.");
        redirecionarParaLogin();
        return;
      }
      if (!response.ok) {
        const raw = await response.text();
        let result = {};
        try {
          result = JSON.parse(raw);
        } catch (error) {
          // Resposta HTML de proxy não deve virar uma mensagem vazia para o operador.
          registrarAviso("relatorio_auditoria.resposta_nao_json", error);
        }
        throw new Error(result.error || "Não foi possível baixar o relatório.");
      }

      const contentType = response.headers.get("content-type") || "";
      if (!contentType.toLowerCase().includes("application/pdf")) {
        throw new Error("O servidor não retornou um relatório PDF válido.");
      }
      const blob = await response.blob();
      if (!blob.size) throw new Error("O relatório retornou vazio.");
      downloadBlob(blob, `romaneio-${id}.pdf`, browser);
    } catch (error) {
      notificar(error.message || "Não foi possível baixar o relatório.");
    }
  }

  async function exportarDados() {
    try {
      await store.loadSyncStatus();
      const syncSummary = store.state.syncStatus?.summary || {};
      const pendingSync = ["PENDENTE", "PROCESSANDO", "ERRO"]
        .reduce((total, status) => total + (Number(syncSummary[status]) || 0), 0);
      downloadCsv(`dallogix-${paginaAtual()}.csv`, [
        ["Campo", "Valor"],
        ["Romaneio", store.state.romaneio],
        ["Caminhão", store.state.truck],
        ["Estado", store.state.operationalState],
        ["Carregado", store.state.loaded],
        ["Planejado", store.state.planned],
        ["Sincronização pendente", pendingSync],
      ], browser);
    } catch (error) {
      notificar(error.message || "Não foi possível atualizar a fila de sincronização.");
    }
  }

  return function tratarAcaoRelatorios(action, node) {
    if (action === "download-audit-report") return baixarRelatorioAuditoria(node);
    if (action === "export") return exportarDados();
    return false;
  };
}
