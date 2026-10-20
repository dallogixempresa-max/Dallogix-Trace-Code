<?php

declare(strict_types=1);

function api_v1_limite_por_minuto(string $environmentKey, int $default): int
{
    $configured = filter_var(
        getenv($environmentKey),
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1, "max_range" => 100000]],
    );
    return $configured === false ? $default : $configured;
}

function api_v1_aplicar_limite_requisicoes(
    PDO $pdo,
    int $apiKeyId,
    int $companyId,
    bool $write,
): void {
    $requestClass = $write ? "WRITE" : "READ";
    $keyLimit = api_v1_limite_por_minuto(
        $write ? "API_V1_WRITE_LIMIT_PER_KEY" : "API_V1_READ_LIMIT_PER_KEY",
        $write ? 20 : 120,
    );
    $companyLimit = api_v1_limite_por_minuto(
        $write ? "API_V1_WRITE_LIMIT_PER_COMPANY" : "API_V1_READ_LIMIT_PER_COMPANY",
        $write ? 120 : 600,
    );
    $retryAfter = 0;
    $windowKey = intdiv(time(), 60);

    $pdo->beginTransaction();
    try {
        foreach ([
            ["limites_api_v1_empresa", "company_id", $companyId, $companyLimit],
            ["limites_api_v1_chave", "api_key_id", $apiKeyId, $keyLimit],
        ] as [$table, $scopeColumn, $scopeId, $limit]) {
            $upsert = $pdo->prepare(
                "INSERT INTO {$table} ({$scopeColumn}, request_class, window_key, request_count, last_seen_at)
                 VALUES (:scope_id, :request_class, :window_key, 1, UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                   request_count = IF(window_key = VALUES(window_key), LEAST(request_count + 1, " . ($limit + 1) . "), 1),
                   window_key = VALUES(window_key),
                   last_seen_at = VALUES(last_seen_at)",
            );
            $upsert->execute([
                "scope_id" => $scopeId,
                "request_class" => $requestClass,
                "window_key" => $windowKey,
            ]);
            $read = $pdo->prepare(
                "SELECT request_count FROM {$table}
                 WHERE {$scopeColumn} = :scope_id AND request_class = :request_class
                 FOR UPDATE",
            );
            $read->execute(["scope_id" => $scopeId, "request_class" => $requestClass]);
            if ((int) $read->fetchColumn() > $limit) {
                $retryAfter = max($retryAfter, 60 - (time() % 60));
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    if ($retryAfter > 0) {
        header("Retry-After: {$retryAfter}");
        api_v1_responder_erro(
            "rate_limited",
            "A chave ou empresa excedeu o limite de requisições. Tente novamente após o intervalo informado.",
            429,
        );
    }
}
