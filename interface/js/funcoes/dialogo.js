const SELETOR_FOCO = [
  "button:not([disabled])",
  "[href]",
  "input:not([disabled]):not([type=hidden])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]:not([tabindex=\"-1\"]):not([disabled])",
].join(",");

function elementosFocaveis(modal) {
  return [...modal.querySelectorAll(SELETOR_FOCO)].filter((elemento) => {
    if (elemento.getAttribute("aria-hidden") === "true") return false;
    if (typeof elemento.getClientRects !== "function") return true;
    return elemento.getClientRects().length > 0;
  });
}

function focoAindaDisponivel(elemento) {
  if (!elemento || elemento === document.body || typeof elemento.focus !== "function") return false;
  if (elemento.isConnected === false) return false;
  return typeof document.contains !== "function" || document.contains(elemento);
}

/**
 * Instala o comportamento comum dos diálogos do Trace: foco inicial,
 * navegação por Tab confinada ao diálogo, Escape e retorno do foco ao
 * elemento que abriu a confirmação.
 */
export function prepararDialogoAcessivel(modal, { focoInicial = null, aoEscape = null } = {}) {
  const focoAnterior = document.activeElement;
  const focoPadrao = () => elementosFocaveis(modal)[0] || modal;
  const elementoInicial = focoInicial || focoPadrao();
  let ativo = true;

  if (!modal.hasAttribute("tabindex")) modal.setAttribute("tabindex", "-1");

  const aoTeclar = (evento) => {
    if (!ativo) return;
    if (evento.key === "Escape" && typeof aoEscape === "function") {
      evento.preventDefault();
      aoEscape();
      return;
    }
    if (evento.key !== "Tab") return;
    const focaveis = elementosFocaveis(modal);
    if (!focaveis.length) {
      evento.preventDefault();
      modal.focus();
      return;
    }
    const primeiro = focaveis[0];
    const ultimo = focaveis[focaveis.length - 1];
    if (evento.shiftKey && document.activeElement === primeiro) {
      evento.preventDefault();
      ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
      evento.preventDefault();
      primeiro.focus();
    }
  };

  document.addEventListener("keydown", aoTeclar);
  elementoInicial?.focus?.();

  return {
    desligar() {
      if (!ativo) return;
      ativo = false;
      document.removeEventListener("keydown", aoTeclar);
    },
    restaurarFoco() {
      if (focoAindaDisponivel(focoAnterior)) focoAnterior.focus();
    },
  };
}
