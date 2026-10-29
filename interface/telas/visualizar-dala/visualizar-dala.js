import { dataHora, numero } from "../../js/funcoes/formato.js?v=202609170930";
import { button, esc } from "../../js/funcoes/html.js";
import { rotuloComando, rotuloEstado, rotuloEvento, rotuloStatusComando } from "../../js/funcoes/rotulos.js";
import { pageHeader, physicalStateBadge } from "../../js/funcoes/view.js?v=202610280005";

export function dalaView(store) {
  const dala = store.state.equipmentDetail;
  if (!dala) return `${pageHeader("Cadastros / Dalas", "Dala", "Carregando…")}`;
  const operation = (store.state.dalaStatuses || []).find(
    (item) => Number(item.id) === Number(dala.id),
  ) || {};
  const planned = Number(operation.planned_quantity || 0);
  const loaded = Number(operation.valid_readings || 0);
  const percentage =
    planned > 0 ? Math.min(100, Math.round((loaded / planned) * 100)) : 0;
  const commands = store.state.dalaCommands || [];
  const pagination = store.state.dalaCommandsPagination || { page: 1, per_page: 30, total: commands.length, pages: 1 };
  const commandRows = commands.length ? commands.map((command) => `<tr><td data-label="ID">#${command.id}</td><td data-label="Comando">${esc(rotuloComando(command.command))}<small><code>${esc(command.command)}</code></small></td><td data-label="Status">${esc(rotuloStatusComando(command.status))}</td><td data-label="Solicitado em">${esc(dataHora(command.requested_at))}</td><td data-label="Resposta">${esc(command.response_message || "Aguardando resposta")}</td></tr>`).join("") : '<tr><td colspan="5" class="empty-cell">Nenhum comando registrado para esta Dala.</td></tr>';
  const commandSummary = `<p class="muted dala-diagnostics-summary">A rotina diária não apaga estes comandos; zerar a empresa remove o histórico dela. Mostrando ${commands.length} de ${Number(pagination.total) || 0}, em páginas de ${Number(pagination.per_page) || 30}.</p>`;
  const commandPagination = Number(pagination.pages) > 1
    ? `<nav class="table-pagination" aria-label="Paginação do histórico de comandos"><span>Página ${pagination.page} de ${pagination.pages}</span><div class="actions"><button class="button secondary small" data-action="dala-command-page" data-id="${dala.id}" data-page="${Number(pagination.page) - 1}" type="button"${Number(pagination.page) <= 1 ? " disabled" : ""}>Anterior</button><button class="button secondary small" data-action="dala-command-page" data-id="${dala.id}" data-page="${Number(pagination.page) + 1}" type="button"${Number(pagination.page) >= Number(pagination.pages) ? " disabled" : ""}>Próxima</button></div></nav>`
    : "";
  return `<div class="title-row with-actions dala-page-header"><div><span class="dala-page-kicker">Cadastros / Dalas</span><h2>${esc(dala.name)}</h2><p class="muted">Identificador <code>${esc(dala.equipment_code)}</code></p></div><div class="actions"><button class="button secondary page-back" data-action="back-dala" type="button">← Voltar</button></div></div>
<section class="panel dala-overview-panel"><div class="dala-overview-heading"><div><span class="dala-page-kicker">Configuração da Dala</span><h3>Comunicação e cadastro</h3></div><p id="dala-view-status" class="dala-status-line" data-status-region="dala-communication" data-equipment-id="${dala.id}"><span class="status-dot"></span>Verificando comunicação…</p></div>
<div class="dala-info-grid">
<div class="dala-info-item"><small>Nome da Dala</small><strong>${esc(dala.name)}</strong></div>
<div class="dala-info-item"><small>Identificador</small><strong><code>${esc(dala.equipment_code)}</code></strong></div>
<div class="dala-info-item"><small>IP do CLP</small><strong>${esc(dala.plc_ip || "—")}</strong></div>
<div class="dala-info-item"><small>Porta do CLP</small><strong>${dala.plc_port || "—"}</strong></div>
<div class="dala-info-item"><small>Criada em</small><strong>${dataHora(dala.created_at)}</strong></div>
<div class="dala-info-item"><small>Atualizada em</small><strong>${dataHora(dala.updated_at)}</strong></div>
</div></section>
<section class="panel dala-stats-panel" data-status-region="dala-statistics"><div class="panel-heading"><div><span class="dala-page-kicker">Operação</span><h3>Estatísticas da Dala</h3></div><span class="dala-last-signal">Último sinal: ${esc(dataHora(operation.last_seen_at, "Sem sinal registrado"))}</span></div><div class="grid four dala-stats-grid">
  <div class="metric"><small>Estado da operação</small><strong>${esc(rotuloEstado(operation.carregamento_state))}</strong></div>
  <div class="metric"><small>Estado físico</small><strong>${physicalStateBadge(operation, { compact: true })}</strong></div>
  <div class="metric"><small>Romaneio atual</small><strong>${esc(operation.romaneio_number ? `#${operation.romaneio_number}` : "—")}</strong></div>
  <div class="metric"><small>Carregado</small><strong>${numero(loaded)} / ${numero(planned)}</strong></div>
  <div class="metric"><small>Progresso</small><strong>${percentage}%</strong></div>
  </div></section>
<section class="panel dala-diagnostics-panel" data-status-region="dala-diagnostics"><div class="panel-heading"><div><span class="dala-page-kicker">Monitoramento</span><h3>Diagnóstico do CLP</h3><p class="muted">Comandos enviados, status do gateway e retorno registrado.</p></div><button class="button secondary" data-action="reload-dala-diagnostics" data-id="${dala.id}" type="button">Atualizar</button></div>${commandSummary}<div class="table-wrap"><table class="mobile-card-table"><thead><tr><th>ID</th><th>Comando</th><th>Status</th><th>Solicitado em</th><th>Resposta</th></tr></thead><tbody>${commandRows}</tbody></table></div>${commandPagination}</section>`;
}
