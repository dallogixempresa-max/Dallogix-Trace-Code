export function criarTratadorAcoesCadastros({
  store,
  render,
  notificar,
  confirmarAcao,
  paginaAtual,
  criarLinhaItemRomaneio,
}) {
  async function recarregarDalas() {
    store.state.equipmentsLoaded = false;
    store.state.equipmentsError = "";
    render();
    await store.loadEquipments({ force: true });
    if (paginaAtual() === "dalas") render();
  }

  async function alternarAtivacaoProduto(node) {
    const product = (store.state.products || []).find(
      (item) => Number(item.id) === Number(node.dataset.id),
    );
    if (!product) return;

    const nextActive = node.dataset.active === "1" ? 0 : 1;
    const productForm = document.querySelector("#product-form");
    const raw = productForm
      ? Object.fromEntries(new FormData(productForm))
      : product;
    try {
      await store.updateProduct({
        id: product.id,
        ...raw,
        active: nextActive,
      });
      notificar(nextActive ? "Produto ativado." : "Produto desativado.");
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function excluirProduto(node) {
    if (!await confirmarAcao(`Excluir o produto "${node.dataset.name}"? Os itens de romaneios vinculados serão removidos.`)) return;
    try {
      await store.deleteProduct(node.dataset.id);
      notificar("Produto excluído.");
      store.state.productFormOpen = false;
      store.state.editingProductId = null;
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  async function excluirDala(node) {
    if (!["ADMIN_DALLOGIX", "ADMIN_EMPRESA"].includes(store.state.userRole)) {
      notificar("Somente administradores podem excluir Dala.");
      return;
    }
    if (!await confirmarAcao(`Excluir a Dala "${node.dataset.name}"?`)) return;
    if (!await confirmarAcao(`SEGUNDA CONFIRMAÇÃO: excluir a Dala "${node.dataset.name}" definitivamente?`)) return;
    try {
      await store.deleteEquipment(node.dataset.id);
      notificar("Dala excluída.");
      render();
    } catch (error) {
      notificar(error.message);
    }
  }

  return function tratarAcaoCadastro(action, node) {
    switch (action) {
      case "reload-dalas":
        return recarregarDalas();
      case "toggle-product-form":
        store.state.productFormOpen = !store.state.productFormOpen;
        store.state.editingProductId = null;
        render();
        return true;
      case "cancel-product":
        store.state.productFormOpen = false;
        store.state.editingProductId = null;
        render();
        return true;
      case "edit-product":
        store.state.productFormOpen = true;
        store.state.editingProductId = node.dataset.id;
        render();
        window.scrollTo({ top: 0, behavior: "smooth" });
        return true;
      case "toggle-product-active": {
        return alternarAtivacaoProduto(node);
      }
      case "delete-product-edit":
        return excluirProduto(node);
      case "toggle-dala-form":
        store.state.dalaFormOpen = !store.state.dalaFormOpen;
        render();
        return true;
      case "delete-dala":
        return excluirDala(node);
      case "add-item": {
        const tbody = document.querySelector("#manifest-items tbody");
        if (tbody) tbody.insertAdjacentHTML("beforeend", criarLinhaItemRomaneio());
        return true;
      }
      case "remove-item":
        node.closest("tr")?.remove();
        return true;
      default:
        return false;
    }
  };
}
