/**
 * Transporte HTTP único do Trace.
 *
 * As telas continuam recebendo um Response nativo para preservar os contratos
 * existentes, mas toda requisição passa por timeout, cancelamento e retry
 * controlado. Escritas nunca são repetidas automaticamente.
 */
export class ErroApi extends Error {
  constructor(message, { code = "API_ERROR", status = 0, url = "", cause = null } = {}) {
    super(message);
    this.name = "ErroApi";
    this.code = code;
    this.status = Number(status) || 0;
    this.url = url;
    this.cause = cause;
  }
}

const METODOS_SEGUROS = new Set(["GET", "HEAD", "OPTIONS"]);
const RETRY_STATUS = new Set([408, 425, 429, 502, 503, 504]);
const JANELA_ATIVIDADE_USUARIO_MS = 60_000;
let ultimaAtividadeUsuarioEm = 0;

export function registrarAtividadeDeUsuario() {
  ultimaAtividadeUsuarioEm = Date.now();
}

if (globalThis.window?.addEventListener) {
  for (const evento of ["pointerdown", "keydown", "touchstart", "wheel"]) {
    globalThis.window.addEventListener(evento, registrarAtividadeDeUsuario, { capture: true, passive: true });
  }
}

const MENSAGENS_HTTP = new Map([
  [400, "A requisição é inválida."],
  [401, "Sua sessão expirou. Entre novamente no sistema."],
  [403, "Você não tem permissão para esta operação."],
  [404, "O recurso solicitado não foi encontrado."],
  [409, "A operação entrou em conflito com o estado atual."],
  [422, "Os dados enviados não puderam ser validados."],
  [429, "Muitas tentativas. Aguarde alguns segundos e tente novamente."],
  [500, "O servidor encontrou um erro interno."],
  [502, "O servidor está temporariamente indisponível."],
  [503, "O servidor está temporariamente indisponível."],
  [504, "O servidor demorou para responder."],
]);

export function mensagemHttp(status, fallback = "Não foi possível concluir a requisição.") {
  return MENSAGENS_HTTP.get(Number(status)) || fallback;
}

/** Converte uma resposta inválida em ErroApi sem consumir o corpo original. */
export async function erroRespostaHttp(response, fallback = "Não foi possível concluir a requisição.") {
  if (response?.ok) return null;
  let detalhe = null;
  try {
    const fonte = typeof response?.clone === "function" ? response.clone() : response;
    detalhe = await fonte?.json?.();
  } catch (_) {
    detalhe = null;
  }
  const mensagem = detalhe?.error || detalhe?.message || fallback || mensagemHttp(response?.status);
  return new ErroApi(mensagem, {
    code: `HTTP_${Number(response?.status) || 0}`,
    status: Number(response?.status) || 0,
    url: response?.url || "",
  });
}

export async function exigirRespostaHttp(response, fallback) {
  const erro = await erroRespostaHttp(response, fallback);
  if (erro) throw erro;
  return response;
}

function atraso(ms) {
  const setTimeoutFn = globalThis.window?.setTimeout || globalThis.setTimeout;
  return new Promise((resolve) => setTimeoutFn(resolve, ms));
}

function mensagemDeErro(error, url) {
  if (error?.name === "AbortError") {
    return `A requisição demorou mais que o permitido (${url}).`;
  }
  if (globalThis.navigator?.onLine === false) {
    return "Sem conexão de rede para concluir a requisição.";
  }
  return error?.message || "Não foi possível comunicar com o servidor.";
}

function copiarCabecalhos(headers) {
  if (Array.isArray(headers)) return Object.fromEntries(headers);
  if (typeof Headers !== "undefined" && headers instanceof Headers) {
    return Object.fromEntries(headers.entries());
  }
  return { ...(headers || {}) };
}

function sinalComPrazo(signal, timeoutMs) {
  const controller = new AbortController();
  let timer = null;
  const abort = () => controller.abort();
  if (signal) {
    if (signal.aborted) controller.abort();
    else signal.addEventListener("abort", abort, { once: true });
  }
  if (timeoutMs > 0) {
    const setTimeoutFn = globalThis.window?.setTimeout || globalThis.setTimeout;
    timer = setTimeoutFn(() => controller.abort(), timeoutMs);
  }
  return {
    signal: controller.signal,
    clear() {
      const clearTimeoutFn = globalThis.window?.clearTimeout || globalThis.clearTimeout;
      if (timer) clearTimeoutFn(timer);
      signal?.removeEventListener("abort", abort);
    },
  };
}

export class ClienteApi {
  constructor({ timeoutMs = 15000, retry = 2, backoffMs = 400, onUnauthorized = null } = {}) {
    this.timeoutMs = timeoutMs;
    this.retry = retry;
    this.backoffMs = backoffMs;
    this.onUnauthorized = typeof onUnauthorized === "function" ? onUnauthorized : null;
    this.navigationController = null;
  }

  /**
   * Invalida consultas GET da tela anterior e devolve o sinal da nova tela.
   * Escritas continuam usando somente o sinal explícito do chamador.
   */
  beginNavigation() {
    this.navigationController?.abort();
    this.navigationController = new AbortController();
    return this.navigationController.signal;
  }

  async fetch(url, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const canRetry = METODOS_SEGUROS.has(method);
    const attempts = canRetry ? Math.max(0, Number(options.retry ?? this.retry)) : 0;
    const timeoutMs = Number(options.timeoutMs ?? this.timeoutMs);
    const navigationSignal = canRetry ? this.navigationController?.signal : undefined;
    const callerSignal = options.signal || navigationSignal;
    const { timeoutMs: _timeout, retry: _retry, ...requestOptions } = options;
    const headers = copiarCabecalhos(requestOptions.headers);
    if (
      ultimaAtividadeUsuarioEm > 0 &&
      Date.now() - ultimaAtividadeUsuarioEm <= JANELA_ATIVIDADE_USUARIO_MS
    ) {
      const activityHeader = Object.keys(headers).find(
        (name) => name.toLowerCase() === "x-trace-user-activity",
      ) || "X-Trace-User-Activity";
      headers[activityHeader] = "1";
    }

    for (let attempt = 0; attempt <= attempts; attempt += 1) {
      const request = sinalComPrazo(callerSignal, timeoutMs);
      try {
        const response = await fetch(url, { ...requestOptions, headers, signal: request.signal });
        const requestPath = String(url).split(/[?#]/, 1)[0];
        const isAuthenticationBootstrap = ["/api/login.php", "/api/me.php"].some(
          (path) => requestPath.endsWith(path),
        );
        if (response.status === 401 && !isAuthenticationBootstrap) {
          this.onUnauthorized?.(response, url);
        }
        if (response.ok || !canRetry || !RETRY_STATUS.has(response.status) || attempt >= attempts) {
          return response;
        }
        if (callerSignal?.aborted) {
          throw new ErroApi("A requisição foi cancelada.", {
            code: "API_CANCELLED",
            url,
          });
        }
      } catch (error) {
        if (callerSignal?.aborted) {
          throw new ErroApi("A requisição foi cancelada.", {
            code: "API_CANCELLED",
            url,
            cause: error,
          });
        }
        if (attempt >= attempts) {
          const cancelledByCaller = callerSignal?.aborted === true;
          throw new ErroApi(
            cancelledByCaller ? "A requisição foi cancelada." : mensagemDeErro(error, url),
            {
            code: cancelledByCaller
              ? "API_CANCELLED"
              : error?.name === "AbortError"
                ? "API_TIMEOUT"
                : "API_NETWORK",
            url,
            cause: error,
            },
          );
        }
      } finally {
        request.clear();
      }
      await atraso(this.backoffMs * (2 ** attempt));
    }

    throw new ErroApi("A requisição não pôde ser concluída.", { url });
  }
}
