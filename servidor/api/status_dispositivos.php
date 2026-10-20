<?php

declare(strict_types=1);

require_once __DIR__ . "/../configuracao/bootstrap.php";
require_once __DIR__ . "/../src/Aplicacao/ServicoMonitoramento.php";

use App\Aplicacao\ServicoMonitoramento;

exigir_metodo_http(["GET"]);
$usuario = exigir_sessao_usuario();
if ($usuario["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}

session_write_close();
$devices = (new ServicoMonitoramento(obter_conexao_banco()))->obterStatusDispositivos(
    (int) $usuario["company_id"],
    limite_sinal_clp_segundos(),
    limite_sinal_camera_segundos(),
);
responder_json(["data" => $devices]);
