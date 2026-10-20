<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoDisponibilidadeClp.php";

use App\Aplicacao\ExcecaoDisponibilidadeClp;
use App\Aplicacao\ServicoDisponibilidadeClp;
use App\Persistencia\RepositorioDisponibilidadeClp;

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}

$payload = request_json();
$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
if (!$loadingId) {
    json_response(["error" => "Carregamento é obrigatório."], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $currentStatement = $pdo->prepare(
        "SELECT id, state, equipment_id FROM carregamentos WHERE id = :id AND company_id = :company_id FOR UPDATE",
    );
    $currentStatement->execute([
        "id" => $loadingId,
        "company_id" => $user["company_id"],
    ]);
    $current = $currentStatement->fetch();
    if (!$current) {
        $pdo->rollBack();
        json_response(
            ["error" => "Carregamento não encontrado para esta empresa."],
            404,
        );
    }
    if ($current["state"] !== "EMERGENCIA") {
        $pdo->rollBack();
        json_response(
            ["error" => "A máquina não está bloqueada por emergência."],
            409,
        );
    }
    (new ServicoDisponibilidadeClp(
        new RepositorioDisponibilidadeClp($pdo),
    ))->validarComando(
        (int) $user["company_id"],
        (int) $current["equipment_id"],
    );

    $existing = $pdo->prepare(
        "SELECT id FROM solicitacoes_comandos_clp
         WHERE carregamento_id = :carregamento_id AND command = 'DESBLOQUEAR_MAQUINA'
           AND status IN ('PENDENTE','PROCESSANDO') ORDER BY id DESC LIMIT 1 FOR UPDATE",
    );
    $existing->execute(["carregamento_id" => $loadingId]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $pdo->commit();
        json_response(["data" => ["id" => (int) $loadingId, "state" => "EMERGENCIA", "command" => "DESBLOQUEAR_MAQUINA", "command_request_id" => $existingId, "message" => "Já existe uma solicitação de desbloqueio aguardando confirmação do gateway industrial."]]);
    }
    $insert = $pdo->prepare(
        "INSERT INTO solicitacoes_comandos_clp (company_id, equipment_id, carregamento_id, command, requested_by) VALUES (:company_id, :equipment_id, :carregamento_id, 'DESBLOQUEAR_MAQUINA', :requested_by)",
    );
    $insert->execute([
        "company_id" => $user["company_id"],
        "equipment_id" => $current["equipment_id"],
        "carregamento_id" => $loadingId,
        "requested_by" => $user["id"],
    ]);
    $requestId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        $user,
        "MAQUINA_DESBLOQUEADA",
        "carregamento",
        (int) $loadingId,
        [
            "previous_state" => "EMERGENCIA",
            "state" => "EMERGENCIA",
            "command_request_id" => $requestId,
            "command" => "DESBLOQUEAR_MAQUINA",
        ],
    );
    $pdo->commit();
    json_response([
        "data" => [
            "id" => (int) $loadingId,
            "previous_state" => "EMERGENCIA",
            "state" => "EMERGENCIA",
            "command" => "DESBLOQUEAR_MAQUINA",
            "command_request_id" => $requestId,
            "message" => "Solicitação registrada; a máquina só será liberada após confirmação do gateway industrial.",
        ],
    ]);
} catch (ExcecaoDisponibilidadeClp $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(["error" => $exception->getMessage()], $exception->httpStatus);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Machine unlock failed: " . $exception->getMessage());
    json_response(["error" => "Não foi possível desbloquear a máquina."], 500);
}
