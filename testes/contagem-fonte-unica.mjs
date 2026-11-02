import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const manifestApi = await readFile(new URL("../servidor/api/romaneios/romaneios.php", import.meta.url), "utf8");
const manifestList = await readFile(new URL("../servidor/src/Persistencia/RepositorioRomaneiosLista.php", import.meta.url), "utf8");

test("o contador canônico é incrementado na mesma transação da leitura", () => {
  assert.match(readingsEndpoint, /UPDATE carregamentos SET leituras_validas = leituras_validas \+ 1/);
  // O INSERT da leitura e o incremento precisam estar na mesma transação, senão
  // o contador diverge da tabela e as telas passam a mostrar números diferentes.
  const begin = readingsEndpoint.indexOf("$pdo->beginTransaction();");
  const increment = readingsEndpoint.indexOf("SET leituras_validas = leituras_validas + 1");
  const commit = readingsEndpoint.indexOf("$pdo->commit();");
  assert.ok(begin !== -1 && increment !== -1 && commit !== -1);
  assert.ok(begin < increment && increment < commit);
});

test("o detalhe do romaneio lê o contador canônico com escopo de empresa", () => {
  assert.match(
    manifestApi,
    /SELECT SUM\(c2\.leituras_validas\) FROM carregamentos c2 WHERE c2\.romaneio_id = r\.id AND c2\.company_id = r\.company_id/,
  );
  // A subquery antiga recontava leituras e era a única irmã sem filtro de empresa.
  assert.doesNotMatch(manifestApi, /SELECT COUNT\(\*\) FROM leituras l JOIN carregamentos c2/);
});

test("a listagem de romaneios lê o mesmo contador", () => {
  assert.match(manifestList, /SELECT c\.romaneio_id, SUM\(c\.leituras_validas\) AS loaded_quantity/);
  assert.doesNotMatch(manifestList, /SELECT c\.romaneio_id, COUNT\(\*\) AS loaded_quantity/);
});

test("as sacas detectadas seguem escopadas por empresa", () => {
  assert.match(
    manifestApi,
    /FROM eventos_sensor s JOIN carregamentos c5 ON c5\.id = s\.carregamento_id WHERE c5\.romaneio_id = r\.id AND c5\.company_id = r\.company_id/,
  );
});
