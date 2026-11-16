import { apresentacaoProgressoOperacao } from "../../js/funcoes/progresso-operacao.js?v=202611150003";
import { estadoControles, motivosControles, podeLiberarEmergencia } from "../../js/funcoes/controles-operacao.js?v=202611150003";
import { loadingSelection } from "./selecao-dala.js?v=202611150003";
import { renderItensRomaneio } from "./itens-romaneio.js?v=202611150003";
export { podeVincularDala, paradaPodeSubstituirInicio, paradaPodeSerEnfileiradaAposInicio, podeLiberarEmergencia, workControlLocks } from "../../js/funcoes/controles-operacao.js?v=202611150003";
import { button, esc } from "../../js/funcoes/html.js";
import { numero } from "../../js/funcoes/formato.js?v=202611120004";
import { rotuloComando, rotuloEstado } from "../../js/funcoes/rotulos.js?v=202611120004";
import { emergencyPanel } from "../../js/funcoes/view.js?v=202611120004";
import { motivoBloqueioFinalizacao, podeFinalizarCarregamento } from "../../js/funcoes/regras-carregamento.js?v=202611120004";

function equipmentLabel(store) {
  if (store.state.equipmentCode && store.state.equipmentCode !== "—") {
    return store.state.equipmentCode;
  }
  const equipment = (store.state.equipments || []).find(
    (item) => Boolean(store.state.equipmentId) && Number(item.id) === Number(store.state.equipmentId),
  );
  return equipment?.equipment_code || "Esteira";
}

function workControls(store) {
  const loadingItems = Array.isArray(store.state.loadingItems)
    ? store.state.loadingItems
    : [];
  const detectedBags = Number(store.state.detectedBags) || 0;
  const plannedTotal = Number(store.state.planned) || 0;
  const validReadings = Number(store.state.loaded) || 0;
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
  const controlState = estadoControles(store.state);
  const motivosAcao = motivosControles(controlState, {
    clpAvailable,
    clpUnavailableMessage,
    machineCommandsAvailable,
    machineCommandsUnavailableMessage,
  });
  const currentCommand = controlState.currentCommand;
  const pendingCommand = controlState.pendingCommand;
  const pendingStatus = controlState.commandStatus;
  const currentCommandPending = controlState.currentCommandPending;
  const comandoPendente = controlState.commandPending;
  const comandosDisponiveis = controlState.hasLoading && clpAvailable &&
    machineCommandsAvailable && controlState.canOperate;
  const atributosAcao = (reason, disabled) => {
    const estaBloqueada = Boolean(disabled);
    return {
      disabled: estaBloqueada,
      "aria-disabled": String(estaBloqueada),
      ...(reason ? {
        title: reason,
        [estaBloqueada ? "data-block-reason" : "data-action-notice"]: reason,
      } : {}),
    };
  };
  // A emergência continua disponível para registrar a intenção; o gateway
  // valida a comunicação e a autorização antes de qualquer escrita física.
  const atributosEmergencia = atributosAcao(
    store.state.loadingId ? "" : "Nenhum carregamento selecionado",
    false,
  );
  const estadoOperacional = controlState.operationalState;
  const esteiraEmMovimentoOuIniciando = controlState.stopAvailable;
  const estadoReversaoConhecido = controlState.reversalKnown;
  const reversaoDesligarPermitida = controlState.reversalOffPermitted;
  const metaOuParadaFisicaBloqueiaInicio = Boolean(store.state.stopReason) ||
    controlState.manifestTargetReached;
  const motivoEstadoReversao = "Confirme o estado da reversão antes de alterar o sentido.";
  const atributosLigarEsteira = atributosAcao(
    motivosAcao.run,
    !comandosDisponiveis || comandoPendente || esteiraEmMovimentoOuIniciando ||
      (controlState.preparedAndStopped && metaOuParadaFisicaBloqueiaInicio) ||
      (!controlState.preparedAndStopped && !controlState.runClickableInCurrentState),
  );
  const motivoParadaIndisponivel = estadoOperacional === "CARREGANDO" && store.state.running !== true
    ? "Aguardando confirmação de movimento para habilitar a parada."
    : ["PREPARANDO", "PAUSADO"].includes(estadoOperacional) && store.state.running !== true
      ? "A esteira já está parada."
      : "Aguardando confirmação do estado da esteira para habilitar a parada.";
  const atributosPararEsteira = atributosAcao(
    motivosAcao.stop || (esteiraEmMovimentoOuIniciando ? "" : motivoParadaIndisponivel),
    !comandosDisponiveis || !esteiraEmMovimentoOuIniciando || Boolean(motivosAcao.stop),
  );
  const atributosLigarReversao = esteiraEmMovimentoOuIniciando
    ? atributosAcao(motivosAcao["reverse-on"] || "A esteira precisa estar parada para alterar a reversão.", true)
    : atributosAcao(
      motivosAcao["reverse-on"] || (!estadoReversaoConhecido ? motivoEstadoReversao : ""),
      !comandosDisponiveis || comandoPendente || !estadoReversaoConhecido || Boolean(motivosAcao["reverse-on"]),
    );
  const atributosDesligarReversao = esteiraEmMovimentoOuIniciando
    ? atributosAcao(motivosAcao["reverse-off"] || "A esteira precisa estar parada para alterar a reversão.", true)
    : atributosAcao(
      motivosAcao["reverse-off"] || (!reversaoDesligarPermitida ? motivoEstadoReversao : ""),
      !comandosDisponiveis || comandoPendente || !reversaoDesligarPermitida || Boolean(motivosAcao["reverse-off"]),
    );
  const rotuloPararEsteira = esteiraEmMovimentoOuIniciando
    ? controlState.reversalCommand === "REVERSAO_ATIVAR"
      ? "Desligar esteira · sentido reversa"
      : "Desligar esteira · sentido normal"
    : "Desligar esteira";
  const loadingPicker = `<div class="work-header-actions"><button class="button secondary small work-back-button" data-action="goto-manifests" type="button">← Romaneios</button>${(store.state.activeLoadings || []).length > 1 ? `<button class="button secondary small work-back-button" data-action="change-loading" type="button">Trocar Dala</button>` : ""}</div>`;
  const latestScannerReading = store.state.latestScannerReading;
  const leitorPodeConcluirPassagens = Boolean(store.state.loadingId)
    && ["CARREGANDO", "FINALIZANDO", "PAUSADO"].includes(estadoOperacional);
  const scannerValue = leitorPodeConcluirPassagens
    ? (latestScannerReading?.value || "Aguardando leitura…")
    : "Aguardando leitura…";
  const scannerValueClass = leitorPodeConcluirPassagens &&
    ["SEM_LEITURA", "PRODUTO_INCORRETO"].includes(latestScannerReading?.result)
    ? " is-error"
    : "";
  const scannerMonitor = store.state.loadingId
    ? `<div class="work-reader-monitor"><div class="work-reader-monitor-heading"><span class="kicker">Visor do leitor</span><small>Último código recebido automaticamente do leitor.</small></div><div class="work-scanner-display"><span class="work-scanner-display-label">Código de barras</span><output id="automatic-scanner-barcode" class="work-scanner-display-value${scannerValueClass}" data-live="scanner-value" role="status" aria-live="polite" aria-atomic="true">${esc(scannerValue)}</output></div></div>`
    : "";
  const controls = `<div class="work-controls"><div class="work-routine-controls">${button("Ligar esteira", "run", "primary", atributosLigarEsteira)}${button(rotuloPararEsteira, "stop", "ghost", atributosPararEsteira)}${button("Ligar reversão", "reverse-on", "secondary", atributosLigarReversao)}${button("Desligar reversão", "reverse-off", "secondary", atributosDesligarReversao)}</div><div class="work-emergency-zone">${button("EMERGÊNCIA", "emergency", "danger", atributosEmergencia)}</div></div>`;
  // Quando o carregamento entra em emergência, o painel de liberação precisa
  // aparecer na própria tela de operação — sem forçar o operador a navegar
  // para a página separada /emergencia.html. O botão de desbloqueio fica
  // desabilitado enquanto já houver uma solicitação DESBLOQUEAR_MAQUINA
  // pendente ou em processamento pelo gateway.
  const pendingUnlock = currentCommandPending && pendingCommand === "DESBLOQUEAR_MAQUINA";
  const unlockButtonAttributes = pendingUnlock
    ? { disabled: true, "aria-disabled": "true", title: "Aguardando confirmação do gateway para o desbloqueio." }
    : "";
  const emergencySection = estadoOperacional === "EMERGENCIA"
    ? emergencyPanel({
        loading: store.state.romaneio && store.state.romaneio !== "—"
          ? `Romaneio #${esc(store.state.romaneio)} · caminhão ${esc(store.state.truck || "—")}`
          : "Carregamento em emergência.",
        canUnlock: podeLiberarEmergencia({ role: store.state.userRole, loadingId: store.state.loadingId }),
        buttonAttributes: unlockButtonAttributes,
        commandStatus: store.state.plcCommand,
        compact: true,
        showStartButton: false,
      })
    : "";
  const finishReady = podeFinalizarCarregamento(store.state);
  const targetReached = plannedTotal > 0 && detectedBags >= plannedTotal;
  const finishBlockReason = motivoBloqueioFinalizacao(store.state);
  const finishBlockNotice = !finishReady && targetReached
    ? `<p class="work-completion-notice near-end" data-live="finish-block-notice">${esc(finishBlockReason)}</p>`
    : "";
  const finishAction = estadoOperacional === "EMERGENCIA" ? "" : `<div class="work-header-finish-actions">${button(
    "Finalizar romaneio",
    "finish",
    finishReady ? "primary" : "secondary",
    finishReady
      ? { title: "Finaliza este carregamento e, se for o último, encerra o romaneio." }
      : {
          disabled: true,
          "aria-disabled": "true",
          title: finishBlockReason,
        },
  )}</div>`;
  const stopCommand = pendingCommand === "PAUSAR_CARREGAMENTO" ? currentCommand : null;
  const stopConfirmationMessage = store.state.emergency
    ? pendingCommand === "DESBLOQUEAR_MAQUINA" && currentCommandPending
      ? "A solicitação de liberação da emergência está aguardando o gateway. A confirmação fica disponível quando o carregamento voltar a pausado."
      : "A emergência está ativa. Solicite a liberação abaixo; a operação continuará pausada até a confirmação da unidade removida."
    : store.state.stopReason === "COTA_PRODUTO_ATINGIDA"
      ? "A cota deste produto foi atingida. Aguarde a confirmação da parada física antes de continuar com outro produto."
    : store.state.stopReason === "COTA_ROMANEIO_ATINGIDA"
      ? "A quantidade do romaneio foi atingida. Confirme a parada física e aguarde as leituras e fotos antes de finalizar."
    : !stopCommand
    ? "O gateway ainda não confirmou um comando de parada. Verifique a máquina antes de confirmar."
    : ["PENDENTE", "PROCESSANDO"].includes(pendingStatus)
      ? "Parada solicitada ao gateway; aguardando resposta. O estado local pausado não confirma que a esteira parou."
      : pendingStatus === "APLICADO"
        ? "O gateway confirmou o comando de parada. Verifique a máquina e retire a unidade antes de liberar a retomada."
        : `O gateway não confirmou a parada: ${currentCommand.response_message || "estado físico não confirmado."} Verifique a máquina antes de liberar a retomada.`;
  const stopEmergencyAction = store.state.emergency
    ? podeLiberarEmergencia({ role: store.state.userRole, loadingId: store.state.loadingId })
      ? button("Solicitar liberação da emergência", "unlock", "secondary", unlockButtonAttributes)
      : '<p>Solicite ao supervisor ou administrador a liberação da emergência.</p>'
    : button("Acionar emergência", "emergency", "danger");
  const stopReasonIsProductQuota = store.state.stopReason === "COTA_PRODUTO_ATINGIDA";
  const stopReasonIsManifestQuota = store.state.stopReason === "COTA_ROMANEIO_ATINGIDA";
  const stopConfirmationLabel = stopReasonIsManifestQuota
    ? "Quantidade do romaneio atingida"
    : stopReasonIsProductQuota
    ? "Meta do produto atingida"
    : store.state.stopReason === "EXCESSO"
      ? "Quantidade excedida"
      : "Ação de segurança necessária";
  const stopConfirmationCheckLabel = stopReasonIsManifestQuota
    ? "Confirmo que a esteira está fisicamente parada e vou conferir as leituras e fotos antes de finalizar."
    : stopReasonIsProductQuota
    ? "Confirmo que a esteira está fisicamente parada e vou continuar com outro produto."
    : "Confirmo que a unidade foi removida e que a esteira está fisicamente parada.";
  const stopConfirmationButtonLabel = stopReasonIsManifestQuota
    ? "Confirmar parada"
    : stopReasonIsProductQuota
    ? "Confirmar parada e continuar"
    : "Confirmar remoção e liberar retomada";
  const quotaStopReason = ["COTA_ROMANEIO_ATINGIDA", "EXCESSO"].includes(store.state.stopReason);
  const stopConfirmation = store.state.stopReason && !store.state.emergency && !quotaStopReason
    ? `<dialog id="work-stop-dialog" class="work-stop-dialog" role="alertdialog" aria-modal="true" aria-labelledby="work-stop-confirmation-title" aria-describedby="work-stop-confirmation-alert work-stop-confirmation-status"><section class="work-stop-confirmation"><span class="kicker">${stopConfirmationLabel}</span><h2 id="work-stop-confirmation-title">Atenção: operação pausada</h2><p id="work-stop-confirmation-alert" class="work-stop-confirmation-alert">${esc(store.state.operatorAlert || "Confirme a parada física antes de continuar.")}</p><p id="work-stop-confirmation-status">${esc(stopConfirmationMessage)}</p><form class="confirm-stop-removal-form"><label><input type="checkbox" name="confirmar_remocao" value="true" required${store.state.stopRemovalAcknowledged === true ? " checked" : ""} autofocus /><span>${stopConfirmationCheckLabel}</span></label><button class="button primary" type="submit">${stopConfirmationButtonLabel}</button><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form><div class="work-stop-emergency-action">${stopEmergencyAction}</div></section></dialog>`
    : "";
  const badge = ["PENDENTE", "PROCESSANDO"].includes(pendingStatus) && pendingCommand
      ? `<span class="badge blue">${esc(rotuloComando(pendingCommand))} · aguardando CLP</span>`
      : store.state.operationalState === "EMERGENCIA"
      ? '<span class="badge red">Emergência solicitada · operação bloqueada</span>'
      : `<span class="badge yellow">${esc(rotuloEstado(store.state.operationalState))}</span>`;
  const commandFeedback = store.state.commandFeedback
    ? `<p class="work-selection-error" role="alert" aria-live="assertive">${esc(store.state.commandFeedback)}</p>`
    : "";
  const itemProgress = renderItensRomaneio(loadingItems);
  const workTitle = store.state.romaneio && store.state.romaneio !== "—"
    ? `Romaneio #${store.state.romaneio}`
    : "Operação";
  const { endNotice, endNoticeTone, remainingLabel } = apresentacaoProgressoOperacao(store.state);
  const totalSummary = `<section class="work-live-summary" aria-label="Contagem e progresso do carregamento"><div class="work-live-summary-heading"><strong>Sacas detectadas pelo sensor</strong><span><b data-live="detected">${numero(detectedBags)}</b> / <b data-live="planned">${numero(plannedTotal)}</b> previstas · <span data-live="progress-percent">${totalPercent}%</span></span></div><div class="work-live-summary-foot"><span>Scanner: <b data-live="scanner-readings">${numero(store.state.scannerReadings || 0)}</b> sacas com código</span></div><div class="progress work-live-progress" role="progressbar" aria-label="Progresso de sacas detectadas pelo sensor M2052" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${totalPercent}"><i data-live="progress-bar" style="width:${totalPercent}%"></i></div><div class="work-live-summary-foot"><span data-live="remaining-label">${remainingLabel}</span> · <b>${numero(validReadings)}</b> leituras válidas</div><p class="work-completion-notice ${endNoticeTone}" data-live="end-notice"${endNotice ? "" : " hidden"}>${endNotice}</p>${finishBlockNotice}${scannerMonitor}</section>`;
  const panelHeading = estadoOperacional === "EMERGENCIA"
    ? ""
    : `<div class="panel-heading"><div><span class="kicker">Comandos da máquina</span><h3>Operar Dala</h3></div></div>`;
  const machinePanel = `<section class="panel work-machine-panel">${panelHeading}${commandFeedback}<div class="work-machine-controls">${emergencySection}${stopConfirmation}${controls}</div></section>`;
  const statusColumn = `<section class="work-status-column">${totalSummary}${itemProgress}</section>`;
  const workHeader = `<header class="work-screen-header">${loadingPicker}<div class="work-screen-heading"><h2>${esc(workTitle)}</h2><p>Dala ${esc(equipmentLabel(store))} · Caminhão ${esc(store.state.truck || "—")} <span class="work-header-state">${badge}</span></p></div>${finishAction}</header>`;
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
