/** Executa a fila e mantém a trava de clique independente da renderização. */
export function criarExecutorComandosOperacao({
  store,
  servicoOperacao,
  machineCommandQueue,
  render,
  getPage,
  registrarErro,
  notificarForaDaOperacao,
}) {
  return async function executarComandoDeOperacao(node, action) {
    const loadingId = Number(node.dataset.loadingId || store.state.loadingId) || null;
    const commands = {
      "start-machine": "INICIAR_CARREGAMENTO",
      run: "INICIAR_CARREGAMENTO",
      "stop-machine": "PAUSAR_CARREGAMENTO",
      stop: "PAUSAR_CARREGAMENTO",
      "reverse-machine": "REVERSAO_ATIVAR",
      "reverse-on": "REVERSAO_ATIVAR",
      "reverse-off": "REVERSAO_DESATIVAR",
      emergency: "EMERGENCIA",
    };
    const command = action === "reverse-toggle"
      ? (store.state.returnMode ? "REVERSAO_DESATIVAR" : "REVERSAO_ATIVAR")
      : commands[action];
    // Trava os botões já no clique, antes de o servidor confirmar o PENDENTE:
    // fecha a janela entre "operador clicou" e "status chegou" em que um
    // segundo clique (duplicado) ou um clique perdido podiam ocorrer.
    // EMERGÊNCIA é isenta de propósito: pela regra dos controles de operação ela precisa continuar disponível mesmo com
    // outro comando em voo — nunca pode ser descartada aqui, só serializada
    // pela fila como já era antes desta trava.
    const isEmergency = command === "EMERGENCIA";
    const commandToken = !isEmergency && store.beginCommand ? store.beginCommand() : null;
    if (!isEmergency && !commandToken && store.state.commandInFlight) {
      // Já existe um comando em voo (clique duplicado antes do re-render
      // desabilitar o botão): ignora silenciosamente em vez de enfileirar
      // outro envio ao CLP.
      return;
    }
    if (!isEmergency && getPage() === "work") render();
    try {
      await machineCommandQueue.enqueue(loadingId, command, async () => {
        if (command === "INICIAR_CARREGAMENTO") return servicoOperacao.iniciar(loadingId);
        if (command === "PAUSAR_CARREGAMENTO") return servicoOperacao.parar(loadingId);
        if (command === "REVERSAO_ATIVAR") return servicoOperacao.reversao(true, loadingId);
        if (command === "REVERSAO_DESATIVAR") return servicoOperacao.reversao(false, loadingId);
        if (command === "EMERGENCIA") return store.requestMachineEmergency(loadingId);
        throw new Error("Comando operacional inválido.");
      });
      if (getPage() === "work") render();
    } catch (error) {
      if (getPage() === "work") {
        registrarErro("operacao.comando.falhou", error, {
          acao: action,
          carregamento_id: loadingId,
        });
        // A falha precisa ficar visível na própria tela de operação (quiosque):
        // antes, o erro era apenas logado e o operador não recebia feedback.
        store.state.commandFeedback = error?.message
          || "Não foi possível enviar o comando à máquina. Verifique o equipamento.";
        render();
      } else {
        notificarForaDaOperacao(error.message, "erro");
      }
    } finally {
      // Libera a trava de clique independentemente do resultado: sucesso,
      // erro, ou mesmo se o comando foi deduplicado pela fila (nesse caso o
      // commandPending do status PENDENTE/PROCESSANDO assume a trava a partir
      // daqui, então destravar aqui não reabre a janela de clique perdido).
      if (store.endCommand && commandToken) {
        store.endCommand(commandToken);
        if (getPage() === "work") render();
      }
    }
  }
  ;
}
