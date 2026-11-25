<?php
declare(strict_types=1);

// A velocidade da esteira e a tolerância antes da chegada vêm da configuração do
// cliente; o padrão continua sendo a esteira medida (0,7 m/s e 250 ms).
require __DIR__ . '/../servidor/api/operacoes/temporizacao_leitor.php';

function timing_in_child(array $env): array
{
    $code = 'require ' . var_export(__DIR__ . '/../servidor/api/operacoes/temporizacao_leitor.php', true) . ';'
        . 'try { echo json_encode(trace_scanner_timing()); } catch (Throwable $e) { echo json_encode(["error" => $e->getMessage()]); }';
    $vars = '';
    foreach ($env as $key => $value) {
        $vars .= escapeshellarg("$key=$value") . ' ';
    }
    $output = shell_exec('env -i ' . $vars . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    return json_decode((string) $output, true) ?: [];
}

$default = timing_in_child([]);
if ($default['belt_speed_m_s'] !== 0.7 || $default['travel_ms'] !== 179 || $default['early_tolerance_ms'] !== 250) {
    throw new RuntimeException('O padrão deve continuar 0,7 m/s, 179 ms e 250 ms: ' . json_encode($default));
}

$custom = timing_in_child([
    'TRACE_BELT_SPEED_M_S' => '1.0',
    'TRACE_SENSOR_TO_READER_DISTANCE_M' => '0.5',
    'TRACE_SCANNER_EARLY_TOLERANCE_MS' => '100',
]);
if ($custom['travel_ms'] !== 500 || $custom['early_tolerance_ms'] !== 100 || $custom['no_read_deadline_ms'] !== 2000) {
    throw new RuntimeException('Velocidade e tolerância configuradas não foram aplicadas: ' . json_encode($custom));
}

$comma = timing_in_child(['TRACE_BELT_SPEED_M_S' => '0,7']);
if (($comma['travel_ms'] ?? null) !== 179) {
    throw new RuntimeException('Velocidade com vírgula decimal deve ser aceita: ' . json_encode($comma));
}

foreach (['0', '-1', 'rapida', '6'] as $invalid) {
    $result = timing_in_child(['TRACE_BELT_SPEED_M_S' => $invalid]);
    if (!isset($result['error'])) {
        throw new RuntimeException("Velocidade inválida '$invalid' foi aceita.");
    }
}

$clamped = timing_in_child(['TRACE_SCANNER_EARLY_TOLERANCE_MS' => '99999']);
if ($clamped['early_tolerance_ms'] !== 2000) {
    throw new RuntimeException('A tolerância deve ser limitada a 2000 ms.');
}
echo "ok\n";
