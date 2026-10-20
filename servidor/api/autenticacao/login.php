<?php

declare(strict_types=1);

define("TRACE_START_SESSION", true);
require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);

$contentType = strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? ""));
if (str_contains($contentType, "application/json")) {
    $payload = ler_json_da_requisicao();
} else {
    // Permite o fallback nativo do formulário sem alterar o fluxo JSON usado
    // pela aplicação JavaScript.
    $payload = [
        "email" => $_POST["email"] ?? "",
        "password" => $_POST["password"] ?? "",
    ];
}
$email = strtolower(trim((string) ($payload["email"] ?? "")));
$password = (string) ($payload["password"] ?? "");

// Toda tentativa, inclusive corpo incompleto ou email inválido, entra no
// limitador por IP e identidade antes de qualquer retorno antecipado.
verificar_taxa_de_login(mb_substr($email, 0, 254));

if ($email === "" || $password === "") {
    usleep(150000);
    responder_json(["error" => "Credenciais inválidas."], 401);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    usleep(150000);
    responder_json(["error" => "Credenciais inválidas."], 401);
}

if (mb_strlen($password) < 6 || mb_strlen($password) > 128) {
    usleep(150000);
    responder_json(["error" => "Credenciais inválidas."], 401);
}

$statement = obter_conexao_banco()->prepare(
    "SELECT u.id, u.company_id, u.name, u.email, u.password_hash, u.role, u.active, u.must_change_password, u.auth_version,
            e.login_domain AS company_login_domain
     FROM usuarios u
     LEFT JOIN empresas e ON e.id = u.company_id
     WHERE u.email = :email
       AND (u.company_id IS NULL OR e.archived_at IS NULL)
     LIMIT 1",
);
$statement->execute(["email" => $email]);
$usuario = $statement->fetch();
$hashComparacao = is_array($usuario)
    ? (string) $usuario["password_hash"]
    : '$2y$10$KyYdGXeTDGHkxm8b83po0OrNc6y90vZqVfUreYwjbQU4ptOOeHcB2'; // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- Hash fictício fixo equaliza o custo de password_verify para usuário inexistente; não autentica uma conta.
$senhaValida = password_verify($password, $hashComparacao);

if (
    !$usuario ||
    !(bool) $usuario["active"] ||
    !$senhaValida
) {
    usleep(200000);
    responder_json(["error" => "Credenciais inválidas."], 401);
}

if (($usuario["role"] ?? "") !== "ADMIN_DALLOGIX") {
    exigir_instalacao_local_ativa(
        obter_conexao_banco(),
        (int) ($usuario["company_id"] ?? 0),
    );
    if (!trace_e_instalacao_local()) {
        validar_licenca_ativa(
            obter_conexao_banco(),
            (int) ($usuario["company_id"] ?? 0),
        );
    }
}

registrar_login_sucesso($email);

if (password_needs_rehash($usuario["password_hash"], PASSWORD_DEFAULT)) {
    $rehash = db()->prepare(
        "UPDATE usuarios SET password_hash = :password_hash WHERE id = :id",
    );
    $rehash->execute([
        "password_hash" => password_hash($password, PASSWORD_DEFAULT),
        "id" => $usuario["id"],
    ]);
}

session_regenerate_id(true);
$session =& trace_contexto_sessao();
$session["user"] = usuario_publico($usuario);
$session["auth_version"] = (int) $usuario["auth_version"];
$session["user_validated_at"] = time();

responder_json([
    "authenticated" => true,
    "user" => $session["user"],
    "csrf_token" => gerar_token_csrf(),
    "password_change_required" => false,
]);
