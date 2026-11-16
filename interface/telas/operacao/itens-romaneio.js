import { esc } from "../../js/funcoes/html.js";
import { numero } from "../../js/funcoes/formato.js?v=202611120004";

export function renderItensRomaneio(loadingItems) {
  const itemCountLabel = `${numero(loadingItems.length)} ${loadingItems.length === 1 ? "item" : "itens"}`;
  const itemProgress = `<section class="panel work-items-panel"><div class="panel-heading"><div><h3>Itens do romaneio</h3></div><div class="work-items-summary"><small>${itemCountLabel}</small></div></div><div class="work-item-progress-list">${loadingItems.length ? loadingItems.map((item) => {
    const loaded = Number(item.loaded_quantity) || 0;
    const noRead = Number(item.no_read_quantity) || 0;
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
    return `<article class="work-item-progress-card"><div class="work-item-progress-heading"><div class="work-item-identity"><strong>${esc(item.name || "Produto")}</strong><code>${esc(item.code || "—")}</code></div><span class="badge ${status[1]}">${status[0]}</span></div><div class="work-item-progress-values"><strong>${numero(loaded)} <span>/ ${numero(planned)} lidas</span></strong><span>${percent}%</span></div>${noRead > 0 ? `<small class="work-item-no-read">${numero(noRead)} sem leitura de código (passou pelo sensor)</small>` : ""}<div class="work-item-progress-track" role="progressbar" aria-label="Leituras válidas de ${esc(item.name || "produto")}" aria-valuemin="0" aria-valuemax="${planned}" aria-valuenow="${Math.min(loaded, planned)}"><i style="width:${percent}%"></i></div><div class="work-item-progress-foot"><span>Identificadas</span><span>${remainingText}</span></div></article>`;
  }).join("") : '<p class="empty-cell">Nenhum item detalhado para este carregamento.</p>'}</div></section>`;
  return itemProgress;
}
