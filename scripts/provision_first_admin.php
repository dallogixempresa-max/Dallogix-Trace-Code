<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli" || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Este provisionamento só pode ser executado num terminal interativo.\n");
    exit(2);
}

require_once __DIR__ . "/../servidor/configuracao/bootstrap.php";

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return trim((string) $value);
}

function promptSecret(string $label): string
{
    $modes = [];
    exec("stty -g < /dev/tty", $modes, $status);
    if ($status !== 0 || !isset($modes[0])) {
        throw new RuntimeException("Não foi possível configurar a entrada segura do terminal.");
    }
    fwrite(STDOUT, $label);
    exec("stty -echo < /dev/tty", $output, $status);
    if ($status !== 0) {
        throw new RuntimeException("Não foi possível ocultar a senha digitada.");
    }
    try {
        $value = fgets(STDIN);
    } finally {
        exec("stty " . escapeshellarg(trim($modes[0])) . " < /dev/tty");
        fwrite(STDOUT, "\n");
    }
    return rtrim((string) $value, "\r\n");
}

try {
    $name = prompt("Nome do administrador: ");
    $email = strtolower(prompt("E-mail de acesso: "));
    if ($name === "" || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Informe um nome e um e-mail válidos.");
    }

    $password = promptSecret("Senha temporária (mínimo 12 caracteres): ");
    $confirmation = promptSecret("Confirme a senha temporária: ");
    if (strlen($password) < 12 || strlen($password) > 128 || !hash_equals($password, $confirmation)) {
        throw new RuntimeException("As senhas não coincidem ou não respeitam o tamanho mínimo.");
    }

    $pdo = obter_conexao_banco();
    $lock = $pdo->query("SELECT GET_LOCK('dallogix-trace-first-admin', 5)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException("Outro provisionamento inicial já está em andamento.");
    }
    try {
        $existing = $pdo->query(
            "SELECT COUNT(*) FROM usuarios WHERE role = 'ADMIN_DALLOGIX'",
        )->fetchColumn();
        if ((int) $existing !== 0) {
            throw new RuntimeException("Já existe um administrador da plataforma; nenhum usuário foi alterado.");
        }

        $statement = $pdo->prepare(
            "INSERT INTO usuarios (company_id, name, email, password_hash, role, active, must_change_password)
             VALUES (NULL, :name, :email, :password_hash, 'ADMIN_DALLOGIX', 1, 1)",
        );
        $statement->execute([
            "name" => $name,
            "email" => $email,
            "password_hash" => password_hash($password, PASSWORD_DEFAULT),
        ]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('dallogix-trace-first-admin')");
    }

    fwrite(STDOUT, "Administrador inicial criado. No primeiro acesso será exigida a troca da senha.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: " . $error->getMessage() . "\n");
    exit(1);
}
