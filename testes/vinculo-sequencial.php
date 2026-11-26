<?php
declare(strict_types=1);

// Ligação sequencial (fila) do código à passagem, no modo pulso do M2052 (sem contador no CLP).
require __DIR__ . '/../servidor/api/operacoes/vinculo_leitor.php';
date_default_timezone_set('UTC');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');
putenv('TRACE_SCANNER_LINK_MODE');

function banco(): PDO
{
    $db = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
    $fn = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    // Com milissegundos, como o MySQL faz com DATETIME(3).
    $db->$fn('UNIX_TIMESTAMP', static fn(string $date): float => (float) (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('U.u'));
    $db->$fn('FROM_UNIXTIME', static fn(float $seconds): string => trace_epoch_ms_to_sql_datetime((int) round($seconds * 1000)));
    $db->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT, modbus_passage_from_epoch_ms INTEGER)');
    $db->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');
    return $db;
}

/** Passagem detectada em $epochMs; chega ao servidor $atrasoMs depois; $precisa = com intervalo Modbus. */
function passagem(PDO $db, int $id, int $epochMs, int $atrasoMs = 30, bool $precisa = true): void
{
    $db->prepare('INSERT INTO eventos_sensor VALUES (?, 1, 1, ?, ?, ?)')->execute([
        $id,
        trace_epoch_ms_to_sql_datetime($epochMs),
        trace_epoch_ms_to_sql_datetime($epochMs + $atrasoMs),
        $precisa ? $epochMs - 20 : null,
    ]);
}

function leitura(PDO $db, int $passagem, string $resultado = 'VALIDO'): void
{
    $db->prepare('INSERT INTO leituras VALUES (?, ?)')->execute([$passagem, $resultado]);
}

$verificacoes = 0;
function confere(bool $ok, string $mensagem): void
{
    global $verificacoes;
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
    $verificacoes++;
}

$base = 1795116546000;

// Sacas de 0,60 m com 5 cm de vão a 0,7 m/s: passagens a cada ~929 ms. O código
// no fundo da saca 1 é lido ~1.036 ms depois dela, quando a saca 2 já passou
// pelo sensor: as duas cabem na janela.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 929);
$scan = $base + 1036;

// Sem configuração, a regra é a fila, no modo pulso (sem contador nem heartbeat do sensor).
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere(!$match['ambiguous'] && ($match['best']['sensor_event_id'] ?? null) === 1 && $match['link_rule'] === 'SEQUENCIAL', 'Sem configuração, a fila deve ligar a leitura à passagem pendente mais antiga.');
confere(count($match['candidates']) === 2, 'As candidatas da janela devem continuar visíveis para auditoria.');

// TRACE_SCANNER_LINK_MODE=janela volta à regra antiga, que recusa como ambígua.
putenv('TRACE_SCANNER_LINK_MODE=janela');
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['ambiguous'] && $match['best'] === null && $match['link_rule'] === 'JANELA', 'Com janela configurada, a leitura em duas passagens continua ambígua.');
putenv('TRACE_SCANNER_LINK_MODE=qualquer-outro-valor');
$match = trace_scanner_event_match($db, 1, 1, $scan);
confere($match['link_rule'] === 'SEQUENCIAL', 'Valor desconhecido mantém a fila.');
putenv('TRACE_SCANNER_LINK_MODE');

// Sequência: a saca 1 recebe o código do fundo e a saca 2 o seu, sem troca.
leitura($db, 1);
$match = trace_scanner_event_match($db, 1, 1, $base + 929 + 180);
confere(($match['best']['sensor_event_id'] ?? null) === 2 && $match['link_rule'] === 'JANELA', 'Candidata única deve seguir a janela, sem mudança.');

// Carregamento 149 (26/11/2026, vídeo da fábrica): grupos de três sacas com
// ~505 ms entre passagens e o código chegando ~1.354 ms depois da própria
// passagem. Hoje as três caem na janela e tudo era recusado como ambíguo; com a
// fila cada código vai para a sua saca, inclusive com um ERROR no meio.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 505);
passagem($db, 3, $base + 1010);
$codigo1 = trace_scanner_event_match($db, 1, 1, $base + 1354);
confere(count($codigo1['candidates']) === 3 && ($codigo1['best']['sensor_event_id'] ?? null) === 1, 'Com três sacas na janela, o 1º código vai para a 1ª saca.');
leitura($db, 1);
$erro2 = trace_scanner_event_match($db, 1, 1, $base + 505 + 1540, false, true);
confere(($erro2['best']['sensor_event_id'] ?? null) === 2, 'O ERROR da 2ª saca vai para a 2ª saca.');
leitura($db, 2, 'SEM_LEITURA');
$codigo3 = trace_scanner_event_match($db, 1, 1, $base + 1010 + 1354);
confere(($codigo3['best']['sensor_event_id'] ?? null) === 3 && !$codigo3['ambiguous'], 'O 3º código vai para a 3ª saca.');

// Mesmo carregamento, com o maior atraso medido (1.543 ms) e o menor vão (401 ms).
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 401);
passagem($db, 3, $base + 802);
foreach ([1, 2, 3] as $saca) {
    $match = trace_scanner_event_match($db, 1, 1, $base + ($saca - 1) * 401 + 1543);
    confere(($match['best']['sensor_event_id'] ?? null) === $saca && !$match['ambiguous'], "Com atraso de 1.543 ms, o código da saca {$saca} vai para ela.");
    leitura($db, $saca);
}

// Passagem que já saiu da janela nunca é candidata, mesmo sendo a mais antiga.
$db = banco();
passagem($db, 1, $base - 3000);
passagem($db, 2, $base);
passagem($db, 3, $base + 929);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere(($match['best']['sensor_event_id'] ?? null) === 2, 'Passagem fora da janela não pode receber a leitura.');

// Nenhuma passagem pendente: continua sem vínculo (a tela espera a passagem).
$db = banco();
$match = trace_scanner_event_match($db, 1, 1, $base);
confere($match['best'] === null && !$match['ambiguous'], 'Sem passagem pendente não há vínculo.');

// Defeito 1: um reenvio do Node-RED insere a saca k (id 12) depois da saca
// k+1 (id 11). A fila segue a hora da detecção, não o id.
$db = banco();
passagem($db, 11, $base + 929, 30);
passagem($db, 12, $base, 900);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere(($match['best']['sensor_event_id'] ?? null) === 12 && $match['link_rule'] === 'SEQUENCIAL', 'A fila deve seguir a hora em que o sensor viu a saca, não a ordem de inserção.');

// Defeito 2: com ERROR (sem código) a janela inclui passagens já fechadas como
// SEM_LEITURA. O ERROR da saca 2 não pode ir para a saca 1, já fechada.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 929);
leitura($db, 1, 'SEM_LEITURA');
$erroDaSaca2 = trace_scanner_event_match($db, 1, 1, $base + 929 + 750, false, true);
confere(($erroDaSaca2['best']['sensor_event_id'] ?? null) === 2, 'O ERROR da saca 2 deve ir para a saca 2, não para a saca 1 já fechada.');
$statusDoWorker = trace_scanner_event_match($db, 1, 1, $base + 929 + 750);
confere(($statusDoWorker['best']['sensor_event_id'] ?? null) === 2, 'Tela e worker do leitor devem apontar a mesma passagem.');

// Defeito 3a: passagens sem o intervalo Modbus da detecção não são
// confiáveis para a fila: volta para a janela.
$db = banco();
passagem($db, 1, $base, 30, false);
passagem($db, 2, $base + 929, 30, false);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere($match['ambiguous'] && $match['best'] === null && $match['link_rule'] === 'JANELA', 'Passagem sem intervalo Modbus não pode entrar na fila.');

// Defeito 3b: passagem que chegou ao servidor muito depois da detecção pode
// ter perdido o próprio código: a fila não age.
$db = banco();
passagem($db, 1, $base, 1400);
passagem($db, 2, $base + 929, 30);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere($match['ambiguous'] && $match['best'] === null && $match['link_rule'] === 'JANELA', 'Passagem atrasada não pode entrar na fila.');

// Atraso dentro do limite continua valendo para a fila.
$db = banco();
passagem($db, 1, $base, 900);
passagem($db, 2, $base + 929, 30);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere(($match['best']['sensor_event_id'] ?? null) === 1 && $match['link_rule'] === 'SEQUENCIAL', 'Atraso dentro do limite não deve desligar a fila.');

echo "Ligação sequencial: {$verificacoes} verificações passaram.\n";
