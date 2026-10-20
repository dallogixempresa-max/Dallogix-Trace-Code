<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
$device = require_device_token(["CLP"]);

$signalLimit = limite_sinal_clp_segundos();
$statement = db()->prepare(
    "SELECT e.id, e.equipment_code, e.name, e.plc_ip, e.plc_port, e.plc_protocol,
            (SELECT c.id FROM carregamentos c
             WHERE c.equipment_id = e.id AND c.company_id = e.company_id
               AND c.state = 'CARREGANDO'
             ORDER BY c.id DESC LIMIT 1) AS active_loading_id,
            CASE WHEN d.status = 'ONLINE'
                       AND d.last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$signalLimit} SECOND)
                 THEN 1 ELSE 0 END AS modbus_recently_confirmed
     FROM equipamentos e
     LEFT JOIN status_dispositivos d
       ON d.equipment_id = e.id AND d.device_type = 'CLP'
     WHERE e.id = :equipment_id AND e.company_id = :company_id LIMIT 1",
);
$statement->execute([
    "equipment_id" => $device["equipment_id"],
    "company_id" => $device["company_id"],
]);
$equipment = $statement->fetchAll();
foreach ($equipment as &$item) {
    // O gateway usa o mesmo destino privado validado pelo teste do cadastro.
    // Entregar o IP resolvido evita uma segunda resolução DNS no Node-RED.
    $item["plc_connect_ip"] = resolver_destino_clp_local(
        (string) ($item["plc_ip"] ?? ""),
        (int) ($item["plc_port"] ?? 0),
    );
}
unset($item);
json_response(["data" => $equipment]);
