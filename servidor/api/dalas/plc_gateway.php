<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoGatewayClp.php";

use App\Aplicacao\ExcecaoGatewayClp;
use App\Aplicacao\ServicoGatewayClp;

// Este endpoint é exclusivo do gateway industrial instalado no PC da máquina.
// A credencial identifica o dispositivo e limita a fila à sua própria Dala.
$device = require_device_token(["CLP"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}

$payload = request_json();
$action = strtoupper(trim((string) ($payload["action"] ?? "CLAIM")));
$service = new ServicoGatewayClp(db());
try {
    if ($action === "CLAIM") {
        $equipmentId = filter_var(
            $payload["equipment_id"] ?? null,
            FILTER_VALIDATE_INT,
        );
        if ($equipmentId !== false && (int) $equipmentId !== $device["equipment_id"]) {
            json_response(["error" => "O equipamento não pertence a este gateway."], 403);
        }
        $request = $service->reservarComando($device["equipment_id"], $device["id"]);
        if (!$request) {
            http_response_code(204);
            exit();
        }
        json_response(["data" => $request]);
    }
    if ($action !== "COMPLETE") {
        json_response(["error" => "Ação inválida."], 422);
    }
    $requestId = filter_var(
        $payload["request_id"] ?? null,
        FILTER_VALIDATE_INT,
    );
    $status = strtoupper(trim((string) ($payload["status"] ?? "")));
    $message = trim((string) ($payload["message"] ?? ""));
    json_response([
        "data" => $service->concluirComando((int) $requestId, $device["id"], $status, $message),
    ]);
} catch (ExcecaoGatewayClp $exception) {
    json_response(
        ["error" => $exception->getMessage()],
        $exception->httpStatus,
    );
}
