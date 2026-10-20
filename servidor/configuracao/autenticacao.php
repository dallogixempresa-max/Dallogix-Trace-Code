<?php

declare(strict_types=1);

function gerar_token_csrf(): string
{
    $session =& trace_contexto_sessao();
    if (empty($session["csrf_token"])) {
        $session["csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $session["csrf_token"];
}

/** @return array{code:string,hash:string,preview:string} */
function gerar_codigo_ativacao_empresa(): array
{
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $part = static function (int $length) use ($alphabet): string {
        $value = "";
        for ($index = 0; $index < $length; $index++) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $value;
    };
    // 16 base32 characters provide 80 bits of entropy while keeping the
    // one-time setup code practical to type. Accept older codes during transition.
    $code = "TRC-" . implode("-", [$part(4), $part(4), $part(4), $part(4)]);
    $normalized = str_replace("-", "", $code);
    return [
        "code" => $code,
        "hash" => hash("sha256", $normalized),
        "preview" => substr($normalized, -4),
    ];
}

function codigo_ativacao_empresa_valido(string $codigo): bool
{
    return preg_match(
        '/\ATRC-(?:[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){3}|[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{5}){4})\z/',
        strtoupper(trim($codigo)),
    ) === 1;
}

function exigir_csrf(): void
{
    $provided = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if ($provided === "" || !hash_equals(gerar_token_csrf(), $provided)) {
        responder_json(["error" => "Token CSRF inválido ou ausente."], 419);
    }
}

/** @deprecated Use exigir_csrf() in new endpoints. */
function require_csrf(): void
{
    exigir_csrf();
}

function hash_limite_login_origem(string $identidade): string
{
    $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? "unknown"));
    return hash("sha256", "login|" . $ip . "|" . strtolower(trim($identidade)));
}

function hash_limite_ativacao(string $codigo): string
{
    $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? "unknown"));
    return hash("sha256", "activation|" . $ip . "|" . strtoupper(trim($codigo)));
}

function hash_limite_login_ip(): string
{
    $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = "unknown";
    }
    return hash("sha256", "ip|" . $ip);
}

function registrar_tentativa_limitada_de_login(
    PDO $connection,
    string $identityHash,
    int $maxAttempts,
): ?int {
    $ensure = $connection->prepare(
        "INSERT INTO limites_login (identity_hash, attempts, window_started_at, blocked_until, violation_count)
         VALUES (:identity_hash, 0, NOW(), NULL, 0)
         ON DUPLICATE KEY UPDATE identity_hash = VALUES(identity_hash)",
    );
    $ensure->execute(["identity_hash" => $identityHash]);

    $statement = $connection->prepare(
        "SELECT attempts, blocked_until, violation_count,
                TIMESTAMPDIFF(SECOND, window_started_at, NOW()) AS window_age_seconds,
                TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS remaining_block_seconds
         FROM limites_login WHERE identity_hash = :identity_hash LIMIT 1 FOR UPDATE",
    );
    $statement->execute(["identity_hash" => $identityHash]);
    $limit = $statement->fetch();
    if (!$limit) {
        throw new RuntimeException("Registro de limite de login não encontrado.");
    }

    $remaining = max(0, (int) ($limit["remaining_block_seconds"] ?? 0));
    if ($limit["blocked_until"] !== null && $remaining > 0) {
        return $remaining;
    }

    $resetWindow = (int) ($limit["window_age_seconds"] ?? 0) >= 60;
    $attempts = ($resetWindow ? 0 : (int) $limit["attempts"]) + 1;
    if ($attempts >= $maxAttempts) {
        $violations = (int) $limit["violation_count"] + 1;
        $blockSeconds = min(900, 60 * (2 ** min(4, $violations - 1)));
        $update = $connection->prepare(
            "UPDATE limites_login
             SET attempts = 0, window_started_at = NOW(),
                 blocked_until = DATE_ADD(NOW(), INTERVAL {$blockSeconds} SECOND),
                 violation_count = :violation_count
             WHERE identity_hash = :identity_hash",
        );
        $update->execute([
            "violation_count" => $violations,
            "identity_hash" => $identityHash,
        ]);
        return $blockSeconds;
    }

    $update = $connection->prepare(
        "UPDATE limites_login
         SET attempts = :attempts,
             window_started_at = IF(:reset_window = 1, NOW(), window_started_at),
             blocked_until = NULL
         WHERE identity_hash = :identity_hash",
    );
    $update->execute([
        "attempts" => $attempts,
        "reset_window" => $resetWindow ? 1 : 0,
        "identity_hash" => $identityHash,
    ]);
    return null;
}

function verificar_taxa_de_login(string $identidade): void
{
    $connection = obter_conexao_banco();
    try {
        $connection->beginTransaction();
        $retryAfter = null;
        foreach ([
            [hash_limite_login_ip(), 30],
            [hash_limite_login_origem($identidade), 10],
        ] as [$bucket, $maxAttempts]) {
            $retryAfter = registrar_tentativa_limitada_de_login(
                $connection,
                $bucket,
                $maxAttempts,
            );
            if ($retryAfter !== null) {
                break;
            }
        }
        $connection->commit();
        if ($retryAfter !== null) {
            header("Retry-After: {$retryAfter}");
            responder_json(["error" => "Muitas tentativas. Aguarde alguns minutos."], 429);
        }
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function verificar_taxa_de_ativacao(string $codigo): void
{
    $connection = obter_conexao_banco();
    try {
        $connection->beginTransaction();
        $retryAfter = null;
        foreach ([
            [hash_limite_login_ip(), 30],
            // Pair the code bucket with the source IP to avoid global lockout
            // of an activation code by an unrelated client.
            [hash_limite_ativacao($codigo), 10],
        ] as [$bucket, $maxAttempts]) {
            $retryAfter = registrar_tentativa_limitada_de_login(
                $connection,
                $bucket,
                $maxAttempts,
            );
            if ($retryAfter !== null) {
                break;
            }
        }
        $connection->commit();
        if ($retryAfter !== null) {
            header("Retry-After: {$retryAfter}");
            responder_json(["error" => "Muitas tentativas. Aguarde alguns minutos."], 429);
        }
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function registrar_login_sucesso(string $identidade): void
{
    $statement = obter_conexao_banco()->prepare(
        "UPDATE limites_login
         SET attempts = 0, window_started_at = NOW(), blocked_until = NULL, violation_count = 0
         WHERE identity_hash = :identity_hash",
    );
    $statement->execute([
        "identity_hash" => hash_limite_login_origem($identidade),
    ]);
}

function dominio_base_login(): string
{
    $configurado = strtolower(trim((string) (getenv("LOGIN_EMAIL_BASE_DOMAIN") ?: "dallogix")));
    $base = preg_replace('/[^a-z0-9.-]+/', '-', $configurado) ?: "dallogix";
    $base = trim($base, ".-");
    return $base !== "" ? $base : "dallogix";
}

function slug_empresa(string $nome): string
{
    $ascii = function_exists("iconv")
        ? @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $nome)
        : false;
    $origem = $ascii !== false && $ascii !== "" ? $ascii : $nome;
    $slug = strtolower((string) (preg_replace('/[^a-z0-9]+/i', '-', $origem) ?: ""));
    $slug = trim($slug, "-");
    return $slug !== "" ? $slug : "empresa";
}

function gerar_dominio_login_empresa(PDO $pdo, string $nome): string
{
    $prefixo = dominio_base_login() . ".";
    $slug = substr(slug_empresa($nome), 0, max(1, 120 - strlen($prefixo)));
    $base = $prefixo . $slug;
    $candidato = $base;
    $sufixo = 2;
    $consulta = $pdo->prepare(
        "SELECT id FROM empresas WHERE login_domain = :company_login_domain
         UNION ALL
         SELECT id FROM usuarios WHERE email LIKE CONCAT('%@', :user_login_domain) LIMIT 1",
    );

    while (true) {
        $consulta->execute([
            "company_login_domain" => $candidato,
            "user_login_domain" => $candidato,
        ]);
        if (!$consulta->fetch()) {
            return $candidato;
        }

        $textoSufixo = "-" . $sufixo;
        $candidato = substr($base, 0, max(1, 120 - strlen($textoSufixo))) . $textoSufixo;
        $sufixo++;
    }
}

function obter_usuario_sessao(): ?array
{
    $session =& trace_contexto_sessao();
    return isset($session["user"]) && is_array($session["user"])
        ? $session["user"]
        : null;
}

function sessao_auth_version_compativel(int $sessionVersion, int $currentVersion): bool
{
    return $sessionVersion > 0 && $sessionVersion === $currentVersion;
}

function exigir_sessao_usuario(bool $permitirTrocaSenha = false): array
{
    $session =& trace_contexto_sessao();
    $usuario = obter_usuario_sessao();
    if ($usuario === null) {
        responder_json(["error" => "Autenticação necessária."], 401);
    }
    $consulta = obter_conexao_banco()->prepare(
        "SELECT u.id, u.company_id, u.name, u.email, u.role, u.active, u.must_change_password, u.auth_version,
                e.login_domain AS company_login_domain
         FROM usuarios u
         LEFT JOIN empresas e ON e.id = u.company_id
         WHERE u.id = :id
           AND (u.company_id IS NULL OR e.archived_at IS NULL)
         LIMIT 1",
    );
    $consulta->execute(["id" => (int) ($usuario["id"] ?? 0)]);
    $usuarioAtual = $consulta->fetch();
    if (!$usuarioAtual || !(bool) $usuarioAtual["active"]) {
        encerrar_sessao_atual();
        responder_json(["error" => "Sessão expirada ou acesso desativado."], 401);
    }
    $sessionAuthVersion = (int) ($session["auth_version"] ?? 0);
    // Sessões criadas antes do versionamento não carregam auth_version. Elas
    // precisam ser rejeitadas, nunca promovidas silenciosamente para uma
    // sessão atual, para que a rotação de senha/perfil invalide todo o legado.
    if (!sessao_auth_version_compativel($sessionAuthVersion, (int) $usuarioAtual["auth_version"])) {
        encerrar_sessao_atual();
        responder_json(["error" => "A sessão foi encerrada porque as credenciais foram alteradas."], 401);
    }
    $usuarioPublico = usuario_publico($usuarioAtual);
    if (($usuarioPublico["role"] ?? "") !== "ADMIN_DALLOGIX") {
        exigir_instalacao_local_ativa(
            obter_conexao_banco(),
            (int) ($usuarioPublico["company_id"] ?? 0),
        );
        if (!trace_e_instalacao_local()) {
            validar_licenca_ativa(
                obter_conexao_banco(),
                (int) ($usuarioPublico["company_id"] ?? 0),
            );
        }
    }
    $session["user"] = $usuarioPublico;
    $session["auth_version"] = (int) $usuarioAtual["auth_version"];
    $session["user_validated_at"] = time();
    return $usuarioPublico;
}

/** @deprecated Use exigir_sessao_usuario() in new endpoints. */
function require_session_user(): array
{
    return exigir_sessao_usuario();
}

function exigir_perfil(array $perfisPermitidos): array
{
    $usuario = exigir_sessao_usuario();
    if (!in_array($usuario["role"], $perfisPermitidos, true)) {
        responder_json(["error" => "Perfil sem permissão para esta ação."], 403);
    }
    return $usuario;
}

/** @deprecated Use exigir_perfil() in new endpoints. */
function require_role(array $allowedRoles): array
{
    return exigir_perfil($allowedRoles);
}

function usuario_publico(array $usuario): array
{
    return [
        "id" => (int) $usuario["id"],
        "name" => $usuario["name"],
        "email" => $usuario["email"],
        "role" => $usuario["role"],
        "company_id" => $usuario["company_id"] === null ? null : (int) $usuario["company_id"],
        "company_login_domain" => isset($usuario["company_login_domain"])
            ? (string) $usuario["company_login_domain"]
            : null,
        "must_change_password" => (bool) ($usuario["must_change_password"] ?? false),
    ];
}
