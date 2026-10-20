<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoEstadoCarregamento.php";

use App\Aplicacao\ExcecaoEstadoCarregamento;
use App\Aplicacao\ServicoEstadoCarregamento;

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
session_write_close();

$payload = request_json();
$loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
if (!$loadingId) {
    json_response(["error" => "Carregamento obrigatório."], 422);
}
if (($payload["confirmar_remocao"] ?? false) !== true) {
    json_response(["error" => "Confirme a remoção da unidade e a parada física."], 422);
}

try {
    $data = (new ServicoEstadoCarregamento(db()))->confirmarRemocao($user, (int) $loadingId);
    json_response(["data" => $data]);
} catch (ExcecaoEstadoCarregamento $exception) {
    json_response(["error" => $exception->getMessage()], $exception->httpStatus);
}
