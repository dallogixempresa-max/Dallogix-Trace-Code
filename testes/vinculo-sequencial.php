<?php
declare(strict_types=1);

// Ligação do resultado do leitor à passagem pela ORDEM, sem tempo (modo pulso do M2052, sem contador).
require __DIR__ . '/../servidor/api/operacoes/vinculo_leitor.php';
date_default_timezone_set('America/Sao_Paulo');
putenv('TRACE_SENSOR_TO_READER_DISTANCE_M=0.125');
putenv('TRACE_SCANNER_NO_READ_TIMEOUT_MS=1500');
putenv('TRACE_SCANNER_LINK_MODE');

function banco(): PDO
{
    $db = class_exists(PDO\Sqlite::class) ? new PDO\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
    $fn = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    // detected_at vem do NOW() do MySQL, em UTC, com milissegundos.
    $db->$fn('UNIX_TIMESTAMP', static fn(string $date): float => (float) (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('U.u'));
    $db->$fn('FROM_UNIXTIME', static fn(float $seconds): string => (new DateTimeImmutable('@' . sprintf('%.3F', $seconds)))->format('Y-m-d H:i:s.v'));
    $db->exec('CREATE TABLE eventos_sensor (id INTEGER, carregamento_id INTEGER, equipment_id INTEGER, device_detected_at TEXT, detected_at TEXT, modbus_passage_from_epoch_ms INTEGER)');
    $db->exec('CREATE TABLE leituras (sensor_event_id INTEGER UNIQUE, result TEXT)');
    return $db;
}

/** Passagem vista pelo sensor em $epochMs (gravada no fuso do PHP); chega ao servidor $atrasoMs depois (UTC). */
function passagem(PDO $db, int $id, int $epochMs, int $atrasoMs = 30, bool $horaDoGateway = true): void
{
    $servidor = (new DateTimeImmutable('@' . sprintf('%.3F', ($epochMs + $atrasoMs) / 1000)))->format('Y-m-d H:i:s.v');
    $db->prepare('INSERT INTO eventos_sensor VALUES (?, 1, 1, ?, ?, ?)')->execute([
        $id,
        $horaDoGateway ? trace_epoch_ms_to_sql_datetime($epochMs) : null,
        $servidor,
        $horaDoGateway ? $epochMs - 20 : null,
    ]);
}

function leitura(PDO $db, int $passagem, string $resultado = 'VALIDO'): void
{
    $db->prepare('INSERT INTO leituras VALUES (?, ?)')->execute([$passagem, $resultado]);
}

function vinculo(PDO $db, int $scanMs, bool $erro = false): ?int
{
    $match = trace_scanner_event_match($db, 1, 1, $scanMs, false, $erro);
    if ($match['ambiguous']) {
        throw new RuntimeException('A fila nunca deve recusar como ambígua.');
    }
    return $match['best']['sensor_event_id'] ?? null;
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

// Sem configuração a regra é a fila, sem janela: duas sacas a ~929 ms e o
// código da 1ª chegando quando a 2ª já passou.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 929);
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere(($match['best']['sensor_event_id'] ?? null) === 1 && !$match['ambiguous'] && $match['link_rule'] === 'SEQUENCIAL', 'Sem configuração, o código vai para a passagem em aberto mais antiga.');
confere(array_column($match['candidates'], 'sensor_event_id') === [1, 2], 'As passagens em aberto ficam visíveis em ordem.');

// TRACE_SCANNER_LINK_MODE=janela volta à regra antiga, que recusa como ambígua.
putenv('TRACE_SCANNER_LINK_MODE=janela');
$match = trace_scanner_event_match($db, 1, 1, $base + 1036);
confere($match['ambiguous'] && $match['best'] === null && $match['link_rule'] === 'JANELA', 'Com janela, a leitura em duas passagens continua ambígua.');
putenv('TRACE_SCANNER_LINK_MODE=qualquer-outro-valor');
confere(vinculo($db, $base + 1036) === 1, 'Valor desconhecido mantém a fila.');
putenv('TRACE_SCANNER_LINK_MODE');

// Geometria da fábrica: sacas de ~0,60 m com 10 cm livres a 0,7 m/s → 0,14 s
// livres e ~1.000 ms de uma passagem à seguinte. O resultado chega 0,9–2,3 s
// depois da própria passagem; uma saca dá ERROR. Cada saca recebe o seu.
$db = banco();
$atrasos = [1 => 900, 2 => 1500, 3 => 1680, 4 => 2300, 5 => 1100, 6 => 2200];
foreach ($atrasos as $saca => $_) {
    passagem($db, $saca, $base + ($saca - 1) * 1000);
}
foreach ($atrasos as $saca => $atraso) {
    $erro = $saca === 4;
    confere(vinculo($db, $base + ($saca - 1) * 1000 + $atraso, $erro) === $saca, "Saca {$saca} (atraso {$atraso} ms" . ($erro ? ', ERROR' : '') . ') recebe o próprio resultado.');
    leitura($db, $saca, $erro ? 'SEM_LEITURA' : 'VALIDO');
}

// Carregamento 149 (vídeo de 26/11): três sacas a ~505 ms, código ~1.354 ms
// depois da própria passagem e um ERROR no meio. Antes tudo era recusado.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 505);
passagem($db, 3, $base + 1010);
confere(vinculo($db, $base + 1354) === 1, 'O 1º código vai para a 1ª saca.');
leitura($db, 1);
confere(vinculo($db, $base + 505 + 1540, true) === 2, 'O ERROR da 2ª saca vai para a 2ª saca.');
leitura($db, 2, 'SEM_LEITURA');
confere(vinculo($db, $base + 1010 + 1354) === 3, 'O 3º código vai para a 3ª saca.');

// Sem tempo: um resultado muito atrasado continua indo para a própria saca,
// enquanto ela não for fechada pelo prazo de segurança.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 1000);
passagem($db, 3, $base + 2000);
confere(vinculo($db, $base + 3500) === 1, 'Resultado atrasado vai para a própria saca.');

// Causa: a saca precisa ter passado pelo sensor antes do resultado. Código que
// chega antes da passagem não é ligado (a tela espera a passagem chegar).
$db = banco();
passagem($db, 1, $base + 1000);
confere(vinculo($db, $base + 900) === null, 'Passagem posterior ao resultado não recebe o código.');
confere(vinculo($db, $base + 1000) === 1, 'Com a passagem registrada, o código é ligado na hora.');

// Nenhuma passagem em aberto: sem vínculo.
$db = banco();
confere(vinculo($db, $base) === null, 'Sem passagem em aberto não há vínculo.');

// Um reenvio do Node-RED insere a saca k (id 12) depois da saca k+1 (id 11):
// a fila segue a hora em que o sensor viu a saca, não a ordem de inserção.
$db = banco();
passagem($db, 11, $base + 929, 30);
passagem($db, 12, $base, 900);
confere(vinculo($db, $base + 1036) === 12, 'A fila segue a hora do sensor, não o id.');

// Passagem já fechada como SEM_LEITURA não recebe o resultado da saca seguinte,
// nem quando o resultado é um ERROR.
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 929);
leitura($db, 1, 'SEM_LEITURA');
confere(vinculo($db, $base + 929 + 750, true) === 2, 'O ERROR da saca 2 vai para a saca 2, não para a 1 já fechada.');
confere(vinculo($db, $base + 929 + 750) === 2, 'O código da saca 2 vai para a saca 2.');

// Passagem sem a hora do gateway usa a hora do servidor (UTC) na mesma ordem.
$db = banco();
passagem($db, 1, $base, 40, false);
passagem($db, 2, $base + 1000, 40, true);
confere(vinculo($db, $base + 1500) === 1, 'Passagem só com a hora do servidor entra na ordem certa.');

// Requisito documentado: um resultado perdido desloca o código seguinte
// para a saca anterior (a contagem por produto não muda).
$db = banco();
passagem($db, 1, $base);
passagem($db, 2, $base + 1000);
confere(vinculo($db, $base + 1000 + 1300) === 1, 'Sem o resultado da saca 1, o código seguinte vai para ela (requisito: um resultado por saca).');

echo "Ligação sequencial: {$verificacoes} verificações passaram.\n";
