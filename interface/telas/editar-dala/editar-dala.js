import { dataHora, numero } from "../../js/funcoes/formato.js?v=202609170930";
import { button, esc } from "../../js/funcoes/html.js";
import { rotuloComando, rotuloEstado, rotuloEvento, rotuloStatusComando } from "../../js/funcoes/rotulos.js";
import { pageHeader, physicalStateBadge } from "../../js/funcoes/view.js?v=202610061745";

export function dalaEdit(store) {
  const dala = store.state.equipmentDetail;
  if (!dala)
    return `${pageHeader("Cadastros / Dalas", "Editar Dala", "Carregando…")}`;
  return `<div class="title-row with-actions dala-page-header"><div><h2>Editar Dala ${esc(dala.equipment_code)}</h2></div><div class="actions"><button class="button secondary page-back" data-action="back-dala" type="button">← Voltar</button></div></div>
<form id="dala-edit-form" data-id="${dala.id}">
<section class="panel"><div class="grid one">
<label>Nome da Dala<input name="name" required value="${esc(dala.name)}" /></label>
<label>Identificador<input name="equipment_code" required readonly pattern="[a-z0-9_]{1,30}" title="O identificador não pode ser alterado após o cadastro" value="${esc(dala.equipment_code)}" /><small>Este identificador é usado pelo serviço da Dala e não pode ser alterado após o cadastro.</small></label>
<label>Endereço do CLP (IP ou nome)<input name="plc_ip" required value="${esc(dala.plc_ip || "")}" /></label>
<label>Porta do CLP<input name="plc_port" type="number" min="1" max="65535" required value="${dala.plc_port || ""}" /></label>
<input type="hidden" name="external_port" value="${esc(dala.external_port || "")}" />
</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p><div class="actions">${button("Salvar", "save-dala-edit")}</div></section>
</form>`;
}
