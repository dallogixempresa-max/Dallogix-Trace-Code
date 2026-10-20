const COMANDOS_OPERACIONAIS = new Set([
  "INICIAR_CARREGAMENTO",
  "PAUSAR_CARREGAMENTO",
]);
const COMANDOS_REVERSAO = new Set(["REVERSAO_ATIVAR", "REVERSAO_DESATIVAR"]);
const ESTADOS_TERMINAIS = new Set(["APLICADO", "REJEITADO", "ERRO", "EXPIRADO"]);

/** Enfileira solicitações ao gateway e concilia o retorno com o estado da tela. */
export class ServicoComandosMaquina {
  constructor({
    api,
    state,
    jsonHeaders,
    jsonResponse,
    applyCommandIntent,
    getStatusQueue,
    setStatusQueue,
  }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.applyCommandIntent = applyCommandIntent;
    this.getStatusQueue = getStatusQueue;
    this.setStatusQueue = setStatusQueue;
  }

  async solicitar(loadingId, command) {
    if (!loadingId) {
      throw new Error("Nenhum carregamento ativo para esta Dala.");
    }
    this.state.commandFeedback = "";
    const response = await this.api.fetch("/api/comando_maquina.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ carregamento_id: loadingId, command }),
    });
    const result = await this.jsonResponse(response, "Não foi possível enviar o comando ao gateway industrial.");
    if (Number(this.state.loadingId) !== Number(loadingId)) return result.data;
    this.state.plcCommand = {
      id: result.data.command_request_id,
      command: result.data.command,
      status: result.data.status || "PENDENTE",
      response_message: result.data.message || null,
    };
    this.state.commandIntent = {
      id: Number(result.data.command_request_id) || null,
      loadingId: Number(loadingId),
      command,
      status: this.state.plcCommand.status,
      previousState: this.state.operationalState,
      previousReversalCommand: this.state.reversalCommand,
    };
    this.applyCommandIntent();
    return result.data;
  }

  loadStatus(loadingId = this.state.loadingId, operation = () => this._loadStatus(loadingId)) {
    // A rejeição volta ao chamador; normalizar só a cauda evita travar os próximos polls.
    const refresh = this.getStatusQueue().then(
      () => operation(),
      () => operation(),
    );
    this.setStatusQueue(refresh.then(
      () => null,
      () => null,
    ));
    return refresh;
  }

  async _loadStatus(loadingId = this.state.loadingId) {
    if (!loadingId) {
      this.state.plcCommand = null;
      this.state.commandIntent = null;
      return null;
    }
    const response = await this.api.fetch(
      `/api/comandos_industriais.php?carregamento_id=${encodeURIComponent(loadingId)}`,
    );
    const result = await this.jsonResponse(response, "Não foi possível consultar o comando industrial.");
    if (Number(this.state.loadingId) !== Number(loadingId)) return null;
    let confirmedReversalCommand;
    if (Object.hasOwn(result, "reversal_command")) {
      const confirmedCommand = COMANDOS_REVERSAO.has(result.reversal_command)
        ? result.reversal_command
        : null;
      confirmedReversalCommand = confirmedCommand;
      this.state.reversalCommand = confirmedCommand;
      this.state.returnMode = confirmedCommand === "REVERSAO_ATIVAR";
    }
    const nextCommand = result.data || null;
    const currentCommand = this.state.plcCommand;
    const currentId = Number(currentCommand?.id) || 0;
    const nextId = Number(nextCommand?.id) || 0;
    const currentStatus = String(currentCommand?.status || "").toUpperCase();
    const nextStatus = String(nextCommand?.status || "").toUpperCase();

    // A consulta pode chegar antes que o gateway publique um comando recém-enfileirado.
    if (
      currentCommand &&
      !nextCommand &&
      ["PENDENTE", "PROCESSANDO"].includes(currentStatus)
    ) {
      this.applyCommandIntent();
      return currentCommand;
    }
    if (!nextCommand) {
      this.state.plcCommand = null;
      this.state.commandIntent = null;
      this.applyCommandIntent();
      return null;
    }

    // Não regrede um ACK terminal nem substitui um pedido mais novo por uma resposta atrasada.
    if (
      currentCommand &&
      nextCommand &&
      currentId === nextId &&
      ESTADOS_TERMINAIS.has(currentStatus) &&
      !ESTADOS_TERMINAIS.has(nextStatus)
    ) {
      this.applyCommandIntent();
      return currentCommand;
    }
    if (currentCommand && nextCommand && nextId < currentId) {
      this.applyCommandIntent();
      return currentCommand;
    }

    this.state.plcCommand = nextCommand;
    if (nextId > currentId) this.state.commandFeedback = "";
    const intent = this.state.commandIntent;
    const intentId = Number(intent?.id) || 0;
    const sameCommand = intent &&
      String(intent.command || "").toUpperCase() === String(nextCommand.command || "").toUpperCase();
    const matchesIntent = intent && (
      (intentId && nextId && intentId === nextId) ||
      ((!intentId || !nextId) && sameCommand)
    );
    if (intent && nextId && intentId && nextId > intentId) {
      this.state.commandIntent = null;
    } else if (intent && !intentId && !sameCommand) {
      this.state.commandIntent = null;
    } else if (matchesIntent && ESTADOS_TERMINAIS.has(nextStatus)) {
      this.state.commandFeedback = "";
      if (
        nextStatus !== "APLICADO" &&
        COMANDOS_OPERACIONAIS.has(intent.command)
      ) {
        this.state.operationalState = intent.previousState || this.state.operationalState;
        this.state.running = ["CARREGANDO", "FINALIZANDO"].includes(this.state.operationalState);
      }
      if (nextStatus !== "APLICADO" && COMANDOS_REVERSAO.has(intent.command)) {
        const confirmed = confirmedReversalCommand === undefined
          ? (intent.previousReversalCommand ?? null)
          : confirmedReversalCommand;
        this.state.returnMode = confirmed === "REVERSAO_ATIVAR";
        this.state.reversalCommand = confirmed;
      }
      this.state.commandIntent = null;
    } else if (matchesIntent) {
      intent.status = nextStatus || intent.status;
    }
    this.applyCommandIntent();
    return this.state.plcCommand;
  }
}
