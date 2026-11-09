import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { readFileSync } from "node:fs";
import test from "node:test";

const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const manifestApi = await readFile(new URL("../servidor/api/romaneios/romaneios.php", import.meta.url), "utf8");
const manifestDetailQuery = manifestApi.match(/\$header = \$pdo->prepare\(\s*"([\s\S]*?)"\s*,/)?.[1] || "";
const manifestList = await readFile(new URL("../servidor/src/Persistencia/RepositorioRomaneiosLista.php", import.meta.url), "utf8");
const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
const finishEndpoint = await readFile(new URL("../servidor/api/operacoes/encerrar_carregamento.php", import.meta.url), "utf8");

// carregamentos.leituras_validas é um contador POR CARREGAMENTO. O relatório de
// auditoria e o encerramento precisam do mesmo número por produto e por resultado,
// granularidades que o contador não atende. Por isso as telas de romaneio contam
// leituras VALIDO como o PDF: trocar só um dos lados pelo contador faria o total da
// tela deixar de bater com a soma da coluna "Movido" do documento de auditoria.

test("o contador por carregamento é incrementado na mesma transação da leitura", () => {
  assert.match(readingsEndpoint, /UPDATE carregamentos SET leituras_validas = leituras_validas \+ 1/);
  const begin = readingsEndpoint.indexOf("$pdo->beginTransaction();");
  const increment = readingsEndpoint.indexOf("SET leituras_validas = leituras_validas + 1");
  const commit = readingsEndpoint.indexOf("$pdo->commit();");
  assert.ok(begin !== -1 && increment !== -1 && commit !== -1);
  assert.ok(begin < increment && increment < commit);
});

test("telas contam só leituras VALIDO como lidas; sem leitura fica fora", () => {
  // Sacas que passaram pelo sensor sem código não são lidas. Se a tela voltar a
  // contar SEM_LEITURA como lida, o operador vê saca sem código como leitura.
  assert.match(manifestDetailQuery, /FROM leituras l\s+JOIN carregamentos c2 ON c2\.id = l\.carregamento_id[\s\S]{0,400}?l\.result = 'VALIDO'/);
  assert.match(manifestList, /FROM leituras l\s+JOIN carregamentos c ON c\.id = l\.carregamento_id[\s\S]{0,400}?l\.result = 'VALIDO'/);
  assert.doesNotMatch(manifestDetailQuery, /l\.result[^;\n]*SEM_LEITURA/);
  assert.doesNotMatch(manifestList, /l\.result[^;\n]*SEM_LEITURA/);
  assert.doesNotMatch(manifestApi, /SUM\(c2\.leituras_validas\)/);
  assert.doesNotMatch(manifestList, /SUM\(c\.leituras_validas\) AS loaded_quantity/);
});

test("relatório operacional conta só lidas; sem leitura vem na coluna própria", async () => {
  const operacional = await readFile(new URL("../servidor/api/relatorios/relatorio_operacional.php", import.meta.url), "utf8");
  assert.match(operacional, /SUM\(l\.result = .VALIDO.\) AS loaded_quantity/);
  assert.match(operacional, /SUM\(l\.result = .SEM_LEITURA. AND NOT EXISTS \(SELECT 1 FROM retornos rt WHERE rt\.leitura_id = l\.id\)\) AS no_readings/);
});

test("retirada de passagem sem código entra por retornos e sai da contagem sem leitura", () => {
  const retornos = readFileSync(new URL("../servidor/api/operacoes/retornos.php", import.meta.url), "utf8");
  const monitoramento = readFileSync(new URL("../servidor/src/Persistencia/RepositorioMonitoramento.php", import.meta.url), "utf8");
  assert.match(retornos, /l\.result = .VALIDO. OR \(l\.result = .SEM_LEITURA. AND l\.sensor_event_id IS NOT NULL\)/);
  assert.equal((monitoramento.match(/NOT EXISTS \(SELECT 1 FROM retornos r WHERE r\.leitura_id = l\.id\)/g) || []).length, 2);

});

test("PDF de auditoria: Movido conta só lidas e Sem leitura é coluna própria", () => {
  assert.match(reportEndpoint, /AND l\.result = .VALIDO. AND l\.product_id = ri\.product_id/);
  assert.match(reportEndpoint, /"Sem leitura"/);
  assert.match(reportEndpoint, /AS no_read_quantity/);
});

test("card de produto na operação separa lidas de sem leitura", async () => {
  const monitoramento = await readFile(new URL("../servidor/src/Persistencia/RepositorioMonitoramento.php", import.meta.url), "utf8");
  assert.match(monitoramento, /leituras_validas AS \([\s\S]{0,300}?l\.result = 'VALIDO'/);
  assert.match(monitoramento, /leituras_sem_leitura AS \([\s\S]{0,300}?l\.result = 'SEM_LEITURA' AND l\.product_id IS NOT NULL/);
  assert.match(monitoramento, /"no_read_quantity" => \(int\) \$item\["no_read_quantity"\]/);
});

test("auditoria e encerramento contam por produto, o que o contador não permite", () => {
  // Esta é a razão de não unificar no contador: ambos filtram por product_id.
  assert.match(reportEndpoint, /l\.product_id = ri\.product_id/);
  assert.match(finishEndpoint, /l\.product_id = ri\.product_id/);
});

test("a contagem do detalhe do romaneio é escopada por empresa", () => {
  // Esta subquery era a única irmã sem o filtro, o que também impedia o uso do
  // índice idx_reading_company_loading_result_product.
  assert.match(
    manifestApi,
    /JOIN carregamentos c2 ON c2\.id = l\.carregamento_id\s+WHERE c2\.romaneio_id = r\.id AND c2\.company_id = r\.company_id/,
  );
});

test("as sacas detectadas seguem escopadas por empresa", () => {
  assert.match(
    manifestApi,
    /FROM eventos_sensor s JOIN carregamentos c5 ON c5\.id = s\.carregamento_id WHERE c5\.romaneio_id = r\.id AND c5\.company_id = r\.company_id/,
  );
});
