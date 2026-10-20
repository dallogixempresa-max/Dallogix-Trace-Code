<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoDisponibilidadeClp.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoEstadoCarregamento.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoComandoClp.php";

use App\Aplicacao\ExcecaoDisponibilidadeClp;
use App\Aplicacao\ExcecaoComandoClp;
use App\Aplicacao\ExcecaoEstadoCarregamento;
use App\Aplicacao\ServicoEstadoCarregamento;
use App\Aplicacao\ServicoComandoClp;

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "PATCH") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
$payload = request_json();
$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
$target = strtoupper(trim((string) ($payload["state"] ?? "")));
if (!$loadingId) {
    json_response(
        ["error" => "Carregamento e estado válido são obrigatórios."],
        422,
    );
}
if ($target === "CARREGANDO" && $user["company_id"] !== null) {
    require_active_license(db(), (int) $user["company_id"]);
}

try {
    $data = $target === "EMERGENCIA"
        ? (new ServicoComandoClp(db()))->solicitarEmergencia($user, (int) $loadingId)
        : (new ServicoEstadoCarregamento(db()))->alterarEstado($user, (int) $loadingId, $target);
    json_response(["data" => $data]);
} catch (ExcecaoEstadoCarregamento $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
} catch (ExcecaoDisponibilidadeClp $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
} catch (ExcecaoComandoClp $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
}
