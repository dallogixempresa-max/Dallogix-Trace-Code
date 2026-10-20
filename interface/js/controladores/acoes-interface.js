/** Controla interações locais da interface sem acionar operações de negócio. */
export function criarTratadorAcoesInterface({
  isMobileMenuViewport,
  setMobileMenuState,
  saveSidebarCollapsedPreference,
}) {
  return function tratarAcaoInterface(action, node) {
    switch (action) {
      case "toggle-menu": {
        const shell = document.querySelector(".shell");
        if (isMobileMenuViewport()) {
          const open = shell?.classList.toggle("mobile-menu-open") || false;
          setMobileMenuState(open);
          node.setAttribute("aria-expanded", String(open));
          node.setAttribute("title", open ? "Fechar menu" : "Abrir menu");
          return true;
        }

        const collapsed = shell
          ? shell.classList.toggle("sidebar-collapsed")
          : false;
        saveSidebarCollapsedPreference(collapsed);
        return true;
      }
      case "toggle-manifest-filters": {
        const panel = document.querySelector("#manifest-filters-panel");
        if (!panel) return true;
        const willOpen = panel.hidden;
        panel.hidden = !willOpen;
        node.setAttribute("aria-expanded", String(willOpen));
        return true;
      }
      case "toggle-user-create-password": {
        const input = document.getElementById(
          node.getAttribute("aria-controls") || "user-create-password",
        );
        if (!input) return true;
        const willShow = input.type === "password";
        input.type = willShow ? "text" : "password";
        node.textContent = willShow ? "Ocultar senha" : "Ver senha";
        node.setAttribute("aria-label", willShow ? "Ocultar senha" : "Ver senha");
        node.setAttribute("aria-pressed", String(willShow));
        return true;
      }
      case "clear-manifest-date": {
        const form = document.querySelector("#manifest-filters");
        const field = form?.elements?.namedItem(node.dataset.field || "");
        if (!field) return true;
        field.value = "";
        field.dispatchEvent(new Event("change", { bubbles: true }));
        return true;
      }
      default:
        return false;
    }
  };
}

/** Registra uma vez os gestos globais do menu lateral. */
export function instalarInteracoesGlobaisInterface(setMobileMenuState) {
  if (document.body.dataset.shellInteractionsBound) return;
  document.body.dataset.shellInteractionsBound = "1";

  function fecharMenuMovel() {
    const shell = document.querySelector(".shell");
    if (!shell?.classList.contains("mobile-menu-open")) return;
    shell.classList.remove("mobile-menu-open");
    setMobileMenuState(false);
    const toggle = document.querySelector('[data-action="toggle-menu"]');
    toggle?.setAttribute("aria-expanded", "false");
    toggle?.setAttribute("title", "Abrir menu");
  }

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") fecharMenuMovel();
  });
  document.addEventListener("click", (event) => {
    const shell = document.querySelector(".shell");
    if (!shell?.classList.contains("mobile-menu-open")) return;
    if (event.target.closest('.sidebar, [data-action="toggle-menu"]')) return;
    fecharMenuMovel();
  });
}
