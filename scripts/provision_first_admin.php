<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli" || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Este provisionamento só pode ser executado num terminal interativo.\n");
    exit(2);
}

require_once __DIR__ . "/../servidor/configuracao/bootstrap.php";

/** @return array{status:int,stdout:string,stderr:string} */
function executarStty(array $arguments): array
{
    $process = proc_open(
        ["stty", ...$arguments],
        [
            0 => ["file", "/dev/tty", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return ["status" => 1, "stdout" => "", "stderr" => "Não foi possível iniciar stty."];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        "status" => proc_close($process),
        "stdout" => is_string($stdout) ? $stdout : "",
        "stderr" => is_string($stderr) ? $stderr : "",
    ];
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return trim((string) $value);
}

function promptSecret(string $label): string
{
    $modeResult = executarStty(["-g"]);
    $mode = trim($modeResult["stdout"]);
    if ($modeResult["status"] !== 0 || preg_match('/\A[A-Za-z0-9:]+\z/', $mode) !== 1) {
        throw new RuntimeException("Não foi possível configurar a entrada segura do terminal.");
    }
    fwrite(STDOUT, $label);
    $echoResult = executarStty(["-echo"]);
    if ($echoResult["status"] !== 0) {
        throw new RuntimeException("Não foi possível ocultar a senha digitada.");
    }
    try {
        $value = fgets(STDIN);
    } finally {
        $restoreResult = executarStty([$mode]);
        if ($restoreResult["status"] !== 0) {
            fwrite(STDERR, "AVISO: não foi possível restaurar o modo de entrada do terminal.\n");
        }
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
