const TAB_STORAGE_KEY = "trace-tab-id";

function gerarIdAba() {
  const bytes = new Uint8Array(16);
  if (globalThis.crypto?.getRandomValues) {
    globalThis.crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function obterIdAba() {
  try {
    const atual = String(sessionStorage.getItem(TAB_STORAGE_KEY) || "").trim().toLowerCase();
    if (/^[a-f0-9]{32}$/.test(atual)) return atual;
    const novo = gerarIdAba();
    sessionStorage.setItem(TAB_STORAGE_KEY, novo);
    return novo;
  } catch (error) {
    return "";
  }
}

export function configurarSessaoPorAba() {
  if (typeof window === "undefined" || typeof window.fetch !== "function") return;
  if (window.__traceSessionFetchConfigured === true) return;

  const fetchOriginal = window.fetch.bind(window);
  window.fetch = (input, init) => {
    const tabId = obterIdAba();
    if (!tabId) return fetchOriginal(input, init);

    const inputUrl =
      typeof Request !== "undefined" && input instanceof Request
        ? input.url
        : String(input);
    let destino;
    try {
      destino = new URL(inputUrl, window.location.href);
    } catch (error) {
      return fetchOriginal(input, init);
    }
    if (destino.origin !== window.location.origin) {
      return fetchOriginal(input, init);
    }

    const headers = new Headers(
      typeof Request !== "undefined" && input instanceof Request
        ? input.headers
        : undefined,
    );
    if (init?.headers) {
      new Headers(init.headers).forEach((value, name) => headers.set(name, value));
    }
    headers.set("X-Trace-Tab", tabId);
    return fetchOriginal(input, { ...(init || {}), headers });
  };
  window.__traceSessionFetchConfigured = true;
}
