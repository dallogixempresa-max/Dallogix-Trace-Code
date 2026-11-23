<?php
declare(strict_types=1);

require __DIR__ . '/../servidor/api/operacoes/temporizacao_leitor.php';
date_default_timezone_set('UTC');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');

$db = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
$registerFunction = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$db->$registerFunction('FROM_UNIXTIME', static fn(float $seconds): string => trace_epoch_ms_to_sql_datetime((int) round($seconds * 1000)));
$db->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT)');
$db->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');

$now = 1795116546000;
$at = static fn(int $offsetMs): string => trace_epoch_ms_to_sql_datetime($now + $offsetMs);
$insert = $db->prepare('INSERT INTO eventos_sensor VALUES (?, ?, ?, ?, ?)');
// Passagem antiga além do prazo do finalizador: nunca recebe código.
$insert->execute([1, 1, 1, $at(-60000), $at(-60000)]);
// Relógio do gateway 5 s adiantado: não pode impedir o vínculo.
$insert->execute([2, 1, 1, $at(3000), $at(-2000)]);
$insert->execute([3, 1, 1, $at(4000), $at(-1000)]);
// Outra Dala e outro carregamento não entram na fila.
$insert->execute([4, 1, 2, $at(-1500), $at(-1500)]);
$insert->execute([5, 2, 1, $at(-1500), $at(-1500)]);

$record = $db->prepare("INSERT INTO leituras VALUES (?, 'VALIDO')");
foreach ([2, 3, null] as $expected) {
    $actual = trace_oldest_pending_passage($db, 1, 1, $now);
    if ($actual !== $expected) {
        throw new RuntimeException('Vínculo por ordem inesperado: esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
    if ($actual !== null) {
        $record->execute([$actual]);
    }
}

// Uma falha anterior já finalizada como SEM_LEITURA não segura as próximas.
$insert->execute([6, 1, 1, $at(5000), $at(0)]);
$db->exec("INSERT INTO leituras VALUES (6, 'SEM_LEITURA')");
$insert->execute([7, 1, 1, $at(6000), $at(500)]);
if (trace_oldest_pending_passage($db, 1, 1, $now + 600) !== 7) {
    throw new RuntimeException('Passagem seguinte a uma falha deveria receber a leitura.');
}
$record->execute([7]);

// A ordem física segue a ordem de inserção no Trace, mesmo se horários de
// dispositivo/servidor vierem invertidos ou com diferença entre si.
$insert->execute([8, 1, 1, $at(12000), $at(2000)]);
$insert->execute([9, 1, 1, $at(11000), $at(3000)]);
foreach ([8, 9] as $expected) {
    $actual = trace_oldest_pending_passage($db, 1, 1, $now + 4000);
    if ($actual !== $expected) {
        throw new RuntimeException('FIFO não pode depender dos horários: esperado ' . $expected . ', obtido ' . var_export($actual, true));
    }
    $record->execute([$actual]);
}
