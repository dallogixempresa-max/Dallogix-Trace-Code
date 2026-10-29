import { button, esc } from "../../js/funcoes/html.js";
import { dataHora } from "../../js/funcoes/formato.js?v=202609170930";
import { machineGrid, deviceBadge } from "../../js/funcoes/view.js?v=202610280005";

export function company(store) {
  const detail = store.state.companyDetail;
  if (!detail) {
    return `<div class="title-row company-detail-header"><div class="company-detail-heading"><span class="kicker">Dallogix / gerenciamento</span><button class="button secondary page-back" data-action="back-companies" type="button">← Voltar</button></div></div><section class="panel"><p class="empty-cell">Nenhuma empresa selecionada.</p></section>`;
  }
  const machines = detail.maquinas || [];
  const total = machines.length;
  const online = machines.filter(
    (machine) => String(machine.clp_status || "").toUpperCase() === "ONLINE",
  ).length;
  const notOnline = total - online;
  const occurrences = detail.ocorrencias_recentes || [];
  const romaneios = detail.romaneios || {};
  const openRomaneios =
    (romaneios.AGUARDANDO || 0) + (romaneios.EM_ANDAMENTO || 0);
  const installations = store.state.industrialInstallations || [];
  const equipmentOptions = store.state.industrialEquipmentOptions || [];
  const generatedCode = store.state.industrialInstallationActivation;
  const licenseStatus = String(detail.license_status || "SEM_LICENCA");
  const licenseActive = licenseStatus === "ATIVA";
  const activeInstallations = installations.filter((installation) => !installation.archived);
  const archivedInstallations = installations.filter((installation) => installation.archived);
  const renderInstallation = (installation, archived = false) => {
    const id = Number(installation.id);
    const name = esc(installation.name);
    const dalaName = esc(installation.equipment_name || "Não vinculada");
    const isActivated = Boolean(installation.activated || installation.activated_at);
    const isBlocked = Boolean(installation.access_blocked);
    const statusKey = String(installation.status || "DESCONHECIDO").toUpperCase();
    const connectionStatuses = {
      ONLINE: "Online",
      OFFLINE: "Offline",
      ERRO: "Com erro",
      DESCONHECIDO: "Sem sinal",
    };
    const connectionClasses = {
      ONLINE: "online",
      OFFLINE: "offline",
      ERRO: "error",
      DESCONHECIDO: "unknown",
    };
    let status = connectionStatuses[statusKey] || "Sem sinal";
    let stateClass = connectionClasses[statusKey] || "unknown";
    if (!isActivated) {
      status = "Pendente de ativação";
      stateClass = "pending";
    }
    if (isBlocked) {
      status = isActivated ? "Acesso bloqueado" : "Ativação bloqueada";
      stateClass = "blocked";
    }
    if (archived) {
      status = "Arquivado";
      stateClass = "archived";
    }
    const lastSignal = installation.last_seen_at ? dataHora(installation.last_seen_at) : "Ainda não se conectou";
    const disk = installation.pc_details?.disk || {};
    const freeBytes = Number(disk.free_bytes);
    const totalBytes = Number(disk.total_bytes);
    const freePercent = Number(disk.free_percent);
    const hasDiskStats = Number.isFinite(freeBytes) && freeBytes >= 0 && Number.isFinite(totalBytes) && totalBytes > 0 &&
      Number.isFinite(freePercent) && freePercent >= 0 && freePercent <= 100;
    const freeDiskGb = hasDiskStats ? (freeBytes / (1024 ** 3)).toLocaleString("pt-BR", { maximumFractionDigits: 1 }) : "";
    const totalDiskGb = hasDiskStats ? (totalBytes / (1024 ** 3)).toLocaleString("pt-BR", { maximumFractionDigits: 1 }) : "";
    const diskInfo = hasDiskStats
      ? '<strong>' + freePercent.toLocaleString("pt-BR", { maximumFractionDigits: 1 }) + '% livre</strong><small>' + freeDiskGb + ' GB de ' + totalDiskGb + ' GB</small>'
      : '<strong>Sem leitura</strong><small>Disponível após a primeira comunicação</small>';
    const code = !archived && !isBlocked
      ? (generatedCode?.id === id ? generatedCode.activation_code : installation.activation_code)
      : null;
    const options = equipmentOptions.filter((equipment) =>
      !equipment.industrial_pc_id || Number(equipment.industrial_pc_id) === id,
    );
    const canLinkDala = !archived && !isBlocked && (!isActivated || !installation.equipment_id) && options.length > 0;
    const dalaInfoLabel = canLinkDala
      ? (installation.equipment_id ? "Dala deste PC" : "Vincular Dala")
      : "Dala vinculada";
    const dalaControl = canLinkDala
      ? '<form class="industrial-pc-link-form" data-installation-id="' + id + '"><label>Dala deste PC<select name="equipment_id" required><option value="">Selecione uma Dala</option>' +
        options.map((equipment) => '<option value="' + Number(equipment.id) + '"' +
          (Number(equipment.id) === Number(installation.equipment_id) ? ' selected' : '') + '>' +
          esc(equipment.name) + ' · ' + esc(equipment.equipment_code) + '</option>').join("") +
        '</select></label><button class="button secondary small" data-action="link-industrial-pc-dala" type="button">Vincular Dala</button></form>'
      : '<div class="industrial-pc-info-value"><strong>' + dalaName +
        (installation.equipment_code ? ' · ' + esc(installation.equipment_code) : '') + '</strong></div>';
    const activationControl = archived
      ? '<p class="industrial-pc-note">O acesso e o código foram revogados ao arquivar. Restaure e gere um novo código para reativar este PC.</p>'
      : isBlocked
      ? '<p class="industrial-pc-note">' + (isActivated ? 'Acesso remoto bloqueado. A operação local continua funcionando.' : 'A ativação está bloqueada. Libere para gerar um código.') + '</p>'
      : isActivated
      ? '<p class="industrial-pc-note"><strong>PC ativado</strong>' +
        (installation.activated_at ? ' desde ' + esc(dataHora(installation.activated_at)) : '') + '</p>'
      : code
      ? '<details class="industrial-pc-code"><summary>Ver código de ativação</summary><div><code>' + esc(code) +
        '</code></div></details>'
      : '<p class="industrial-pc-note">Nenhum código pendente.</p><button class="button primary small" data-action="generate-industrial-pc-code" data-id="' + id + '" type="button" ' +
        (licenseActive ? '' : 'disabled') + '>Gerar código</button>';
    const accessSummary = archived ? 'Arquivado' : isBlocked ? 'Bloqueado' : isActivated ? 'Liberado' : 'Sem bloqueio';
    const accessButtonLabel = isBlocked
      ? (isActivated ? 'Liberar acesso' : 'Liberar ativação')
      : (isActivated ? 'Bloquear acesso' : 'Bloquear ativação');
    const accessActions = archived
      ? '<div class="industrial-pc-actions"><button class="button secondary small" data-action="restore-industrial-pc" data-id="' + id + '" type="button">Restaurar PC</button><button class="button danger small" data-action="delete-industrial-pc" data-id="' + id + '" data-name="' + name + '" type="button">Excluir definitivamente</button></div>'
      : '<div class="industrial-pc-actions"><button class="button ' + (isBlocked ? 'primary' : 'secondary') + ' small" data-action="toggle-industrial-pc-access" data-id="' + id +
        '" data-name="' + name + '" data-activated="' + isActivated + '" data-blocked="' + (!isBlocked) + '" type="button">' +
        accessButtonLabel + '</button><button class="button secondary small" data-action="archive-industrial-pc" data-id="' + id + '" data-name="' + name + '" type="button">Arquivar PC</button></div>';
    return '<article class="industrial-pc-row is-' + stateClass + (archived ? ' is-archived' : '') + '"><header class="industrial-pc-card-header"><div class="industrial-pc-identity"><small>PC industrial #' + id + '</small><h4>' + name +
      '</h4></div><span data-installation-status="' + id + '" class="industrial-pc-status is-' + stateClass + '">' + esc(status) + '</span></header><div class="industrial-pc-info-grid"><section class="industrial-pc-info-item"><small>' + dalaInfoLabel + '</small>' + dalaControl +
      '</section><section class="industrial-pc-info-item"><small>Última comunicação</small><strong data-installation-last-seen="' + id + '">' + esc(lastSignal) + '</strong></section><section class="industrial-pc-info-item"><small>Armazenamento do PC</small>' + diskInfo +
      '</section><section class="industrial-pc-info-item"><small>Acesso remoto</small><strong>' + esc(accessSummary) + '</strong></section></div><footer class="industrial-pc-footer"><div class="industrial-pc-activation"><small>Ativação</small>' +
      activationControl + '</div><div class="industrial-pc-controls">' + accessActions + '</div></footer></article>';
  };
  const createPcPanel = '<section class="panel industrial-pc-create-panel"><div class="panel-heading"><div><h3>Cadastrar PC industrial</h3></div></div><form id="industrial-pc-create-form" data-company-id="' + Number(detail.id) +
    '" class="industrial-pc-create-form"><label>Nome do PC industrial<input name="name" maxlength="160" required placeholder="Ex.: Esteira 1 · Unidade Campinas" /></label><button class="button primary" type="submit">Cadastrar PC industrial</button><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form></section>';
  const managePcPanel = '<section class="panel industrial-pc-management-panel"><div class="panel-heading"><div><span class="kicker">Acesso individual por PC</span><h3>Gerenciar PCs industriais</h3><p>Cada cartão reúne os dados e o acesso de um PC. Bloquear pausa a sincronização; a operação local continua.</p></div></div><div class="industrial-pc-list">' +
    (activeInstallations.length ? activeInstallations.map((installation) => renderInstallation(installation)).join("") : '<p class="empty-cell">Nenhum PC industrial cadastrado. Use o formulário acima para criar o primeiro.</p>') +
    '</div>' + (archivedInstallations.length ? '<details class="industrial-pc-archived"><summary>PCs arquivados (' + archivedInstallations.length + ')</summary><div class="industrial-pc-list">' +
      archivedInstallations.map((installation) => renderInstallation(installation, true)).join("") + '</div></details>' : '') +
    (!licenseActive ? '<small>Ative a licença da empresa para gerar códigos de ativação.</small>' : '') + '</section>';
  const renamePanel = `<section class="panel company-rename-panel"><form id="company-rename-form" class="company-rename-form" data-company-id="${detail.id}"><div class="panel-heading"><div><span class="kicker">Cadastro da empresa</span><h3>Nome da empresa</h3><p>O domínio dos logins permanece o mesmo para não interromper os acessos existentes.</p></div><button class="button primary" type="submit">Salvar nome</button></div><label>Nome comercial<input name="name" maxlength="160" value="${esc(detail.name)}" required /></label><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p></form></section>`;
  return `<div class="title-row with-actions company-detail-header"><div class="company-detail-heading"><span class="kicker">Dallogix / gerenciamento</span><button class="button secondary page-back" data-action="back-companies" type="button">← Voltar</button></div><div class="actions">${button("Logins", "open-users", "secondary", `data-company-id="${detail.id}"`)}</div></div>
    ${renamePanel}${createPcPanel}${managePcPanel}
    <div class="grid four company-dashboard-metrics" data-status-region="company-metrics">
      <div class="panel metric"><small>Máquinas</small><strong>${total}</strong></div>
      <div class="panel metric"><small>Online</small><strong class="metric-green">${online}</strong></div>
      <div class="panel metric"><small>Não online</small><strong class="${notOnline > 0 ? "metric-red" : ""}">${notOnline}</strong></div>
      <div class="panel metric"><small>Romaneios abertos</small><strong>${openRomaneios}</strong></div>
    </div><br>
    <div data-status-region="company-machines">${machineGrid(machines, "Nenhuma máquina cadastrada para esta empresa.")}</div><br>
    <section class="panel" data-status-region="company-peripherals"><h3>Periféricos reportados</h3>${machines.length ? `<div class="table-wrap"><table><thead><tr><th>Máquina</th><th>Identificador</th><th>CLP</th><th>Último sinal</th></tr></thead><tbody>${machines.map((machine) => `<tr><td><strong>${esc(machine.name)}</strong></td><td>${esc(machine.equipment_code)}</td><td>${deviceBadge(machine.clp_status)}</td><td>${esc(dataHora(machine.last_seen_at))}</td></tr>`).join("")}</tbody></table></div>` : '<p class="empty-cell">Sem dispositivos registrados.</p>'}</section><br>
    <section class="panel"><h3>Ocorrências recentes</h3><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Quantidade</th><th>Descrição</th><th>Data</th></tr></thead><tbody>${occurrences.length ? occurrences.map((occurrence) => `<tr><td><strong>${esc(occurrence.type)}</strong></td><td>${Number(occurrence.quantity)}</td><td>${occurrence.description ? esc(occurrence.description) : "—"}</td><td>${esc(dataHora(occurrence.created_at))}</td></tr>`).join("") : '<tr><td colspan="4" class="empty-cell">Nenhuma ocorrência registrada.</td></tr>'}</tbody></table></div></section>`;
}
