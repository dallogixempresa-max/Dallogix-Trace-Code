const LIMITE_REGISTROS = 100;
const INTERVALOS_SUPRESSAO = { aviso: 15000, erro: 5000, info: 0 };
const registros = [];
const ultimosRegistros = new Map();
let transporteRemoto = null;

const CHAVES_SENSIVEIS = /token|senha|password|authorization|cookie|secret|credential|csrf/i;

function valorSeguro(valor, vistos = new WeakSet()) {
  if (valor === null || valor === undefined) return valor;
  if (typeof valor === "string" || typeof valor === "number" || typeof valor === "boolean") return valor;
  if (typeof valor !== "object") return String(valor);
  if (vistos.has(valor)) return "[circular]";
  vistos.add(valor);
  if (Array.isArray(valor)) return valor.slice(0, 20).map((item) => valorSeguro(item, vistos));
  return Object.fromEntries(
    Object.entries(valor).slice(0, 30).map(([chave, item]) => [
      chave,
      CHAVES_SENSIVEIS.test(chave) ? "[omitido]" : valorSeguro(item, vistos),
    ]),
  );
}

function mensagemSegura(valor) {
  return String(valor || "")
    .replace(/Bearer\s+[^\s,;]+/gi, "Bearer [omitido]")
    .replace(/([?&](?:token|senha|password|secret|authorization|csrf)=)[^&\s]+/gi, "$1[omitido]")
    .replace(/(https?:\/\/)[^/@\s]+@/gi, "$1[credenciais]@");
}

function erroSeguro(erro) {
  if (!erro) return null;
  if (typeof erro === "string") return { mensagem: mensagemSegura(erro) };
  return {
    nome: String(erro.name || "Erro"),
    mensagem: mensagemSegura(erro.message || erro),
    ...(erro.code ? { codigo: String(erro.code) } : {}),
    ...(Number.isFinite(Number(erro.status)) ? { status: Number(erro.status) } : {}),
  };
}

function novoIdentificadorCorrelacao() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const valores = new Uint8Array(16);
  globalThis.crypto?.getRandomValues?.(valores);
  if (valores.some((valor) => valor !== 0)) {
    return [...valores].map((valor) => valor.toString(16).padStart(2, "0")).join("");
  }
  return `${Date.now().toString(16)}${Math.random().toString(16).slice(2).padEnd(16, "0")}`.slice(0, 32);
}

export function configurarTransporteLogs(transporte) {
  transporteRemoto = typeof transporte === "function" ? transporte : null;
}

function enviarLogRemoto(registro) {
  if (!transporteRemoto || registro.nivel === "info") return;
  Promise.resolve()
    .then(() => transporteRemoto(registro))
    .catch((error) => {
      globalThis.console?.warn?.("[Trace] Não foi possível persistir o log do navegador.", error);
    });
}

/** Registra falhas técnicas sem guardar credenciais ou payloads sensíveis. */
export function registrarLogFrontend(nivel, contexto, erro = null, detalhes = {}, { agora = Date.now() } = {}) {
  const tipo = ["info", "aviso", "erro"].includes(nivel) ? nivel : "erro";
  const chave = `${tipo}:${contexto}:${erroSeguro(erro)?.mensagem || ""}`;
  const intervalo = INTERVALOS_SUPRESSAO[tipo];
  const ultimo = ultimosRegistros.get(chave) || 0;
  if (intervalo > 0 && agora - ultimo < intervalo) return null;
  ultimosRegistros.set(chave, agora);

  const registro = {
    correlation_id: novoIdentificadorCorrelacao(),
    momento: new Date(agora).toISOString(),
    nivel: tipo,
    contexto: String(contexto || "geral"),
    ...(erro ? { erro: erroSeguro(erro) } : {}),
    ...(detalhes && typeof detalhes === "object" ? { detalhes: valorSeguro(detalhes) } : {}),
  };
  registros.push(registro);
  if (registros.length > LIMITE_REGISTROS) registros.shift();

  const metodo = globalThis.console?.[tipo === "aviso" ? "warn" : tipo] || globalThis.console?.log;
  metodo?.call(globalThis.console, `[Trace] ${registro.contexto}`, registro);
  enviarLogRemoto(registro);
  return registro;
}

export const logFrontend = Object.freeze({
  info: (contexto, detalhes = {}) => registrarLogFrontend("info", contexto, null, detalhes),
  aviso: (contexto, erro, detalhes = {}) => registrarLogFrontend("aviso", contexto, erro, detalhes),
  erro: (contexto, erro, detalhes = {}) => registrarLogFrontend("erro", contexto, erro, detalhes),
});

export function listarLogsFrontend() {
  return registros.map((registro) => ({ ...registro }));
}

export function limparLogsFrontend() {
  registros.length = 0;
  ultimosRegistros.clear();
}
