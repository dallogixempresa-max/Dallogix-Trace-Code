<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);
$usuario = exigir_sessao_usuario(true);
require_csrf();
$payload = request_json();
$senhaAtual = (string) ($payload["current_password"] ?? "");
$novaSenha = (string) ($payload["new_password"] ?? "");
if (
    $senhaAtual === "" ||
    strlen($novaSenha) < 6 ||
    strlen($novaSenha) > 128 ||
    hash_equals($senhaAtual, $novaSenha)
) {
    json_response(["error" => "Informe uma nova senha diferente, com pelo menos 6 caracteres."], 422);
}

$pdo = db();
$statement = $pdo->prepare(
    "SELECT password_hash, auth_version FROM usuarios WHERE id = :id AND active = 1 LIMIT 1",
);
$statement->execute(["id" => $usuario["id"]]);
$stored = $statement->fetch();
if (!$stored || !password_verify($senhaAtual, (string) $stored["password_hash"])) {
    json_response(["error" => "Senha atual inválida."], 401);
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        "UPDATE usuarios SET password_hash = :password_hash, must_change_password = 0,
            auth_version = auth_version + 1
         WHERE id = :id AND active = 1",
    );
    $update->execute([
        "password_hash" => password_hash($novaSenha, PASSWORD_DEFAULT),
        "id" => $usuario["id"],
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException("Não foi possível atualizar a senha.");
    }
    $usuarioAtualizado = $usuario;
    $usuarioAtualizado["must_change_password"] = false;
    record_operational_event(
        $pdo,
        $usuarioAtualizado,
        "SENHA_ATUALIZADA",
        "user",
        (int) $usuario["id"],
        ["forced_change" => (bool) $usuario["must_change_password"]],
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

session_regenerate_id(true);
$session =& trace_contexto_sessao();
$session["user"] = $usuarioAtualizado;
$session["auth_version"] = (int) $stored["auth_version"] + 1;
$session["user_validated_at"] = time();
json_response([
    "data" => ["updated" => true],
    "user" => $session["user"],
    "csrf_token" => gerar_token_csrf(),
]);
