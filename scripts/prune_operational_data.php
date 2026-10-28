<?php

declare(strict_types=1);

$host = getenv("DB_HOST") ?: "127.0.0.1";
$name = getenv("DB_NAME") ?: "trace_local";
$user = getenv("DB_USER") ?: "trace";
$password = trim((string) getenv("DB_PASSWORD"));
if ($password === "") {
    throw new RuntimeException("DB_PASSWORD não configurado.");
}
$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$days = static function (string $name, int $default): int {
    $value = (int) (getenv($name) ?: $default);
    return max(0, min(36500, $value));
};

$removed = [];

/** @return list<int> */
$selectIds = static function (PDO $pdo, string $sql, array $params): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return array_map(static fn (array $row): int => (int) $row["id"], $statement->fetchAll());
};

$deleteIds = static function (PDO $pdo, string $table, array $ids): int {
    if ($ids === []) {
        return 0;
    }
    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $statement = $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
    $statement->execute($ids);
    return $statement->rowCount();
};

$batch = 5000;
$readingDays = $days("READING_RETENTION_DAYS", 365);
if ($readingDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$readingDays} days"))->format("Y-m-d H:i:s");
    do {
        $ids = $selectIds(
            $pdo,
            "SELECT l.id FROM leituras l
             WHERE l.read_at < :cutoff
               AND NOT EXISTS (SELECT 1 FROM imagens i WHERE i.reading_id = l.id)
               AND NOT EXISTS (SELECT 1 FROM retornos r WHERE r.leitura_id = l.id)
             ORDER BY l.id LIMIT {$batch}",
            ["cutoff" => $cutoff],
        );
        $count = $deleteIds($pdo, "leituras", $ids);
        $removed["leituras"] = ($removed["leituras"] ?? 0) + $count;
    } while (count($ids) === $batch && $count > 0);
}

$sensorDays = $days("SENSOR_EVENT_RETENTION_DAYS", 90);
if ($sensorDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$sensorDays} days"))->format("Y-m-d H:i:s");
    do {
        $ids = $selectIds(
            $pdo,
            "SELECT e.id FROM eventos_sensor e
             WHERE e.detected_at < :cutoff
               AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = e.id)
               AND NOT EXISTS (SELECT 1 FROM solicitacoes_imagens_evidencia c WHERE c.sensor_event_id = e.id)
             ORDER BY e.id LIMIT {$batch}",
            ["cutoff" => $cutoff],
        );
        $count = $deleteIds($pdo, "eventos_sensor", $ids);
        $removed["eventos_sensor"] = ($removed["eventos_sensor"] ?? 0) + $count;
    } while (count($ids) === $batch && $count > 0);
}

// Auditorias novas possuem o mesmo event_uuid da outbox e recebem delivered_at
// quando a entrega remota é confirmada. Isso permite limpar a fila depois de
// poucos dias sem perder a prova de que a auditoria foi sincronizada. Auditorias
// históricas sem esse vínculo ficam congeladas para exportação/inspeção.
$auditDays = $days("AUDIT_RETENTION_DAYS", 730);
if ($auditDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$auditDays} days"))->format("Y-m-d H:i:s");
    do {
        $ids = $selectIds(
            $pdo,
            "SELECT a.id FROM logs_auditoria a
             WHERE a.created_at < :cutoff
               AND a.event_uuid IS NOT NULL
               AND a.delivered_at IS NOT NULL
               AND NOT EXISTS (
                 SELECT 1 FROM fila_sincronizacao pending
                 WHERE pending.company_id = a.company_id
                   AND pending.event_uuid = a.event_uuid
                   AND pending.status IN ('PENDENTE', 'PROCESSANDO', 'ERRO')
               )
             ORDER BY a.id LIMIT {$batch}",
            ["cutoff" => $cutoff],
        );
        $count = $deleteIds($pdo, "logs_auditoria", $ids);
        $removed["logs_auditoria"] = ($removed["logs_auditoria"] ?? 0) + $count;
    } while (count($ids) === $batch && $count > 0);
}

$sentDays = $days("SYNC_SENT_RETENTION_DAYS", 30);
if ($sentDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$sentDays} days"))->format("Y-m-d H:i:s");
    do {
        $statement = $pdo->prepare(
            "DELETE FROM fila_sincronizacao
             WHERE status = 'ENVIADO' AND updated_at IS NOT NULL AND updated_at < :cutoff
             LIMIT {$batch}",
        );
        $statement->execute(["cutoff" => $cutoff]);
        $count = $statement->rowCount();
        $removed["fila_sincronizacao"] = ($removed["fila_sincronizacao"] ?? 0) + $count;
    } while ($count === $batch);
}

$errorDays = $days("ERROR_LOG_RETENTION_DAYS", 365);
if ($errorDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$errorDays} days"))->format("Y-m-d H:i:s");
    do {
        $statement = $pdo->prepare(
            "DELETE FROM logs_erros WHERE criado_em < :cutoff LIMIT {$batch}",
        );
        $statement->execute(["cutoff" => $cutoff]);
        $count = $statement->rowCount();
        $removed["logs_erros"] = ($removed["logs_erros"] ?? 0) + $count;
    } while ($count === $batch);
}

$idempotencyDays = $days("API_IDEMPOTENCY_RETENTION_DAYS", 730);
if ($idempotencyDays > 0) {
    $cutoff = (new DateTimeImmutable("-{$idempotencyDays} days"))->format("Y-m-d H:i:s");
    do {
        $statement = $pdo->prepare(
            "DELETE FROM api_romaneios_idempotencia
             WHERE response_json IS NOT NULL AND created_at < :cutoff
             ORDER BY created_at LIMIT {$batch}",
        );
        $statement->execute(["cutoff" => $cutoff]);
        $count = $statement->rowCount();
        $removed["api_romaneios_idempotencia"] = ($removed["api_romaneios_idempotencia"] ?? 0) + $count;
    } while ($count === $batch);
}

foreach (["limites_api_v1_chave", "limites_api_v1_empresa"] as $table) {
    do {
        $statement = $pdo->prepare(
            "DELETE FROM {$table} WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 48 HOUR) LIMIT {$batch}",
        );
        $statement->execute();
        $count = $statement->rowCount();
        $removed[$table] = ($removed[$table] ?? 0) + $count;
    } while ($count === $batch);
}

do {
    $statement = $pdo->prepare(
        "DELETE FROM limites_logs_frontend WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 48 HOUR) LIMIT {$batch}",
    );
    $statement->execute();
    $count = $statement->rowCount();
    $removed["limites_logs_frontend"] = ($removed["limites_logs_frontend"] ?? 0) + $count;
} while ($count === $batch);

do {
    $statement = $pdo->prepare(
        "DELETE FROM limites_login
         WHERE (blocked_until IS NULL AND window_started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
            OR (blocked_until IS NOT NULL AND blocked_until <= NOW())
         LIMIT {$batch}",
    );
    $statement->execute();
    $count = $statement->rowCount();
    $removed["limites_login"] = ($removed["limites_login"] ?? 0) + $count;
} while ($count === $batch);

$parts = [];
foreach (["leituras", "eventos_sensor", "fila_sincronizacao", "logs_auditoria", "logs_erros", "limites_login", "api_romaneios_idempotencia", "limites_api_v1_chave", "limites_api_v1_empresa", "limites_logs_frontend"] as $table) {
    $parts[] = $table . "=" . (int) ($removed[$table] ?? 0);
}
echo "Retenção operacional: " . implode(" ", $parts) . ".\n";
