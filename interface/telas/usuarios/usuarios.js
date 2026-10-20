import { button, esc } from "../../js/funcoes/html.js";
import { dataHora } from "../../js/funcoes/formato.js?v=202609170930";
import { pageHeader } from "../../js/funcoes/view.js?v=202610061745";

const roleLabel = {
  ADMIN_EMPRESA: "Administrador",
  SUPERVISOR: "Supervisor",
  USUARIO: "Usuário",
};

export function users(store) {
  const platform = store.state.userRole === "ADMIN_DALLOGIX";
  const companyId = store.state.selectedCompanyId || "";
  const companyScoped =
    platform &&
    Boolean(companyId) &&
    new URLSearchParams(window.location.search).has("company_id");
  const users = store.state.users || [];
  const companyOptions = (store.state.companies || [])
    .map(
      (company) =>
        `<option value="${company.id}"${String(company.id) === String(companyId) ? " selected" : ""}>${esc(company.name)}</option>`,
    )
    .join("");
  const selectedCompany = (store.state.companies || []).find(
    (company) => String(company.id) === String(companyId),
  );
  const emailDomain =
    selectedCompany?.login_domain ||
    store.state.companyLoginDomain ||
    "dallogix.local";
  const defaultEmailPrefix = users.length ? "" : "admin";
  const roleOptions = platform
    ? ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]
    : ["SUPERVISOR", "USUARIO"];
  const companyField = platform
    ? companyScoped
      ? `<input type="hidden" name="company_id" value="${esc(companyId)}" />`
      : `<label>Empresa<select name="company_id" required>${companyOptions}</select></label>`
    : "";
  const companyContext = platform
    ? companyScoped
      ? `<section class="panel compact-help selected-company-context"><span class="kicker">Empresa em gerenciamento</span><strong>${esc(selectedCompany?.name || "Empresa selecionada")}</strong><small>@${esc(emailDomain)}</small></section>`
      : `<section class="panel compact-help"><label>Empresa para gerenciar<select id="users-company-selector"><option value="">Selecionar empresa…</option>${companyOptions}</select></label></section>`
    : "";
  const setup =
    platform && !companyId
      ? `<section class="panel empty-cell"><p>Selecione uma empresa para visualizar ou criar logins.</p></section>`
      : `<section class="panel users-create-panel"><div class="panel-heading"><div><span class="kicker">Novo acesso</span><h3>Criar login</h3><p>O domínio identifica a empresa. Escolha apenas o início do login.</p></div><span class="badge blue">@${esc(emailDomain)}</span></div><form id="user-create-form" data-company-id="${esc(companyId)}"><div class="grid four user-create-fields${platform && !companyScoped ? " has-company-field" : ""}">${companyField}<label>Nome<input name="name" required maxlength="160" autocomplete="name" /></label><label class="login-address-label">Login de acesso<div class="login-address"><input name="email_prefix" value="${defaultEmailPrefix}" required pattern="[a-z0-9][a-z0-9._-]{2,63}" autocomplete="username" aria-label="Início do login" /><span>@${esc(emailDomain)}</span></div><small>Use letras minúsculas, números, ponto, hífen ou sublinhado.</small></label><label>Perfil<select name="role">${roleOptions.map((role) => `<option value="${role}">${roleLabel[role]}</option>`).join("")}</select></label><label>Senha<div class="password-field"><input id="user-create-password" name="password" type="password" minlength="6" required autocomplete="new-password" /><button class="password-toggle" type="button" data-action="toggle-user-create-password" aria-controls="user-create-password" aria-pressed="false">Ver senha</button></div></label></div><div class="actions">${button("Criar login", "create-user")}</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form></section>`;
  const description = platform
    ? companyScoped
      ? `Crie e gerencie os acessos de ${selectedCompany?.name || "esta empresa"}.`
      : "Crie administradores, supervisores e usuários para cada empresa."
    : "Crie supervisores e usuários para sua empresa.";
  const backAction = companyScoped
    ? `data-action="back-company" data-company-id="${esc(companyId)}"`
    : `data-action="back-settings"`;
  return `<div class="title-row with-actions has-back"><button class="button secondary page-back" ${backAction} type="button">← Voltar</button><div><span class="kicker">Sistema / acessos</span><h2>Usuários</h2><p>${esc(description)}</p></div></div> ${companyContext}${companyContext ? "<br>" : ""}${setup}<br><section class="panel table-wrap"><h3>Logins ativos</h3><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Criado em</th><th>Ações</th></tr></thead><tbody data-status-region="user-list">${users.length ? users.map((user) => `<tr><td><strong>${esc(user.name)}</strong></td><td>${esc(user.email)}</td><td>${esc(roleLabel[user.role] || user.role)}</td><td><span class="badge ${Number(user.active) ? "green" : "red"}">${Number(user.active) ? "Ativo" : "Inativo"}</span></td><td>${esc(dataHora(user.created_at))}</td><td><div class="table-actions"><button class="text-link" data-action="toggle-user" data-id="${user.id}" data-name="${esc(user.name)}" data-role="${user.role}" data-active="${Number(user.active)}" type="button" ${Number(user.id) === Number(store.state.currentUserId) ? 'disabled title="Não é possível desativar o próprio acesso"' : ""}>${Number(user.active) ? "Desativar" : "Ativar"}</button><button class="text-link" data-action="reset-user-password" data-id="${user.id}" data-name="${esc(user.name)}" data-role="${user.role}" data-active="${Number(user.active)}" type="button">Redefinir senha</button></div></td></tr>`).join("") : '<tr><td colspan="6" class="empty-cell">Nenhum login encontrado.</td></tr>'}</tbody></table></section>`;
}
