<?php

declare(strict_types=1);

/** @deprecated Use responder_json() in new endpoints. */
function json_response(array $payload, int $status = 200): never
{
    responder_json($payload, $status);
}

function obter_conexao_banco(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv("DB_HOST") ?: "mysql";
    $name = getenv("DB_NAME") ?: "trace_local";
    $user = getenv("DB_USER") ?: "trace";
    $password = trim((string) getenv("DB_PASSWORD"));

    if ($password === "") {
        throw new RuntimeException("DB_PASSWORD não configurado.");
    }

    if (in_array(strtolower($password), ["password", "password1234", "change-me-local", "change-me-root"], true)) {
        throw new RuntimeException("DB_PASSWORD não pode usar uma credencial padrão.");
    }

    $connection = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $connection;
}

/** @deprecated Use obter_conexao_banco() in new endpoints. */
function db(): PDO
{
    return obter_conexao_banco();
}

function exigir_metodo_http(array $metodosPermitidos): void
{
    $metodoAtual = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (!in_array($metodoAtual, array_map('strtoupper', $metodosPermitidos), true)) {
        responder_json(["error" => "Método de requisição não permitido."], 405);
    }
}

function ler_json_da_requisicao(bool $exigirTipoJson = true): array
{
    $metodo = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (in_array($metodo, ["GET", "HEAD"], true)) {
        return [];
    }

    if ($exigirTipoJson) {
        $contentType = strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? ""));
        if ($contentType !== "" && !str_contains($contentType, "application/json")) {
            responder_json(["error" => "Content-Type inválido. Envie JSON."], 415);
        }
    }

    $rawBody = file_get_contents("php://input");
    if ($rawBody === false || trim($rawBody) === "") {
        responder_json(["error" => "Corpo da requisição inválido."], 400);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        responder_json(["error" => "JSON da requisição inválido."], 400);
    }

    return $payload;
}

/** @deprecated Use ler_json_da_requisicao() in new endpoints. */
function request_json(): array
{
    return ler_json_da_requisicao();
}

/**
 * No PC industrial, a Dala cadastrada define o destino do CLP. Resolve o
 * endereço uma única vez e só permite IPv4 privado, evitando que a verificação
 * de conectividade alcance serviços públicos, loopback ou metadados da rede.
 */
