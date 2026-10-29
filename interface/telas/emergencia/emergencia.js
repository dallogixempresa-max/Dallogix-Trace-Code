import { button, esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202609201000";
import { deviceBadge, emergencyPanel, pageHeader, physicalStateBadge, progress } from "../../js/funcoes/view.js?v=202610280005";
import { rotuloEstado, rotuloOcorrencia, rotuloStatusSincronizacao } from "../../js/funcoes/rotulos.js";
export function emergency(store) {
  const canUnlock = Boolean(store.state.loadingId);
  const loading = store.state.loadingId
    ? `Carregamento #${esc(store.state.loadingId)} • romaneio ${esc(store.state.romaneio)} • caminhão ${esc(store.state.truck)}`
    : "Nenhum carregamento ativo identificado.";
  return `<div data-status-region="emergency-status">${emergencyPanel({ loading, canUnlock, commandStatus: store.state.plcCommand })}</div>`;
}
