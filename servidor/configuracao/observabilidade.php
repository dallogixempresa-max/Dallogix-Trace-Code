<?php

declare(strict_types=1);

/** Retorna um identificador único controlado pelo servidor para esta requisição. */
function trace_correlation_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $id = bin2hex(random_bytes(16));

    if (!headers_sent()) {
        header("X-Correlation-ID: {$id}");
        header("X-Request-ID: {$id}");
    }
    return $id;
}

function trace_correlacao_externa(?string $candidate = null): ?string
{
    $provided = trim((string) ($candidate
        ?? $_SERVER["HTTP_X_CORRELATION_ID"]
        ?? $_SERVER["HTTP_X_REQUEST_ID"]
        ?? ""));

    return preg_match('/\A[A-Za-z0-9._:-]{8,80}\z/', $provided) === 1
        ? $provided
        : null;
}

function trace_redigir_segredos(string $mensagem): string
{
    $mensagem = preg_replace('/Bearer\s+[^\s,;]+/i', "Bearer [omitido]", $mensagem) ?: $mensagem;
    $segredo = "~([\"']?(?:[a-z0-9_]*_)?(?:password|senha|token|secret|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|csrf|cookie)[\"']?\\s*[:=]\\s*)(?!(?:Bearer\\s+\\[omitido\\]|\\[omitido\\]))(?:\"[^\"]*\"|'[^']*'|[^\\s,;&]+)~i";
    $mensagem = preg_replace($segredo, '$1[omitido]', $mensagem) ?: $mensagem;
    return preg_replace('#(https?://)[^/@\s]+@#i', '$1[credenciais]@', $mensagem) ?: $mensagem;
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function trace_payload_com_correlacao(array $payload): array
{
    $requestId = trace_correlation_id();
    $externalId = isset($payload["upstream_correlation_id"])
        ? trace_correlacao_externa((string) $payload["upstream_correlation_id"])
        : null;
    if ($externalId === null && isset($payload["correlation_id"])) {
        $externalId = trace_correlacao_externa((string) $payload["correlation_id"]);
    }
    $externalId ??= trace_correlacao_externa();
    $payload["correlation_id"] = $requestId;
    if ($externalId !== null && !hash_equals($requestId, $externalId)) {
        $payload["upstream_correlation_id"] = $externalId;
    } else {
        unset($payload["upstream_correlation_id"]);
    }
    return $payload;
}

function trace_detalhes_prontidao_autorizados(): bool
{
    $configured = trim((string) (getenv("TRACE_READINESS_TOKEN") ?: ""));
    $provided = trim((string) ($_SERVER["HTTP_X_TRACE_READINESS_TOKEN"] ?? ""));
    if ($configured !== "" && $provided !== "" && hash_equals($configured, $provided)) {
        return true;
    }

    $user = session_status() === PHP_SESSION_ACTIVE && function_exists("obter_usuario_sessao")
        ? obter_usuario_sessao()
        : null;
    return is_array($user) && in_array(
        strtoupper((string) ($user["role"] ?? "")),
        ["ADMIN_DALLOGIX", "ADMIN_EMPRESA", "SUPERVISOR"],
        true,
    );
}
