import { logFrontend } from "../utilitarios/LogFrontend.js?v=202610060007";

/** Compartilha SSE e polling rápido entre Operação e Emergência. */
export function createOperationalRealtimeController({
  store,
  getPage,
  render,
  refreshWorkLiveView,
  refreshOtherLiveView = () => {},
  setFreshness = () => {},
  workStructureSignature,
  getViewSignature,
}) {
  let eventSource = null;
  let fallbackTimer = null;
  let reconnectTimer = null;
  let polling = false;
  let reconnectFailures = 0;
  let generation = 0;
  let active = false;
  let consecutiveFailures = 0;
  let lastPollStartedAt = 0;
  let lastSuccessAt = Date.now();
  let lastError = "";

  const isRealtimePage = () => ["work", "emergency"].includes(getPage());
  const isCurrent = (token) => token === generation && isRealtimePage();
  const recordSuccess = () => {
    consecutiveFailures = 0;
    lastSuccessAt = Date.now();
    lastError = "";
  };
  const recordFailure = (error) => {
    consecutiveFailures += 1;
    const message = error?.message || String(error);
    if (message !== lastError) {
      logFrontend.aviso("tempo-real.consulta", error);
      lastError = message;
    }
  };
  const publishFreshness = (page) => {
    try {
      setFreshness(page, {
        stale: consecutiveFailures >= 2,
        consecutiveFailures,
        staleForMs: Math.max(0, Date.now() - lastSuccessAt),
      });
    } catch (error) {
      logFrontend.aviso("tempo-real.indicador-atualizacao", error);
    }
  };

  const stop = () => {
    generation += 1;
    active = false;
    if (fallbackTimer) window.clearTimeout(fallbackTimer);
    if (reconnectTimer) window.clearTimeout(reconnectTimer);
    fallbackTimer = null;
    reconnectTimer = null;
    eventSource?.close();
    eventSource = null;
  };

  const refreshCurrentView = (page) => {
    try {
      if (page === "work") {
        if (workStructureSignature() !== getViewSignature()) render();
        else refreshWorkLiveView();
        return;
      }
      refreshOtherLiveView(page);
    } catch (error) {
      logFrontend.aviso("tempo-real.renderizacao", error);
    }
  };

  const pollOnce = async (token, page) => {
    if (!isCurrent(token) || polling) return false;
    polling = true;
    lastPollStartedAt = Date.now();
    try {
      const requests = [
        store.loadActiveLoading(store.state.selectedLoadingId),
        store.loadDeviceStatuses(),
      ];
      if (page === "work") requests.push(store.loadPendingReadings());
      const results = await Promise.allSettled(requests);
      if (!isCurrent(token)) return false;
      const currentPage = getPage();
      const failed = results.find((result) => result.status === "rejected");
      if (failed) recordFailure(failed.reason);
      else recordSuccess();
      refreshCurrentView(currentPage);
      publishFreshness(currentPage);
      return !failed;
    } catch (error) {
      if (!isCurrent(token)) return false;
      recordFailure(error);
      const currentPage = getPage();
      refreshCurrentView(currentPage);
      publishFreshness(currentPage);
      return false;
    } finally {
      polling = false;
    }
  };

  const fallback = (token) => {
    if (fallbackTimer || !isCurrent(token)) return;
    const interval = globalThis.document?.hidden ? 10000 : 2000;
    fallbackTimer = window.setTimeout(async () => {
      fallbackTimer = null;
      if (!isCurrent(token)) return;
      if (polling) {
        const currentPage = getPage();
        refreshCurrentView(currentPage);
        publishFreshness(currentPage);
        fallback(token);
        return;
      }
      try {
        await pollOnce(token, getPage());
      } catch (error) {
        if (isCurrent(token)) {
          recordFailure(error);
          publishFreshness(getPage());
        }
      } finally {
        if (isCurrent(token)) fallback(token);
      }
    }, interval);
  };

  const connect = (token) => {
    if (!isCurrent(token) || eventSource) return;
    const consume = async (payload) => {
      if (!isCurrent(token)) return;
      const requestPage = getPage();
      reconnectFailures = 0;
      let requestError = null;
      const snapshotAt = Date.parse(payload?.sent_at || "");
      const snapshotPredatesPoll = Number.isFinite(snapshotAt) &&
        lastPollStartedAt > 0 && snapshotAt + 1000 < lastPollStartedAt;
      if (!snapshotPredatesPoll) {
        // O stream pode capturar a transição entre a gravação do comando e a
        // atualização da lista. Confirme o vazio pelo endpoint HTTP antes de
        // aceitar que o carregamento terminou.
        const selectedId = Number(store.state.selectedLoadingId) || null;
        let activeLoadings = Array.isArray(payload?.active_loadings)
          ? payload.active_loadings
          : [];
        const snapshotHasSelection = selectedId === null || activeLoadings.some(
          (loading) => Number(loading?.id) === selectedId,
        );
        if (selectedId && store.state.loadingId && !snapshotHasSelection) {
          try {
            await store.loadActiveLoading(selectedId);
            if (store.state.selectedLoadingId === selectedId) {
              activeLoadings = store.state.activeLoadings || activeLoadings;
            }
          } catch (error) {
            requestError = error;
            activeLoadings = store.state.activeLoadings || activeLoadings;
          }
        }
        if (!isCurrent(token)) return;
        try {
          await store.applyActiveLoadingSnapshot(activeLoadings, selectedId, () => isCurrent(token));
          if (!isCurrent(token)) return;
          if (payload?.monitoring) {
            store.state.monitoring = payload.monitoring;
            store.state.monitoringUpdatedAt = Number.isFinite(snapshotAt)
              ? new Date(snapshotAt).toISOString()
              : new Date().toISOString();
          }
        } catch (error) {
          requestError ||= error;
        }
      }
      const requests = [];
      if (store.state.loadingId) {
        requests.push(store.loadPlcCommandStatus(store.state.loadingId));
        if (requestPage === "work") requests.push(store.loadPendingReadings());
      }
      const results = await Promise.allSettled(requests);
      requestError ||= results.find((result) => result.status === "rejected")?.reason || null;
      if (!isCurrent(token)) return;
      const currentPage = getPage();
      if (requestError) recordFailure(requestError);
      else if (!snapshotPredatesPoll) recordSuccess();
      refreshCurrentView(currentPage);
      publishFreshness(currentPage);
    };

    const reconnect = () => {
      if (!isCurrent(token) || reconnectTimer) return;
      eventSource?.close();
      eventSource = null;
      reconnectFailures += 1;
      // Polling remains the live fallback; stream reconnects use the same
      // generation so an old timer cannot silently stop the fallback loop.
      const delay = Math.min(30000, 5000 * (2 ** (reconnectFailures - 1)));
      reconnectTimer = window.setTimeout(() => {
        reconnectTimer = null;
        if (!isCurrent(token)) return;
        connect(token);
      }, delay);
    };

    eventSource = store.subscribeOperationalEvents(consume, reconnect);
  };

  const start = () => {
    if (!isRealtimePage() || active) return;
    active = true;
    const token = ++generation;
    reconnectFailures = 0;
    consecutiveFailures = 0;
    lastSuccessAt = Date.now();
    lastError = "";
    try {
      connect(token);
    } catch (error) {
      recordFailure(error);
    }
    fallback(token);
  };

  globalThis.document?.addEventListener?.("visibilitychange", () => {
    if (globalThis.document.hidden || !isRealtimePage()) return;
    if (fallbackTimer) window.clearTimeout(fallbackTimer);
    fallbackTimer = null;
    const token = generation;
    void (async () => {
      try {
        await pollOnce(token, getPage());
      } catch (error) {
        if (isCurrent(token)) {
          recordFailure(error);
          publishFreshness(getPage());
        }
      } finally {
        if (isCurrent(token)) fallback(token);
      }
    })();
  });

  return { start, stop };
}
