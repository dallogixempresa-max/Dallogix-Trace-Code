import { dataHora, numero } from "../../js/funcoes/formato.js?v=202609170930";
import { button, esc } from "../../js/funcoes/html.js";
import { rotuloComando, rotuloEstado, rotuloEvento, rotuloStatusComando } from "../../js/funcoes/rotulos.js";
import { pageHeader, physicalStateBadge } from "../../js/funcoes/view.js?v=202610280005";
import { estadoCarregando, estadoErro } from "../../js/componentes/estados.js?v=202610060002";

function dalaStatusCell(equipment) {
  return `<div class="dala-status" data-equipment-id="${equipment.id}"><span class="status-dot unknown" aria-hidden="true"></span><span class="dala-status-label unknown" role="status" aria-label="Verificando comunicação do CLP" title="Verificando comunicação do CLP">—</span></div>`;
}

function dalaReferenceActions(equipment, canManage, canDelete) {
  const actions = [
    `<button data-action="view-dala" data-id="${equipment.id}" type="button">Visualizar</button>`,
    canManage ? `<button data-action="edit-dala" data-id="${equipment.id}" type="button">Editar</button>` : "",
  ].filter(Boolean);
  const deleteAction = canDelete
    ? `<button class="dala-action-v2 danger dala-delete-action" data-action="delete-dala" data-id="${equipment.id}" data-name="${esc(equipment.name)}" type="button" aria-label="Excluir Dala ${esc(equipment.name)}" title="Excluir Dala"><svg aria-hidden="true" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h10M6 4V2.75A.75.75 0 0 1 6.75 2h2.5a.75.75 0 0 1 .75.75V4M4.5 4.5l.55 8.1a1 1 0 0 0 1 .93h3.9a1 1 0 0 0 1-.93l.55-8.1M6.5 7v4M9.5 7v4" /></svg>Excluir</button>`
    : "";
  return `<div class="dala-actions-v2">${actions.map((item) => item.replace("<button ", '<button class="dala-action-v2" ')).join("")}${deleteAction}</div>`;
}

export function dalas(store) {
  const rows = store.state.equipments || [];
  const loadError = store.state.equipmentsError && rows.length === 0
    ? store.state.equipmentsError
    : "";
  const loading = !store.state.equipmentsLoaded || store.state.equipmentsLoading;
  const open = store.state.dalaFormOpen;
  const hasIndustrialPc = rows.some((equipment) => "industrial_pc_name" in equipment);
  const canDelete = store.state.userRole === "ADMIN_EMPRESA";
  const canManage = store.state.userRole === "ADMIN_EMPRESA";
  if (open) {
    return `<div class="dala-create-screen"><div class="title-row has-back"><button class="button secondary page-back" data-action="toggle-dala-form" type="button">← Voltar</button><div><h2>Nova Dala</h2><p>Cadastre a comunicação da Dala com o CLP e o gateway.</p></div></div>
<section class="panel dala-create-panel"><form id="dala-create-form"><div class="grid one">
<label>Nome da Dala<input name="name" autocomplete="off" required /></label>
<label>Identificador<input name="equipment_code" autocomplete="off" required pattern="[A-Za-z0-9_-]{1,30}" title="Letras, números, hífen ou underscore (máx. 30)" /><small>Use letras, números, hífen ou underscore. Máximo de 30 caracteres.</small></label>
<label>Endereço do CLP (IP ou nome)<input name="plc_ip" autocomplete="off" required /></label>
<label>Porta do CLP<input name="plc_port" type="number" min="1" max="65535" required /></label>
</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p><div class="actions">${button("Cadastrar Dala", "submit-dala")}</div></form></section></div>`;
  }
  const cells = (equipment) => `<div class="dala-grid-row-v2 ${hasIndustrialPc ? "has-industrial-pc" : ""}" role="row">
<div class="dala-grid-cell-v2" data-label="Nome" role="cell"><strong>${esc(equipment.name)}</strong></div>
<div class="dala-grid-cell-v2" data-label="Identificador" role="cell"><code>${esc(equipment.equipment_code)}</code></div>
${hasIndustrialPc ? `<div class="dala-grid-cell-v2" data-label="PC industrial" role="cell">${equipment.industrial_pc_name ? `<div class="dala-industrial-pc-info"><strong>${esc(equipment.industrial_pc_name)}</strong>${equipment.industrial_pc_status ? `<small>${esc(equipment.industrial_pc_status)}${equipment.industrial_pc_last_seen_at ? ` · ${esc(dataHora(equipment.industrial_pc_last_seen_at))}` : ""}</small>` : ""}</div>` : "<span>Não vinculado</span>"}</div>` : ""}
<div class="dala-grid-cell-v2" data-label="IP do CLP" role="cell">${esc(equipment.plc_ip || "—")}</div>
<div class="dala-grid-cell-v2" data-label="Porta do CLP" role="cell">${esc(equipment.plc_port || "—")}</div>
<div class="dala-grid-cell-v2" data-label="Status" role="cell">${dalaStatusCell(equipment)}</div>
<div class="dala-grid-cell-v2" data-label="Ações" role="cell">${dalaReferenceActions(equipment, canManage, canDelete)}</div>
</div>`;
  const content = loading
    ? estadoCarregando("Carregando Dalas…", { className: "dala-loading" })
    : loadError
    ? estadoErro("Não foi possível carregar as Dalas", loadError, { label: "Tentar novamente", acao: "reload-dalas" }, { className: "dala-load-error" })
    : `<div class="dalas-grid-v2 ${hasIndustrialPc ? "has-industrial-pc" : ""}" role="table"><div class="dala-grid-head-v2 ${hasIndustrialPc ? "has-industrial-pc" : ""}" role="row">${["Nome", "Identificador", ...(hasIndustrialPc ? ["PC industrial"] : []), "IP do CLP", "Porta do CLP", "Status", "Ações"].map((label) => `<div role="columnheader">${label}</div>`).join("")}</div>${rows.length ? rows.map(cells).join("") : '<div class="dala-empty-v2" role="row"><div role="cell">Nenhuma Dala cadastrada.</div></div>'}</div>`;
  return `<section class="dalas-screen-v2" aria-labelledby="dalas-title"><div class="dalas-header-v2"><h2 id="dalas-title">Dalas</h2>${canManage ? button("Nova dala", "toggle-dala-form", "primary") : ""}</div><div data-status-region="dala-list">${content}</div></section>`;
}

// Tela Visualizar Dala: cartões com dados cadastrais e status de comunicação.
