import { logFrontend } from "../utilitarios/LogFrontend.js?v=202610060006";

const TELAS_COM_STATUS_DALAS = new Set(["dashboard", "settings", "dalas", "dala"]);

/** Mantém o status do CLP recente durante a navegação, sem depender da tela aberta. */
export function createDalaStatusRealtimeController({ store, getPage, isAuthenticated, refresh }) {
  let timer = null;
  let generation = 0;
  let active = false;
  let polling = false;
  let lastError = "";
  let lastSuccessAt = Date.now();
  let consecutiveFailures = 0;

  const isCurrent = (token) => active && token === generation && isAuthenticated();
  const delay = () => globalThis.document?.hidden ? 15000 : 5000;

  const schedule = (token) => {
    if (timer || !isCurrent(token)) return;
    timer = window.setTimeout(() => void poll(token), delay());
  };

  const refreshCurrentStatusView = async (token) => {
    const page = getPage();
    if (!isCurrent(token) || globalThis.document?.hidden || !TELAS_COM_STATUS_DALAS.has(page)) return;

    try {
      await refresh(page, {
        stale: consecutiveFailures >= 2,
        consecutiveFailures,
        staleForMs: Math.max(0, Date.now() - lastSuccessAt),
      });
    } catch (error) {
      logFrontend.aviso("status.dalas.renderizacao", error);
    }
  };

  const poll = async (token) => {
    timer = null;
    if (!isCurrent(token)) return;
    if (polling) {
      schedule(token);
      return;
    }

    polling = true;
    try {
      try {
        await store.loadDalaStatuses();
        consecutiveFailures = 0;
        lastSuccessAt = Date.now();
        lastError = "";
      } catch (error) {
        consecutiveFailures += 1;
        const message = error?.message || String(error);
        if (message !== lastError) {
          logFrontend.aviso("status.dalas.atualizacao", error);
          lastError = message;
        }
      }

      await refreshCurrentStatusView(token);
    } finally {
      polling = false;
      if (isCurrent(token)) schedule(token);
    }
  };

  const stop = () => {
    generation += 1;
    active = false;
    if (timer) window.clearTimeout(timer);
    timer = null;
  };

  const start = () => {
    if (!isAuthenticated()) {
      stop();
      return;
    }
    if (active) return;
    active = true;
    lastError = "";
    consecutiveFailures = 0;
    lastSuccessAt = Date.now();
    const token = ++generation;
    schedule(token);
  };

  globalThis.document?.addEventListener?.("visibilitychange", () => {
    if (!active || !isAuthenticated()) return;
    if (timer) window.clearTimeout(timer);
    timer = null;
    if (globalThis.document.hidden) schedule(generation);
    else void poll(generation);
  });

  return { start, stop };
}
