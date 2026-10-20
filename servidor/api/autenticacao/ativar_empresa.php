<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);
if (!trace_e_instalacao_local()) {
    responder_json(["error" => "A ativação de empresa está disponível somente na instalação local."], 403);
}
$payload = ler_json_da_requisicao();
$code = strtoupper(trim((string) ($payload["activation_code"] ?? "")));
$email = strtolower(trim((string) ($payload["email"] ?? "")));
$password = (string) ($payload["password"] ?? "");
$installationToken = bin2hex(random_bytes(32));
if (!codigo_ativacao_empresa_valido($code)) {
    responder_json([
        "error" => "Código de ativação incorreto.",
        "error_code" => "ACTIVATION_CODE_INVALID",
    ], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6 || mb_strlen($password) > 128) {
    responder_json(["error" => "Informe o login e a senha do administrador da empresa."], 422);
}

$centralUrl = trim((string) (getenv("TRACE_CENTRAL_URL") ?: ""));
if ($centralUrl === "") {
    $remoteUrl = trim((string) (getenv("SYNC_REMOTE_BATCH_URL") ?: getenv("SYNC_REMOTE_URL") ?: ""));
    $parts = parse_url($remoteUrl);
    if (is_array($parts) && isset($parts["scheme"], $parts["host"])) {
        $centralUrl = $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "");
    }
}
if (url_remota_segura($centralUrl) === "") {
    responder_json(["error" => "Servidor central não configurado para esta instalação."], 503);
}

$sessionCookies = [];
$postJson = static function (string $url, array $body, array $extraHeaders = []) use (&$sessionCookies): array {
    $handle = curl_init_url_remota_segura($url);
    if ($handle === false) {
        throw new RuntimeException("Não foi possível iniciar a conexão com o servidor central.");
    }
    $headers = ["Content-Type: application/json", "Accept: application/json"];
    foreach ($extraHeaders as $name => $value) {
        $headers[] = is_string($name) ? "{$name}: {$value}" : (string) $value;
    }
    if ($sessionCookies !== []) {
        $headers[] = "Cookie: " . implode("; ", array_map(
            static fn (string $name, string $value): string => "{$name}={$value}",
            array_keys($sessionCookies),
            array_values($sessionCookies),
        ));
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = trim((string) curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    if ($error !== "") {
        throw new RuntimeException("Servidor central indisponível.");
    }
    $headerBlock = substr((string) $raw, 0, $headerSize);
    foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $headerLine) {
        if (stripos($headerLine, "Set-Cookie:") !== 0) {
            continue;
        }
        $cookie = trim(substr($headerLine, strlen("Set-Cookie:")));
        $separator = strpos($cookie, "=");
        if ($separator === false) {
            continue;
        }
        $cookieName = trim(substr($cookie, 0, $separator));
        $cookieValue = trim(explode(";", substr($cookie, $separator + 1), 2)[0]);
        if ($cookieName !== "") {
            $sessionCookies[$cookieName] = $cookieValue;
        }
    }
    $result = json_decode(substr((string) $raw, $headerSize), true);
    if (!is_array($result)) {
        throw new RuntimeException("Resposta inválida do servidor central.");
    }
    return [$status, $result];
};

try {
    [$activationStatus, $activationResult] = $postJson(
        rtrim($centralUrl, "/") . "/api/validar_ativacao_empresa.php",
        ["activation_code" => $code],
    );
} catch (Throwable $exception) {
    responder_json(["error" => $exception->getMessage()], 503);
}

if ($activationStatus < 200 || $activationStatus >= 300 || !isset($activationResult["data"])) {
    if (
        ($activationResult["error_code"] ?? "") === "ACTIVATION_CODE_INVALID"
        || in_array($activationStatus, [401, 422], true)
    ) {
        responder_json([
            "error" => "Código de ativação incorreto.",
            "error_code" => "ACTIVATION_CODE_INVALID",
        ], 401);
    }
    responder_json(["error" => $activationResult["error"] ?? "Não foi possível validar o código."], 401);
}
$remoteCompany = $activationResult["data"];

try {
    [$loginStatus, $loginResult] = $postJson(
        rtrim($centralUrl, "/") . "/api/login.php",
        ["email" => $email, "password" => $password],
    );
} catch (Throwable $exception) {
    responder_json(["error" => $exception->getMessage()], 503);
}
if ($loginStatus < 200 || $loginStatus >= 300 || ($loginResult["authenticated"] ?? false) !== true) {
    responder_json(["error" => "O login informado não foi validado no servidor central."], 401);
}
$remoteUser = $loginResult["user"] ?? [];
if (($remoteUser["role"] ?? "") !== "ADMIN_EMPRESA" || (int) ($remoteUser["company_id"] ?? 0) !== (int) $remoteCompany["company_id"]) {
    responder_json(["error" => "Use o login de administrador da mesma empresa do código."], 403);
}

try {
    [$tokenStatus, $tokenResult] = $postJson(
        rtrim($centralUrl, "/") . "/api/validar_ativacao_empresa.php",
        [
            "activation_code" => $code,
            "installation_token" => $installationToken,
        ],
        ["X-CSRF-Token" => (string) ($loginResult["csrf_token"] ?? "")],
    );
} catch (Throwable $exception) {
    responder_json(["error" => $exception->getMessage()], 503);
}
if ($tokenStatus < 200 || $tokenStatus >= 300 || !isset($tokenResult["data"])) {
    error_log(sprintf(
        "Falha ao registrar credencial segura: HTTP %d, erro=%s",
        $tokenStatus,
        trim((string) ($tokenResult["error"] ?? "resposta sem dados")),
    ));
    responder_json(["error" => "Não foi possível registrar a credencial segura da instalação."], 503);
}

$pdo = obter_conexao_banco();
$pdo->beginTransaction();
try {
    $companyQuery = $pdo->prepare(
        "SELECT id, remote_company_id FROM empresas WHERE remote_company_id = :remote_id LIMIT 1",
    );
    $companyQuery->execute(["remote_id" => $remoteCompany["company_id"]]);
    $localCompany = $companyQuery->fetch();
    if (!$localCompany) {
        $domainQuery = $pdo->prepare("SELECT id, remote_company_id FROM empresas WHERE login_domain = :login_domain LIMIT 1");
        $domainQuery->execute(["login_domain" => $remoteCompany["login_domain"]]);
        $localCompany = $domainQuery->fetch();
        if ($localCompany && $localCompany["remote_company_id"] !== null && (int) $localCompany["remote_company_id"] !== (int) $remoteCompany["company_id"]) {
            throw new RuntimeException("O domínio da empresa já está associado a outra instalação local.");
        }
    }
    if (!$localCompany) {
        $insertCompany = $pdo->prepare(
            "INSERT INTO empresas (name, login_domain, remote_company_id)
             VALUES (:name, :login_domain, :remote_company_id)",
        );
        $insertCompany->execute([
            "name" => $remoteCompany["name"],
            "login_domain" => $remoteCompany["login_domain"],
            "remote_company_id" => $remoteCompany["company_id"],
        ]);
        $localCompanyId = (int) $pdo->lastInsertId();
    } else {
        $localCompanyId = (int) $localCompany["id"];
        $updateCompany = $pdo->prepare(
            "UPDATE empresas SET name = :name, login_domain = :login_domain, remote_company_id = :remote_company_id
             WHERE id = :id",
        );
        $updateCompany->execute([
            "name" => $remoteCompany["name"],
            "login_domain" => $remoteCompany["login_domain"],
            "remote_company_id" => $remoteCompany["company_id"],
            "id" => $localCompanyId,
        ]);
    }

    $userQuery = $pdo->prepare("SELECT id, company_id FROM usuarios WHERE email = :email LIMIT 1");
    $userQuery->execute(["email" => $email]);
    $localUser = $userQuery->fetch();
    if ($localUser && $localUser["company_id"] !== null && (int) $localUser["company_id"] !== $localCompanyId) {
        throw new RuntimeException("O login informado já pertence a outra empresa local.");
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $localUserId = 0;
    if ($localUser) {
        $updateUser = $pdo->prepare(
            "UPDATE usuarios SET company_id = :company_id, name = :name, password_hash = :password_hash,
                role = 'ADMIN_EMPRESA', active = 1, must_change_password = 0,
                auth_version = auth_version + 1
             WHERE id = :id",
        );
        $updateUser->execute([
            "company_id" => $localCompanyId,
            "name" => $remoteUser["name"] ?? "Administrador da empresa",
            "password_hash" => $passwordHash,
            "id" => $localUser["id"],
        ]);
        $localUserId = (int) $localUser["id"];
    } else {
        $insertUser = $pdo->prepare(
            "INSERT INTO usuarios (company_id, name, email, password_hash, role, active, must_change_password)
             VALUES (:company_id, :name, :email, :password_hash, 'ADMIN_EMPRESA', 1, 0)",
        );
        $insertUser->execute([
            "company_id" => $localCompanyId,
            "name" => $remoteUser["name"] ?? "Administrador da empresa",
            "email" => $email,
            "password_hash" => $passwordHash,
        ]);
        $localUserId = (int) $pdo->lastInsertId();
    }

    // A instalação local mantém uma cópia do último estado conhecido da
    // licença para impedir operações enquanto o servidor central estiver
    // bloqueando a empresa.
    $license = $pdo->prepare(
        "INSERT INTO licencas (company_id, plan_name, billing_period, status, blocked_at, blocked_reason)
         VALUES (:company_id, 'Trace Mensal', 'MENSAL', 'ATIVA', NULL, NULL)
         ON DUPLICATE KEY UPDATE status = 'ATIVA', blocked_at = NULL, blocked_reason = NULL",
    );
    $license->execute(["company_id" => $localCompanyId]);

    $installation = $pdo->prepare(
        "INSERT INTO instalacoes_locais
            (id, company_id, remote_company_id, remote_installation_id, remote_equipment_id,
             company_name, login_domain, sync_token, activated_at)
         VALUES (1, :company_id, :remote_company_id, :remote_installation_id, :remote_equipment_id,
                 :company_name, :login_domain, :sync_token, NOW())
         ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), remote_company_id = VALUES(remote_company_id),
            remote_installation_id = VALUES(remote_installation_id), remote_equipment_id = VALUES(remote_equipment_id),
            company_name = VALUES(company_name), login_domain = VALUES(login_domain),
            sync_token = VALUES(sync_token), activated_at = VALUES(activated_at)",
    );
    $installation->execute([
        "company_id" => $localCompanyId,
        "remote_company_id" => $remoteCompany["company_id"],
        "remote_installation_id" => $remoteCompany["installation_id"],
        "remote_equipment_id" => $remoteCompany["equipment_id"],
        "company_name" => $remoteCompany["name"],
        "login_domain" => $remoteCompany["login_domain"],
        "sync_token" => $installationToken,
    ]);

    $syncUrl = rtrim($centralUrl, "/") . "/api/sincronizacao_eventos.php";
    $settings = $pdo->prepare(
        "INSERT INTO configuracoes_empresa (company_id, sync_remote_url, updated_by)
         VALUES (:company_id, :sync_remote_url, :updated_by)
         ON DUPLICATE KEY UPDATE sync_remote_url = VALUES(sync_remote_url), updated_by = VALUES(updated_by)",
    );
    $settings->execute([
        "company_id" => $localCompanyId,
        "sync_remote_url" => $syncUrl,
        "updated_by" => $localUserId,
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder_json(["error" => $exception->getMessage()], 409);
}

responder_json([
    "data" => [
        "enabled" => true,
        "active" => true,
        "company_name" => $remoteCompany["name"],
        "login_domain" => $remoteCompany["login_domain"],
    ],
]);
