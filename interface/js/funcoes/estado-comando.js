const STATUS_PENDENTE = new Set(["PENDENTE", "PROCESSANDO"]);

function idComando(comando) {
  const id = Number(comando?.id);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function statusComando(comando) {
  return String(comando?.status || "").toUpperCase();
}

/** Resolve a tela para um único pedido, sem misturar status e comando de IDs distintos. */
export function resolverComandoAtual(plcCommand, commandIntent) {
  if (!plcCommand) return commandIntent || null;
  if (!commandIntent) return plcCommand;

  const plcId = idComando(plcCommand);
  const intentId = idComando(commandIntent);
  if (plcId && intentId && plcId !== intentId) {
    return plcId > intentId ? plcCommand : commandIntent;
  }

  const mesmoComando = String(plcCommand.command || "").toUpperCase() ===
    String(commandIntent.command || "").toUpperCase();
  if ((plcId && intentId && plcId === intentId) ||
      ((!plcId || !intentId) && mesmoComando)) {
    return {
      ...commandIntent,
      ...plcCommand,
      id: plcCommand.id ?? commandIntent.id,
      command: plcCommand.command || commandIntent.command,
      status: plcCommand.status || commandIntent.status,
    };
  }

  if (STATUS_PENDENTE.has(statusComando(commandIntent))) return commandIntent;
  return plcCommand;
}
