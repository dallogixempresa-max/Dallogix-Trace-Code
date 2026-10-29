import { button, esc } from "./html.js";
import { data, numero, relativo } from "./formato.js?v=202609201000";
import { rotuloEstado, rotuloStatusComando, rotuloStatusRomaneio } from "./rotulos.js?v=202609240001";

export function deviceStatusSummary(value) {
  const normalized = String(value || "DESCONHECIDO").trim().toUpperCase();
  const known = {
    ONLINE: ["OK", "green"],
    LOCAL: ["OK", "green"],
    OK: ["OK", "green"],
    OFFLINE: ["SEM COMUNICAÇÃO", "red"],
    ERRO: ["ERRO", "red"],
    DESCONHECIDO: ["DESCONHECIDO", "yellow"],
    NAO_REGISTRADO: ["NÃO REGISTRADO", "yellow"],
    "NÃO REGISTRADO": ["NÃO REGISTRADO", "yellow"],
  };
  const [label, tone] = known[normalized] || [normalized, "yellow"];
  return { label, tone };
}

export function pageHeader(kicker, title, description, action = "") {
  return `<div class="title-row"><div><span class="kicker">${esc(kicker)}</span><h2>${esc(title)}</h2><p>${esc(description)}</p></div>${action}</div>`;
}
export function statuses(store = null) {
  const selectedEquipmentId = Number(store?.state?.equipmentId) || null;
  const devices = (store?.state?.monitoring?.dispositivos || []).filter(
    (item) =>
      !selectedEquipmentId || Number(item.equipment_id) === selectedEquipmentId,
  );
  const known = ["SENSOR", "SCANNER", "CLP", "SERVER"];
  const labels = {
    SENSOR: "Sensor",
    SCANNER: "Scanner",
    CLP: "CLP",
    SERVER: "Servidor",
  };
  const status = (type) => type === "SERVER"
    ? (store?.state?.serverStatus || "DESCONHECIDO")
    : (devices.find((item) => item.device_type === type)?.status || "NAO_REGISTRADO");
  return `<div class="status">${known.map((type) => {
    const presentation = deviceStatusSummary(status(type));
    return `<span>${labels[type]} <b class="status-value status-${presentation.tone}" data-live-status="${type}">${esc(presentation.label)}</b></span>`;
  }).join("")}</div>`;
}

export function emergencyPanel({
  loading = "Emergência registrada no Trace",
  canUnlock = false,
  buttonAttributes = "",
  commandStatus = null,
  compact = false,
  showTechnical = true,
} = {}) {
  const status = String(commandStatus?.status || "").toUpperCase();
  const command = status ? rotuloStatusComando(status) : "Aguardando retorno do gateway";
  const commandTone = ["ERRO", "REJEITADO", "EXPIRADO"].includes(status)
    ? "red"
    : status === "APLICADO"
      ? "blue"
      : "yellow";
  const acknowledgement = status === "APLICADO"
    ? "O gateway reportou o comando como aplicado. Isso, sozinho, não confirma o estado físico da esteira."
    : "A operação está bloqueada no Trace; a parada física depende do circuito de segurança e da confirmação do CLP.";
  const disabledStart = button("Ligar esteira", "run", "primary", {
    disabled: true,
    "aria-disabled": "true",
    title: "Bloqueado enquanto a emergência estiver ativa",
  });
  const technicalDetails = showTechnical
    ? `<details class="emergency-technical"><summary>Detalhes do comando</summary><div class="work-command-feedback-status"><span>Retorno do gateway</span><span class="badge ${commandTone}">${esc(command)}</span></div>${commandStatus?.response_message ? `<p>${esc(commandStatus.response_message)}</p>` : ""}<p>${acknowledgement}</p></details>`
    : "";
  return `<section class="emergency${compact ? " emergency-compact" : ""}" aria-live="assertive">
    <div class="emergency-intro"><span class="kicker">Solicitação de emergência</span><h2>Operação bloqueada</h2><p>Estado lógico do Trace: <strong>${esc(loading)}</strong></p></div>
    <div class="emergency-lock-status"><strong>Esteira bloqueada</strong><span>Ligar esteira fica indisponível enquanto a emergência estiver ativa.</span></div>
    <div class="emergency-actions">${disabledStart}${canUnlock ? button("Liberar emergência", "unlock", "secondary", buttonAttributes) : ""}</div>
    ${technicalDetails}
    <p class="emergency-safety-note">A emergência permanece travada até uma liberação explícita. A solicitação não substitui o botão físico de emergência; confira a condição segura na máquina e no CLP antes de liberar.</p>
  </section>`;
}
export function progress(store) {
  const detected = Number(store.state.detectedBags ?? store.state.loaded) || 0;
  const planned = Number(store.state.planned) || 0;
  const pct =
    planned > 0 ? Math.min(100, Math.round((detected / planned) * 100)) : 0;
  return `<div class="progress"><i data-live="progress-bar" style="width:${pct}%"></i></div><div class="progress-label"><span data-live="progress-percent">${pct}% concluído</span><span data-live="progress-count">${numero(detected)} / ${numero(planned)} sacas detectadas pelo sensor</span></div>`;
}
export function badge(status) {
  const label = rotuloStatusRomaneio(status);
  const tone = ["FINALIZADO", "Finalizado"].includes(status)
    ? "green"
    : ["CANCELADO", "Cancelado"].includes(status)
      ? "red"
      : "yellow";
  return `<span class="badge ${tone}">${esc(label)}</span>`;
}

// Badge de status no padrão da referência TracePlatform.
export function manifestStatusBadge(row) {
  const activeState = String(row.active_state || "").toUpperCase();
  const activeStateLabels = {
    AGUARDANDO: ["Aguardando", "yellow"],
    PREPARANDO: ["Preparando", "blue"],
    CARREGANDO: ["Carregando", "green"],
    PAUSADO: ["Pausado", "yellow"],
    FINALIZANDO: ["Finalizando", "blue"],
    EMERGENCIA: ["Emergência", "red"],
  };
  if (activeStateLabels[activeState]) {
    const [label, tone] = activeStateLabels[activeState];
    return `<span class="badge ${tone}">${label}${row.active_equipment ? " - " + esc(row.active_equipment) : ""}</span>`;
  }
  if (row.status === "EM_ANDAMENTO") {
    return `<span class="badge blue">Em andamento${row.active_equipment ? " - " + esc(row.active_equipment) : ""}</span>`;
  }
  if (row.status === "FINALIZADO") {
    return Number(row.has_divergence)
      ? '<span class="badge orange">Finalizado c/ divergência</span>'
      : '<span class="badge green">Finalizado</span>';
  }
  if (row.status === "CANCELADO")
    return '<span class="badge red">Cancelado</span>';
  return badge(row.status);
}

export function manifestsTable(rows) {
  const list = Array.isArray(rows) ? rows : [];
  const body = list.length
    ? list
        .map((r) => {
          const viewAction = button("Visualizar", "view-manifest", "secondary", `data-id="${r.id}"`);
          const actions = `<div class="manifest-row-actions">${viewAction}</div>`;
          const operation = r.active_loading_id
            ? button("Retomar", "resume-loading", "primary", `data-loading-id="${r.active_loading_id}"`)
            : "—";
          return `<tr>
          <td data-label="Data do carregamento">${data(r.scheduled_date)}</td>
          <td data-label="Código do romaneio"><strong>${esc(r.number)}</strong></td>
          <td data-label="Expedidor">${esc(r.expedidor || "—")}</td>
          <td data-label="Status">${manifestStatusBadge(r)}</td>
          <td data-label="Ações">${actions}</td>
          <td data-label="Operação">${operation}</td>
        </tr>`;
        })
        .join("")
    : '<tr><td colspan="6" class="empty-cell">Nenhum romaneio encontrado.</td></tr>';
  return `<div class="panel table-wrap"><table class="manifests-table mobile-card-table"><thead><tr><th>Data do carregamento</th><th>Código do romaneio</th><th>Expedidor</th><th>Status</th><th>Ações</th><th>Operação</th></tr></thead><tbody>${body}</tbody></table></div>`;
}

export function deviceBadge(value) {
  const normalized = String(value || "DESCONHECIDO").toUpperCase();
  const labels = {
    ONLINE: "Online",
    OFFLINE: "Sem comunicação",
    ERRO: "Erro",
    DESCONHECIDO: "Desconhecido",
    NAO_REGISTRADO: "Não registrado",
    LOCAL: "OK",
  };
  const tone = normalized === "ONLINE" || normalized === "LOCAL"
    ? "green"
    : ["OFFLINE", "ERRO"].includes(normalized)
      ? "red"
      : "yellow";
  return `<span class="badge ${tone}">${esc(labels[normalized] || normalized)}</span>`;
}

export function operationalBadge(value) {
  const normalized = String(value || "OCIOSA").toUpperCase();
  const labels = {
    OPERANDO: ["Operando", "green"],
    PARADA_CONFIRMADA: ["Parada confirmada", "yellow"],
    PARADA_SOLICITADA: ["Parada solicitada", "yellow"],
    EMERGENCIA_SEM_CONFIRMACAO: ["Emergência · aguardando CLP", "red"],
    SEM_COMUNICACAO: ["Sem comunicação", "red"],
    ERRO_COMUNICACAO: ["Erro de comunicação", "red"],
    CLP_NAO_REGISTRADO: ["CLP não registrado", "yellow"],
    STATUS_DESCONHECIDO: ["Status desconhecido", "yellow"],
    OPERACAO_SEM_RETORNO: ["Operação · sem retorno físico", "yellow"],
    PREPARANDO: ["Em preparação", "blue"],
    OCIOSA: ["Ociosa", "blue"],
  };
  const [label, tone] = labels[normalized] || [normalized, "yellow"];
  return `<span class="badge ${tone}">${esc(label)}</span>`;
}

export function physicalStateBadge(machine = {}, { compact = false } = {}) {
  const communication = String(machine.clp_status || "").toUpperCase();
  let label = compact ? "Sem retorno do CLP" : "Desconhecido · sem retorno físico do CLP";
  let tone = "yellow";
  if (communication === "OFFLINE") {
    label = compact ? "Desconhecido" : "Desconhecido · sem comunicação com o CLP";
    tone = "red";
  } else if (communication === "ERRO") {
    label = compact ? "Desconhecido" : "Desconhecido · erro de comunicação com o CLP";
    tone = "red";
  } else if (communication === "NAO_REGISTRADO") {
    label = compact ? "CLP não registrado" : "Desconhecido · CLP não registrado";
  } else if (communication !== "ONLINE") {
    label = compact ? "Desconhecido" : "Estado físico desconhecido";
  } else if (machine.physical_running === true) {
    label = compact ? "Em operação" : "Operação reportada pelo CLP";
    tone = "green";
  } else if (machine.physical_running === false) {
    label = compact ? "Parada" : "Parada reportada pelo CLP";
    tone = "blue";
  }
  return `<span class="badge ${tone}">${esc(label)}</span>`;
}

function machineCard(machine) {
  const loaded = Number(machine.valid_readings || 0);
  const planned = Number(machine.planned_quantity || 0);
  const pct =
    planned > 0 ? Math.min(100, Math.round((loaded / planned) * 100)) : 0;
  const state = rotuloEstado(machine.carregamento_state, "Ociosa");
  const clpStatus = String(machine.clp_status || "").toUpperCase();
  const offlineClass = ["OFFLINE", "ERRO"].includes(clpStatus) ? "is-offline" : "";
  return `<article class="machine-card ${offlineClass}">
    <header><div><strong>${esc(machine.name)}</strong><small>${esc(machine.equipment_code)}</small></div><div class="company-card-statuses">${deviceBadge(machine.clp_status)}${operationalBadge(machine.operational_status)}</div></header>
    <dl>
      <div><dt>Romaneio</dt><dd>${machine.romaneio_number ? "#" + esc(machine.romaneio_number) : "—"}</dd></div>
      <div><dt>Caminhão</dt><dd>${machine.plate ? esc(machine.plate) : "—"}</dd></div>
      <div><dt>Estado da operação</dt><dd>${esc(state)}</dd></div>
      <div><dt>Estado físico</dt><dd>${physicalStateBadge(machine)}</dd></div>
      <div><dt>Último sinal</dt><dd data-relative-time="${esc(machine.last_seen_at || "")}">${esc(relativo(machine.last_seen_at))}</dd></div>
    </dl>
    <div class="progress"><i style="width:${pct}%"></i></div>
    <div class="progress-label"><span>${pct}% concluído</span><span>${numero(loaded)} / ${numero(planned)} sacas</span></div>
  </article>`;
}

export function machineGrid(
  machines,
  emptyMessage = "Nenhuma máquina cadastrada.",
) {
  const list = Array.isArray(machines) ? machines : [];
  return `<div class="card-grid machine-grid">${list.length ? list.map(machineCard).join("") : `<p class="empty-cell">${emptyMessage}</p>`}</div>`;
}

function industrialPcStatusPresentation(company) {
  const status = String(company.industrial_pc_status || "DESCONHECIDO").toUpperCase();
  const labels = {
    ONLINE: ["PC industrial online", "green", "Online", "metric-green"],
    OFFLINE: ["PC industrial sem comunicação", "red", "Offline", "metric-red"],
    ERRO: ["PC industrial com erro", "red", "Erro", "metric-red"],
    DESCONHECIDO: ["PC industrial sem sinal", "yellow", "Sem sinal", ""],
  };
  const [label, tone, metric, metricTone] = labels[status] || labels.DESCONHECIDO;
  return { label, tone, metric, metricTone };
}

function companyConnectionPresentation(company) {
  const total = Number(company.total_machines || 0);
  const online = Number(company.machines_online || 0);
  const industrialPcStatus = String(company.industrial_pc_status || "DESCONHECIDO").toUpperCase();
  if (company.archived) return { label: "Arquivada", tone: "yellow" };
  if (total === 0) return { label: "Sem Dalas", tone: "yellow" };
  if (online > 0) return { label: `${online}/${total} Dalas online`, tone: "green" };
  if (["OFFLINE", "ERRO"].includes(industrialPcStatus)) {
    return { label: "PC industrial sem comunicação", tone: "red" };
  }
  return { label: "Dalas sem sinal", tone: "yellow" };
}

function updateCompanyBadge(node, presentation) {
  if (!node) return;
  node.className = `badge ${presentation.tone}`;
  node.textContent = presentation.label;
}

/** Atualiza apenas conectividade em cards já renderizados, preservando formulários e foco. */
export function refreshCompanyStatusCards(companies, root = globalThis.document, { stale = false, staleMessage = null } = {}) {
  if (!root?.querySelectorAll) return;
  const list = Array.isArray(companies) ? companies : [];
  const byId = new Map(list.map((company) => [String(company.id), company]));
  root.querySelectorAll("[data-company-card-id]").forEach((card) => {
    const company = byId.get(String(card.dataset.companyCardId || ""));
    if (!company) return;

    const pcStatus = industrialPcStatusPresentation(company);
    const connection = companyConnectionPresentation(company);
    updateCompanyBadge(
      card.querySelector("[data-company-industrial-status-badge]"),
      stale ? { label: "Status desatualizado", tone: "yellow" } : pcStatus,
    );
    updateCompanyBadge(
      card.querySelector("[data-company-connection-badge]"),
      stale ? { label: "Status desatualizado", tone: "yellow" } : connection,
    );

    const onlineMetric = card.querySelector('[data-company-metric="dalas-online"]');
    if (onlineMetric) {
      onlineMetric.textContent = String(Number(company.machines_online || 0));
      onlineMetric.className = !stale && Number(company.machines_online || 0) > 0 ? "metric-green" : "";
    }
    const pcMetric = card.querySelector('[data-company-metric="industrial-pc"]');
    if (pcMetric) {
      pcMetric.textContent = stale ? "Desatualizado" : pcStatus.metric;
      pcMetric.className = stale ? "" : pcStatus.metricTone;
    }
    const lastDalaSignal = card.querySelector('[data-company-last-signal="dalas"]');
    if (lastDalaSignal) lastDalaSignal.textContent = company.last_signal_at || "—";
    const lastPcSignal = card.querySelector('[data-company-last-signal="pc"]');
    if (lastPcSignal) lastPcSignal.textContent = company.industrial_pc_last_seen_at || "—";
  });

  const active = list.filter((company) => !company.archived);
  const total = active.reduce((sum, company) => sum + Number(company.total_machines || 0), 0);
  const online = active.reduce((sum, company) => sum + Number(company.machines_online || 0), 0);
  const onlineSummary = root.querySelector('[data-company-summary="machines-online"]');
  if (onlineSummary) {
    onlineSummary.textContent = `${online} / ${total}`;
    onlineSummary.className = stale ? "" : online > 0 ? "metric-green" : total > 0 ? "metric-red" : "";
  }
  const offlineSummary = root.querySelector('[data-company-summary="machines-offline"]');
  if (offlineSummary) {
    offlineSummary.textContent = String(Math.max(0, total - online));
    offlineSummary.className = stale ? "" : total - online > 0 ? "metric-red" : "metric-green";
  }
  const feedback = root.querySelector("[data-company-status-feedback]");
  if (feedback) {
    feedback.textContent = stale
      ? staleMessage || "Não foi possível atualizar a conectividade. Os indicadores mostram a última consulta confirmada."
      : "";
    feedback.hidden = !stale;
  }
}

export function companyCard(company, { compact = false } = {}) {
  const total = Number(company.total_machines || 0);
  const online = Number(company.machines_online || 0);
  const industrialPc = industrialPcStatusPresentation(company);
  const users = Number(company.total_users || 0);
  const activeUsers = Number(company.active_users || 0);
  const archived = Boolean(company.archived);
  const connection = companyConnectionPresentation(company);
  const licenseStatus = String(company.license_status || "SEM_LICENCA");
  const licenseLabel = {
    ATIVA: "Licença ativa",
    BLOQUEADA: "Bloqueada",
    SEM_LICENCA: "Sem licença",
  }[licenseStatus] || licenseStatus;
  const licenseTone = archived ? "yellow" : licenseStatus === "ATIVA" ? "green" : "red";
  const licenseAction = licenseStatus === "ATIVA" ? "Bloquear licença" : "Ativar licença";
  const licenseBadge = archived ? "" : `<span class="badge ${licenseTone}">${esc(licenseLabel)}</span>`;
  const managementActions = archived
    ? `<button class="button primary small" data-action="restore-company" data-id="${company.id}" data-name="${esc(company.name)}" type="button">Restaurar</button><button class="button ghost danger-link small" data-action="delete-company-permanently" data-id="${company.id}" data-name="${esc(company.name)}" type="button">Excluir definitivamente</button>`
    : `<button class="button ${licenseStatus === "ATIVA" ? "danger" : "primary"} small" data-action="toggle-license" data-id="${company.id}" data-status="${esc(licenseStatus)}" type="button">${licenseAction}</button><button class="button ghost danger-link small" data-action="archive-company" data-id="${company.id}" data-name="${esc(company.name)}" type="button">Arquivar</button>`;
  return `<article class="company-card" data-company-card-id="${esc(company.id)}">
    <header><div class="company-card-identity"><strong>${esc(company.name)}</strong><small>${total} máquina(s) • último sinal das Dalas <span data-company-last-signal="dalas">${company.last_signal_at ? esc(company.last_signal_at) : "—"}</span></small><small>Último sinal do PC industrial: <span data-company-last-signal="pc">${company.industrial_pc_last_seen_at ? esc(company.industrial_pc_last_seen_at) : "—"}</span></small><code>Login: @${esc(company.login_domain || "—")}</code></div><div class="company-card-statuses"><span class="badge ${industrialPc.tone}" data-company-industrial-status-badge>${industrialPc.label}</span><span class="badge ${connection.tone}" data-company-connection-badge>${connection.label}</span>${licenseBadge}</div></header>
    <div class="company-card-metrics">
      <div><b>${total}</b><small>Máquinas</small></div>
      <div><b class="${online > 0 ? "metric-green" : ""}" data-company-metric="dalas-online">${online}</b><small>Dalas online</small></div>
      <div><b class="${industrialPc.metricTone}" data-company-metric="industrial-pc">${industrialPc.metric}</b><small>PC industrial</small></div>
      <div><b>${activeUsers}/${users}</b><small>Acessos ativos</small></div>
    </div>
    <footer><div class="actions"><button class="button secondary" data-action="open-company" data-id="${company.id}" type="button">Gerenciar</button>${compact ? "" : managementActions}</div></footer>
  </article>`;
}

export function companyGrid(
  companies,
  emptyMessage = "Nenhuma empresa cadastrada.",
  options = {},
) {
  const list = Array.isArray(companies) ? companies : [];
  return `<div class="card-grid company-grid">${list.length ? list.map((company) => companyCard(company, options)).join("") : `<p class="empty-cell">${emptyMessage}</p>`}</div>`;
}
