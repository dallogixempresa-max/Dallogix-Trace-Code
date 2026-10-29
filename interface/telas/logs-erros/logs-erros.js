import { esc, button } from "../../js/funcoes/html.js";
import { dataHora } from "../../js/funcoes/formato.js?v=202609170930";
import { pageHeader } from "../../js/funcoes/view.js?v=202610280005";

export function errorLogs(store) {
  const rows = store.state.errorLogs || [];
  const diagnostic = store.state.technicalDiagnostics || {};
  const queue = diagnostic.queue || {};
  const disk = diagnostic.disk || {};
  const devices = Array.isArray(diagnostic.devices) ? diagnostic.devices : [];
  const commands = Array.isArray(diagnostic.commands) ? diagnostic.commands : [];
  const deadLetters = Array.isArray(store.state.deadLetters) ? store.state.deadLetters : [];
  const pagination = store.state.errorLogsPagination || { page: 1, per_page: 100, total: rows.length, pages: 1 };
  const retentionDays = Number.isInteger(Number(store.state.errorLogRetentionDays))
    ? Math.max(0, Number(store.state.errorLogRetentionDays))
    : 365;
  const format = (value) => value ? dataHora(value) : "—";
  const rastreamento = (value) => {
    try {
      const context = JSON.parse(String(value || "{}"));
      const ids = [context.correlation_id, context.upstream_correlation_id].filter(Boolean);
      return ids.length ? ids.map((id) => `<code>${esc(id)}</code>`).join("<br>") : "—";
    } catch {
      return "—";
    }
  };
  const bytes = (value) => {
    const amount = Number(value);
    if (!Number.isFinite(amount) || amount < 0) return "—";
    const units = ["B", "KB", "MB", "GB", "TB"];
    const unit = Math.min(Math.floor(Math.log(Math.max(amount, 1)) / Math.log(1024)), units.length - 1);
    const scaled = amount / (1024 ** unit);
    return `${scaled >= 10 || unit === 0 ? Math.round(scaled) : scaled.toFixed(1)} ${units[unit]}`;
  };
  const diskTitle = disk.scope === "pc_industrial" ? "Disco do PC industrial" : "Disco do servidor";
  const diskUsage = disk.used_bytes == null || disk.total_bytes == null
    ? "Dados de capacidade indisponíveis"
    : `${bytes(disk.used_bytes)} ocupados de ${bytes(disk.total_bytes)}`;
  const deviceRows = devices.length ? devices.map((item) => `<tr><td>${esc(item.device_type)}</td><td>${item.active || 0} / ${item.total || 0}</td><td>${item.stale || 0}</td><td>${esc(format(item.last_seen_at))}</td></tr>`).join("") : '<tr><td colspan="4" class="empty-cell">Nenhum dispositivo registrado.</td></tr>';
  const commandRows = commands.length ? commands.map((item) => `<tr><td>${esc(format(item.requested_at))}</td><td><code>${esc(item.command)}</code></td><td>${esc(item.status)}</td><td>${esc(item.response_message || "—")}</td></tr>`).join("") : '<tr><td colspan="4" class="empty-cell">Nenhum comando recente.</td></tr>';
  const errorPagination = Number(pagination.pages) > 1
    ? `<nav class="table-pagination" aria-label="Paginação dos logs de erro"><span>${Number(pagination.total) || 0} registro(s) · página ${pagination.page} de ${pagination.pages}</span><div class="actions"><button class="button secondary small" data-action="error-logs-page" data-page="${Number(pagination.page) - 1}" type="button"${Number(pagination.page) <= 1 ? " disabled" : ""}>Anterior</button><button class="button secondary small" data-action="error-logs-page" data-page="${Number(pagination.page) + 1}" type="button"${Number(pagination.page) >= Number(pagination.pages) ? " disabled" : ""}>Próxima</button></div></nav>`
    : `<nav class="table-pagination" aria-label="Resumo dos logs de erro"><span>${Number(pagination.total) || 0} registro(s) · retenção automática: ${retentionDays} dias.</span></nav>`;
  const errorLogLimit = `<p class="muted">Esta lista mostra até 100 registros por página.</p>`;
  const deadLetterRows = deadLetters.length ? deadLetters.map((item) => `<tr><td><code>${esc(item.event_uuid)}</code><small>${esc(format(item.moved_at))}</small></td><td>${esc(item.event_type)}</td><td>${esc(item.failed_attempts)}</td><td class="error-message-cell">${esc(item.last_error)}</td><td><button class="button secondary" data-action="requeue-dead-letter" data-id="${esc(item.id)}" type="button">Reenfileirar</button> <button class="button ghost" data-action="resolve-dead-letter" data-id="${esc(item.id)}" type="button">Resolver</button></td></tr>`).join("") : '<tr><td colspan="5" class="empty-cell">Nenhum evento aguardando análise.</td></tr>';
  return `${pageHeader("Sistema / diagnóstico", "Painel técnico", "Visão operacional para investigar banco, sincronização, dispositivos, comandos e espaço em disco.", button("Atualizar", "reload-error-logs", "secondary"))}
  <section class="grid four dashboard-metrics" data-status-region="technical-metrics"><div class="panel metric"><small>Banco</small><strong>${esc(diagnostic.database?.status || "—")}</strong></div><div class="panel metric"><small>Fila pendente</small><strong>${queue.pending || 0}</strong><span>${queue.errors || 0} erro(s)</span></div><div class="panel metric"><small>Fila morta</small><strong>${diagnostic.dead_letter_pending || 0}</strong><span>evento(s) aguardando análise</span></div><div class="panel metric"><small>${diskTitle}</small><strong>${disk.free_percent == null ? "—" : `${disk.free_percent}% livre`}</strong><span>${diskUsage}</span></div></section><br>
  <section class="panel reference-table-panel" data-status-region="technical-devices"><h3>Dispositivos e comunicação</h3><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Ativos</th><th>Sem sinal</th><th>Último heartbeat</th></tr></thead><tbody>${deviceRows}</tbody></table></div></section><br>
  <section class="panel reference-table-panel" data-status-region="technical-commands"><h3>Últimos comandos ao gateway</h3><p class="muted">Este resumo mostra os 10 mais recentes. O histórico completo, paginado e preservado pela rotina de retenção, fica no diagnóstico da Dala.</p><div class="table-wrap"><table><thead><tr><th>Solicitado</th><th>Comando</th><th>Status</th><th>Retorno</th></tr></thead><tbody>${commandRows}</tbody></table></div></section><br>
  <section class="panel reference-table-panel" data-status-region="technical-dead-letters"><div class="panel-heading"><div><h3>Fila morta de sincronização</h3><p class="muted">Reenfileire somente depois de corrigir a causa do erro. Toda ação fica registrada na auditoria.</p></div></div><div class="table-wrap"><table><thead><tr><th>Evento</th><th>Tipo</th><th>Tentativas</th><th>Último erro</th><th>Ação</th></tr></thead><tbody>${deadLetterRows}</tbody></table></div></section><br>
  <section class="panel compact-help"><strong>Proteção de dados</strong><p>Senhas, tokens, sessões e conteúdo de formulários não são armazenados neste histórico.</p></section><br>
  <section class="panel reference-table-panel" data-status-region="technical-errors"><div class="panel-heading"><div><h3>Erros registrados nesta instalação</h3><p class="muted">Inclui falhas do navegador, exceções PHP registradas e transições OFFLINE/ERRO dos dispositivos. Os logs brutos do Docker, Nginx, MySQL e falhas ocorridas antes da resposta do Trace continuam nos próprios serviços.</p></div></div>${errorLogLimit}${errorPagination}<div class="table-wrap"><table class="error-logs-table"><thead><tr><th>Data</th><th>Origem</th><th>Rastreamento</th><th>Mensagem</th><th>Empresa</th><th>Usuário</th></tr></thead><tbody>${rows.length ? rows.map((item) => `<tr><td>${esc(format(item.criado_em))}</td><td><code>${esc(item.origem)}</code></td><td>${rastreamento(item.contexto)}</td><td class="error-message-cell">${esc(item.mensagem)}</td><td>${esc(item.empresa || "Sistema")}</td><td>${esc(item.usuario || "—")}</td></tr>`).join("") : '<tr><td colspan="6" class="empty-cell">Nenhum registro encontrado neste histórico.</td></tr>'}</tbody></table></div></section>`;
}
