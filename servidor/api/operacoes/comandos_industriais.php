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
session_write_close();

$loadingId = filter_var($_GET["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
$equipmentId = filter_var($_GET["equipment_id"] ?? null, FILTER_VALIDATE_INT);
if ($equipmentId) {
    $perPage = 30;
    $requestedPage = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
    $requestedPage = $requestedPage === false || $requestedPage < 1 ? 1 : (int) $requestedPage;
    $count = db()->prepare(
        "SELECT COUNT(*) FROM solicitacoes_comandos_clp
         WHERE equipment_id = :equipment_id AND company_id = :company_id",
    );
    $count->execute(["equipment_id" => $equipmentId, "company_id" => $user["company_id"]]);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($requestedPage, $pages);
    $offset = ($page - 1) * $perPage;
    $statement = db()->prepare(
        "SELECT r.id, r.carregamento_id, r.command, r.status, r.requested_at,
                r.claimed_at, r.completed_at, r.response_message,
                m.number AS romaneio_number
         FROM solicitacoes_comandos_clp r
         LEFT JOIN carregamentos c ON c.id = r.carregamento_id
         LEFT JOIN romaneios m ON m.id = c.romaneio_id
         WHERE r.equipment_id = :equipment_id AND r.company_id = :company_id
         ORDER BY r.id DESC LIMIT :per_page OFFSET :offset",
    );
    $statement->bindValue(":equipment_id", (int) $equipmentId, PDO::PARAM_INT);
    $statement->bindValue(":company_id", (int) $user["company_id"], PDO::PARAM_INT);
    $statement->bindValue(":per_page", $perPage, PDO::PARAM_INT);
    $statement->bindValue(":offset", $offset, PDO::PARAM_INT);
    $statement->execute();
    json_response([
        "data" => $statement->fetchAll(),
        "pagination" => ["page" => $page, "per_page" => $perPage, "total" => $total, "pages" => $pages],
    ]);
}
if (!$loadingId) {
    json_response(["error" => "equipment_id ou carregamento_id é obrigatório."], 422);
}

$statement = db()
    ->prepare("SELECT id, command, status, requested_at, claimed_at, completed_at, response_message
    FROM solicitacoes_comandos_clp
    WHERE carregamento_id = :carregamento_id AND company_id = :company_id
    ORDER BY id DESC LIMIT 1");
$statement->execute([
    "carregamento_id" => $loadingId,
    "company_id" => $user["company_id"],
]);
$command = $statement->fetch();
$reversalStatement = db()->prepare(
    "SELECT direction.command
     FROM solicitacoes_comandos_clp direction
     JOIN carregamentos c ON c.equipment_id = direction.equipment_id
     WHERE c.id = :carregamento_id AND c.company_id = :loading_company_id
       AND direction.company_id = :command_company_id
       AND direction.command IN ('REVERSAO_ATIVAR', 'REVERSAO_DESATIVAR')
       AND direction.status = 'APLICADO'
     ORDER BY direction.id DESC LIMIT 1",
);
$reversalStatement->execute([
    "carregamento_id" => $loadingId,
    "loading_company_id" => $user["company_id"],
    "command_company_id" => $user["company_id"],
]);
json_response([
    "data" => $command ?: null,
    "reversal_command" => $reversalStatement->fetchColumn() ?: null,
]);
