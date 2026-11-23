import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
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


test("o intervalo medido resgata uma resposta 100 ms após o código, mas dois vínculos geram ambiguidade", () => {
  const file = new URL("../servidor/api/operacoes/temporizacao_leitor.php", import.meta.url);
  const php = spawnSync("php", ["-r", `
    putenv("TRACE_SENSOR_TO_READER_DISTANCE_M=0.125");
    putenv("TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500");
    require $argv[1];
    $scan = 1795116546300;
    $response = $scan + 100;
    $previousRequest = $scan - 160;
    $modbus = trace_scanner_passage_candidate($scan, $response, $previousRequest);
    $old = trace_scanner_passage_candidate($scan, $response, null);
    $other = trace_scanner_passage_candidate($scan, $scan - 600, null);
    $unique = trace_choose_scanner_passage([["sensor_event_id" => 1, ...$modbus]], 250);
    $ambiguous = trace_choose_scanner_passage([["sensor_event_id" => 1, ...$modbus], ["sensor_event_id" => 2, ...$other]], 250);
    echo json_encode([
      $old === null, $unique["best"]["sensor_event_id"], $ambiguous["ambiguous"], $ambiguous["best"],
      trace_scanner_passage_candidate($scan, $response, $scan - 3000) === null,
      trace_scanner_passage_candidate($scan, $response, $scan + 101) === null,
    ]);
  `, file.pathname], { encoding: "utf8" });
  assert.equal(php.status, 0, php.stderr);
  assert.deepEqual(JSON.parse(php.stdout), [true, 1, true, null, true, true]);
});

test("passagens sucessivas com o mesmo código recuperam após uma leitura sem passagem", () => {
  const file = new URL("../servidor/api/operacoes/temporizacao_leitor.php", import.meta.url);
  const php = spawnSync("php", ["-r", `
    putenv("TRACE_SENSOR_TO_READER_DISTANCE_M=0.125");
    putenv("TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500");
    require $argv[1];
    $pending = [1 => [10000, 9800], 2 => [11100, 10850], 3 => [12200, 11950]];
    $ids = [];
    foreach ([7000, 11000, 12100, 12100] as $scan) {
      $matches = [];
      foreach ($pending as $id => [$end, $start]) {
        $match = trace_scanner_passage_candidate($scan, $end, $start);
        if ($match !== null) $matches[] = ["sensor_event_id" => $id, ...$match];
      }
      $id = trace_choose_scanner_passage($matches, 250)["best"]["sensor_event_id"] ?? null;
      $ids[] = $id;
      unset($pending[$id]);
    }
    echo json_encode($ids);
  `, file.pathname], { encoding: "utf8" });
  assert.equal(php.status, 0, php.stderr);
  assert.deepEqual(JSON.parse(php.stdout), [null, 2, 3, null]);
});
