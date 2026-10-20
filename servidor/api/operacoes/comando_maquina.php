<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoDisponibilidadeClp.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoComandoClp.php";

use App\Aplicacao\ExcecaoDisponibilidadeClp;
use App\Aplicacao\ExcecaoComandoClp;
use App\Aplicacao\ServicoComandoClp;

// Controlador: a saída física continua sob responsabilidade do CLP. Esta rota
// apenas encaminha a intenção autenticada à camada de aplicação e à sua fila local.
$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
// As verificações que acessam banco e a transação do comando não devem manter
// o lock da sessão que as consultas de status usam com o mesmo cookie.
session_write_close();
require_active_license(db(), (int) $user["company_id"]);
$payload = request_json();
$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
$command = strtoupper(trim((string) ($payload["command"] ?? "")));
if (!$loadingId) {
    json_response(
        [
            "error" => "Carregamento e comando válido são obrigatórios.",
        ],
        422,
    );
}

try {
    $service = new ServicoComandoClp(db());
    $data = match (true) {
        in_array($command, ["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"], true)
            => $service->solicitarOperacao($user, (int) $loadingId, $command),
        $command === "EMERGENCIA"
            => $service->solicitarEmergencia($user, (int) $loadingId),
        default
            => $service->solicitarReversao($user, (int) $loadingId, $command),
    };
    json_response(["data" => $data]);
} catch (ExcecaoComandoClp $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
} catch (ExcecaoDisponibilidadeClp $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
}
