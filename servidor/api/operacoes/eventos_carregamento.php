<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoMonitoramento.php";

use App\Aplicacao\ServicoMonitoramento;

exigir_metodo_http(["GET"]);
$usuario = exigir_sessao_usuario();
if ($usuario["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}
session_write_close();

// Conexões curtas atravessam proxies e releases; o cliente reconecta ao término.
header("Content-Type: text/event-stream; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");
header("X-Trace-Stream: carregamento-v1");
while (ob_get_level() > 0) {
    ob_end_flush();
}

$service = new ServicoMonitoramento(obter_conexao_banco());
$companyId = (int) $usuario["company_id"];
$payload = [
    // Operação recebe o estado do carregamento neste stream. O status do CLP
    // é consultado pela rota leve de dispositivos; não agregamos relatórios e
    // históricos a cada reconexão SSE.
    "active_loadings" => $service->carregamentosAtivos($companyId),
    "sent_at" => gmdate("c"),
];
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    responder_json(["error" => "Não foi possível gerar o estado operacional."], 500);
}

// A rota mantém o contrato de evento usado pelo navegador, mas entrega um
// snapshot curto. O navegador reconecta com backoff; nenhum worker do PHP-FPM
// fica preso por dezenas de segundos mantendo uma conexão aberta.
echo "event: carregamento\n";
echo "data: {$encoded}\n\n";
if (function_exists("ob_flush")) {
    @ob_flush();
}
flush();
