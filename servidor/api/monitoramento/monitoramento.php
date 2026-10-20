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
$periodDays = filter_var($_GET["period_days"] ?? 30, FILTER_VALIDATE_INT);
if ($periodDays === false) {
    responder_json(["error" => "Período de monitoramento inválido."], 422);
}
session_write_close();
$limiteSemSinal = limite_sinal_clp_segundos();
$service = new ServicoMonitoramento(obter_conexao_banco());
$companyId = (int) $usuario["company_id"];
$periodDays = (int) $periodDays;
$cameraSignalLimit = limite_sinal_camera_segundos();

$view = (string) ($_GET["view"] ?? "");
$snapshot = match ($view) {
    "alertas" => $service->obterResumoAlertas($companyId, $periodDays, $limiteSemSinal, $cameraSignalLimit),
    "resumo" => $service->obterResumoFinal($companyId, $periodDays),
    default => $service->obterInstantaneo($companyId, $periodDays, $limiteSemSinal, $cameraSignalLimit),
};
responder_json(["data" => $snapshot]);
