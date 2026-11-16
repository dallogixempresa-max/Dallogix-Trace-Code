import { resolverComandoAtual } from "./estado-comando.js?v=202611120004";
import { rotuloEstado } from "./rotulos.js?v=202611120004";

export function podeVincularDala(role) {
  return ["ADMIN_EMPRESA", "SUPERVISOR"].includes(String(role || "").toUpperCase());
}

export function paradaPodeSubstituirInicio({ state, pendingStatus, pendingCommand }) {
  return String(state || "").toUpperCase() === "PAUSADO" &&
    String(pendingStatus || "").toUpperCase() === "PENDENTE" &&
    String(pendingCommand || "").toUpperCase() === "INICIAR_CARREGAMENTO";
}

export function paradaPodeSerEnfileiradaAposInicio({ state, pendingStatus, pendingCommand }) {
  return ["PREPARANDO", "CARREGANDO", "PAUSADO"].includes(
    String(state || "").toUpperCase(),
  ) && ["PENDENTE", "PROCESSANDO"].includes(
    String(pendingStatus || "").toUpperCase(),
  ) && String(pendingCommand || "").toUpperCase() === "INICIAR_CARREGAMENTO";
}

export function podeLiberarEmergencia({ role, loadingId }) {
  return Boolean(loadingId) && ["ADMIN_EMPRESA", "SUPERVISOR"].includes(
    String(role || "").toUpperCase(),
  );
}

export function estadoControles(state) {
  const operationalState = String(state.operationalState || "").toUpperCase();
  const reversalCommand = String(state.reversalCommand || "").toUpperCase();
  const currentCommand = resolverComandoAtual(state.plcCommand, state.commandIntent);
  const commandStatus = String(currentCommand?.status || "").toUpperCase();
  const pendingCommand = String(currentCommand?.command || "").toUpperCase();
  const currentCommandPending = ["PENDENTE", "PROCESSANDO"].includes(commandStatus);
  const commandPending = state.commandInFlight === true || currentCommandPending;
  const startPending = currentCommandPending && pendingCommand === "INICIAR_CARREGAMENTO";
  const stopped = ["PREPARANDO", "PAUSADO"].includes(operationalState) && state.running !== true;
  const operationRunning = (commandPending && pendingCommand === "INICIAR_CARREGAMENTO") ||
    operationalState === "CARREGANDO" ||
    (state.running === true && !["PREPARANDO", "PAUSADO", "FINALIZANDO", "FINALIZADO", "EMERGENCIA"].includes(operationalState));
  const emergency = operationalState === "EMERGENCIA";
  const planned = Number(state.planned) || 0;
  const detected = Number(state.detectedBags) || 0;
  const reversalConfirmedOn = reversalCommand === "REVERSAO_ATIVAR" &&
    !(currentCommandPending && pendingCommand === "REVERSAO_ATIVAR");

  return {
    state,
    operationalState,
    reversalCommand,
    currentCommand,
    commandStatus,
    pendingCommand,
    currentCommandPending,
    commandPending,
    startPending,
    stopped,
    operationRunning,
    emergency,
    hasLoading: Boolean(state.loadingId),
    manifestTargetReached: planned > 0 && detected >= planned,
    canOperate: ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"].includes(String(state.userRole || "").toUpperCase()),
    reversalKnown: ["REVERSAO_ATIVAR", "REVERSAO_DESATIVAR"].includes(reversalCommand),
    reversalOn: reversalCommand === "REVERSAO_ATIVAR" ||
      (commandPending && pendingCommand === "REVERSAO_ATIVAR"),
    reversalConfirmedOn,
    reversalOffPermitted: reversalConfirmedOn ||
      (currentCommandPending && pendingCommand === "REVERSAO_ATIVAR" && commandStatus === "PENDENTE"),
    stopAvailable: (operationalState === "CARREGANDO" && state.running === true) ||
      operationalState === "FINALIZANDO" ||
      startPending,
    preparedAndStopped: ["PREPARANDO", "PAUSADO"].includes(operationalState) && state.running !== true,
    runClickableInCurrentState: ["AGUARDANDO", "EMERGENCIA"].includes(operationalState),
  };
}

export function motivosControles(context, {
  clpAvailable = true,
  clpUnavailableMessage = "Comunicação com o CLP indisponível. Novos comandos estão bloqueados.",
  machineCommandsAvailable = true,
  machineCommandsUnavailableMessage = "O gateway não confirmou a liberação dos comandos da máquina.",
} = {}) {
  const {
    state,
    operationalState,
    reversalCommand,
    commandStatus,
    pendingCommand,
    commandPending,
    stopped,
    operationRunning,
    emergency,
    hasLoading,
    manifestTargetReached,
    canOperate,
    reversalOn,
  } = context;
  const canReverse = canOperate;
  const motivos = { run: "", stop: "", "reverse-on": "", "reverse-off": "" };

  if (!hasLoading) {
    for (const action of Object.keys(motivos)) motivos[action] = "Nenhum carregamento selecionado.";
    return motivos;
  }

  if (!clpAvailable) {
    for (const action of Object.keys(motivos)) motivos[action] = clpUnavailableMessage;
    return motivos;
  }

  if (!machineCommandsAvailable) {
    for (const action of Object.keys(motivos)) motivos[action] = machineCommandsUnavailableMessage;
    return motivos;
  }

  if (!canOperate) {
    for (const action of Object.keys(motivos)) motivos[action] = "Seu perfil não pode enviar comandos para a máquina.";
    return motivos;
  }

  if (commandPending) {
    const waiting = commandStatus === "PROCESSANDO"
      ? "Aguarde o gateway confirmar o comando."
      : "Aguarde o envio do comando atual.";
    for (const action of Object.keys(motivos)) motivos[action] = waiting;
    if (pendingCommand === "INICIAR_CARREGAMENTO") {
      motivos.stop = "";
    }
    return motivos;
  }

  const stateLock = (action) => {
    if (emergency) return "A emergência precisa ser liberada antes de alterar a operação.";
    if (operationalState === "CARREGANDO") return "Pare a esteira antes de alterar a direção ou iniciar novamente.";
    if (operationalState === "FINALIZANDO") return "O carregamento está finalizando e não aceita novos comandos de operação.";
    if (operationalState === "FINALIZADO") return "Este carregamento já foi finalizado.";
    if (operationalState === "AGUARDANDO") return "O carregamento ainda não está preparado para receber comandos.";
    if (!stopped) return `O estado ${rotuloEstado(operationalState)} não permite ${action}.`;
    return "";
  };
  motivos.run = emergency
    ? "A emergência precisa ser liberada antes de iniciar."
    : state.stopReason
      ? state.stopReason === "COTA_ROMANEIO_ATINGIDA"
        ? "Confirme a parada física e finalize o romaneio após conferir leituras e fotos."
        : state.stopReason === "COTA_PRODUTO_ATINGIDA"
        ? "Confirme a parada física antes de continuar com outro produto."
        : "Confirme que a unidade foi removida e que a esteira está fisicamente parada antes de retomar."
    : manifestTargetReached
      ? "A quantidade prevista do romaneio foi atingida. Abra a conferência final para encerrar."
    : !stopped
      ? stateLock("iniciar a esteira")
    : reversalCommand !== "REVERSAO_DESATIVAR"
      ? "Confirme a reversão desligada antes de ligar a esteira para frente."
      : "";
  motivos.stop = emergency
    ? "A emergência já mantém a operação parada."
    : operationRunning || operationalState === "FINALIZANDO"
      ? ""
      : !stopped
        ? stateLock("parar a esteira")
        : "A esteira já está parada.";
  motivos["reverse-on"] = !canReverse
    ? "Seu perfil não pode alterar a reversão."
    : emergency
      ? "A emergência precisa ser liberada antes de alterar a direção."
      : !stopped
        ? stateLock("alterar a direção")
        : reversalOn
          ? "A reversão já está ligada."
          : "";
  motivos["reverse-off"] = !canReverse
    ? "Seu perfil não pode alterar a reversão."
    : emergency
      ? "A emergência precisa ser liberada antes de alterar a direção."
      : !stopped
        ? stateLock("alterar a direção")
        : reversalCommand === "REVERSAO_DESATIVAR"
        ? "A reversão já está desligada."
        : "";
  return motivos;
}

export function workControlLocks(state, options = {}) {
  return motivosControles(estadoControles(state), options);
}
