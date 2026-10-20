import { prepararDialogoAcessivel } from "../funcoes/dialogo.js?v=202610061820";

// Exibe feedback no fluxo da página, sem sobrepor controles do quiosque.
export function limparNotificacao() {
  document.getElementById("trace-inline-feedback")?.remove();
}

export function notificar(mensagem, tipo = "info") {
  const container = document.querySelector(".content") || document.querySelector("main");
  if (!container) return null;
  limparNotificacao();
  const notice = document.createElement("div");
  notice.id = "trace-inline-feedback";
  notice.className = "trace-inline-feedback";
  if (tipo === "erro") notice.classList.add("is-error");
  notice.setAttribute("role", tipo === "erro" ? "alert" : "status");
  const content = document.createElement("span");
  content.textContent = String(mensagem ?? "");
  const dismiss = document.createElement("button");
  dismiss.type = "button";
  dismiss.className = "button ghost small";
  dismiss.textContent = "Fechar aviso";
  dismiss.setAttribute("aria-label", "Fechar aviso");
  dismiss.addEventListener("click", () => notice.remove());
  notice.append(content, dismiss);
  container.prepend(notice);
  return notice;
}

export function confirmarAcao(mensagem, { titulo = "Confirmação necessária", confirmar = "Confirmar", cancelar = "Cancelar" } = {}) {
  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.className = "trace-modal-overlay";
    const modal = document.createElement("section");
    modal.className = "trace-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    const heading = document.createElement("h2");
    heading.id = `trace-modal-titulo-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    modal.setAttribute("aria-labelledby", heading.id);
    heading.textContent = titulo;
    const text = document.createElement("p");
    text.id = `trace-modal-descricao-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    modal.setAttribute("aria-describedby", text.id);
    text.textContent = String(mensagem || "");
    const actions = document.createElement("div");
    actions.className = "trace-modal-acoes";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "button ghost";
    cancel.textContent = cancelar;
    const accept = document.createElement("button");
    accept.type = "button";
    accept.className = "button primary";
    accept.textContent = confirmar;
    actions.append(cancel, accept);
    modal.append(heading, text, actions);
    overlay.append(modal);
    document.body.append(overlay);
    let done = false;
    let controle = null;
    const finish = (value) => {
      if (done) return;
      done = true;
      document.removeEventListener("keydown", onKeyDown);
      controle?.desligar();
      overlay.remove();
      controle?.restaurarFoco();
      resolve(value);
    };
    const onKeyDown = (event) => {
      if (event.key === "Enter") finish(true);
    };
    cancel.addEventListener("click", () => finish(false), { once: true });
    accept.addEventListener("click", () => finish(true), { once: true });
    document.addEventListener("keydown", onKeyDown);
    controle = prepararDialogoAcessivel(modal, { focoInicial: accept, aoEscape: () => finish(false) });
  });
}

export function solicitarTexto(
  mensagem,
  {
    titulo = "Informação necessária",
    confirmar = "Continuar",
    cancelar = "Cancelar",
    valorInicial = "",
    tipo = "text",
    multilinha = false,
    obrigatorio = false,
  } = {},
) {
  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.className = "trace-modal-overlay";
    const modal = document.createElement("section");
    modal.className = "trace-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    const heading = document.createElement("h2");
    heading.id = `trace-modal-titulo-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    modal.setAttribute("aria-labelledby", heading.id);
    heading.textContent = titulo;
    const text = document.createElement("p");
    text.id = `trace-modal-descricao-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    modal.setAttribute("aria-describedby", text.id);
    text.textContent = String(mensagem || "");
    const field = multilinha ? document.createElement("textarea") : document.createElement("input");
    field.className = "trace-modal-campo";
    if (!multilinha) field.type = tipo;
    field.value = String(valorInicial ?? "");
    field.required = obrigatorio;
    field.setAttribute("aria-label", titulo);
    if (multilinha) field.rows = 4;
    const actions = document.createElement("div");
    actions.className = "trace-modal-acoes";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "button ghost";
    cancel.textContent = cancelar;
    const accept = document.createElement("button");
    accept.type = "button";
    accept.className = "button primary";
    accept.textContent = confirmar;
    actions.append(cancel, accept);
    modal.append(heading, text, field, actions);
    overlay.append(modal);
    document.body.append(overlay);
    let done = false;
    let controle = null;
    const finish = (value) => {
      if (done) return;
      done = true;
      document.removeEventListener("keydown", onKeyDown);
      controle?.desligar();
      overlay.remove();
      controle?.restaurarFoco();
      resolve(value);
    };
    const onKeyDown = (event) => {
      if (event.key === "Enter" && (multilinha ? event.ctrlKey : true)) {
        event.preventDefault();
        if (!obrigatorio || field.value.trim()) finish(field.value);
      }
    };
    cancel.addEventListener("click", () => finish(null), { once: true });
    accept.addEventListener("click", () => {
      if (obrigatorio && !field.value.trim()) {
        field.focus();
        return;
      }
      finish(field.value);
    }, { once: true });
    document.addEventListener("keydown", onKeyDown);
    controle = prepararDialogoAcessivel(modal, { focoInicial: field, aoEscape: () => finish(null) });
    field.select?.();
  });
}
