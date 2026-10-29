const ACOES_COMANDO_MAQUINA = new Set([
  "start-machine",
  "stop-machine",
  "run",
  "stop",
  "reverse-machine",
  "reverse-on",
  "reverse-off",
  "reverse-toggle",
  "emergency",
]);

/** Encaminha as ações operacionais sem acoplar a regra de negócio ao DOM global. */
export function criarTratadorAcoesOperacionais({
  store,
  render,
  navegar,
  paginaAtual,
  estaVoltandoAoDashboard,
  idDaConsulta,
  solicitarTexto,
  confirmarAcao,
  notificar,
  notificarForaDaOperacao,
  registrarAviso,
  servicoEmergencia,
  executarComandoDeOperacao,
  changeOperationLoading,
}) {
  async function carregarLeiturasPendentes(node) {
    if (node.dataset.busy === "1") return;
    const page = Number(node.dataset.page);
    if (!Number.isSafeInteger(page) || page < 1 || page > store.state.pendingReadingsPages) return;

    node.dataset.busy = "1";
    node.disabled = true;
    try {
      await store.loadPendingReadings(page);
      if (paginaAtual() === "work") render();
    } catch (error) {
      notificar(error.message || "Não foi possível carregar as leituras pendentes.", "erro");
    } finally {
      if (node.isConnected) {
        node.dataset.busy = "0";
        node.disabled = false;
      }
    }
  }

  async function cancelarRomaneio(node) {
    const reason = await solicitarTexto(
      "Informe o motivo do cancelamento do romaneio:",
      { obrigatorio: true },
    ) || "";
    if (!reason.trim()) return;
    if (!await confirmarAcao("Confirma o cancelamento deste romaneio? Essa ação não poderá ser desfeita.")) return;
    if (!await confirmarAcao("SEGUNDA CONFIRMAÇÃO: cancelar este romaneio agora?")) return;

    try {
      await store.cancelManifest(node.dataset.id || idDaConsulta(), reason.trim());
      notificar("Romaneio cancelado.");
      await navegar(estaVoltandoAoDashboard() ? "dashboard" : "manifests");
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function cancelarOperacao(node) {
    const reason = await solicitarTexto(
      "Informe o motivo do cancelamento da operação:",
      { obrigatorio: true },
    ) || "";
    if (!reason.trim()) return;
    if (!await confirmarAcao("Confirma o cancelamento? A operação precisa estar pausada ou em emergência.")) return;

    const manifestId = node.dataset.id || idDaConsulta();
    store.state.manifestActionPending = true;
    store.state.manifestActionFeedback = { type: "status", message: "Cancelando operação…" };
    render();

    let cancellationResult;
    try {
      cancellationResult = await store.cancelManifest(manifestId, reason.trim());
    } catch (error) {
      store.state.manifestActionPending = false;
      store.state.manifestActionFeedback = {
        type: "error",
        message: error.message || "Não foi possível cancelar a operação.",
      };
      render();
      return;
    }

    store.state.manifestActionPending = false;
    if (store.state.manifestDetail) {
      store.state.manifestDetail.status = cancellationResult?.status || "CANCELADO";
      store.state.manifestDetail.active_loading_id = null;
    }
    store.state.manifestActionFeedback = {
      type: "success",
      message: "Operação cancelada e registrada na auditoria.",
    };
    render();

    try {
      await navegar(estaVoltandoAoDashboard() ? "dashboard" : "manifests");
      store.state.manifestActionFeedback = null;
    } catch (error) {
      registrarAviso("romaneio.cancelamento.navegacao", error);
    }
  }

  async function encerrarRomaneio(node) {
    const manifestId = Number(node.dataset.id || store.state.manifestDetail?.id) || 0;
    if (!manifestId) return;
    if (!await confirmarAcao("Todos os carregamentos deste romaneio foram finalizados. Encerrar agora e liberar o relatório de auditoria?")) return;
    store.state.manifestActionPending = true;
    store.state.manifestActionFeedback = { type: "status", message: "Encerrando romaneio…" };
    render();
    try {
      await store.finishManifest(manifestId);
      store.state.manifestActionFeedback = {
        type: "success",
        message: "Romaneio encerrado. O relatório de auditoria já está disponível.",
      };
    } catch (error) {
      store.state.manifestActionFeedback = {
        type: "error",
        message: error.message || "Não foi possível encerrar o romaneio.",
      };
    } finally {
      store.state.manifestActionPending = false;
      render();
    }
  }

  async function selecionarCarregamento(node) {
    try {
      store.state.commandFeedback = "";
      await store.loadActiveLoading(node.dataset.id);
      await store.loadDeviceStatuses();
      render();
    } catch (error) {
      store.state.selectedLoadingId = null;
      store.state.commandFeedback = error?.message || "Não foi possível carregar a Dala selecionada.";
      render();
    }
  }

  async function abrirResumo() {
    if (!["FINALIZANDO", "FINALIZADO"].includes(store.state.operationalState)) {
      notificarForaDaOperacao(
        "O resumo final ficará disponível quando a quantidade prevista for atingida.",
        "aviso",
      );
      return;
    }
    await navegar("summary");
  }

  async function liberarEmergencia(node) {
    node.disabled = true;
    try {
      await servicoEmergencia.liberar();
      render();
    } catch (error) {
      notificarForaDaOperacao(error.message, "erro");
    } finally {
      node.disabled = false;
    }
  }

  async function finalizarCarregamento() {
    try {
      const hasItemDivergence = (store.state.loadingItems || []).some((item) =>
        Number(item.loaded_quantity || 0) !== Number(item.planned_quantity || 0),
      );
      const canAuthorizeDivergence = ["ADMIN_EMPRESA", "SUPERVISOR"].includes(
        String(store.state.userRole || "").toUpperCase(),
      );
      if (hasItemDivergence && !canAuthorizeDivergence) {
        notificarForaDaOperacao(
          "Há produto sem identificação ou com quantidade divergente. Um supervisor ou administrador precisa encerrar o carregamento com justificativa.",
          "aviso",
        );
        return;
      }
      const justification = hasItemDivergence
        ? await solicitarTexto(
          "Justificativa obrigatória para finalizar com divergência:",
          { obrigatorio: true, multilinha: true },
        ) || ""
        : "";
      if (hasItemDivergence && !justification.trim()) return;
      const result = await store.finishLoading(justification.trim());
      if (result?.romaneio_pronto_para_encerrar && result.romaneio_id) {
        await store.loadManifest(result.romaneio_id);
        await navegar("manifest", `?id=${encodeURIComponent(result.romaneio_id)}`);
      } else {
        notificar("Carregamento finalizado. Ainda há outros carregamentos neste romaneio.");
      }
      render();
    } catch (error) {
      notificarForaDaOperacao(error.message, "erro");
    }
  }

  return function tratarAcaoOperacional(acao, node) {
    switch (acao) {
      case "pending-readings-page":
        return carregarLeiturasPendentes(node);
      case "cancel-manifest":
        return cancelarRomaneio(node);
      case "cancel-manifest-progress":
        return cancelarOperacao(node);
      case "finish-manifest":
        return encerrarRomaneio(node);
      case "select-loading":
        return selecionarCarregamento(node);
      case "change-loading":
        return changeOperationLoading(store, render);
      case "open-summary":
        return abrirResumo();
      case "unlock":
        return liberarEmergencia(node);
      case "finish":
        return finalizarCarregamento();
      default:
        if (ACOES_COMANDO_MAQUINA.has(acao)) {
          return executarComandoDeOperacao(node, acao);
        }
        return false;
    }
  };
}
