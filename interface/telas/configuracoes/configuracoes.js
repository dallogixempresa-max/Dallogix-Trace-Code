import { button, esc } from "../../js/funcoes/html.js";

const DEVICE_TYPES = [
  ["SENSOR", "Sensor", "Sinal de passagem no CLP"],
  ["SCANNER", "Scanner", "Elgin EL8600 · USB HID"],
  ["CLP", "CLP", "Controle da esteira"],
  ["CAMERA", "Câmera", "Registro visual"],
  ["SERVER", "Servidor", "API desta instalação"],
];

export function apresentacaoStatusDispositivo(value, type = "") {
  const normalized = String(value || "NAO_REGISTRADO").trim().toUpperCase();
  const deviceType = String(type).toUpperCase();
  if (deviceType === "SENSOR") {
    if (normalized === "ONLINE") {
      return {
        monitored: false,
        online: false,
        tone: "unknown",
        label: "NÃO CONFIRMADO",
        detail: "CLP comunicando; conexão física do sensor não confirmada.",
      };
    }
    if (["OFFLINE", "ERRO"].includes(normalized)) {
      return {
        monitored: true,
        online: false,
        tone: "error",
        label: "SEM SINAL",
        detail: "CLP sem leitura de M2052; não confirma defeito no sensor.",
      };
    }
    return {
      monitored: false,
      online: false,
      tone: "unknown",
      label: "NÃO VALIDADO",
      detail: "Conexão física do sensor não validada.",
    };
  }
  if (deviceType === "SCANNER") {
    if (normalized === "ONLINE") {
      return {
        monitored: true,
        online: true,
        tone: "online",
        label: "CONECTADO",
        detail: "Elgin EL8600 detectado no USB.",
      };
    }
    if (normalized === "OFFLINE") {
      return {
        monitored: true,
        online: false,
        tone: "offline",
        label: "DESCONECTADO",
        detail: "Elgin EL8600 não detectado no USB.",
      };
    }
    if (normalized === "ERRO") {
      return {
        monitored: true,
        online: false,
        tone: "error",
        label: "ERRO",
        detail: "Falha na verificação USB do PC.",
      };
    }
    return {
      monitored: false,
      online: false,
      tone: "unknown",
      label: "NÃO VERIFICADO",
      detail: "Detecção USB não configurada neste PC.",
    };
  }
  const online = ["ONLINE", "LOCAL", "OK"].includes(normalized);
  const error = normalized === "ERRO";
  const unknown = ["", "DESCONHECIDO", "NAO_REGISTRADO", "NÃO REGISTRADO"].includes(normalized);
  const tone = online ? "online" : error ? "error" : unknown ? "unknown" : "offline";
  const label = online ? "ON" : error ? "ERRO" : unknown ? "SEM SINAL" : "OFF";
  const detail = online ? "Sinal recebido" : error ? "Falha de comunicação" : unknown ? "Aguardando sinal" : "Sem sinal";
  return { monitored: true, online, tone, label, detail };
}

export function apresentacaoStatusDala(status) {
  const raw = String(status?.status || "DESCONHECIDO").toUpperCase();
  if (raw === "ONLINE") return { label: "ON", tone: "online", detail: "CLP comunicando" };
  if (raw === "OFFLINE") return { label: "OFF", tone: "offline", detail: "CLP sem comunicação" };
  if (raw === "ERRO") return { label: "OFF", tone: "offline", detail: "Falha de comunicação com o CLP" };
  return { label: "—", tone: "unknown", detail: "Status do CLP sem confirmação" };
}

export function resumoStatusDispositivos(presentations) {
  return presentations.reduce((summary, status) => {
    if (!status.monitored) summary.unmonitored += 1;
    else if (status.online) summary.online += 1;
    else summary.attention += 1;
    return summary;
  }, { online: 0, attention: 0, unmonitored: 0 });
}

export function renderStatusDalaBinario(status) {
  const presentation = apresentacaoStatusDala(status);
  return `<span class="settings-dala-status ${presentation.tone}" role="status" aria-label="${esc(presentation.detail)}" title="${esc(presentation.detail)}">${presentation.label}</span>`;
}

export function apresentacaoConectividadePc(syncStatus) {
  if (!syncStatus) return ["unknown", "Status da sincronização indisponível"];
  const centralSync = syncStatus.central_sync || {};
  if (!centralSync.configured) return ["unknown", "Sincronização central não configurada"];
  if (!centralSync.installation_registered) return ["unknown", "Instalação ainda não registrada"];
  const status = String(
    centralSync.pc_status || (centralSync.pc_online ? "ONLINE" : "DESCONHECIDO"),
  ).toUpperCase();
  return {
    ONLINE: ["online", "PC industrial comunicando com o Central"],
    OFFLINE: ["offline", "Central sem comunicação recente com o PC"],
    ERRO: ["offline", "Falha na comunicação do PC com o Central"],
    DESCONHECIDO: ["unknown", "Central sem dados recentes do PC"],
  }[status] || ["unknown", "Status do PC industrial desconhecido"];
}

function deviceStatusPanel(store) {
  const devices = store.state.deviceStatuses || [];
  const equipments = new Map(
    (store.state.equipments || []).map((equipment) => [
      Number(equipment.id),
      equipment.equipment_code || equipment.name || `Dala ${equipment.id}`,
    ]),
  );
  const records = DEVICE_TYPES.flatMap(([type, label, description]) => {
    if (type === "SERVER") {
      return [{ type, label, description, value: store.state.serverStatus || "DESCONHECIDO", device: null }];
    }
    const matches = devices.filter((item) => item.device_type === type);
    return matches.length
      ? matches.map((device) => ({ type, label, description, value: device.status, device }))
      : [{ type, label, description, value: "NAO_REGISTRADO", device: null }];
  });
  const presentations = records.map((record) => apresentacaoStatusDispositivo(record.value, record.type));
  const { online: onlineCount, attention: attentionCount, unmonitored: unmonitoredCount } = resumoStatusDispositivos(presentations);
  const cards = records.map(({ type, label, description, value, device }) => {
    const status = apresentacaoStatusDispositivo(value, type);
    const equipmentLabel = device?.equipment_id
      ? equipments.get(Number(device.equipment_id)) || `Dala ${device.equipment_id}`
      : type === "SERVER" ? "Instalação atual" : "Vínculo ainda não registrado";
    const equipmentAttribute = device?.equipment_id
      ? ` data-live-status-equipment="${Number(device.equipment_id)}"`
      : "";
    return `<article class="settings-device-status-card ${status.tone}" data-device-status-card="${type}"${equipmentAttribute}>
      <div class="settings-device-status-identity"><span class="settings-device-status-mark" aria-hidden="true">${esc(label.slice(0, 1))}</span><div><strong>${esc(label)}</strong><small>${esc(description)}</small><small>${esc(equipmentLabel)}</small></div></div>
      <div class="settings-device-status-result"><b class="status-value status-${status.tone}" data-live-status="${type}" data-live-status-mode="binary">${status.label}</b><span class="settings-device-status-detail" data-live-status-detail="${type}">${esc(status.detail)}</span></div>
    </article>`;
  }).join("");
  return `<section class="panel settings-device-status-panel" aria-label="Status dos dispositivos">
    <div class="settings-device-status-heading"><div><span class="kicker">Status da máquina</span><h3>Dispositivos e serviços</h3><p>Atualização automática enquanto esta tela estiver aberta.</p></div></div>
    <div class="settings-device-status-summary"><div><strong data-device-summary="online-count">${onlineCount}</strong><span>online</span></div><div><strong data-device-summary="attention-count">${attentionCount}</strong><span data-device-summary="attention-label">sem sinal ou com falha</span></div><div><strong data-device-summary="unmonitored-count">${unmonitoredCount}</strong><span>não confirmado</span></div></div>
    <div class="settings-device-status-grid">${cards}</div>
    <p class="settings-device-status-note">Sensor: o CLP responde pelo sinal M2052, mas isso não confirma a conexão física. Scanner: conectado/desconectado só é exibido quando a detecção USB está configurada neste PC.</p>
  </section><br>`;
}

// Conectividade local/remota, Dalas (somente leitura) e importação de PDF.
export function settings(store) {
  const config = store.state.configuration || { settings: {}, dalas: [] };
  const saved = config.settings || {};
  const [syncTone, syncLabel] = apresentacaoConectividadePc(store.state.syncStatus);
  const dalaStatuses = Array.isArray(store.state.dalaStatuses)
    ? store.state.dalaStatuses
    : [];
  const totalDalas = config.dalas.length;
  const onlineDalas = dalaStatuses.filter((dala) => dala.status === "ONLINE").length;
  const offlineDalas = dalaStatuses.filter((dala) => ["OFFLINE", "ERRO"].includes(dala.status)).length;
  const unknownDalas = Math.max(0, totalDalas - onlineDalas - offlineDalas);
  const dalaTone = totalDalas === 0 || unknownDalas > 0 || (onlineDalas > 0 && offlineDalas > 0)
    ? "unknown"
    : offlineDalas > 0
      ? "offline"
      : "online";
  const dalaLabel = totalDalas === 0
    ? "Dalas não cadastradas"
    : onlineDalas === totalDalas
      ? "Dalas online"
      : offlineDalas === totalDalas
        ? "Dalas sem comunicação"
        : onlineDalas > 0
          ? "Dalas parcialmente online"
          : "Dalas sem status";
  const mapping = saved.pdf_field_mapping || {};
  const searchField = saved.pdf_search_field || "barcode";
  const fields = [
    ["codigo", "Código", false],
    ["data", "Data do carregamento", false],
    ["expedidor", "Expedidor", false],
    ["placa", "Placa do caminhão", false],
    ["motorista", "Motorista", false],
    ["produto", "Identificador do produto", true],
    ["quantidade", "Quantidade", true],
  ];
  const dalaRows = config.dalas.length
    ? config.dalas
        .map((dala) => {
          const status = dalaStatuses.find((entry) => Number(entry.equipment_id) === Number(dala.id));
          return `<tr><td>${esc(dala.name)}</td><td><code>${esc(dala.equipment_code)}</code></td><td>${esc(dala.plc_ip || "—")}</td><td>${dala.plc_port || "—"}</td><td class="dala-status" data-equipment-id="${dala.id}">${renderStatusDalaBinario(status)}</td></tr>`;
        })
        .join("")
    : '<tr><td colspan="5" class="empty-cell">Nenhuma Dala cadastrada.</td></tr>';
  const canManageUsers = ["ADMIN_DALLOGIX", "ADMIN_EMPRESA"].includes(
    store.state.userRole,
  );
  return `<div class="title-row"><div><h2>Configurações</h2></div></div>
${canManageUsers ? `<section class="panel settings-access-panel"><div class="panel-heading"><div><h3>Gerenciar usuários</h3><p>Crie e gerencie os usuários, perfis e acessos da empresa.</p></div>${button("Abrir gerenciamento de usuários", "open-users", "primary")}</div></section><br>` : ""}
<section class="panel"><h3>Comunicação industrial</h3><p>O PC industrial acessa o CLP pela rede local e inicia a sincronização HTTPS com o servidor central. Não é necessário abrir porta pública ou configurar redirecionamento no roteador.</p></section><br>
<section class="panel"><h3>Conectividade</h3><div class="sync-status-row" data-settings-connectivity="pc"><span class="status-dot ${syncTone}" data-settings-connectivity-dot></span><strong data-settings-connectivity-label>${syncLabel}</strong></div><br><div class="sync-status-row" data-settings-connectivity="dalas"><span class="status-dot ${dalaTone}" data-settings-connectivity-dot></span><strong data-settings-connectivity-label>${dalaLabel}</strong></div></section><br>
${deviceStatusPanel(store)}
<section class="panel"><div class="panel-heading"><h3>Dalas</h3><div class="actions">${button("Gerenciar Dalas", "goto-dalas")}</div></div>
<p>Visão consolidada das Dalas cadastradas e do último sinal Modbus recebido do PC industrial. Para cadastrar ou editar, use Gerenciar Dalas.</p>
<p><strong>Identificador:</strong> código único da Dala. <strong>IP do CLP:</strong> endereço na rede local da fábrica. <strong>Porta do CLP:</strong> porta TCP Modbus informada pelo fabricante.</p>
<div class="table-wrap settings-dalas-table-wrap"><table class="settings-dalas-table"><thead><tr><th>Nome da Dala</th><th>Identificador da Dala</th><th>IP do CLP</th><th>Porta do CLP</th><th>Comunicação</th></tr></thead><tbody>${dalaRows}</tbody></table></div></section><br>
<section class="panel pdf-import-panel"><div class="panel-heading pdf-import-heading"><div><h3>Importação de romaneios (PDF)</h3><p class="compact-help">Informe apenas os nomes dos campos que existem no PDF. <b class="required">*</b> Obrigatório.</p></div></div>
<form id="pdf-settings-form"><div class="pdf-import-fields">
${fields.map(([key, label, required]) => `<label>${label}${required ? ' <b class="required">*</b>' : ""}<input name="pdf_${key}" value="${esc(mapping[key] || "")}" placeholder="Nome do campo no PDF" /></label>`).join("")}
</div>
<div class="pdf-import-footer"><label>Buscar produtos por <b class="required">*</b><select name="pdf_search_field"><option value="barcode"${searchField === "barcode" ? " selected" : ""}>Código de barras</option><option value="sku"${searchField === "sku" ? " selected" : ""}>SKU</option></select></label><div class="actions">${button("Salvar parâmetros", "save-pdf-settings")}</div></div></form></section>`;
}
