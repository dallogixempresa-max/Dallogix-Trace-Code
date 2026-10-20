/** Mantém um único listener de clique e encaminha inclusive ações criadas depois do render. */
export function criarDelegacaoAcoes(root) {
  let despacharAcaoAtual = null;

  root.addEventListener("click", (event) => {
    const node = event.target?.closest?.("[data-action]");
    if (node && despacharAcaoAtual) void despacharAcaoAtual(node);
  });

  return (despacharAcao) => {
    despacharAcaoAtual = despacharAcao;
  };
}

export function criarDespachanteAcoes({
  api,
  store,
  telas,
  navegar,
  paginaPadrao,
  caminhoPagina,
  renderPagina,
  render,
  acoesDeFormulario,
  notificar,
  tratadores,
  windowRef = window,
}) {
  const tratadoresEmOrdem = [
    tratadores.administracao,
    tratadores.operacional,
    tratadores.diagnostico,
    tratadores.relatorios,
    tratadores.cadastros,
    tratadores.interface,
  ];

  return async function despacharAcao(node) {
    const action = node.dataset.action;

    // Botões com rota contextual não devem cair no histórico genérico.
    if (node.classList.contains("page-back") && !action) {
      if (windowRef.history.length > 1) windowRef.history.back();
      else await navegar(paginaPadrao(), "", { replace: true });
      return;
    }

    // O submit já pertence ao formulário; requestSubmit duplicaria a ação.
    if (acoesDeFormulario.has(action)) {
      if (node.getAttribute("type") !== "submit") {
        node.closest("form")?.requestSubmit();
      }
      return;
    }

    if (action === "reload-page") {
      await renderPagina();
      return;
    }

    const navigationHandled = tratadores.navegacao(action, node);
    if (navigationHandled) {
      if (navigationHandled !== true) await navigationHandled;
      return;
    }

    for (const handler of tratadoresEmOrdem) {
      const handled = handler(action, node);
      if (handled !== false) {
        await handled;
        return;
      }
    }

    if (action === "logout") {
      await api.fetch("/api/logout.php", {
        method: "POST",
        headers: store.csrfToken ? { "X-CSRF-Token": store.csrfToken } : {},
      });
      windowRef.location.href = caminhoPagina("login");
      return;
    }

    if (action === "manifest-page") {
      try {
        await store.setManifestPage(node.dataset.page);
        render();
      } catch (error) {
        notificar(error.message);
      }
      return;
    }

    if (telas[action]) navegar(action);
  };
}
