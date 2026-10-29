<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();

json_response(
    ["error" => "Uma falha de leitura não pode ser convertida manualmente em código válido. Consulte a imagem no relatório de auditoria."],
    410,
);
