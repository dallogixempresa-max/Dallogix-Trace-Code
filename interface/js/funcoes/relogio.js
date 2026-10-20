import { ClienteApi } from "../api/ClienteApi.js?v=202610140003";

const state = {
  offsetMs: 0,
  source: "pc",
  syncedAt: 0,
  centralReachable: false,
};
const api = new ClienteApi({ timeoutMs: 10000, retry: 1 });

function localState() {
  state.offsetMs = 0;
  state.source = "pc";
  state.syncedAt = Date.now();
  state.centralReachable = false;
  return statusRelogio();
}

export function agora() {
  return new Date(Date.now() + state.offsetMs);
}

export function statusRelogio() {
  return {
    source: state.source,
    syncedAt: state.syncedAt,
    centralReachable: state.centralReachable,
    label: state.source === "internet" ? "internet" : "PC local",
  };
}

export function usarRelogioDoPc() {
  return localState();
}

export async function sincronizarRelogio() {
  if (!navigator.onLine) return localState();

  const startedAt = Date.now();
  try {
    const response = await api.fetch(`/api/relogio.php?ts=${startedAt}`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    const result = await response.json().catch(() => ({}));
    const serverMs = Number(result?.unix_ms);
    if (!response.ok || result?.status !== "ok" || !Number.isFinite(serverMs) || serverMs <= 0) {
      return localState();
    }

    // Usa o meio da requisição para compensar parte do atraso de rede.
    const midpoint = (startedAt + Date.now()) / 2;
    state.offsetMs = result.source === "internet" ? serverMs - midpoint : 0;
    state.source = result.source === "internet" ? "internet" : "pc";
    state.syncedAt = Date.now();
    state.centralReachable = Boolean(result.central_reachable);
    return statusRelogio();
  } catch (_) {
    return localState();
  }
}
