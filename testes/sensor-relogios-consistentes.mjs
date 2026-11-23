import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const sensorEvents = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");
const timing = await readFile(new URL("../servidor/api/operacoes/temporizacao_leitor.php", import.meta.url), "utf8");

// eventos_sensor grava dois relógios: detected_at em UTC (NOW(3) com a sessão MySQL
// fixada em +00:00) e device_detected_at no fuso da aplicação. Enquanto cada ramo das
// comparações usa a referência do próprio relógio o resultado é correto; cruzar os dois
// produz um erro silencioso do tamanho do deslocamento do fuso.

test("a conversão de epoch do dispositivo entrega o fuso da aplicação", () => {
  assert.match(timing, /new DateTimeZone\("UTC"\)/);
  assert.match(timing, /setTimezone\(new DateTimeZone\(date_default_timezone_get\(\)\)\)/);
});

test("o debounce compara cada coluna com a referência do seu próprio relógio", () => {
  assert.match(
    sensorEvents,
    /device_detected_at IS NOT NULL AND device_detected_at BETWEEN :device_debounce_start AND :device_debounce_end/,
  );
  assert.match(sensorEvents, /"device_debounce_start" => trace_epoch_ms_to_sql_datetime\(\$debounceStartMs\)/);
  assert.match(sensorEvents, /"device_debounce_end" => trace_epoch_ms_to_sql_datetime\(\$debounceReferenceMs\)/);
  // O ramo sem horário do dispositivo fica inteiro no relógio do banco.
  assert.match(
    sensorEvents,
    /detected_at BETWEEN FROM_UNIXTIME\(:server_debounce_start_ms \/ 1000\.0\)\s*\n\s*AND FROM_UNIXTIME\(:server_debounce_end_ms \/ 1000\.0\)/,
  );
});

test("a expiração de passagens sem leitura mantém a mesma separação", () => {
  assert.match(sensorEvents, /s\.device_detected_at IS NOT NULL AND s\.device_detected_at <= :device_deadline/);
  assert.match(sensorEvents, /"device_deadline" => trace_epoch_ms_to_sql_datetime\(\$deviceDeadlineMs\)/);
  assert.match(sensorEvents, /s\.detected_at <= DATE_SUB\(NOW\(3\), INTERVAL \{\$timeoutMicroseconds\} MICROSECOND\)/);
});

test("a separação entre os dois relógios está documentada no código", () => {
  assert.match(sensorEvents, /eventos_sensor guarda dois relógios diferentes na mesma linha/);
});
