import { esc } from "../../js/funcoes/html.js";
import { dataHora, numero, relativo } from "../../js/funcoes/formato.js?v=202611120002";
import { deviceBadge, pageHeader, physicalStateBadge } from "../../js/funcoes/view.js?v=202611120002";
import { rotuloEstado, rotuloStatusSincronizacao } from "../../js/funcoes/rotulos.js";
export function alerts(store) {
  const data = store.state.alertMonitoring || {
    leituras: {},
    sync_pendente: 0,
    dispositivos: [],
  };
  const devices = data.dispositivos || [];
  const machines = data.maquinas || [];
  const sync = store.state.syncStatus || {
    summary: {},
    recent: [],
    remote_configured: false,
  };
  const summary = sync.summary || {};
  const queueHealth = sync.queue_health || {};
  const centralSync = sync.central_sync || {};
  const centralMessage = centralSync.last_error
    ? `Falha: ${centralSync.last_error}`
    : centralSync.central_url_configured && !centralSync.installation_registered
      ? "URL central configurada, mas esta instalação ainda não foi ativada. Os eventos permanecem na fila local."
    : centralSync.last_sync_at
      ? `Última sincronização central: ${dataHora(centralSync.last_sync_at)}`
      : centralSync.configured
        ? "Sincronização central configurada, aguardando primeira execução."
        : "Instalação sem sincronização central configurada.";
  const syncRows = sync.recent?.length
    ? sync.recent
        .map((item) => {
          const kind = {
            ROMANEIO: "Romaneio",
            LEITURA: "Leitura",
            OCORRENCIA: "Ocorrência",
            COMANDO_CLP: "Comando da máquina",
          }[String(item.aggregate_type || "").toUpperCase()] || "Registro operacional";
          const status = rotuloStatusSincronizacao(item.status);
          const detail = item.status === "ERRO" ? "Nova tentativa automática agendada." : "Aguardando envio automático.";
          return `<tr><td data-label="Registro"><strong>${esc(kind)}</strong><small>Registro #${esc(item.aggregate_id || item.id)}</small></td><td data-label="Situação">${esc(status)}<small>${detail}</small></td><td data-label="Próxima verificação">${esc(dataHora(item.available_at, "agora"))}</td></tr>`;
        })
        .join("")
    : '<tr><td colspan="3" class="empty-cell">Nenhum envio aguardando processamento.</td></tr>';
  return `${pageHeader("Acompanhamento / máquina", "Alertas e sincronização", "Avisos dos periféricos e acompanhamento seguro da fila local-first.")}
  <section class="panel" data-status-region="alert-devices"><ul><li>Leituras válidas registradas: ${numero(data.leituras.VALIDO)}</li><li>Produtos incorretos: ${numero(data.leituras.PRODUTO_INCORRETO)}</li>${devices.map((device) => `<li>${esc(device.equipment_code)} · ${esc(device.device_type)}: <strong>${esc(device.status)}</strong><small>Último sinal: <span data-relative-time="${esc(device.last_seen_at || "")}">${esc(relativo(device.last_seen_at))}</span>${device.segundos_sem_sinal === null ? "" : ` · sem sinal há ${esc(device.segundos_sem_sinal)} s`}</small></li>`).join("")}</ul></section><br>
  <section class="panel" data-status-region="alert-machines"><div class="panel-heading"><div><h3>Estado das Dalas</h3><p>O estado físico só aparece como confirmado quando o CLP enviar esse retorno.</p></div></div><div class="table-wrap"><table class="mobile-card-table"><thead><tr><th>Dala</th><th>Comunicação</th><th>Estado da operação</th><th>Estado físico</th></tr></thead><tbody>${machines.length ? machines.map((machine) => `<tr><td data-label="Dala"><strong>${esc(machine.name)}</strong><small>${esc(machine.equipment_code)}</small></td><td data-label="Comunicação">${deviceBadge(machine.clp_status)}</td><td data-label="Estado da operação">${esc(rotuloEstado(machine.carregamento_state, "Ociosa"))}</td><td data-label="Estado físico">${physicalStateBadge(machine)}</td></tr>`).join("") : '<tr><td colspan="4" class="empty-cell">Nenhuma Dala cadastrada.</td></tr>'}</tbody></table></div></section><br>
  <section class="panel" data-status-region="alert-sync"><div class="panel-heading"><div><h3>Sincronização com o servidor</h3><p>${sync.remote_configured ? "Envios e novas tentativas são processados automaticamente." : "Configure o endereço do servidor para ativar o envio automático."}</p><p>${esc(centralMessage)}</p></div><span class="badge ${sync.remote_configured ? "green" : "yellow"}">${sync.remote_configured ? "Remota disponível" : "Somente local"}</span></div><div class="grid four"><div class="metric"><small>Pendentes</small><strong>${numero(summary.PENDENTE)}</strong></div><div class="metric"><small>Com erro</small><strong class="${summary.ERRO ? "metric-red" : "metric-green"}">${numero(summary.ERRO)}</strong><span>Nova tentativa automática</span></div><div class="metric"><small>Processando</small><strong>${numero(summary.PROCESSANDO)}</strong></div><div class="metric"><small>Enviados</small><strong class="metric-green">${numero(summary.ENVIADO)}</strong></div></div><div class="grid two sync-queue-health"><div class="metric"><small>Evento mais antigo</small><strong class="${queueHealth.stale ? "metric-red" : ""}">${queueHealth.oldest_at ? esc(relativo(queueHealth.oldest_at)) : "Nenhum pendente"}</strong><span>${queueHealth.stale ? "Acima do limite configurado" : "Dentro do limite configurado"}</span></div><div class="metric"><small>Reenvio</small><strong class="metric-green">Automático</strong><span>Falhas permanecem na fila até o servidor confirmar o envio.</span></div></div></section><br>
  <section class="panel table-wrap" data-status-region="alert-pending-sync"><h3>Envios pendentes</h3><p>O sistema envia e tenta novamente automaticamente quando o servidor estiver disponível.</p><table class="mobile-card-table monitoring-table"><thead><tr><th>Registro</th><th>Situação</th><th>Próxima verificação</th></tr></thead><tbody>${syncRows}</tbody></table></section>`;
}
