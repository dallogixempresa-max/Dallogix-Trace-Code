<?php
declare(strict_types=1);
require_once __DIR__ . '/../servidor/src/Aplicacao/ValidadorFilaSensor.php';
use App\Aplicacao\ValidadorFilaSensor;
$cases = [
    [[], null],
    [['pending_events' => '0'], 0],
    [['pending_events' => 3], 3],
    [['pending_events' => -1], null],
    [['pending_events' => 'inválido'], null],
    [['pending_events' => 0.5], null],
    [['pending_events' => false], null],
    [['pending_events_by_loading' => '{"42":2,"43":0}'], 2],
    [['pending_events_by_loading' => '{"43":2}'], 0],
    [['pending_events_by_loading' => '{}'], 0],
    [['pending_events_by_loading' => '{"42":null}'], null],
    [['pending_events_by_loading' => '[]', 'pending_events' => 0], null],
    [['pending_events_by_loading' => 'quebrado', 'pending_events' => 0], null],
    [['pending_events_by_loading' => '{"42":-1}'], null],
    [['pending_events_by_loading' => '{"42":0.5}'], null],
];
foreach ($cases as [$state, $expected]) {
    $actual = ValidadorFilaSensor::pendentes($state, 42);
    if ($actual !== $expected) throw new RuntimeException('Fila inválida liberada ou contagem divergente: ' . json_encode($state));
}
echo "15 casos da fila do sensor passaram.\n";
