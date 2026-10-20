<?php
declare(strict_types=1);

$options = getopt("", ["create", "revoke", "list", "company-id:", "name:", "id:", "scopes:", "expires-at:"]);
$actions = array_values(array_filter(["create", "revoke", "list"], static fn(string $action): bool => array_key_exists($action, $options)));
if (count($actions) !== 1) {
    fwrite(STDERR, "Informe exatamente uma ação: --create, --list ou --revoke.\n");
    exit(2);
}

$installationMode = strtolower(trim((string) (getenv("TRACE_INSTALLATION_MODE") ?: "")));
if (!in_array($installationMode, ["central", "remoto", "server"], true)) {
    fwrite(STDERR, "ERRO: o gerenciamento de chaves da API só pode ser executado no servidor Central.\n");
    exit(2);
}

$host = getenv("DB_HOST") ?: "mysql";
$database = getenv("DB_NAME") ?: "trace_local";
$user = getenv("DB_USER") ?: "trace";
$password = trim((string) getenv("DB_PASSWORD"));
if ($password === "" || in_array(strtolower($password), ["password", "password1234", "change-me-local", "change-me-root"], true)) {
    fwrite(STDERR, "ERRO: DB_PASSWORD ausente ou padrão.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "ERRO: não foi possível conectar ao banco configurado.\n");
    exit(3);
}

$action = $actions[0];
if ($action === "create") {
    $companyId = filter_var($options["company-id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $name = trim((string) ($options["name"] ?? ""));
    if ($companyId === false || $name === "" || mb_strlen($name, "UTF-8") > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        fwrite(STDERR, "Uso: php scripts/provision_api_key.php --create --company-id=ID --name=INTEGRACAO --scopes=empresa:read,romaneios:read --expires-at=AAAA-MM-DDThh:mm:ssZ\n");
        exit(2);
    }

    $allowedScopes = [
        "empresa:read",
        "romaneios:read",
        "romaneios:write",
        "carregamentos:read",
        "leituras:read",
        "equipamentos:read",
        "ocorrencias:read",
        "relatorios:read",
    ];
    if (!array_key_exists("scopes", $options) || trim((string) $options["scopes"]) === "") {
        fwrite(STDERR, "ERRO: informe explicitamente os escopos mínimos necessários com --scopes.\n");
        exit(2);
    }
    if (!array_key_exists("expires-at", $options) || trim((string) $options["expires-at"]) === "") {
        fwrite(STDERR, "ERRO: informe a validade da chave com --expires-at.\n");
        exit(2);
    }

    $scopeInput = (string) $options["scopes"];
    $scopes = array_values(array_unique(array_filter(array_map("trim", explode(",", $scopeInput)), static fn(string $scope): bool => $scope !== "")));
    if ($scopes === [] || array_diff($scopes, $allowedScopes) !== []) {
        fwrite(STDERR, "ERRO: escopo inválido. Valores permitidos: " . implode(",", $allowedScopes) . "\n");
        exit(2);
    }

    $rawExpiry = trim((string) $options["expires-at"]);
    if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $rawExpiry) !== 1) {
        fwrite(STDERR, "ERRO: expires-at deve ser uma data RFC 3339 com fuso horário.\n");
        exit(2);
    }
    try {
        $expiry = new DateTimeImmutable($rawExpiry);
    } catch (Throwable $exception) {
        fwrite(STDERR, "ERRO: expires-at inválido.\n");
        exit(2);
    }
    if ($expiry <= new DateTimeImmutable("now", new DateTimeZone("UTC"))) {
        fwrite(STDERR, "ERRO: expires-at precisa estar no futuro.\n");
        exit(2);
    }
    $expiresAt = $expiry->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s.v");

    $company = $pdo->prepare("SELECT id FROM empresas WHERE id = :id AND archived_at IS NULL LIMIT 1");
    $company->execute(["id" => $companyId]);
    if (!$company->fetch()) {
        fwrite(STDERR, "ERRO: empresa inexistente ou arquivada.\n");
        exit(3);
    }
    $license = $pdo->prepare("SELECT status FROM licencas WHERE company_id = :company_id ORDER BY id DESC LIMIT 1");
    $license->execute(["company_id" => $companyId]);
    if ($license->fetchColumn() !== "ATIVA") {
        fwrite(STDERR, "ERRO: a empresa precisa ter licença ativa.\n");
        exit(3);
    }

    $token = "trc_live_" . bin2hex(random_bytes(32));
    $statement = $pdo->prepare(
        "INSERT INTO chaves_api_integracao (company_id, name, token_prefix, token_hash, scopes_json, expires_at)
         VALUES (:company_id, :name, :token_prefix, :token_hash, :scopes_json, :expires_at)",
    );
    $statement->execute([
        "company_id" => $companyId,
        "name" => $name,
        "token_prefix" => substr($token, 0, 18),
        "token_hash" => hash("sha256", $token),
        "scopes_json" => json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        "expires_at" => $expiresAt,
    ]);
    echo "Integração criada. ID: " . (int) $pdo->lastInsertId() . "\n";
    echo "Chave, exibida somente agora: {$token}\n";
    exit(0);
}

if ($action === "list") {
    $companyId = filter_var($options["company-id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($companyId === false) {
        fwrite(STDERR, "Uso: php scripts/provision_api_key.php --list --company-id=ID\n");
        exit(2);
    }
    $statement = $pdo->prepare(
        "SELECT id, name, token_prefix, scopes_json, created_at, last_used_at, expires_at, revoked_at
         FROM chaves_api_integracao WHERE company_id = :company_id ORDER BY id DESC",
    );
    $statement->execute(["company_id" => $companyId]);
    echo json_encode($statement->fetchAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$keyId = filter_var($options["id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
if ($keyId === false) {
    fwrite(STDERR, "Uso: php scripts/provision_api_key.php --revoke --id=ID\n");
    exit(2);
}
$statement = $pdo->prepare(
    "UPDATE chaves_api_integracao SET revoked_at = UTC_TIMESTAMP(3)
     WHERE id = :id AND revoked_at IS NULL",
);
$statement->execute(["id" => $keyId]);
if ($statement->rowCount() === 0) {
    $exists = $pdo->prepare("SELECT revoked_at FROM chaves_api_integracao WHERE id = :id LIMIT 1");
    $exists->execute(["id" => $keyId]);
    $revokedAt = $exists->fetchColumn();
    if ($revokedAt === false) {
        fwrite(STDERR, "ERRO: integração não encontrada.\n");
        exit(3);
    }
    echo "A integração {$keyId} já estava revogada.\n";
    exit(0);
}
echo "Integração {$keyId} revogada.\n";
