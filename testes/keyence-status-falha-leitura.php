<?php
declare(strict_types=1);
// STATUS do worker Keyence com falha de leitura (ERROR): escolhe a mesma
// passagem que o POST de leituras.php, inclusive a já finalizada como
// SEM_LEITURA. Antes, em duas falhas seguidas, o STATUS indicava outra
// passagem, o POST respondia 409 e o worker descartava o ERROR.

function status_keyence(array $payload, array $scenario): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/suporte/keyence-worker-status.php')
        . ' ' . escapeshellarg(json_encode(['action' => 'STATUS'] + $payload, JSON_THROW_ON_ERROR))
        . ' ' . escapeshellarg(json_encode($scenario, JSON_THROW_ON_ERROR)) . ' 2>&1';
    $output = shell_exec($command);
    $result = json_decode((string) $output, true);
    if (!is_array($result)) {
        throw new RuntimeException('STATUS não respondeu JSON: ' . $output);
    }
    return $result;
}

function confere(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$base = (int) round(microtime(true) * 1000) - 20000;
$travelMs = 179; // 0,125 m a 0,7 m/s

// Uma passagem já finalizada como SEM_LEITURA pelo prazo; o ERROR chega depois.
$finalizada = [
    'events' => [['id' => 1, 'detected_at_ms' => $base]],
    'readings' => [['sensor_event_id' => 1, 'result' => 'SEM_LEITURA']],
];
$scan = $base + $travelMs + 600;
$erro = status_keyence(['scanner_received_at_epoch_ms' => $scan, 'no_read' => true], $finalizada);
confere($erro['status'] === 200, 'STATUS com no_read deveria responder 200.');
confere($erro['body']['data']['pending_sensor_event_id'] === 1, 'ERROR deveria receber a passagem finalizada como SEM_LEITURA.');
confere($erro['body']['data']['pending_sensor_event_ids'] === [1], 'Lista de passagens do STATUS inconsistente.');
confere($erro['post_sensor_event_id'] === 1, 'POST de leituras.php deveria escolher a mesma passagem.');

// Código de produto (sem no_read, como o worker antigo envia) não ocupa passagem resolvida.
foreach ([[], ['no_read' => false]] as $payload) {
    $codigo = status_keyence(['scanner_received_at_epoch_ms' => $scan] + $payload, $finalizada);
    confere($codigo['status'] === 200, 'STATUS sem no_read deveria responder 200.');
    confere($codigo['body']['data']['pending_sensor_event_id'] === null, 'Código não pode ir para passagem já resolvida.');
    confere($codigo['post_sensor_event_id'] === null, 'POST de código deveria concordar com o STATUS.');
}

// Duas falhas seguidas com janelas sobrepostas: a segunda é ambígua para o
// POST (passagem finalizada + pendente); o STATUS não indica a pendente.
$seguidas = [
    'events' => [['id' => 1, 'detected_at_ms' => $base], ['id' => 2, 'detected_at_ms' => $base + 400]],
    'readings' => [['sensor_event_id' => 1, 'result' => 'SEM_LEITURA']],
];
$segundaFalha = $base + 400 + $travelMs;
$erro = status_keyence(['scanner_received_at_epoch_ms' => $segundaFalha, 'no_read' => true], $seguidas);
confere($erro['body']['data']['pending_sensor_event_id'] === $erro['post_sensor_event_id'], 'STATUS e POST divergiram na segunda falha seguida.');
confere($erro['body']['data']['pending_sensor_event_id'] === null, 'Falha ambígua não pode ser associada automaticamente.');
$codigo = status_keyence(['scanner_received_at_epoch_ms' => $segundaFalha], $seguidas);
confere($codigo['body']['data']['pending_sensor_event_id'] === 2, 'Código de produto mantém a passagem pendente como antes.');
confere($codigo['post_sensor_event_id'] === 2, 'POST de código deveria concordar com o STATUS.');

// Indicador inválido é recusado, como em leituras.php.
$invalido = status_keyence(['scanner_received_at_epoch_ms' => $scan, 'no_read' => 'sim'], $finalizada);
confere($invalido['status'] === 422, 'no_read que não é booleano deveria responder 422.');

// active_loading_state: estado do carregamento escolhido, ou null sem carregamento.
// O worker não soma ao contador da tela o código recebido fora de CARREGANDO.
confere($codigo['body']['data']['active_loading_state'] === 'CARREGANDO', 'STATUS deveria informar o estado CARREGANDO.');
confere($codigo['body']['data']['active_loading_id'] === 42, 'STATUS deveria informar o carregamento escolhido.');
foreach (['PAUSADO', 'FINALIZANDO'] as $estado) {
    $parado = status_keyence(['scanner_received_at_epoch_ms' => $segundaFalha], ['loading_state' => $estado] + $seguidas);
    confere($parado['status'] === 200, "STATUS com carregamento {$estado} deveria responder 200.");
    confere($parado['body']['data']['active_loading_state'] === $estado, "STATUS deveria informar o estado {$estado}.");
    confere($parado['body']['data']['active_loading_id'] === 42, "Carregamento {$estado} continua escolhido.");
}
// EMERGENCIA não é escolhido pelo STATUS (regra anterior): sem carregamento, estado null.
$emergencia = status_keyence(['scanner_received_at_epoch_ms' => $segundaFalha], ['loading_state' => 'EMERGENCIA'] + $seguidas);
confere($emergencia['body']['data']['active_loading_id'] === null, 'Carregamento em EMERGENCIA não é escolhido.');
confere($emergencia['body']['data']['active_loading_state'] === null, 'Sem carregamento escolhido, active_loading_state é null.');
// FINALIZADO com passagem pendente continua escolhido e informa o estado.
$finalizado = status_keyence(['scanner_received_at_epoch_ms' => $segundaFalha], ['loading_state' => 'FINALIZADO'] + $seguidas);
confere($finalizado['body']['data']['active_loading_id'] === 42, 'FINALIZADO com passagem pendente deveria ser escolhido.');
confere($finalizado['body']['data']['active_loading_state'] === 'FINALIZADO', 'STATUS deveria informar FINALIZADO.');
$semCarregamento = status_keyence(['scanner_received_at_epoch_ms' => $scan], ['loading_state' => null]);
confere($semCarregamento['status'] === 200, 'STATUS sem carregamento deveria responder 200.');
confere($semCarregamento['body']['data']['active_loading_id'] === null, 'Sem carregamento, active_loading_id é null.');
confere(array_key_exists('active_loading_state', $semCarregamento['body']['data'])
    && $semCarregamento['body']['data']['active_loading_state'] === null, 'Sem carregamento, active_loading_state é null.');

echo "STATUS do worker usa no_read como o POST de leituras, escolhe a mesma passagem e informa o estado do carregamento.\n";
