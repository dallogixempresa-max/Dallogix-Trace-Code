import { button, esc } from "../../js/funcoes/html.js";
import { data, numero } from "../../js/funcoes/formato.js?v=202609201000";
import { agora } from "../../js/funcoes/relogio.js?v=202609170015";
import { rotuloComando, rotuloEstado, rotuloStatusComando, rotuloStatusRomaneio } from "../../js/funcoes/rotulos.js?v=202609240001";
import {
  pageHeader,
  manifestsTable,
  emergencyPanel,
  statuses,
} from "../../js/funcoes/view.js?v=202610280005";

import { itemRow, industrialPcDate } from "../../js/funcoes/romaneio.js?v=202610190001";
export function importScreen(store) {
  const products = store.state.products || [];
  const today = industrialPcDate();
  return `<div class="title-row has-back"><button class="button secondary page-back" data-action="goto-manifests" type="button">← Voltar</button><div><h2>Novo romaneio</h2></div></div>
<section class="panel pdf-import-card"><p>Selecione o arquivo PDF do romaneio para preencher os campos automaticamente. Confira os dados e salve.</p>
<form id="pdf-form"><div class="file-picker"><input id="pdf-file" class="file-input" name="file" type="file" accept=".pdf,application/pdf" required /><label class="button primary file-picker-button" for="pdf-file">Escolher arquivo</label><span class="file-name" data-file-name>Nenhum arquivo escolhido</span></div><div class="actions"><button class="button primary" data-action="import-pdf" type="submit">Importar PDF</button></div><div id="pdf-import-feedback" class="import-feedback" role="status" aria-live="polite"></div></form></section><br>
<div class="divider import-manual-divider"><span>ou cadastre manualmente</span></div>
<p>Preencha os dados do romaneio e adicione os itens com produto e quantidade.</p>
<form id="new-manifest-form">
<section class="panel manifest-data-panel"><div class="manifest-form-grid manifest-form-grid-primary">
<label>Código<input name="number" required /></label>
<label>Data do Carregamento<input name="scheduled_date" type="date" min="${today}" value="${today}" required /></label>
<label>Placa do caminhão<input name="plate" required /></label>
</div><div class="manifest-form-grid manifest-form-grid-secondary">
<label>Expedidor<input name="expedidor" /></label>
<label>Motorista<input name="driver_name" /></label>
</div></section><br>
<section class="panel"><div class="panel-heading"><h3>Itens do Romaneio</h3>${button("Adicionar item", "add-item", "secondary")}</div>
<div class="table-wrap"><table id="manifest-items" class="mobile-card-table"><thead><tr><th>Produto</th><th>Quantidade</th><th></th></tr></thead><tbody>${itemRow(products)}</tbody></table></div>
</section>
<div class="manifest-submit-actions">${button("Cadastrar", "submit-manifest")}</div><p class="form-feedback" data-form-feedback role="status" aria-live="polite" hidden></p>
</form>
<details class="panel csv-legacy"><summary>Importar romaneios por CSV</summary><p>Use o modelo CSV. O sistema aceita arquivos separados por vírgula ou ponto e vírgula. Campos obrigatórios: <b>romaneio, data, placa, produto e quantidade</b>. Motorista e expedidor são opcionais. A data pode ser <b>DD/MM/AAAA</b> ou <b>AAAA-MM-DD</b>.</p><p><a class="text-link" href="/assets/modelo-romaneio.csv" download="modelo-romaneio.csv">Baixar modelo CSV</a></p><form id="csv-form"><div class="file-picker"><input id="csv-file" class="file-input" name="file" type="file" accept=".csv,text/csv" required /><label class="button primary file-picker-button" for="csv-file">Escolher arquivo</label><span class="file-name" data-file-name>Nenhum arquivo escolhido</span></div><small>Máximo: 5 MB ou 10.000 linhas. Linhas repetidas do mesmo produto são somadas automaticamente.</small><div class="actions">${button("Importar e validar", "import-csv")}</div><div id="csv-import-feedback" class="import-feedback" role="status" aria-live="polite"></div></form></details>`;
}
