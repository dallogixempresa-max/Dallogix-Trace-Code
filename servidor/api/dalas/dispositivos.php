<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_session_user();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
exigir_metodo_http(["GET"]);
session_write_close();
$pdo = db();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $statement = $pdo->prepare(
        "SELECT d.id, d.equipment_id, e.equipment_code, d.device_type, d.status, d.last_seen_at, d.details FROM status_dispositivos d JOIN equipamentos e ON e.id = d.equipment_id WHERE e.company_id = :company_id ORDER BY e.equipment_code, d.device_type",
    );
    $statement->execute(["company_id" => $user["company_id"]]);
    json_response(["data" => $statement->fetchAll()]);
}
