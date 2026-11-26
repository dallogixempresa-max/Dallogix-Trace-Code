<?php
declare(strict_types=1);
// Executa a ação STATUS real de keyence_worker.php com SQLite e token simulado.
// Recebe em $argv[1] o payload JSON e em $argv[2] o cenário das passagens.
$payload = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$scenario = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

require_once __DIR__ . '/../../servidor/api/operacoes/temporizacao_leitor.php';
date_default_timezone_set('UTC');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');

$pdo = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$registerFunction = method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$pdo->$registerFunction('UNIX_TIMESTAMP', static fn(string $date): int => strtotime($date));
$pdo->$registerFunction('FROM_UNIXTIME', static fn(float $seconds): string => trace_epoch_ms_to_sql_datetime((int) round($seconds * 1000)));
$pdo->exec('CREATE TABLE carregamentos (id INTEGER, romaneio_id INTEGER, equipment_id INTEGER, company_id INTEGER, state TEXT)');
$pdo->exec('CREATE TABLE romaneios (id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT, modbus_passage_from_epoch_ms INTEGER)');
$pdo->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');
$pdo->exec("INSERT INTO romaneios VALUES (10, 'EM_ANDAMENTO')");
// Estado do carregamento 42 (padrão CARREGANDO); null deixa a Dala sem carregamento.
$loadingState = array_key_exists('loading_state', $scenario) ? $scenario['loading_state'] : 'CARREGANDO';
if ($loadingState !== null) {
    $pdo->prepare("INSERT INTO carregamentos VALUES (42, 10, 1, 7, ?)")->execute([(string) $loadingState]);
}
$insertEvent = $pdo->prepare('INSERT INTO eventos_sensor VALUES (?, 42, 1, ?, ?, NULL)');
foreach ($scenario['events'] ?? [] as $event) {
    $time = trace_epoch_ms_to_sql_datetime((int) $event['detected_at_ms']);
    $insertEvent->execute([(int) $event['id'], $time, $time]);
}
$insertReading = $pdo->prepare('INSERT INTO leituras VALUES (?, ?)');
foreach ($scenario['readings'] ?? [] as $reading) {
    $insertReading->execute([(int) $reading['sensor_event_id'], (string) $reading['result']]);
}

// Passagem que o POST de leituras.php escolheria para a mesma leitura
// (mesma chamada de operacoes/leituras.php, com no_read como critério).
$scanAt = (int) ($payload['scanner_received_at_epoch_ms'] ?? 0);
$postMatch = $scanAt > 0
    ? trace_pending_scanner_event_match($pdo, 42, 1, $scanAt, false, ($payload['no_read'] ?? false) === true)
    : null;

function exigir_metodo_http(array $methods): void {}
function require_device_token(array $types): array
{
    return ['id' => 5, 'equipment_id' => 1, 'company_id' => 7, 'device_type' => 'SCANNER'];
}
function request_json(): array { global $payload; return $payload; }
function db(): PDO { global $pdo; return $pdo; }
function json_response(array $data, int $status = 200): never
{
    global $postMatch;
    echo json_encode([
        'status' => $status,
        'body' => $data,
        'post_sensor_event_id' => $postMatch['best']['sensor_event_id'] ?? null,
    ], JSON_THROW_ON_ERROR);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$code = file_get_contents(__DIR__ . '/../../servidor/api/keyence_worker.php');
$code = preg_replace('/^require_once .*;\s*$/m', '', $code);
$code = preg_replace('/^<\?php\s*/', '', $code);
eval($code);
