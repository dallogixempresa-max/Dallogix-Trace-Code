<?php
declare(strict_types=1);
// Cartão do leitor: ServicoMonitoramento valida o objeto tcp_route do heartbeat
// do worker sem mudar a regra ONLINE/OFFLINE, que continua sendo a do ping.

require_once __DIR__ . '/../servidor/src/Aplicacao/ServicoMonitoramento.php';

use App\Aplicacao\ServicoMonitoramento;

final class RotaTcpStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}

final class RotaTcpConnection extends PDO
{
    public function __construct(private array $rows) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!str_contains($query, 'FROM status_dispositivos')) {
            throw new RuntimeException('Consulta inesperada no status dos dispositivos.');
        }
        return new RotaTcpStatement($this->rows);
    }
}

function confere(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function leitor(?array $tcpRoute, string $networkStatus = 'ONLINE', array $linha = []): array
{
    $details = [
        'reader_network_status' => $networkStatus,
        'reader_probe_method' => 'ICMP_PING',
        'reader_ip' => '192.168.1.30',
        'reader_probe_checked_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
    ];
    if ($tcpRoute !== null) {
        $details['tcp_route'] = $tcpRoute;
    }
    $row = [
        'equipment_id' => 1,
        'device_type' => 'SCANNER',
        'status' => 'ONLINE',
        'last_seen_at' => gmdate('Y-m-d H:i:s'),
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
        'segundos_sem_sinal' => 1,
        'micros_sem_sinal' => 1000000,
        'equipment_code' => 'DALA-01',
    ];
    $row = $linha + $row;
    $service = new ServicoMonitoramento(new RotaTcpConnection([$row]));
    return $service->obterStatusDispositivos(7)[0];
}

$agora = gmdate('Y-m-d\TH:i:s.000\Z');
$rotaValida = [
    'connected' => true,
    'connections_open' => 1,
    'last_connected_at' => $agora,
    'last_disconnected_at' => null,
    'last_disconnect_reason' => null,
    'last_frame_at' => $agora,
    'frames_received' => 12,
    'frames_rejected' => 1,
    'scans_registered' => 9,
    'scans_unassociated' => 2,
    'scans_rejected_by_trace' => 1,
    'failed_scans_pending' => 3,
    'scan_error' => 'Leitura recusada pelo Trace: passagem já registrada.',
    'scan_error_at' => $agora,
    'unassociated_by_loading' => [
        ['loading_id' => 42, 'count' => 2, 'last' => [
            'barcode' => '7898250782141', 'no_read' => false, 'reason' => 'sem passagem disponível', 'received_at' => $agora,
            'loading_state' => 'CARREGANDO',
        ]],
        ['loading_id' => null, 'count' => 1, 'last' => [
            'barcode' => '', 'no_read' => true, 'reason' => 'sem carregamento ativo', 'received_at' => $agora,
            'loading_state' => null,
        ]],
    ],
];
// Campos que o servidor acrescenta: heartbeat recente, rota atual.
$atual = ['stale' => false, 'stale_since' => null];

// Contrato válido passa inteiro; o estado do cartão continua o do ping.
$device = leitor($rotaValida);
confere($device['status'] === 'ONLINE', 'O ping ONLINE deve manter o leitor ONLINE.');
confere($device['tcp_route'] === $rotaValida + $atual, 'Rota TCP válida deve chegar à tela sem alteração.');
confere($device['reader_ip'] === '192.168.1.30', 'Campos existentes do leitor continuam expostos.');

// Rota TCP com erro não troca ONLINE por OFFLINE; ping sem resposta continua OFFLINE.
confere(leitor(['connected' => false] + $rotaValida, 'OFFLINE')['status'] === 'OFFLINE', 'Ping sem resposta continua OFFLINE.');

// Worker anterior (sem tcp_route) ou objeto malformado: o cartão ignora a rota.
foreach ([null, [], ['lista', 'de', 'valores']] as $semRota) {
    $device = $semRota === null ? leitor(null) : leitor($semRota);
    confere(array_key_exists('tcp_route', $device) && $device['tcp_route'] === null, 'Sem rota válida, tcp_route deve ser null.');
    confere($device['status'] === 'ONLINE', 'Sem rota TCP o leitor segue a regra do ping.');
}
$device = leitor(null);
confere($device['reader_network_status'] === 'ONLINE', 'Worker antigo continua com o status de rede.');

// Valores fora do contrato são limpos campo a campo.
$antigo = gmdate('Y-m-d\TH:i:s.000\Z', time() - 1200);
$entradas = [];
for ($i = 1; $i <= 7; $i++) {
    $entradas[] = ['loading_id' => $i, 'count' => $i, 'last' => [
        'barcode' => "COD\x01\x7F" . str_repeat('9', 300) . "é",
        'no_read' => 'sim',
        'reason' => "linha\nquebrada " . str_repeat('x', 200),
        'received_at' => 'ontem',
    ]];
}
$entradas[0]['loading_id'] = '42';
$entradas[1]['count'] = -3;
$entradas[2]['last'] = 'texto';
$device = leitor([
    'connected' => 'true',
    'connections_open' => -1,
    'last_connected_at' => 'ontem',
    'last_disconnected_at' => '2026-11-26 03:10:00',
    'last_disconnect_reason' => "Conexão\u{0000} encerrada " . str_repeat('y', 300),
    'last_frame_at' => 1795116546000,
    'frames_received' => '5',
    'frames_rejected' => 2.5,
    'scans_registered' => 2000000000,
    'scans_unassociated' => true,
    'scans_rejected_by_trace' => null,
    'failed_scans_pending' => 4,
    'scan_error' => 'Trace indisponível durante a associação',
    'scan_error_at' => $antigo,
    'unassociated_by_loading' => $entradas,
]);
$rota = $device['tcp_route'];
confere($rota['connected'] === false, 'connected só aceita booleano.');
foreach (['connections_open', 'frames_received', 'frames_rejected', 'scans_registered', 'scans_unassociated', 'scans_rejected_by_trace'] as $campo) {
    confere($rota[$campo] === 0, "Contador {$campo} inválido deveria virar 0.");
}
confere($rota['failed_scans_pending'] === 4, 'Contador válido é preservado.');
foreach (['last_connected_at', 'last_disconnected_at', 'last_frame_at'] as $campo) {
    confere($rota[$campo] === null, "Data {$campo} fora do ISO 8601 deveria virar null.");
}
confere(mb_strlen((string) $rota['last_disconnect_reason']) <= 120, 'Motivo da desconexão limitado a 120 caracteres.');
confere(preg_match('/[\x00-\x1F\x7F]/', (string) $rota['last_disconnect_reason']) === 0, 'Motivo sem caracteres de controle.');
confere($rota['scan_error'] === null && $rota['scan_error_at'] === null, 'Erro com mais de 10 min não aparece no cartão.');
confere(count($rota['unassociated_by_loading']) === 5, 'No máximo 5 carregamentos sem passagem.');
confere($rota['unassociated_by_loading'][0]['loading_id'] === null, 'loading_id só aceita inteiro positivo.');
confere($rota['unassociated_by_loading'][1]['count'] === 0, 'count negativo vira 0.');
confere($rota['unassociated_by_loading'][2]['last'] === null, 'last que não é objeto vira null.');
$ultimo = $rota['unassociated_by_loading'][3]['last'];
confere(preg_match('/^[\x20-\x7E]{1,128}$/', $ultimo['barcode']) === 1, 'Código limitado a 128 caracteres ASCII imprimíveis.');
confere(str_starts_with($ultimo['barcode'], 'COD9'), 'Caracteres de controle são removidos do código.');
confere($ultimo['no_read'] === false, 'no_read só aceita booleano.');
confere(mb_strlen($ultimo['reason']) <= 120 && !str_contains($ultimo['reason'], "\n"), 'Motivo limitado e sem quebra de linha.');
confere($ultimo['received_at'] === null, 'received_at inválido vira null.');

// Erro recente continua visível com o horário.
$device = leitor(['scan_error' => 'Leitura sem passagem', 'scan_error_at' => $agora] + $rotaValida);
confere($device['tcp_route']['scan_error'] === 'Leitura sem passagem', 'Erro recente aparece no cartão.');
confere($device['tcp_route']['scan_error_at'] === $agora, 'Horário do erro recente é mantido.');

// loading_state do último código: texto do STATUS, ou null fora do contrato.
$estados = [];
foreach (['PAUSADO', null, 7, str_repeat('E', 60), "FINAL\nIZANDO"] as $i => $estado) {
    $estados[] = ['loading_id' => $i + 1, 'count' => 0, 'last' => [
        'barcode' => '789', 'no_read' => false, 'reason' => 'sem passagem disponível', 'received_at' => $agora,
    ] + ($estado === null ? [] : ['loading_state' => $estado])];
}
$ultimos = array_column(leitor(['unassociated_by_loading' => $estados] + $rotaValida)['tcp_route']['unassociated_by_loading'], 'last');
confere($ultimos[0]['loading_state'] === 'PAUSADO', 'loading_state do STATUS chega à tela.');
confere($ultimos[1]['loading_state'] === null, 'Worker sem loading_state: null.');
confere($ultimos[2]['loading_state'] === null, 'loading_state que não é texto vira null.');
confere(strlen((string) $ultimos[3]['loading_state']) === 40, 'loading_state limitado a 40 caracteres.');
confere($ultimos[4]['loading_state'] === 'FINAL IZANDO', 'loading_state sem caracteres de controle.');

// Heartbeat vencido: o leitor fica DESCONHECIDO e a rota é marcada como
// antiga, com o horário do último heartbeat, para o cartão não dizer "conectada".
$ultimoSinal = gmdate('Y-m-d H:i:s', time() - 600) . '.000';
$vencido = leitor($rotaValida, 'ONLINE', ['last_seen_at' => $ultimoSinal, 'segundos_sem_sinal' => 600, 'micros_sem_sinal' => 600000000]);
confere($vencido['status'] === 'DESCONHECIDO', 'Heartbeat vencido deixa o leitor DESCONHECIDO.');
confere($vencido['tcp_route']['stale'] === true, 'Heartbeat vencido marca a rota como antiga.');
confere($vencido['tcp_route']['stale_since'] === $ultimoSinal, 'Rota antiga informa o último heartbeat.');
confere($vencido['tcp_route']['connected'] === true, 'O último estado informado é preservado para histórico.');
$nunca = leitor($rotaValida, 'ONLINE', ['last_seen_at' => null, 'segundos_sem_sinal' => null, 'micros_sem_sinal' => null]);
confere($nunca['tcp_route']['stale'] === true && $nunca['tcp_route']['stale_since'] === null, 'Sem heartbeat registrado, rota antiga sem horário.');
confere(leitor(null, 'ONLINE', ['micros_sem_sinal' => 600000000])['tcp_route'] === null, 'Worker antigo vencido continua sem rota.');

// O heartbeat grava details inteiro (coluna JSON), sem limite que corte o objeto aninhado.
$heartbeat = file_get_contents(__DIR__ . '/../servidor/api/dalas/device_heartbeat.php');
confere(str_contains($heartbeat, 'json_encode($payload["details"]'), 'device_heartbeat.php deve gravar details completo.');

echo "Rota TCP do leitor validada campo a campo, sem mudar a regra ONLINE/OFFLINE.\n";
