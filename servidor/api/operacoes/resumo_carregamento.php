<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$loadingId = filter_var($_GET["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
if (!$loadingId) {
    json_response(["error" => "Carregamento obrigatório."], 422);
}

session_write_close();
$pdo = db();
$loading = $pdo->prepare(
    "SELECT c.id, c.state, c.started_at, c.finished_at,
            COALESCE(c.leituras_validas, 0) AS leituras_validas,
            (SELECT COUNT(*) FROM eventos_sensor s
             WHERE s.carregamento_id = c.id AND s.equipment_id = c.equipment_id) AS sacas_detectadas,
            r.number AS romaneio_number, rt.plate, e.equipment_code,
            (SELECT JSON_UNQUOTE(JSON_EXTRACT(la.metadata, '$.message'))
             FROM logs_auditoria la
             WHERE la.company_id = c.company_id
               AND la.action = 'ALERTA_FIM_PRODUTO'
               AND la.entity_type = 'carregamento'
               AND la.entity_id = c.id
             ORDER BY la.id DESC
             LIMIT 1) AS end_product_alert
     FROM carregamentos c
     JOIN romaneios r ON r.id = c.romaneio_id
     JOIN romaneio_caminhoes rt ON rt.id = c.truck_id
     JOIN equipamentos e ON e.id = c.equipment_id
     WHERE c.id = :id AND c.company_id = :company_id LIMIT 1",
);
$loading->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
$data = $loading->fetch();
if (!$data) {
    json_response(["error" => "Carregamento não encontrado."], 404);
}

$count = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};
$data["leituras_validas"] = (int) ($data["leituras_validas"] ?? 0);
$data["sacas_detectadas"] = (int) ($data["sacas_detectadas"] ?? 0);
$data["sem_leitura"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'SEM_LEITURA'",
    ["id" => $loadingId],
);
$data["produtos_incorretos"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'PRODUTO_INCORRETO'",
    ["id" => $loadingId],
);
$data["ocorrencias"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM ocorrencias WHERE carregamento_id = :id",
    ["id" => $loadingId],
);
$data["imagens_pendentes"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia WHERE carregamento_id = :id AND status IN ('PENDENTE','CAPTURANDO')",
    ["id" => $loadingId],
);
$data["imagens_incidentes"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia WHERE carregamento_id = :id AND status = 'CAPTURADA' AND reason <> 'NORMAL'",
    ["id" => $loadingId],
);
json_response(["data" => $data]);
