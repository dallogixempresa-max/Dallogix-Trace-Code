import { button, esc } from "../../js/funcoes/html.js";
import { numero, relativo } from "../../js/funcoes/formato.js?v=202609201000";
import { rotuloEstado } from "../../js/funcoes/rotulos.js";
import { deviceBadge, pageHeader, physicalStateBadge } from "../../js/funcoes/view.js?v=202610280005";
import { estadoErro, estadoVazio } from "../../js/componentes/estados.js?v=202610060002";

function dalaAlbumCard(equipment, machines, role) {
  const machine =
    machines.find((item) => Number(item.id) === Number(equipment.id)) || {};
  const status = machine.clp_status || "DESCONHECIDO";
  const normalizedStatus = String(status).toUpperCase();
  const statusClass = normalizedStatus === "ONLINE"
    ? "online"
    : ["OFFLINE", "ERRO"].includes(normalizedStatus)
      ? "offline"
      : "unknown";
  const lastSignal = relativo(machine.last_seen_at);
  const state = String(machine.carregamento_state || "AGUARDANDO").toUpperCase();
  const planned = Number(machine.planned_quantity || 0);
  const loaded = Number(machine.valid_readings || 0);
  const percentage =
    planned > 0 ? Math.min(100, Math.round((loaded / planned) * 100)) : 0;
  return `<article class="dala-album-card">
    <header>
      <div>
        <span class="album-label">Dala</span>
        <h3>${esc(equipment.name)}</h3>
        <code>${esc(equipment.equipment_code)}</code>
      </div>
      ${deviceBadge(status)}
    </header>
    <div class="dala-album-info dala-romaneio-info">
      <div><small>Romaneio</small><strong>${machine.romaneio_number ? `#${esc(machine.romaneio_number)}` : "Sem romaneio"}</strong></div>
      <div><small>Caminhão</small><strong>${esc(machine.plate || "—")}</strong></div>
      <div><small>Estado</small><strong>${esc(rotuloEstado(state))}</strong></div>
      <div><small>Estado físico</small><strong>${physicalStateBadge(machine, { compact: true })}</strong></div>
      <div><small>Carregado</small><strong>${numero(loaded)} / ${numero(planned)}</strong></div>
    </div>
    <div class="album-progress"><div class="progress"><i style="width:${percentage}%"></i></div><small>${percentage}% do romaneio</small></div>
    <footer>
      <span data-relative-time="${esc(machine.last_seen_at || "")}"><i class="status-dot ${statusClass}"></i>${esc(lastSignal)}</span>
      <div class="dashboard-card-links"><button class="button secondary small" data-action="view-dala" data-id="${equipment.id}" type="button">Visualizar Dala e estatísticas</button>${machine.romaneio_id ? `<button class="button secondary small" data-action="view-manifest" data-id="${machine.romaneio_id}" type="button">Romaneio</button>` : ""}</div>
    </footer>
  </article>`;
}

function dalaSkeletonCard() {
  return `<article class="dala-album-card dala-album-card-skeleton" aria-hidden="true">
    <header><div><span class="skeleton-line skeleton-label"></span><span class="skeleton-line skeleton-title"></span><span class="skeleton-line skeleton-code"></span></div><span class="skeleton-badge"></span></header>
    <div class="dala-album-info"><div><span class="skeleton-line"></span><span class="skeleton-line skeleton-value"></span></div><div><span class="skeleton-line"></span><span class="skeleton-line skeleton-value"></span></div><div><span class="skeleton-line"></span><span class="skeleton-line skeleton-value"></span></div><div><span class="skeleton-line"></span><span class="skeleton-line skeleton-value"></span></div></div>
    <div class="skeleton-progress"></div><footer><span class="skeleton-line skeleton-footer"></span><span class="skeleton-button"></span></footer>
  </article>`;
}

function dashboardEquipmentPriority(equipment, machines) {
  const machine = machines.find((item) => Number(item.id) === Number(equipment.id)) || {};
  const status = String(machine.clp_status || "").toUpperCase();
  const state = String(machine.carregamento_state || "").toUpperCase();
  const offline = ["OFFLINE", "ERRO"].includes(status);
  const unknown = ["", "NAO_REGISTRADO", "DESCONHECIDO"].includes(status);
  const emergency = state.includes("EMERGEN") || machine.emergency === true;
  const active = Boolean(machine.carregamento_id);
  return (offline ? 100 : unknown ? 50 : 0) + (emergency ? 50 : 0) + (active ? 10 : 0);
}

// Dashboard no padrão da referência TracePlatform: somente informações das Dalas.
export function dashboard(store) {
  const equipments = store.state.equipments || [];
  const machines = store.state.dalaStatuses || [];
  const canManageDalas = store.state.userRole === "ADMIN_EMPRESA";
  const loading = !store.state.equipmentsLoaded;
  const sortedEquipments = [...equipments].sort(
    (left, right) =>
      dashboardEquipmentPriority(right, machines) -
      dashboardEquipmentPriority(left, machines),
  );
  const content = loading
    ? `${dalaSkeletonCard()}${dalaSkeletonCard()}${dalaSkeletonCard()}`
    : store.state.equipmentsError && !equipments.length
      ? estadoErro("Não foi possível carregar o painel", store.state.equipmentsError, { label: "Tentar novamente", acao: "reload-page" })
      : sortedEquipments.length
        ? sortedEquipments
            .map((equipment) => dalaAlbumCard(equipment, machines, store.state.userRole))
            .join("")
        : estadoVazio("Nenhuma Dala cadastrada", "Cadastre uma Dala para acompanhar o estado e o progresso da operação.", canManageDalas ? { label: "Gerenciar Dalas", acao: "goto-dalas", tom: "secondary" } : null);
  return `${pageHeader("Esteiras e romaneios", "Operação por Dala", "Estado e progresso de cada operação em andamento.", canManageDalas ? button("Gerenciar Dalas", "goto-dalas", "secondary") : "")}
  <section class="dashboard-dalas" data-status-region="dashboard-dalas">
    <div class="dala-album-grid ${loading ? "is-loading" : ""}">${content}</div>
  </section>`;
}
