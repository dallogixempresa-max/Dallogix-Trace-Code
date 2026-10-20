import { button, esc } from "../../js/funcoes/html.js";
import { data, numero } from "../../js/funcoes/formato.js?v=202609201000";
import { agora } from "../../js/funcoes/relogio.js?v=202609170015";
import { rotuloComando, rotuloEstado, rotuloStatusComando, rotuloStatusRomaneio } from "../../js/funcoes/rotulos.js?v=202609240001";
import {
  pageHeader,
  manifestsTable,
  emergencyPanel,
  statuses,
} from "../../js/funcoes/view.js?v=202610061745";

export function division(store) {
  const manifest = store.state.manifestDetail;
  if (!manifest)
    return `${pageHeader("Operação / planejamento", "Preparar carregamento", "Carregando romaneio…")}`;
  const trucks = manifest.trucks || [];
  const equipments = store.state.equipments || [];
  const occupiedEquipmentIds = new Set(
    (store.state.activeLoadings || []).map((loading) =>
      Number(loading.equipment_id),
    ),
  );
  const total = (manifest.items || []).reduce(
    (sum, item) => sum + (Number(item.planned_quantity) || 0),
    0,
  );
  const truckOptions = trucks
    .map(
      (truck) =>
        `<option value="${truck.id}">${esc(truck.plate)}${truck.driver_name ? ` • ${esc(truck.driver_name)}` : ""}</option>`,
    )
    .join("");
  const equipmentOptions = equipments
    .map(
      (equipment) => {
        const occupied = occupiedEquipmentIds.has(Number(equipment.id));
        return `<option value="${equipment.id}"${occupied ? " disabled" : ""}>${esc(equipment.name)} • ${esc(equipment.equipment_code)}${occupied ? " — ocupada" : ""}</option>`;
      },
    )
    .join("");
  const availableEquipment = equipments.some(
    (equipment) => !occupiedEquipmentIds.has(Number(equipment.id)),
  );
  return `${pageHeader("Operação / planejamento", "Preparar carregamento", "Defina o caminhão e a Dala antes de liberar a operação.")}
  <section class="panel compact-help"><strong>Regra de segurança</strong><p>Uma Dala e um caminhão não podem ter dois carregamentos ativos. A confirmação abaixo apenas prepara a operação no banco local; o motor continua sob intertravamento do CLP.</p></section><br>
  <div class="grid three"><div class="panel metric"><small>Romaneio</small><strong>${esc(manifest.number)}</strong></div><div class="panel metric"><small>Total programado</small><strong>${numero(total)}</strong></div><div class="panel metric"><small>Caminhões disponíveis</small><strong>${numero(trucks.length)}</strong></div></div><br>
  <form id="prepare-loading-form" class="panel"><div class="grid two"><label>Caminhão<select name="truck_id" required ${truckOptions ? "" : "disabled"}>${truckOptions || "<option>Nenhum caminhão cadastrado</option>"}</select></label><label data-status-region="division-equipment">Dala<select name="equipment_id" required ${availableEquipment ? "" : "disabled"}>${equipmentOptions || "<option>Nenhuma Dala cadastrada</option>"}</select></label></div><div data-status-region="division-availability">${availableEquipment ? "" : '<div class="alert-box" role="alert">Todas as Dalas possuem carregamentos ativos. Finalize ou libere uma operação antes de preparar outra.</div>'}</div><div class="actions" data-status-region="division-actions"><button class="button secondary" data-action="back-manifest" type="button">Cancelar</button>${button("Preparar operação", "prepare-loading", "primary", availableEquipment && truckOptions ? "" : "disabled")}</div></form>
  <section class="panel"><h3>Produtos previstos</h3><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Quantidade</th></tr></thead><tbody>${(manifest.items || []).map((item) => `<tr><td>${esc(item.name)}</td><td>${numero(item.planned_quantity)}</td></tr>`).join("") || '<tr><td colspan="2">Nenhum item informado.</td></tr>'}</tbody></table></div></section>`;
}
