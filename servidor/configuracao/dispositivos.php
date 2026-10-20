<?php

declare(strict_types=1);

function resolver_destino_clp_local(string $host, int $port): ?string
{
    if (!trace_e_instalacao_local() || $port < 1 || $port > 65535) {
        return null;
    }

    $host = trim($host);
    if ($host === "" || strlen($host) > 253) {
        return null;
    }

    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ip === false) {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $host) !== 1
            || preg_match('/\A[0-9.]+\z/', $host) === 1) {
            return null;
        }
        $ip = gethostbyname($host);
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
    }

    $octets = array_map('intval', explode('.', $ip));
    $private = $octets[0] === 10
        || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
        || ($octets[0] === 192 && $octets[1] === 168);
    return $private ? $ip : null;
}

/** @return array{id:int, company_id:int, equipment_id:int, device_code:string, device_type:string} */
function require_device_token(array $allowedDeviceTypes = []): array
{
    $provided = trim((string) ($_SERVER["HTTP_X_DEVICE_TOKEN"] ?? ""));
    if ($provided === "") {
        responder_json(["error" => "Credencial do dispositivo ausente."], 401);
    }

    // Provisioned device tokens contain 256 random bits, so their indexed
    // SHA-256 digest can be compared directly without bcrypt per request.
    $statement = obter_conexao_banco()->prepare(
        "SELECT id, company_id, equipment_id, device_code, device_type, token_lookup_hash
         FROM dispositivos
         WHERE active = 1 AND token_lookup_hash = :token_lookup_hash
           AND (token_revoked_at IS NULL OR token_revoked_at > NOW())
           AND (token_expires_at IS NULL OR token_expires_at > NOW())
         LIMIT 1",
    );
    $statement->execute(["token_lookup_hash" => hash("sha256", $provided)]);
    $device = $statement->fetch();
    if (!$device || !hash_equals((string) $device["token_lookup_hash"], hash("sha256", $provided))) {
        responder_json(["error" => "Credencial do dispositivo inválida."], 401);
    }
    if ($allowedDeviceTypes !== [] && !in_array($device["device_type"], $allowedDeviceTypes, true)) {
        responder_json(["error" => "Dispositivo sem permissão para esta operação."], 403);
    }

    $touch = obter_conexao_banco()->prepare(
        "UPDATE dispositivos SET last_seen_at = NOW(), token_last_used_at = NOW()
         WHERE id = :id AND active = 1",
    );
    $touch->execute(["id" => $device["id"]]);

    return [
        "id" => (int) $device["id"],
        "company_id" => (int) $device["company_id"],
        "equipment_id" => (int) $device["equipment_id"],
        "device_code" => (string) $device["device_code"],
        "device_type" => (string) $device["device_type"],
    ];
}

function token_bearer_instalacao(): string
{
    $authorization = trim((string) ($_SERVER["HTTP_AUTHORIZATION"] ?? ""));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }
    return trim((string) ($_SERVER["HTTP_X_INSTALLATION_TOKEN"] ?? ""));
}

function credencial_instalacao_valida(string $token): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
}

/** @return array{id:int,name:string,login_domain:string,license_status:string,license_reason:?string,installation_id:int,equipment_id:?int,installation_name:string,auto_link_first_dala:bool} */
function exigir_instalacao_remota(bool $permitirSemDala = false): array
{
    $token = token_bearer_instalacao();
    if (!credencial_instalacao_valida($token)) {
        responder_json(["error" => "Credencial da instalação ausente ou inválida."], 401);
    }

    $normalized = strtolower($token);
    $pdo = obter_conexao_banco();
    $statement = $pdo->prepare(
        "SELECT p.id AS installation_id, p.company_id, p.equipment_id, p.auto_link_first_dala,
                p.name AS installation_name, c.name, c.login_domain
         FROM instalacoes_industriais p
         JOIN empresas c ON c.id = p.company_id
         WHERE c.archived_at IS NULL AND p.archived_at IS NULL
           AND p.access_blocked_at IS NULL AND p.sync_token_hash = :token_hash
         LIMIT 1",
    );
    $statement->execute(["token_hash" => hash("sha256", $normalized)]);
    $installation = $statement->fetch();
    if (!$installation) {
        responder_json(["error" => "Credencial da instalação inválida."], 401);
    }
    if ($installation["equipment_id"] === null && !$permitirSemDala) {
        responder_json([
            "error" => "Este PC industrial ainda não está vinculado a uma Dala no servidor central.",
            "error_code" => "INSTALLATION_DALA_REQUIRED",
        ], 409);
    }

    // O código identifica a instalação, mas a licença decide se ela pode
    // continuar sincronizando. A mesma regra também é usada pelas rotas
    // operacionais autenticadas.
    $license = validar_licenca_ativa($pdo, (int) $installation["company_id"]);

    return [
        "id" => (int) $installation["company_id"],
        "name" => (string) $installation["name"],
        "login_domain" => (string) $installation["login_domain"],
        "license_status" => (string) $license["status"],
        "license_reason" => $license["blocked_reason"] !== null
            ? (string) $license["blocked_reason"]
            : null,
        "installation_id" => (int) $installation["installation_id"],
        "equipment_id" => $installation["equipment_id"] === null
            ? null
            : (int) $installation["equipment_id"],
        "installation_name" => (string) $installation["installation_name"],
        "auto_link_first_dala" => (bool) $installation["auto_link_first_dala"],
    ];
}

function validar_licenca_ativa(PDO $pdo, int $companyId): array
{
    $statement = $pdo->prepare(
        "SELECT id, status, blocked_reason
         FROM licencas
         WHERE company_id = :company_id
         ORDER BY id DESC
         LIMIT 1",
    );
    $statement->execute(["company_id" => $companyId]);
    $license = $statement->fetch();

    if (!$license) {
        encerrar_sessao_atual();
        responder_json([
            "error" => "Empresa sem licença configurada.",
            "error_code" => "LICENSE_INACTIVE",
            "license_status" => "SEM_LICENCA",
        ], 402);
    }

    if ($license["status"] !== "ATIVA") {
        encerrar_sessao_atual();
        responder_json(
            [
                "error" => "Licença da empresa bloqueada.",
                "error_code" => "LICENSE_INACTIVE",
                "license_status" => $license["status"],
                "blocked_reason" => $license["blocked_reason"],
            ],
            402,
        );
    }

    return $license;
}

function exigir_instalacao_local_ativa(PDO $pdo, int $companyId): void
{
    if (!trace_e_instalacao_local()) {
        return;
    }

    $statement = $pdo->query(
        "SELECT company_id, sync_token, activated_at
         FROM instalacoes_locais
         WHERE id = 1
         LIMIT 1",
    );
    $installation = $statement->fetch();
    if (
        !$installation ||
        trim((string) ($installation["sync_token"] ?? "")) === "" ||
        trim((string) ($installation["activated_at"] ?? "")) === ""
    ) {
        encerrar_sessao_atual();
        responder_json([
            "error" => "O PC industrial desta instalação ainda não foi configurado.",
            "error_code" => "INSTALLATION_INACTIVE",
        ], 403);
    }

    $installedCompanyId = (int) $installation["company_id"];
    if ($companyId > 0 && $installedCompanyId !== $companyId) {
        encerrar_sessao_atual();
        responder_json([
            "error" => "O usuário não pertence à instalação local configurada.",
            "error_code" => "INSTALLATION_MISMATCH",
        ], 403);
    }

    validar_licenca_ativa($pdo, $installedCompanyId);
}

/** @deprecated Use exigir_instalacao_local_ativa() in new endpoints. */
function validar_licenca_local_se_ativada(PDO $pdo, int $companyId): void
{
    exigir_instalacao_local_ativa($pdo, $companyId);
}

/** @deprecated Use validar_licenca_ativa() in new endpoints. */
function require_active_license(PDO $pdo, int $companyId): array
{
    return validar_licenca_ativa($pdo, $companyId);
}
