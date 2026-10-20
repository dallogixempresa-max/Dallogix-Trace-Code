<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_role(["ADMIN_DALLOGIX"]);
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = db();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $statement = $pdo->query(
        "SELECT l.id, l.company_id, c.name AS company_name, l.plan_name,
                l.billing_period, l.status,
                l.blocked_at, l.blocked_reason, l.created_at, l.updated_at
         FROM licencas l JOIN empresas c ON c.id = l.company_id
         WHERE l.id = (SELECT latest.id FROM licencas latest
                       WHERE latest.company_id = l.company_id
                       ORDER BY latest.id DESC LIMIT 1)
         AND c.archived_at IS NULL
         ORDER BY c.name",
    );
    json_response(["data" => $statement->fetchAll()]);
}

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
$payload = request_json();
$companyId = filter_var($payload["company_id"] ?? null, FILTER_VALIDATE_INT);
$status = strtoupper(trim((string) ($payload["status"] ?? "")));
$reason = trim((string) ($payload["blocked_reason"] ?? ""));
$plan = trim((string) ($payload["plan_name"] ?? "Trace Mensal"));
if (!$companyId || !in_array($status, ["ATIVA", "BLOQUEADA"], true)) {
    json_response(["error" => "Empresa e status de licença são obrigatórios."], 422);
}
if ($plan === "" || mb_strlen($plan) > 100 || mb_strlen($reason) > 255) {
    json_response(["error" => "Dados da licença inválidos."], 422);
}
$company = $pdo->prepare(
    "SELECT id, archived_at, activation_code, activation_code_preview,
            activation_code_used_at,
            (activation_code_expires_at IS NULL OR activation_code_expires_at <= NOW()) AS activation_code_expired
     FROM empresas WHERE id = :id LIMIT 1",
);
$company->execute(["id" => $companyId]);
$companyRow = $company->fetch();
if (!$companyRow) json_response(["error" => "Empresa não encontrada."], 404);
if ($companyRow["archived_at"] !== null) {
    json_response(["error" => "A empresa está arquivada e não aceita alterações de licença."], 409);
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        "INSERT INTO licencas (company_id, plan_name, billing_period, status, blocked_at, blocked_reason)
         VALUES (:company_id, :plan_name, 'MENSAL', :status, :blocked_at, :reason)
         ON DUPLICATE KEY UPDATE plan_name = VALUES(plan_name), status = VALUES(status),
             blocked_at = VALUES(blocked_at), blocked_reason = VALUES(blocked_reason)",
    );
    $statement->execute([
        "company_id" => $companyId,
        "plan_name" => $plan,
        "status" => $status,
        "blocked_at" => $status === "ATIVA" ? null : date("Y-m-d H:i:s"),
        "reason" => $reason !== "" ? $reason : null,
    ]);
    if ($status === "ATIVA"
        && empty($companyRow["activation_code_used_at"])
        && (trim((string) ($companyRow["activation_code"] ?? "")) === "" || (bool) $companyRow["activation_code_expired"])) {
        $activation = gerar_codigo_ativacao_empresa();
        $activationUpdate = $pdo->prepare(
            "UPDATE empresas
             SET activation_code = :code,
                 activation_code_hash = :code_hash,
                 activation_code_preview = :code_preview,
                 activation_code_created_at = NOW(),
                 activation_code_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY),
                 activation_code_used_at = NULL
             WHERE id = :id",
        );
        $activationUpdate->execute([
            "code" => $activation["code"],
            "code_hash" => $activation["hash"],
            "code_preview" => $activation["preview"],
            "id" => $companyId,
        ]);
    }
    $idStatement = $pdo->prepare("SELECT id FROM licencas WHERE company_id = :company_id LIMIT 1");
    $idStatement->execute(["company_id" => $companyId]);
    $id = (int) $idStatement->fetchColumn();
    record_operational_event($pdo, $user, "LICENCA_ATUALIZADA", "license", $id, [
        "company_id" => (int) $companyId,
        "status" => $status,
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
json_response(["data" => ["id" => $id, "company_id" => (int) $companyId, "status" => $status]], 201);
