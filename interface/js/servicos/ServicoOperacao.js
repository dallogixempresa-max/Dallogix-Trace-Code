/** Encapsula as operações físicas da Dala sem misturar regras com a tela. */
export class ServicoOperacao {
  constructor(store) {
    this.store = store;
  }

  exigirCarregamento(loadingId = this.store.state.loadingId) {
    if (!loadingId) throw new Error("Nenhum carregamento ativo para esta Dala.");
    return loadingId;
  }

  async iniciar(loadingId = this.store.state.loadingId) {
    return this.store.requestMachineOperation(
      "INICIAR_CARREGAMENTO",
      this.exigirCarregamento(loadingId),
    );
  }

  async parar(loadingId = this.store.state.loadingId) {
    return this.store.requestMachineOperation(
      "PAUSAR_CARREGAMENTO",
      this.exigirCarregamento(loadingId),
    );
  }

  async reversao(ativar, loadingId = this.store.state.loadingId) {
    return this.store.requestMachineReverse(
      this.exigirCarregamento(loadingId),
      ativar ? "REVERSAO_ATIVAR" : "REVERSAO_DESATIVAR",
    );
  }
}
