import { statusRelogio, sincronizarRelogio, usarRelogioDoPc } from "../funcoes/relogio.js?v=202609170015";

export function createLocalConnectivityController({
  store,
  api,
  getCurrentPage,
  getInstallationMode,
  setInstallationMode,
  refreshWorkView,
  refreshSettingsView,
  render,
  logWarning,
  serviceWorkerUrl,
}) {
  let healthTimer = null;
  let healthRequestInFlight = false;

  function setLocalIndicator(state, checkedAt = null, installationMode = getInstallationMode()) {
    const labels = installationMode === "central"
      ? {
          online: "Servidor central online",
          degraded: "Servidor central sem confirmação",
          offline: "Servidor central sem comunicação",
        }
      : {
          online: "Sistema local online",
          degraded: "Sistema local sem confirmação",
          offline: "Sistema local sem comunicação",
        };
    const clock = statusRelogio();
    const detail = checkedAt
      ? `Última verificação: ${new Date(checkedAt).toLocaleTimeString("pt-BR", { timeZone: "America/Sao_Paulo" })} • Horário: ${clock.label}`
      : `Horário: ${clock.label}`;
    document.querySelectorAll(".local").forEach((indicator) => {
      indicator.classList.remove("is-online", "is-degraded", "is-offline");
      indicator.classList.add(`is-${state}`);
      indicator.innerHTML = `<i></i> ${labels[state] || labels.degraded}`;
      indicator.title = detail;
      indicator.setAttribute("aria-label", `${labels[state] || labels.degraded}. ${detail}`);
    });
  }

  async function refresh() {
    if (healthRequestInFlight) return;
    if (!navigator.onLine) {
      usarRelogioDoPc();
      store.state.serverStatus = "OFFLINE";
      setLocalIndicator("offline");
      if (getCurrentPage() === "work") refreshWorkView();
      if (getCurrentPage() === "settings") refreshSettingsView();
      return;
    }
    healthRequestInFlight = true;
    try {
      const [response] = await Promise.all([
        api.fetch(`/api/health.php?ts=${Date.now()}`, {
          cache: "no-store",
          headers: { Accept: "application/json" },
        }),
        sincronizarRelogio(),
      ]);
      const result = await response.json().catch(() => ({}));
      const installationMode = result.installation_mode === "central" ? "central" : "local";
      setInstallationMode(installationMode);
      const state = response.ok && result.status === "ok" ? "online" : "degraded";
      store.state.serverStatus = state === "online" ? "ONLINE" : "ERRO";
      setLocalIndicator(state, result.checked_at || Date.now(), installationMode);
      if (getCurrentPage() === "work") refreshWorkView();
      if (getCurrentPage() === "settings") refreshSettingsView();
      if (state === "online") {
        const previousPending = store.state.offlineQueueSize;
        store.flushOfflineOperations().then((queue) => {
          if (getCurrentPage() === "work" && (queue.sent > 0 || queue.pending !== previousPending)) render();
        }).catch((error) => logWarning("fila.offline", error));
      }
    } catch (error) {
      logWarning("indicador.saude", error);
      store.state.serverStatus = "OFFLINE";
      setLocalIndicator("offline");
      if (getCurrentPage() === "work") refreshWorkView();
      if (getCurrentPage() === "settings") refreshSettingsView();
    } finally {
      healthRequestInFlight = false;
    }
  }

  function installIndicator() {
    if (healthTimer) return;
    window.addEventListener("online", () => {
      refresh();
      store.flushOfflineOperations().then(() => {
        if (getCurrentPage() === "work") render();
      }).catch((error) => {
        logWarning("fila.offline", error);
        /* a fila permanece armazenada para a próxima tentativa */
      });
    });
    window.addEventListener("offline", () => {
      usarRelogioDoPc();
      refresh();
    });
    refresh();
    healthTimer = window.setInterval(refresh, 30000);
  }

  function installOfflineShell() {
    if (!("serviceWorker" in navigator) || window.location.protocol === "file:") return;
    let reloadAfterUpdate = false;
    navigator.serviceWorker.addEventListener("controllerchange", () => {
      if (!reloadAfterUpdate) return;
      reloadAfterUpdate = false;
      window.location.reload();
    });
    navigator.serviceWorker.register(serviceWorkerUrl).then((registration) => {
      const activateUpdateQuietly = () => {
        if (!registration.waiting || !navigator.serviceWorker.controller) return;
        reloadAfterUpdate = true;
        registration.waiting.postMessage({ type: "ATIVAR_NOVA_VERSAO" });
      };
      if (registration.waiting) activateUpdateQuietly();
      registration.addEventListener("updatefound", () => {
        const worker = registration.installing;
        if (!worker) return;
        worker.addEventListener("statechange", () => {
          if (worker.state === "installed" && navigator.serviceWorker.controller) {
            activateUpdateQuietly();
          }
        });
      });
      registration.update().catch((error) => {
        logWarning("service-worker.atualizacao", error);
        // A aplicação continua funcional quando a verificação do cache falhar.
      });
    }).catch((error) => {
      logWarning("service-worker.registro", error);
      // A aplicação continua funcional quando o navegador não oferece suporte ao cache offline.
    });
  }

  return { installIndicator, installOfflineShell, refresh };
}
