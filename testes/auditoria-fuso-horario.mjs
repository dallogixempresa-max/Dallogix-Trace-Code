import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
const compose = await readFile(new URL("../docker-compose.yml", import.meta.url), "utf8");
const syncPreparation = await readFile(new URL("../servidor/src/Aplicacao/ServicoPreparacaoCarregamentoSincronizado.php", import.meta.url), "utf8");
const keyenceWorker = await readFile(new URL("../servidor/api/keyence_worker.php", import.meta.url), "utf8");
const syncEvents = await readFile(new URL("../servidor/src/Persistencia/RepositorioCarregamentosEventos.php", import.meta.url), "utf8");

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

test("a zero-date do MySQL não vira data absurda no relatório", () => {
  // "0000-00-00 00:00:00" não lança em DateTimeImmutable e sairia como 29/11/-0001.
  assert.match(reportEndpoint, /\(int\) \$moment->format\("Y"\) < 1970/);
});

test("a data programada continua sem conversão de fuso", () => {
  // scheduled_date é DATE: converter fuso deslocaria o dia do carregamento.
  assert.match(
    reportEndpoint,
    /\$formatDate = static function \(\?string \$value\): string \{[\s\S]*?date\("d\/m\/Y", \$timestamp\)/,
  );
});

test("a sincronização não fabrica horário de início nem usa relógio local", () => {
  // Gravar date() aqui misturava fuso local com o UTC de NOW() na mesma linha.
  assert.doesNotMatch(syncPreparation, /\$data\["started_at"\] \?\? date\("Y-m-d H:i:s"\)/);
  // E fabricar "agora" para um carregamento não iniciado fazia o Início da
  // auditoria avançar a cada polling, já que a sincronização reescreve a coluna.
  assert.doesNotMatch(syncPreparation, /started_at.*\?\? gmdate\(/);
  assert.match(
    syncPreparation,
    /"started_at" => isset\(\$data\["started_at"\]\) && \$data\["started_at"\] !== ""\s*\n\s*\? \(string\) \$data\["started_at"\]\s*\n\s*: null,/,
  );
});

test("a sincronização nunca apaga um início já gravado", () => {
  // Quem zera started_at é desvincularDala(), não o ciclo de sincronização.
  assert.match(syncEvents, /started_at = COALESCE\(:started_at, started_at\)/);
  assert.match(syncEvents, /SET equipment_id = NULL, state = 'AGUARDANDO', started_at = NULL/);
});

test("a captura de evidência do Keyence grava no mesmo relógio do banco", () => {
  // NOW(3) e FROM_UNIXTIME() seguem o fuso da sessão MySQL, fixado em UTC acima.
  assert.match(keyenceWorker, /\$capturedAtExpression = \$capturedAtEpochMs === null/);
  assert.match(keyenceWorker, /"NOW\(3\)"/);
  assert.match(keyenceWorker, /FROM_UNIXTIME\(:captured_at_epoch_ms \/ 1000\.0\)/);
});
