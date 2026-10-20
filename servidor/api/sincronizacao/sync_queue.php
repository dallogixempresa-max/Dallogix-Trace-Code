<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoSincronizacao.php";

use App\Aplicacao\ServicoSincronizacao;

$user = exigir_perfil(["ADMIN_EMPRESA", "SUPERVISOR"]);
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$pdo = db();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $statement = $pdo->prepare(
        'SELECT q.id, q.event_uuid, q.aggregate_type, q.aggregate_id, q.payload, q.status, q.attempts, q.transient_attempts, q.last_error, q.available_at, q.created_at
         FROM fila_sincronizacao q
         WHERE q.company_id = :company_id
         ORDER BY q.id DESC LIMIT 100',
    );
    $statement->execute(["company_id" => $user["company_id"]]);
    json_response(["data" => $statement->fetchAll()]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();

$payload = request_json();
$queueId = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
if (!$queueId) {
    json_response(["error" => "Id do evento é obrigatório."], 422);
}

$result = (new ServicoSincronizacao($pdo))->processarUm($queueId, (int) $user["company_id"]);
if ($result["status"] === "NAO_ENCONTRADO") {
    json_response(["error" => $result["reason"]], 404);
}
if ($result["status"] === "CONCORRENTE") {
    json_response(["error" => $result["reason"]], 409);
}
if ($result["status"] === "ERRO") {
    json_response(["error" => $result["error"], "data" => $result], 502);
}
json_response(["data" => $result], $result["processed"] ? 200 : 202);
