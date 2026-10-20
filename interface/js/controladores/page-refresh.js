import { logFrontend } from "../utilitarios/LogFrontend.js?v=202610060006";

/** Atualiza a página ativa sem reiniciar formulários ou navegação. */
export function createPageRefreshController({ getPage, pages }) {
  let timer = null;
  let activePage = null;
  let polling = false;
  let generation = 0;
  let lastError = "";
  let lastSuccessAt = Date.now();
  let consecutiveFailures = 0;

  const isCurrent = (token, page) =>
    token === generation && activePage === page && getPage() === page;

  const delay = (page) => {
    const config = pages.get(page) || {};
    const interval = config.intervalMs || 5000;
    return globalThis.document?.hidden
      ? (config.hiddenIntervalMs || 15000)
      : interval;
  };

  const schedule = (token, page) => {
    if (timer || !isCurrent(token, page)) return;
    timer = window.setTimeout(() => void poll(token, page), delay(page));
  };

  const poll = async (token, page) => {
    timer = null;
    if (!isCurrent(token, page)) return;
    if (polling) {
      schedule(token, page);
      return;
    }

    polling = true;
    const config = pages.get(page);
    try {
      try {
        const requests = config.load();
        const results = await Promise.allSettled(
          Array.isArray(requests) ? requests : [requests],
        );
        const failed = results.some((result) => result.status === "rejected");
        if (failed) {
          consecutiveFailures += 1;
          const message = results
            .filter((result) => result.status === "rejected")
            .map((result) => result.reason?.message || String(result.reason))
            .join(" | ");
          if (message !== lastError) {
            logFrontend.aviso(`status.${page}.atualizacao`, message);
            lastError = message;
          }
        } else {
          consecutiveFailures = 0;
          lastSuccessAt = Date.now();
          lastError = "";
        }
      } catch (error) {
        consecutiveFailures += 1;
        const message = error?.message || String(error);
        if (message !== lastError) {
          logFrontend.aviso(`status.${page}.atualizacao`, error);
          lastError = message;
        }
      }
      if (isCurrent(token, page)) {
        try {
          await config.refresh({
            stale: consecutiveFailures >= 2,
            consecutiveFailures,
            staleForMs: Math.max(0, Date.now() - lastSuccessAt),
          });
        } catch (refreshError) {
          logFrontend.aviso(`status.${page}.renderizacao`, refreshError);
        }
      }
    } finally {
      polling = false;
      if (isCurrent(token, page)) schedule(token, page);
    }
  };

  const start = () => {
    const page = getPage();
    if (!pages.has(page)) {
      stop();
      return;
    }
    if (activePage === page) return;
    stop();
    activePage = page;
    lastError = "";
    consecutiveFailures = 0;
    lastSuccessAt = Date.now();
    const token = ++generation;
    schedule(token, page);
  };

  const stop = () => {
    generation += 1;
    activePage = null;
    if (timer) window.clearTimeout(timer);
    timer = null;
  };

  globalThis.document?.addEventListener?.("visibilitychange", () => {
    if (globalThis.document.hidden || !activePage) return;
    if (timer) window.clearTimeout(timer);
    timer = null;
    void poll(generation, activePage);
  });

  return { start, stop };
}
