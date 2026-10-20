<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

function api_v1_responder_erro(string $code, string $message, int $status): never
{
    header("Content-Type: application/json; charset=utf-8");
    responder_json([
        "error" => [
            "code" => $code,
            "message" => $message,
            "request_id" => trace_correlation_id(),
        ],
    ], $status);
}

require_once __DIR__ . "/_rate_limit.php";

set_exception_handler(static function (Throwable $exception): void {
    registrar_log_erro($exception, "api_v1_error");
    if (!headers_sent()) {
        api_v1_responder_erro(
            "internal_error",
            "Ocorreu um erro inesperado. Informe o identificador da requisição ao suporte.",
            500,
        );
    }
});

/** @return array{id:int,company_id:int,scopes:list<string>} */
function api_v1_exigir_cliente(array $requiredScopes): array
{
    $installationMode = strtolower(trim((string) (getenv("TRACE_INSTALLATION_MODE") ?: "")));
    if (!in_array($installationMode, ["central", "remoto", "server"], true)) {
        api_v1_responder_erro("central_only", "A API externa está disponível somente no servidor Central.", 404);
    }

    $authorization = trim((string) ($_SERVER["HTTP_AUTHORIZATION"] ?? ""));
    if (preg_match('/\ABearer\s+(trc_live_[a-f0-9]{64})\z/i', $authorization, $matches) !== 1) {
        header('WWW-Authenticate: Bearer realm="Trace API"');
        api_v1_responder_erro("unauthorized", "Chave de API ausente ou inválida.", 401);
    }

    $tokenHash = hash("sha256", $matches[1]);
    $pdo = obter_conexao_banco();
    $statement = $pdo->prepare(
        "SELECT k.id, k.company_id, k.token_hash, k.scopes_json
         FROM chaves_api_integracao k
         JOIN empresas e ON e.id = k.company_id AND e.archived_at IS NULL
         WHERE k.token_hash = :token_hash
           AND k.revoked_at IS NULL
           AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP(3))
         LIMIT 1",
    );
    $statement->execute(["token_hash" => $tokenHash]);
    $key = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($key) || !hash_equals((string) $key["token_hash"], $tokenHash)) {
        header('WWW-Authenticate: Bearer realm="Trace API"');
        api_v1_responder_erro("unauthorized", "Chave de API ausente ou inválida.", 401);
    }

    $scopes = json_decode((string) $key["scopes_json"], true);
    if (!is_array($scopes) || array_filter($scopes, "is_string") !== $scopes) {
        api_v1_responder_erro("invalid_api_key", "A configuração da chave está inválida.", 401);
    }
    $scopes = array_values(array_unique($scopes));
    if (array_diff($requiredScopes, $scopes) !== []) {
        api_v1_responder_erro("insufficient_scope", "A chave não tem permissão para este recurso.", 403);
    }

    $license = $pdo->prepare(
        "SELECT status FROM licencas WHERE company_id = :company_id ORDER BY id DESC LIMIT 1",
    );
    $license->execute(["company_id" => (int) $key["company_id"]]);
    if ($license->fetchColumn() !== "ATIVA") {
        api_v1_responder_erro("license_inactive", "A licença da empresa está inativa.", 402);
    }

    api_v1_aplicar_limite_requisicoes(
        $pdo,
        (int) $key["id"],
        (int) $key["company_id"],
        in_array("romaneios:write", $requiredScopes, true),
    );

    $touch = $pdo->prepare(
        "UPDATE chaves_api_integracao SET last_used_at = UTC_TIMESTAMP(3)
         WHERE id = :id AND revoked_at IS NULL",
    );
    $touch->execute(["id" => (int) $key["id"]]);

    return [
        "id" => (int) $key["id"],
        "company_id" => (int) $key["company_id"],
        "scopes" => $scopes,
    ];
}

/** @return array{limit:int,cursor:int} */
function api_v1_paginacao(): array
{
    $limit = filter_var(
        $_GET["limit"] ?? 50,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1, "max_range" => 100]],
    );
    $cursor = filter_var(
        $_GET["cursor"] ?? 0,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 0]],
    );
    if ($limit === false || $cursor === false) {
        api_v1_responder_erro("invalid_pagination", "Use limit entre 1 e 100 e cursor numérico não negativo.", 422);
    }
    return ["limit" => $limit, "cursor" => $cursor];
}

function api_v1_validar_data(?string $value, string $field): ?string
{
    if ($value === null || trim($value) === "") {
        return null;
    }
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    if ($date === false || $date->format("Y-m-d") !== $value) {
        api_v1_responder_erro("invalid_filter", "O filtro {$field} deve usar o formato AAAA-MM-DD.", 422);
    }
    return $value;
}

/** @param array<string,int|string> $params */
function api_v1_responder_lista(PDO $pdo, string $sql, array $params, int $limit): never
{
    $statement = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue(
            ":{$name}",
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR,
        );
    }
    $statement->bindValue(":fetch_limit", $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    $nextCursor = $hasMore && $rows !== [] ? (string) $rows[array_key_last($rows)]["id"] : null;
    header("Content-Type: application/json; charset=utf-8");
    responder_json([
        "data" => $rows,
        "pagination" => [
            "limit" => $limit,
            "next_cursor" => $nextCursor,
            "has_more" => $hasMore,
        ],
    ]);
}

function api_v1_validar_id(string $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($id === false) {
        api_v1_responder_erro("invalid_id", "O identificador deve ser um número positivo.", 422);
    }
    return $id;
}

/** @param array{id:int,company_id:int,scopes:list<string>} $client */
function api_v1_registrar_download_pdf(PDO $pdo, array $client, int $romaneioId): void
{
    $metadata = json_encode([
        "integration_id" => $client["id"],
        "request_id" => trace_correlation_id(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare(
        "INSERT INTO logs_auditoria
         (event_uuid, company_id, user_id, action, entity_type, entity_id, metadata)
         VALUES (:event_uuid, :company_id, NULL, 'API_RELATORIO_AUDITORIA_BAIXADO', 'romaneio', :entity_id, :metadata)",
    );
    $statement->execute([
        "event_uuid" => trace_uuid_v4(),
        "company_id" => $client["company_id"],
        "entity_id" => $romaneioId,
        "metadata" => $metadata,
    ]);
}
