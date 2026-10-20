<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$usuarioAtor = exigir_sessao_usuario();
if ($usuarioAtor["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
    $limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 50) {
        responder_json(["error" => "Limite de ocorrências inválido."], 422);
    }

    $pdo = obter_conexao_banco();
    $statement = $pdo->prepare(
        "SELECT id, type, quantity, description, carregamento_id, created_at
         FROM ocorrencias
         WHERE company_id = :company_id
         ORDER BY id DESC
         LIMIT :limit",
    );
    $statement->bindValue(":company_id", (int) $usuarioAtor["company_id"], PDO::PARAM_INT);
    $statement->bindValue(":limit", $limit, PDO::PARAM_INT);
    $statement->execute();
    responder_json(["data" => $statement->fetchAll()]);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder_json(["error" => "Método não permitido."], 405);
}
exigir_csrf();
$pdo = obter_conexao_banco();

$payload = ler_json_da_requisicao();
$type = trim((string) ($payload["type"] ?? ""));
$description = trim((string) ($payload["description"] ?? ""));
$quantity = filter_var($payload["quantity"] ?? 1, FILTER_VALIDATE_INT);
$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
if ($type === "" || $quantity === false || $quantity < 1) {
    json_response(
        ["error" => "Tipo e quantidade válida são obrigatórios."],
        422,
    );
}
if ($loadingId) {
    $loading = $pdo->prepare(
        "SELECT id FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
    );
    $loading->execute([
        "id" => $loadingId,
        "company_id" => $usuarioAtor["company_id"],
    ]);
    if (!$loading->fetch()) {
        responder_json(["error" => "Carregamento não pertence à empresa."], 422);
    }
}

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO ocorrencias (company_id, carregamento_id, type, quantity, description, created_by) VALUES (:company_id, :carregamento_id, :type, :quantity, :description, :created_by)",
    );
    $insert->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId ?: null,
        "type" => $type,
        "quantity" => $quantity,
        "description" => $description !== "" ? $description : null,
        "created_by" => $usuarioAtor["id"],
    ]);
    $occurrenceId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "OCORRENCIA_REGISTRADA",
        "ocorrencia",
        $occurrenceId,
        ["type" => $type, "quantity" => $quantity, "carregamento_id" => $loadingId],
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
responder_json(
    [
        "data" => [
            "id" => $occurrenceId,
            "type" => $type,
            "quantity" => $quantity,
        ],
    ],
    201,
);
