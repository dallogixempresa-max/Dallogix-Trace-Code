import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
const compose = await readFile(new URL("../docker-compose.yml", import.meta.url), "utf8");
const syncPreparation = await readFile(new URL("../servidor/src/Aplicacao/ServicoPreparacaoCarregamentoSincronizado.php", import.meta.url), "utf8");
const keyenceWorker = await readFile(new URL("../servidor/api/keyence_worker.php", import.meta.url), "utf8");

test("o banco grava carimbos num único relógio fixado em UTC", () => {
  assert.match(compose, /--default-time-zone=\+00:00/);
});

test("a auditoria converte carimbos UTC para o fuso da operação", () => {
  // Sem a conversão, início, fim e legendas das fotos saem adiantados pelo
  // deslocamento do fuso: o relatório mostrava horários no futuro.
  assert.match(reportEndpoint, /new DateTimeImmutable\(\$value, new DateTimeZone\("UTC"\)\)/);
  assert.match(reportEndpoint, /setTimezone\(new DateTimeZone\(date_default_timezone_get\(\)\)\)/);
  assert.doesNotMatch(
    reportEndpoint,
    /\$formatDateTime = static function \(\?string \$value\): string \{[\s\S]*?date\("d\/m\/Y H:i", \$timestamp\)/,
  );
});

test("a data programada continua sem conversão de fuso", () => {
  // scheduled_date é DATE: converter fuso deslocaria o dia do carregamento.
  assert.match(
    reportEndpoint,
    /\$formatDate = static function \(\?string \$value\): string \{[\s\S]*?date\("d\/m\/Y", \$timestamp\)/,
  );
});

test("o caminho de sincronização não mistura relógio local com UTC", () => {
  assert.match(syncPreparation, /"started_at" => \(string\) \(\$data\["started_at"\] \?\? gmdate\("Y-m-d H:i:s"\)\)/);
  assert.doesNotMatch(syncPreparation, /\$data\["started_at"\] \?\? date\("Y-m-d H:i:s"\)/);
});

test("a captura de evidência do Keyence grava no mesmo relógio do banco", () => {
  // NOW(3) e FROM_UNIXTIME() seguem o fuso da sessão MySQL, fixado em UTC acima.
  assert.match(keyenceWorker, /\$capturedAtExpression = \$capturedAtEpochMs === null/);
  assert.match(keyenceWorker, /"NOW\(3\)"/);
  assert.match(keyenceWorker, /FROM_UNIXTIME\(:captured_at_epoch_ms \/ 1000\.0\)/);
});
