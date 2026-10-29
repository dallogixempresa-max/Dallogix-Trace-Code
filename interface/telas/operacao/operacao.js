import { button, esc } from "../../js/funcoes/html.js";
import { numero } from "../../js/funcoes/formato.js?v=202609201000";
import { rotuloComando, rotuloEstado } from "../../js/funcoes/rotulos.js?v=202609240001";
import { resolverComandoAtual } from "../../js/funcoes/estado-comando.js?v=202610130003";
import { pageHeader } from "../../js/funcoes/view.js?v=202610280005";

function equipmentLabel(store) {
  if (store.state.equipmentCode && store.state.equipmentCode !== "—") {
    return store.state.equipmentCode;
  }
  const equipment = (store.state.equipments || []).find(
    (item) => Boolean(store.state.equipmentId) && Number(item.id) === Number(store.state.equipmentId),
  );
  return equipment?.equipment_code || "Esteira";
}

function loadingSelection(store) {
  const activeLoadings = store.state.activeLoadings || [];
  const canReassignLoading = podeVincularDala(store.state.userRole);
  const occupiedEquipmentIds = new Set(
    activeLoadings
      .filter((loading) => loading.equipment_id)
      .map((loading) => Number(loading.equipment_id)),
  );
  const availableEquipments = (store.state.equipments || []).filter(
    (equipment) => !occupiedEquipmentIds.has(Number(equipment.id)),
  );
  const detached = activeLoadings
    .filter((loading) => !loading.equipment_id)
    .map((loading) => {
      const options = availableEquipments
        .map((equipment) => `<option value="${equipment.id}">${esc(equipment.name)} • ${esc(equipment.equipment_code)}</option>`)
        .join("");
      const reassignControl = canReassignLoading
        ? `<form class="reassign-loading-form" data-loading-id="${loading.id}"><label>Selecionar nova Dala<select name="equipment_id" required ${options ? "" : "disabled"}>${options || "<option>Nenhuma Dala disponível</option>"}</select></label><button class="button primary" type="submit" ${options ? "" : 'disabled title="Nenhuma Dala disponível para vincular."'}>Vincular Dala</button><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form>`
        : '<p class="form-feedback" role="status">Solicite a um administrador da empresa ou supervisor para vincular uma nova Dala.</p>';
      return `<article class="panel detached-loading-card"><span class="kicker">Dala removida</span><h3>${esc(loading.romaneio_number || "Sem romaneio")}</h3><p>Caminhão ${esc(loading.plate || "—")}. O carregamento foi interrompido e aguarda uma nova máquina.</p>${reassignControl}</article>`;
    })
    .join("");
  const cards = activeLoadings
    .filter((loading) => loading.equipment_id)
    .map(
      (loading) =>
        `<button class="dala-selection-card" data-action="select-loading" data-id="${loading.id}" type="button"><span class="kicker">${esc(loading.equipment_code || "Dala")}</span><strong>${esc(loading.romaneio_number || "Sem romaneio")}</strong><span>Caminhão ${esc(loading.plate || "—")}</span><b>${esc(rotuloEstado(loading.state))}</b><span class="dala-selection-count">${numero(loading.detected_bags)} sacas detectadas</span><small>Selecionar esta Dala</small></button>`,
    )
    .join("");
  const feedback = store.state.commandFeedback
    ? `<p class="work-selection-error" role="alert">${esc(store.state.commandFeedback)}</p>`
    : "";
  return `${pageHeader("Operação", "Selecionar Dala", "Escolha a Dala que será acompanhada e controlada nesta tela.")}<section class="dala-selection-screen">${feedback}<div class="dala-selection-heading"><span class="kicker">Operações disponíveis</span><h3>Qual Dala você deseja operar?</h3><p>Cada seleção mantém contagem, comandos e emergência separados.</p></div>${detached ? `<div class="detached-loading-list">${detached}</div>` : ""}<div class="dala-selection-grid">${cards || (detached ? "" : '<p class="empty-cell">Nenhum carregamento disponível.</p>')}</div></section>`;
}

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

export function workControlLocks(state, {
  clpAvailable = true,
  clpUnavailableMessage = "Comunicação com o CLP indisponível. Novos comandos estão bloqueados.",
  machineCommandsAvailable = true,
  machineCommandsUnavailableMessage = "O gateway não confirmou a liberação dos comandos da máquina.",
} = {}) {
  const operationalState = String(state.operationalState || "").toUpperCase();
  const currentCommand = resolverComandoAtual(state.plcCommand, state.commandIntent);
  const commandStatus = String(currentCommand?.status || "").toUpperCase();
  const pendingCommand = String(currentCommand?.command || "").toUpperCase();
  const commandPending = state.commandInFlight === true ||
    ["PENDENTE", "PROCESSANDO"].includes(commandStatus);
  const stopped = ["PREPARANDO", "PAUSADO"].includes(operationalState) && state.running !== true;
  const running = (commandPending && pendingCommand === "INICIAR_CARREGAMENTO") ||
    operationalState === "CARREGANDO" ||
    (state.running === true && !["PREPARANDO", "PAUSADO", "FINALIZANDO", "FINALIZADO", "EMERGENCIA"].includes(operationalState));
  const reversalOn = state.reversalCommand === "REVERSAO_ATIVAR" ||
    (commandPending && pendingCommand === "REVERSAO_ATIVAR");
  const emergency = operationalState === "EMERGENCIA";
  const hasLoading = Boolean(state.loadingId);
  const canOperate = ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"].includes(String(state.userRole || "").toUpperCase());
  const canReverse = canOperate;
  const locks = { run: "", stop: "", "reverse-on": "", "reverse-off": "" };

  if (!hasLoading) {
    for (const action of Object.keys(locks)) locks[action] = "Nenhum carregamento selecionado.";
    return locks;
  }

  if (!clpAvailable) {
    for (const action of Object.keys(locks)) locks[action] = clpUnavailableMessage;
    return locks;
  }

  if (!machineCommandsAvailable) {
    for (const action of Object.keys(locks)) locks[action] = machineCommandsUnavailableMessage;
    return locks;
  }

  if (!canOperate) {
    for (const action of Object.keys(locks)) locks[action] = "Seu perfil não pode enviar comandos para a máquina.";
    return locks;
  }

  if (commandPending) {
    const waiting = commandStatus === "PROCESSANDO"
      ? "Aguarde o gateway confirmar o comando."
      : "Aguarde o envio do comando atual.";
    for (const action of Object.keys(locks)) locks[action] = waiting;
    if (pendingCommand === "INICIAR_CARREGAMENTO") {
      locks.stop = "";
    } else if (pendingCommand === "REVERSAO_ATIVAR" && commandStatus === "PENDENTE") {
      locks["reverse-off"] = "";
    }
    if (!canReverse) {
      locks["reverse-on"] = "Seu perfil não pode alterar a reversão.";
      locks["reverse-off"] = "Seu perfil não pode alterar a reversão.";
    }
    return locks;
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
  locks.run = emergency
    ? "A emergência precisa ser liberada antes de iniciar."
    : state.stopReason
      ? "Confirme que a unidade foi removida e que a esteira está fisicamente parada antes de retomar."
    : !stopped
      ? stateLock("iniciar a esteira")
      : state.reversalCommand === "REVERSAO_ATIVAR"
        ? "Desligue a reversão antes de ligar a esteira para frente."
        : "";
  locks.stop = emergency
    ? "A emergência já mantém a operação parada."
    : running || operationalState === "FINALIZANDO"
      ? ""
      : !stopped
        ? stateLock("parar a esteira")
        : "A esteira já está parada.";
  locks["reverse-on"] = !canReverse
    ? "Seu perfil não pode alterar a reversão."
    : emergency
      ? "A emergência precisa ser liberada antes de alterar a direção."
      : !stopped
        ? stateLock("alterar a direção")
        : reversalOn
          ? "A reversão já está ligada."
          : "";
  locks["reverse-off"] = !canReverse
    ? "Seu perfil não pode alterar a reversão."
    : emergency
      ? "A emergência precisa ser liberada antes de alterar a direção."
      : !stopped
        ? stateLock("alterar a direção")
        : state.reversalCommand === "REVERSAO_DESATIVAR"
        ? "A reversão já está desligada."
        : "";
  return locks;
}

function workControls(store) {
  const loadingItems = Array.isArray(store.state.loadingItems)
    ? store.state.loadingItems
    : [];
  const detectedBags = Number(store.state.detectedBags) || 0;
  const plannedTotal = Number(store.state.planned) || 0;
  const validReadings = Number(store.state.loaded) || 0;
  const remainingTotal = Math.max(0, plannedTotal - detectedBags);
  const totalPercent = plannedTotal > 0
    ? Math.min(100, Math.round((detectedBags / plannedTotal) * 100))
    : 0;
  const clpAvailable = typeof store.clpDisponivel === "function"
    ? store.clpDisponivel()
    : true;
  const clpUnavailableMessage = typeof store.mensagemClpIndisponivel === "function"
      ? store.mensagemClpIndisponivel()
      : "Comunicação com o CLP indisponível. Novos comandos estão bloqueados.";
  const machineCommandsAvailable = typeof store.comandosMaquinaDisponiveis === "function"
    ? store.comandosMaquinaDisponiveis()
    : clpAvailable;
  const machineCommandsUnavailableMessage = typeof store.mensagemComandosMaquinaIndisponiveis === "function"
    ? store.mensagemComandosMaquinaIndisponiveis()
    : "O gateway não confirmou a liberação dos comandos da máquina.";
  const commandLocks = workControlLocks(store.state, {
    clpAvailable,
    clpUnavailableMessage,
    machineCommandsAvailable,
    machineCommandsUnavailableMessage,
  });
  const currentCommand = resolverComandoAtual(store.state.plcCommand, store.state.commandIntent);
  const pendingCommand = String(currentCommand?.command || "").toUpperCase();
  const pendingStatus = String(currentCommand?.status || "").toUpperCase();
  const currentCommandPending = ["PENDENTE", "PROCESSANDO"].includes(pendingStatus);
  const bloqueio = (reason) => reason
    ? { disabled: true, "aria-disabled": "true", title: reason }
    : "";
  // A emergência continua disponível para registrar a intenção; o gateway
  // valida a comunicação e a autorização antes de qualquer escrita física.
  const bloqueioEmergencia = store.state.loadingId
    ? ""
    : { title: "Nenhum carregamento selecionado" };
  const estadoOperacional = String(store.state.operationalState || "").toUpperCase();
  const esteiraEmMovimentoOuIniciando = estadoOperacional === "CARREGANDO" ||
    store.state.running === true ||
    (currentCommandPending && pendingCommand === "INICIAR_CARREGAMENTO");
  const atributosReversaoDuranteMovimento = (reason) => ({
    disabled: true,
    "aria-disabled": "true",
    title: reason || "A esteira precisa estar parada para alterar a reversão.",
  });
  const atributosLigarReversao = esteiraEmMovimentoOuIniciando
    ? atributosReversaoDuranteMovimento(commandLocks["reverse-on"])
    : bloqueio(commandLocks["reverse-on"]);
  const atributosDesligarReversao = esteiraEmMovimentoOuIniciando
    ? atributosReversaoDuranteMovimento(commandLocks["reverse-off"])
    : bloqueio(commandLocks["reverse-off"]);
  const atributosPararEsteira = commandLocks.stop === "A esteira já está parada."
    ? {
        disabled: true,
        "aria-disabled": "true",
        title: commandLocks.stop,
      }
    : bloqueio(commandLocks.stop);
  const loadingPicker = `<div class="work-header-actions"><button class="button secondary small work-back-button" data-action="goto-manifests" type="button">← Romaneios</button>${(store.state.activeLoadings || []).length > 1 ? `<button class="button secondary small work-back-button" data-action="change-loading" type="button">Trocar Dala</button>` : ""}</div>`;
  const latestScannerReading = store.state.latestScannerReading;
  const scannerValue = latestScannerReading?.value || "Aguardando leitura…";
  const scannerValueClass = latestScannerReading?.result === "SEM_LEITURA" ? " is-error" : "";
  const scannerMonitor = store.state.loadingId
    ? `<div class="work-reader-monitor"><div class="work-reader-monitor-heading"><span class="kicker">Visor do leitor</span><small>Último código recebido automaticamente do leitor.</small></div><div class="work-scanner-display"><span class="work-scanner-display-label">Código de barras</span><output id="automatic-scanner-barcode" class="work-scanner-display-value${scannerValueClass}" data-live="scanner-value" role="status" aria-live="polite" aria-atomic="true">${esc(scannerValue)}</output></div></div>`
    : "";
  const controls = `<div class="work-controls"><div class="work-routine-controls">${button("Ligar esteira", "run", "primary", bloqueio(commandLocks.run))}${button("Desligar esteira", "stop", "ghost", atributosPararEsteira)}${button("Ligar reversão", "reverse-on", "secondary", atributosLigarReversao)}${button("Desligar reversão", "reverse-off", "secondary", atributosDesligarReversao)}</div><div class="work-emergency-zone">${button("EMERGÊNCIA", "emergency", "danger", bloqueioEmergencia)}</div></div>`;
  const summaryReady = ["FINALIZANDO", "FINALIZADO"].includes(
    store.state.operationalState,
  );
  const summaryAction = summaryReady
    ? `${button("Abrir resumo final", "open-summary", "secondary")}`
    : "";
  const stopCommand = pendingCommand === "PAUSAR_CARREGAMENTO" ? currentCommand : null;
  const stopConfirmationMessage = !stopCommand
    ? "O gateway ainda não confirmou um comando de parada. Verifique a máquina antes de confirmar."
    : ["PENDENTE", "PROCESSANDO"].includes(pendingStatus)
      ? "Parada solicitada ao gateway; aguardando resposta. O estado local pausado não confirma que a esteira parou."
      : pendingStatus === "APLICADO"
        ? "O gateway confirmou o comando de parada. Verifique a máquina e retire a unidade antes de liberar a retomada."
        : `O gateway não confirmou a parada: ${currentCommand.response_message || "estado físico não confirmado."} Verifique a máquina antes de liberar a retomada.`;
  const stopConfirmation = store.state.stopReason
    ? `<section class="work-stop-confirmation" aria-labelledby="work-stop-confirmation-title"><h3 id="work-stop-confirmation-title">Atenção: operação pausada</h3><p class="work-stop-confirmation-alert" role="alert">${esc(store.state.operatorAlert || "Remova a unidade e confirme antes de continuar.")}</p><p>${esc(stopConfirmationMessage)}</p><form class="confirm-stop-removal-form"><label><input type="checkbox" name="confirmar_remocao" value="true" required /><span>Confirmo que a unidade foi removida e que a esteira está fisicamente parada.</span></label><button class="button primary" type="submit">Confirmar e liberar retomada</button><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form></section>`
    : "";
  const badge = ["PENDENTE", "PROCESSANDO"].includes(pendingStatus) && pendingCommand
      ? `<span class="badge blue">${esc(rotuloComando(pendingCommand))} · aguardando CLP</span>`
      : store.state.operationalState === "EMERGENCIA"
      ? '<span class="badge red">Emergência solicitada · operação bloqueada</span>'
      : `<span class="badge yellow">${esc(rotuloEstado(store.state.operationalState))}</span>`;
  const itemCountLabel = `${numero(loadingItems.length)} ${loadingItems.length === 1 ? "item" : "itens"}`;
  const itemProgress = `<section class="panel work-items-panel"><div class="panel-heading"><div><h3>Itens do romaneio</h3></div><div class="work-items-summary"><small>${itemCountLabel}</small></div></div><div class="work-item-progress-list">${loadingItems.length ? loadingItems.map((item) => {
    const loaded = Number(item.loaded_quantity) || 0;
    const planned = Number(item.planned_quantity) || 0;
    const remainingValue = item.remaining_quantity === null || item.remaining_quantity === undefined
      ? Math.max(0, planned - loaded)
      : Number(item.remaining_quantity);
    const remaining = Number.isFinite(remainingValue) ? Math.max(0, remainingValue) : Math.max(0, planned - loaded);
    const percent = planned > 0 ? Math.min(100, Math.round((loaded / planned) * 100)) : 0;
    const status = planned <= 0
      ? ["Sem previsão", "yellow"]
      : loaded > planned
        ? ["Excedente", "red"]
        : loaded >= planned
          ? ["Concluído", "green"]
          : loaded > 0 ? ["Em andamento", "blue"] : ["Pendente", "yellow"];
    const remainingText = planned > 0 && loaded > planned
      ? `Acima do previsto <strong>${numero(loaded - planned)}</strong>`
      : `Faltam validar <strong>${numero(remaining)}</strong>`;
    return `<article class="work-item-progress-card"><div class="work-item-progress-heading"><div class="work-item-identity"><strong>${esc(item.name || "Produto")}</strong><code>${esc(item.code || "—")}</code></div><span class="badge ${status[1]}">${status[0]}</span></div><div class="work-item-progress-values"><strong>${numero(loaded)} <span>/ ${numero(planned)}</span></strong><span>${percent}%</span></div><div class="work-item-progress-track" role="progressbar" aria-label="Leituras válidas de ${esc(item.name || "produto")}" aria-valuemin="0" aria-valuemax="${planned}" aria-valuenow="${Math.min(loaded, planned)}"><i style="width:${percent}%"></i></div><div class="work-item-progress-foot"><span>Identificadas</span><span>${remainingText}</span></div></article>`;
  }).join("") : '<p class="empty-cell">Nenhum item detalhado para este carregamento.</p>'}</div></section>`;
  const workTitle = store.state.romaneio && store.state.romaneio !== "—"
    ? `Romaneio #${store.state.romaneio}`
    : "Operação";
  const nearEndThreshold = plannedTotal > 0 ? Math.max(1, Math.ceil(plannedTotal * 0.1)) : 0;
  const nearEnd = plannedTotal > 0 && detectedBags > 0 && remainingTotal > 0 && remainingTotal <= nearEndThreshold;
  const complete = plannedTotal > 0 && detectedBags >= plannedTotal;
  const excess = plannedTotal > 0 && detectedBags > plannedTotal;
  const endNotice = excess
    ? `Atenção: ${numero(detectedBags - plannedTotal)} sacas passaram do total previsto.`
    : complete
      ? "Quantidade física prevista atingida. O leitor continua associando os códigos das sacas detectadas."
      : nearEnd
        ? "Atenção: o carregamento está próximo da quantidade prevista."
        : "";
  const endNoticeTone = excess ? "excess" : complete ? "complete" : "near-end";
  const remainingLabel = plannedTotal <= 0
    ? "Quantidade prevista não informada"
    : remainingTotal === 0
      ? `Quantidade física atingida · ${numero(validReadings)} códigos válidos`
      : `Falta${remainingTotal === 1 ? "" : "m"} ${numero(remainingTotal)} saca${remainingTotal === 1 ? "" : "s"} para a quantidade prevista`;
  const totalSummary = `<section class="work-live-summary" aria-label="Contagem e progresso do carregamento"><div class="work-live-summary-heading"><strong>Sacas detectadas pelo sensor</strong><span><b data-live="detected">${numero(detectedBags)}</b> / <b data-live="planned">${numero(plannedTotal)}</b> previstas · <span data-live="progress-percent">${totalPercent}%</span> · <b data-live="loaded">${numero(validReadings)}</b> códigos válidos</span></div><div class="progress work-live-progress" role="progressbar" aria-label="Progresso de sacas detectadas pelo sensor M2052" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${totalPercent}"><i data-live="progress-bar" style="width:${totalPercent}%"></i></div><div class="work-live-summary-foot"><span data-live="remaining-label">${remainingLabel}</span></div><p class="work-completion-notice ${endNoticeTone}" data-live="end-notice"${endNotice ? "" : " hidden"}>${endNotice}</p>${scannerMonitor}</section>`;
  const machinePanel = `<section class="panel work-machine-panel"><div class="panel-heading"><div><span class="kicker">Comandos da máquina</span><h3>Operar Dala</h3></div>${summaryAction ? `<div class="work-machine-heading-actions">${summaryAction}</div>` : ""}</div><div class="work-machine-controls">${stopConfirmation}${controls}</div></section>`;
  const statusColumn = `<section class="work-status-column">${totalSummary}${itemProgress}</section>`;
  const workHeader = `<header class="work-screen-header">${loadingPicker}<div class="work-screen-heading"><h2>${esc(workTitle)}</h2><p>Dala ${esc(equipmentLabel(store))} · Caminhão ${esc(store.state.truck || "—")} <span class="work-header-state">${badge}</span></p></div></header>`;
  return `<div class="work-operation-screen">${workHeader}<div class="work-operation-layout">${statusColumn}${machinePanel}</div></div>`;
}
export function work(store) {
  if (
    !store.state.selectedLoadingId &&
    (store.state.activeLoadings || []).length
  )
    return loadingSelection(store);
  return workControls(store);
}
