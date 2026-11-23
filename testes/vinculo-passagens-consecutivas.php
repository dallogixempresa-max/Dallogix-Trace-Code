<?php
declare(strict_types=1);

require __DIR__ . '/../servidor/api/operacoes/temporizacao_leitor.php';
date_default_timezone_set('UTC');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');

$db = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
$registerFunction = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$db->$registerFunction('UNIX_TIMESTAMP', static fn(string $date): int => strtotime($date));
$db->$registerFunction('FROM_UNIXTIME', static fn(float $seconds): string => trace_epoch_ms_to_sql_datetime((int) round($seconds * 1000)));
$db->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT, modbus_passage_from_epoch_ms INTEGER)');
$db->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');
$base = 1795116546000;
$insert = $db->prepare('INSERT INTO eventos_sensor VALUES (?, 1, 1, ?, ?, NULL)');
foreach ([1 => 0, 2 => 1000, 3 => 2000] as $id => $offset) {
    $time = trace_epoch_ms_to_sql_datetime($base + $offset);
    $insert->execute([$id, $time, $time]);
}
$record = $db->prepare("INSERT INTO leituras VALUES (?, 'VALIDO')");
foreach ([[-3000, null], [-100, null], [179, 1], [1179, 2], [2179, 3], [2179, null]] as [$offset, $expected]) {
    $match = trace_pending_scanner_event_match($db, 1, 1, $base + $offset);
    $actual = $match['best']['sensor_event_id'] ?? null;
    if ($actual !== $expected) {
        throw new RuntimeException("Vínculo inesperado para leitura no deslocamento $offset ms.");
    }
    if ($actual !== null) {
        $record->execute([$actual]);
    }
}

// O leitor pode entregar o código antes da resposta Modbus da mesma passagem.
$db->exec('DELETE FROM leituras; DELETE FROM eventos_sensor');
$insert = $db->prepare('INSERT INTO eventos_sensor VALUES (?, 1, 1, ?, ?, ?)');
$scan = $base + 5000;
$detected = $scan + 100;
$time = trace_epoch_ms_to_sql_datetime($detected);
$insert->execute([10, $time, $time, $scan - 160]);
$match = trace_pending_scanner_event_match($db, 1, 1, $scan);
if (($match['best']['sensor_event_id'] ?? null) !== 10) {
    throw new RuntimeException('Leitura 100 ms antes da resposta deveria identificar a passagem 10.');
}
// O mesmo horário continua rejeitado quando o início do intervalo não existe.
$db->exec('UPDATE eventos_sensor SET modbus_passage_from_epoch_ms = NULL');
$match = trace_pending_scanner_event_match($db, 1, 1, $scan);
if ($match['best'] !== null) {
    throw new RuntimeException('Leitura sem intervalo foi indevidamente associada.');
}

// Duas janelas igualmente compatíveis nunca devem produzir vínculo automático.
$db->exec('DELETE FROM eventos_sensor');
$ambiguousDetected = $scan - 179;
$time = trace_epoch_ms_to_sql_datetime($ambiguousDetected);
$insert->execute([10, $time, $time, null]);
$insert->execute([11, $time, $time, null]);
$match = trace_pending_scanner_event_match($db, 1, 1, $scan);
if (!$match['ambiguous'] || $match['best'] !== null) {
    throw new RuntimeException('Passagens ambíguas foram associadas automaticamente.');
}

// A resposta pode chegar além do limite de busca antigo (+250 ms). Uma
// passagem de outra carga ou Dala com o mesmo horário não causa ambiguidade.
$db->exec('DELETE FROM eventos_sensor');
$detected = $scan + 800;
$time = trace_epoch_ms_to_sql_datetime($detected);
$insert->execute([20, $time, $time, $scan - 200]);
$other = $db->prepare('INSERT INTO eventos_sensor VALUES (?, ?, ?, ?, ?, ?)');
$other->execute([21, 2, 1, $time, $time, $scan - 200]);
$other->execute([22, 1, 2, $time, $time, $scan - 200]);
if ((trace_pending_scanner_event_match($db, 1, 1, $scan)['best']['sensor_event_id'] ?? null) !== 20) {
    throw new RuntimeException('A busca deve alcançar uma resposta atrasada da mesma carga e Dala.');
}

// A passagem 23 é mais próxima pelo horário de resposta, mas ambas comportam a leitura.
$nearTime = trace_epoch_ms_to_sql_datetime($scan - 179);
$insert->execute([23, $nearTime, $nearTime, null]);
$match = trace_pending_scanner_event_match($db, 1, 1, $scan);
if (!$match['ambiguous'] || $match['best'] !== null) {
    throw new RuntimeException('Duas passagens compatíveis não podem receber vínculo por proximidade.');
}
$record->execute([23]);
foreach ([$detected + 1, $detected - 2001] as $invalidStart) {
    $db->prepare('UPDATE eventos_sensor SET modbus_passage_from_epoch_ms = ? WHERE id = 20')->execute([$invalidStart]);
    if (trace_pending_scanner_event_match($db, 1, 1, $scan)['best'] !== null) {
        throw new RuntimeException('Intervalo Modbus inválido ampliou o vínculo.');
    }
}
