<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["GET"]);
if (!trace_e_instalacao_local()) {
    responder_json(["data" => ["enabled" => false, "active" => false]]);
}
$statement = obter_conexao_banco()->query(
    "SELECT i.company_id, i.remote_company_id, i.remote_installation_id, i.remote_equipment_id,
            i.company_name, i.login_domain, i.activated_at,
            (SELECT l.status FROM licencas l WHERE l.company_id = i.company_id ORDER BY l.id DESC LIMIT 1) AS license_status,
            (SELECT l.blocked_reason FROM licencas l WHERE l.company_id = i.company_id ORDER BY l.id DESC LIMIT 1) AS license_reason
     FROM instalacoes_locais i
     WHERE i.id = 1
     LIMIT 1",
);
$installation = $statement->fetch();

if (!$installation) {
    responder_json(["data" => ["enabled" => true, "active" => false]]);
}

responder_json([
    "data" => [
        "enabled" => true,
        "active" => true,
        "company_id" => (int) $installation["company_id"],
        "remote_company_id" => (int) $installation["remote_company_id"],
        "remote_installation_id" => $installation["remote_installation_id"] === null ? null : (int) $installation["remote_installation_id"],
        "remote_equipment_id" => $installation["remote_equipment_id"] === null ? null : (int) $installation["remote_equipment_id"],
        "company_name" => $installation["company_name"],
        "login_domain" => $installation["login_domain"],
        "activated_at" => $installation["activated_at"],
        "license_status" => $installation["license_status"] ?: "BLOQUEADA",
        "license_reason" => $installation["license_reason"],
    ],
]);
