import { button, esc } from "../funcoes/html.js";

function classesBase(tipo, className = "") {
  return ["estado-interface", `estado-${tipo}`, className].filter(Boolean).join(" ");
}

function acaoEstado(acao) {
  if (!acao || typeof acao !== "object" || !acao.acao || !acao.label) return "";
  return button(acao.label, acao.acao, acao.tom || "secondary", acao.atributos || "");
}

export function estadoCarregando(mensagem = "Carregando…", { className = "" } = {}) {
  return `<div class="${classesBase("carregando", className)}" role="status" aria-live="polite"><span class="estado-carregando-icone" aria-hidden="true"></span><span>${esc(mensagem)}</span></div>`;
}

export function estadoVazio(titulo, descricao = "", acao = null, { className = "" } = {}) {
  return `<section class="${classesBase("vazio", className)}" aria-live="polite"><span class="estado-vazio-icone" aria-hidden="true">∅</span><div><h3>${esc(titulo)}</h3>${descricao ? `<p>${esc(descricao)}</p>` : ""}${acaoEstado(acao) ? `<div class="estado-acoes">${acaoEstado(acao)}</div>` : ""}</div></section>`;
}

export function estadoErro(titulo, mensagem, acao = null, { className = "" } = {}) {
  return `<section class="${classesBase("erro", className)}" role="alert"><span class="estado-erro-icone" aria-hidden="true">!</span><div><h3>${esc(titulo)}</h3><p>${esc(mensagem || "Não foi possível concluir a operação.")}</p>${acaoEstado(acao) ? `<div class="estado-acoes">${acaoEstado(acao)}</div>` : ""}</div></section>`;
}
