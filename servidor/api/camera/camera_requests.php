<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_session_user();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$statement = db()->prepare(
    "SELECT r.id, r.sensor_event_id, r.carregamento_id, r.equipment_id, r.reason, r.status, r.requested_at, r.captured_at, r.error_message FROM solicitacoes_captura_camera r JOIN carregamentos c ON c.id = r.carregamento_id WHERE c.company_id = :company_id ORDER BY r.id DESC LIMIT 100",
);
$statement->execute(["company_id" => $user["company_id"]]);
json_response(["data" => $statement->fetchAll()]);
