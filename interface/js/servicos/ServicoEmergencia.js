/**
 * Regras do fluxo de emergência da operação.
 * A tela apenas confirma a ação e apresenta o resultado; as pré-condições e
 * as chamadas ao armazenamento ficam reunidas neste serviço testável.
 */
export class ServicoEmergencia {
  constructor(store) {
    this.store = store;
  }

  exigirCarregamento() {
    if (!this.store.state.loadingId) {
      throw new Error("Nenhum carregamento ativo para esta Dala.");
    }
    return this.store.state.loadingId;
  }

  async solicitar() {
    const loadingId = this.exigirCarregamento();
    return this.store.requestMachineEmergency(loadingId);
  }

  async liberar() {
    this.exigirCarregamento();
    return this.store.unlockMachine();
  }
}
