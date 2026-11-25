<?php
declare(strict_types=1);

// Ligação sequencial (fila) do código à passagem: só com o contador do CLP.
require __DIR__ . '/../servidor/api/operacoes/vinculo_leitor.php';
date_default_timezone_set('UTC');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');

function banco(): PDO
{
    $db = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
    $fn = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    $db->$fn('UNIX_TIMESTAMP', static fn(string $date): int => strtotime($date));
    $db->$fn('FROM_UNIXTIME', static fn(float $seconds): string => trace_epoch_ms_to_sql_datetime((int) round($seconds * 1000)));
    $db->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT, modbus_passage_from_epoch_ms INTEGER)');
    $db->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');
    $db->exec('CREATE TABLE status_dispositivos (equipment_id INTEGER, device_type TEXT, status TEXT, details TEXT)');
    return $db;
}

function passagem(PDO $db, int $id, int $epochMs): void
{
    $time = trace_epoch_ms_to_sql_datetime($epochMs);
    $db->prepare('INSERT INTO eventos_sensor VALUES (?, 1, 1, ?, ?, NULL)')->execute([$id, $time, $time]);
}

function sensor(PDO $db, string $status, array $details): void
{
    $db->exec('DELETE FROM status_dispositivos');
    $db->prepare("INSERT INTO status_dispositivos VALUES (1, 'SENSOR', ?, ?)")->execute([$status, json_encode($details)]);
}

function confere(bool $ok, string $mensagem): void
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

$base = 1795116546000;
$contador = ['signal_source' => 'COUNTER', 'counter_value' => 10];
$pulso = ['signal_value' => 1, 'signal_mode' => 'rising_edge'];

// Sacas de 0,60 m com 5 cm de vão a 0,7 m/s: passagens a cada ~929 ms. O código
// no fundo da saca 1 é lido ~1.036 ms depois dela, quando a saca 2 já passou
// pelo sensor: as duas cabem na janela.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 929);
$scan = $base + 1036;

putenv('TRACE_SCANNER_LINK_MODE');
sensor($db, 'ONLINE', $contador);
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['ambiguous'] && $match['best'] === null && $match['link_rule'] === 'JANELA', 'Sem configuração a regra deve continuar sendo a janela.');

putenv('TRACE_SCANNER_LINK_MODE=sequencial');
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere(!$match['ambiguous'] && ($match['best']['sensor_event_id'] ?? null) === 1 && $match['link_rule'] === 'SEQUENCIAL', 'A fila deve ligar a leitura à passagem pendente mais antiga.');
confere(count($match['candidates']) === 2, 'As candidatas da janela devem continuar visíveis para auditoria.');

sensor($db, 'ONLINE', $pulso);
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['ambiguous'] && $match['best'] === null, 'Sem contador no CLP a fila não pode agir.');

sensor($db, 'OFFLINE', $contador);
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['ambiguous'] && $match['best'] === null, 'Com o sensor offline a fila não pode agir.');

$db->exec('DELETE FROM status_dispositivos');
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['ambiguous'] && $match['best'] === null, 'Sem heartbeat do sensor a fila não pode agir.');

// Sequência: a saca 1 recebe o código do fundo e a saca 2 o seu, sem troca.
sensor($db, 'ONLINE', $contador);
$db->prepare("INSERT INTO leituras VALUES (?, 'VALIDO')")->execute([1]);
$match = trace_scanner_event_match($db, 1, 1, $base + 929 + 180);
confere(($match['best']['sensor_event_id'] ?? null) === 2 && $match['link_rule'] === 'JANELA', 'Candidata única deve seguir a janela, sem mudança.');

// Passagem que já saiu da janela nunca é candidata, mesmo sendo a mais antiga:
// ela fecha como SEM_LEITURA no prazo normal e a fila se realinha.
$db = banco();
sensor($db, 'ONLINE', $contador);
passagem($db, 1, $base - 3000);
passagem($db, 2, $base);
passagem($db, 3, $base + 929);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere(($match['best']['sensor_event_id'] ?? null) === 2, 'Passagem fora da janela não pode receber a leitura.');

// Nenhuma passagem pendente: continua sem vínculo (a tela espera a passagem).
$db = banco();
sensor($db, 'ONLINE', $contador);
$match = trace_scanner_event_match($db, 1, 1, $base);
confere($match['best'] === null && !$match['ambiguous'], 'Sem passagem pendente não há vínculo.');

putenv('TRACE_SCANNER_LINK_MODE');
echo "Ligação sequencial: 9 verificações passaram.\n";
