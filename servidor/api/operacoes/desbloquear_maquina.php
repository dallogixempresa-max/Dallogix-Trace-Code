<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoDisponibilidadeClp.php";

use App\Aplicacao\ExcecaoDisponibilidadeClp;
use App\Aplicacao\ServicoDisponibilidadeClp;
use App\Persistencia\RepositorioDisponibilidadeClp;

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}

json_response(["error" => "Comando removido do Trace. Utilize o painel físico da máquina."], 410);
