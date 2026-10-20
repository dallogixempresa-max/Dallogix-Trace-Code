/**
 * Agrupa ações que só resolvem o destino de uma tela. A aplicação injeta
 * estado e navegação para manter este controlador independente do DOM.
 */
export function criarTratadorAcoesNavegacao({
  navegar,
  paginaAtual,
  buscaAtual,
  store,
  estaVoltandoAoDashboard,
  consultaRetornoDashboard,
  paginaDeRetorno,
  idDaConsulta,
}) {
  const navegarEConfirmar = (...args) => Promise.resolve(navegar(...args)).then(() => true);

  return function tratarAcaoNavegacao(acao, node) {
    switch (acao) {
      case "open-company":
        navegar("company", `?id=${node.dataset.id}&from=${paginaAtual()}`);
        return true;
      case "back-companies": {
        const from = new URLSearchParams(buscaAtual()).get("from");
        navegar(["master-home", "companies"].includes(from || "") ? from : "companies");
        return true;
      }
      case "back-settings":
        navegar("settings");
        return true;
      case "back-company":
        navegar("company", `?id=${encodeURIComponent(node.dataset.companyId || "")}&from=companies`);
        return true;
      case "open-users":
        navegar(
          "users",
          node.dataset.companyId
            ? `?company_id=${node.dataset.companyId}&from=company`
            : "",
        );
        return true;
      case "goto-manifests": {
        const returnToDashboard = new URLSearchParams(buscaAtual()).get("from") === "dashboard";
        navegar(returnToDashboard ? "dashboard" : "manifests");
        return true;
      }
      case "goto-dalas":
        navegar("dalas");
        return true;
      case "goto-companies":
        navegar("companies");
        return true;
      case "goto-error-logs":
        navegar("error-logs");
        return true;
      case "new-manifest":
        navegar("import");
        return true;
      case "view-manifest": {
        const fromDashboard = paginaAtual() === "dashboard" ? "&from=dashboard" : "";
        navegar("manifest", `?id=${node.dataset.id}${fromDashboard}`);
        return true;
      }
      case "edit-manifest":
        navegar("manifest-edit", `?id=${node.dataset.id}`);
        return true;
      case "prepare-manifest":
        navegar("division", `?id=${encodeURIComponent(node.dataset.id)}${estaVoltandoAoDashboard() ? "&from=dashboard" : ""}`);
        return true;
      case "back-manifest":
        navegar("manifest", `?id=${encodeURIComponent(idDaConsulta() || "")}${estaVoltandoAoDashboard() ? "&from=dashboard" : ""}`);
        return true;
      case "resume-loading":
        store.state.selectedLoadingId = Number(node.dataset.loadingId) || null;
        navegar("work", consultaRetornoDashboard());
        return true;
      case "back-work":
        return navegarEConfirmar("work");
      case "view-dala": {
        const from = paginaAtual() === "dashboard" ? "dashboard" : paginaDeRetorno();
        navegar("dala", `?id=${node.dataset.id}&from=${from}`);
        return true;
      }
      case "edit-dala":
        navegar("dala-edit", `?id=${node.dataset.id}&from=${paginaDeRetorno()}`);
        return true;
      case "back-dala":
        navegar(paginaDeRetorno());
        return true;
      case "open-dala-operation":
        store.state.selectedLoadingId = Number(node.dataset.loadingId) || null;
        return navegarEConfirmar("work");
      default:
        return false;
    }
  };
}
