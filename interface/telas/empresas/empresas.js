import { button } from "../../js/funcoes/html.js";
import { pageHeader, companyGrid } from "../../js/funcoes/view.js?v=202610080002";

export function companies(store) {
  const rows = store.state.companies || [];
  const activeRows = rows.filter((company) => !company.archived);
  const archivedRows = rows.filter((company) => company.archived);
  const totalMachines = activeRows.reduce(
    (sum, company) => sum + Number(company.total_machines || 0),
    0,
  );
  const totalOnline = activeRows.reduce(
    (sum, company) => sum + Number(company.machines_online || 0),
    0,
  );
  const totalUsers = activeRows.reduce(
    (sum, company) => sum + Number(company.total_users || 0),
    0,
  );
  const activeUsers = activeRows.reduce(
    (sum, company) => sum + Number(company.active_users || 0),
    0,
  );
  return `${pageHeader("Dallogix / administração global", "Empresas", "Acompanhe empresas, conectividade e acessos. A operação das máquinas permanece isolada em cada empresa.")}<p class="form-feedback status-refresh-feedback" data-company-status-feedback role="status" aria-live="polite" hidden></p><section class="panel"><h3>Nova empresa</h3><form id="company-create-form"><div class="grid two"><label>Nome da empresa<input name="name" maxlength="160" required placeholder="Nome comercial" /></label></div><div class="actions">${button("Criar empresa", "create-company")}</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form></section><br><div data-status-region="company-status-overview"><div class="grid four dashboard-metrics"><div class="panel metric"><small>Empresas ativas</small><strong>${activeRows.length}</strong></div><div class="panel metric"><small>Máquinas online</small><strong class="metric-green" data-company-summary="machines-online">${totalOnline} / ${totalMachines}</strong></div><div class="panel metric"><small>Acessos ativos</small><strong>${activeUsers} / ${totalUsers}</strong></div><div class="panel metric"><small>Não online</small><strong class="metric${totalMachines - totalOnline > 0 ? " metric-red" : ""}" data-company-summary="machines-offline">${totalMachines - totalOnline}</strong></div></div><br><section><h3>Empresas ativas</h3>${companyGrid(activeRows, "Nenhuma empresa ativa cadastrada.")}</section>${archivedRows.length ? `<br><section><h3>Empresas arquivadas</h3>${companyGrid(archivedRows, "Nenhuma empresa arquivada cadastrada.")}</section>` : ""}</div><br><section class="panel compact-help"><strong>Próximo passo ao cadastrar uma empresa</strong><p>Abra a empresa criada, entre em <b>Logins</b> e crie o primeiro administrador. Depois, na tela da empresa, cadastre cada PC industrial e gere um código individual para ativá-lo. Supervisores e usuários operacionais são criados pelo administrador daquela empresa.</p></section>`;
}
