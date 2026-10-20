<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/InicializadorAcoesDala.php";

exigir_metodo_http(["GET", "POST", "PATCH", "DELETE"]);
$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
$user = require_session_user();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$companyId = (int) $user["company_id"];
$canManage = $user["role"] === "ADMIN_EMPRESA";
$payload = in_array($method, ["POST", "PATCH"], true) ? request_json() : [];
$equipmentId = filter_var($_GET["equipment_id"] ?? $payload["equipment_id"] ?? null, FILTER_VALIDATE_INT);
if (!$equipmentId) {
    json_response(["error" => "Dala não informada."], 422);
}
if ($method === "GET") {
    session_write_close();
}

function require_dala(PDO $pdo, int $equipmentId, int $companyId): void
{
    $statement = $pdo->prepare("SELECT id FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1");
    $statement->execute(["id" => $equipmentId, "company_id" => $companyId]);
    if (!$statement->fetch()) {
        json_response(["error" => "Dala não encontrada."], 404);
    }
}

function action_payload(array $payload): array
{
    $command = strtoupper(trim((string) ($payload["comando"] ?? "")));
    $label = trim((string) ($payload["rotulo"] ?? ""));
    $color = strtoupper(trim((string) ($payload["cor"] ?? "CINZA")));
    $mode = strtoupper(trim((string) ($payload["modo"] ?? "DIRETO")));
    $allowed = ["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO", "REVERSAO_ATIVAR", "REVERSAO_DESATIVAR", "EMERGENCIA"];
    if (!in_array($command, $allowed, true) || $label === "" || mb_strlen($label) > 80 || !in_array($color, ["VERDE", "VERMELHO", "CINZA", "AMBAR", "AZUL"], true) || !in_array($mode, ["INCREMENTAL", "DECREMENTAL", "DIRETO"], true)) {
        json_response(["error" => "Configuração da ação inválida."], 422);
    }
    return ["comando" => $command, "rotulo" => $label, "cor" => $color, "modo" => $mode, "visivel" => !empty($payload["visivel"]) ? 1 : 0];
}

$pdo = db();
require_dala($pdo, (int) $equipmentId, $companyId);
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $actions = $pdo->prepare("SELECT id, comando, rotulo, cor, visivel, modo, ordem FROM acoes_dala WHERE equipment_id = :equipment_id AND company_id = :company_id ORDER BY ordem, id");
    $actions->execute(["equipment_id" => $equipmentId, "company_id" => $companyId]);
    $triggers = $pdo->prepare("SELECT g.id, g.evento, g.acao_id, g.ativo, a.rotulo AS acao_rotulo FROM gatilhos_dala g LEFT JOIN acoes_dala a ON a.id = g.acao_id WHERE g.equipment_id = :equipment_id AND g.company_id = :company_id ORDER BY g.id");
    $triggers->execute(["equipment_id" => $equipmentId, "company_id" => $companyId]);
    json_response(["data" => ["acoes" => $actions->fetchAll(), "gatilhos" => $triggers->fetchAll(), "can_manage" => $canManage]]);
}
if (!$canManage) json_response(["error" => "Somente administradores podem configurar ações."], 403);
require_csrf();
if ($method === "POST") {
    $data = action_payload($payload);
    $nextStatement = $pdo->prepare(
        "SELECT COALESCE(MAX(ordem), 0) + 1 FROM acoes_dala WHERE equipment_id = :equipment_id",
    );
    $nextStatement->execute(["equipment_id" => $equipmentId]);
    $next = (int) $nextStatement->fetchColumn();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, visivel, modo, ordem) VALUES (:company_id, :equipment_id, :comando, :rotulo, :cor, :visivel, :modo, :ordem)");
        $insert->execute(["company_id" => $companyId, "equipment_id" => $equipmentId, ...$data, "ordem" => $next]);
        $createdId = (int) $pdo->lastInsertId();
        record_operational_event($pdo, $user, "ACAO_DALA_CRIADA", "acao_dala", $createdId, ["equipment_id" => (int) $equipmentId, "comando" => $data["comando"]]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            json_response(["error" => "Já existe uma ação com esse comando nesta Dala."], 409);
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    json_response(["data" => ["id" => $createdId]], 201);
}
if ($method === "PATCH") {
    $action = (string) ($payload["action"] ?? "update");
    if ($action === "reorder") {
        $ids = array_values(array_filter($payload["ids"] ?? [], fn($id) => filter_var($id, FILTER_VALIDATE_INT)));
        $pdo->beginTransaction();
        try { $update = $pdo->prepare("UPDATE acoes_dala SET ordem = :ordem WHERE id = :id AND equipment_id = :equipment_id AND company_id = :company_id"); foreach ($ids as $index => $id) $update->execute(["ordem" => $index + 1, "id" => $id, "equipment_id" => $equipmentId, "company_id" => $companyId]); $pdo->commit(); }
        catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
        json_response(["data" => ["reordered" => true]]);
    }
    if ($action === "trigger") {
        $triggerId = filter_var($payload["trigger_id"] ?? null, FILTER_VALIDATE_INT);
        $actionId = filter_var($payload["acao_id"] ?? null, FILTER_VALIDATE_INT) ?: null;
        if (!$triggerId) json_response(["error" => "Gatilho inválido."], 422);
        if ($actionId !== null) {
            $ownedAction = $pdo->prepare("SELECT id FROM acoes_dala WHERE id = :id AND equipment_id = :equipment_id AND company_id = :company_id");
            $ownedAction->execute(["id" => $actionId, "equipment_id" => $equipmentId, "company_id" => $companyId]);
            if (!$ownedAction->fetch()) json_response(["error" => "A ação não pertence a esta Dala."], 422);
        }
        $update = $pdo->prepare("UPDATE gatilhos_dala SET acao_id = :acao_id, ativo = :ativo WHERE id = :id AND equipment_id = :equipment_id AND company_id = :company_id");
        $update->execute(["acao_id" => $actionId, "ativo" => !empty($payload["ativo"]) ? 1 : 0, "id" => $triggerId, "equipment_id" => $equipmentId, "company_id" => $companyId]);
        json_response(["data" => ["updated" => true]]);
    }
    $id = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
    $data = action_payload($payload);
    $update = $pdo->prepare("UPDATE acoes_dala SET comando = :comando, rotulo = :rotulo, cor = :cor, visivel = :visivel, modo = :modo WHERE id = :id AND equipment_id = :equipment_id AND company_id = :company_id");
    $update->execute([...$data, "id" => $id, "equipment_id" => $equipmentId, "company_id" => $companyId]);
    json_response(["data" => ["updated" => true]]);
}
if ($method === "DELETE") {
    $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    $delete = $pdo->prepare("DELETE FROM acoes_dala WHERE id = :id AND equipment_id = :equipment_id AND company_id = :company_id");
    $delete->execute(["id" => $id, "equipment_id" => $equipmentId, "company_id" => $companyId]);
    json_response(["data" => ["deleted" => $delete->rowCount() === 1]]);
}
json_response(["error" => "Método não permitido."], 405);
