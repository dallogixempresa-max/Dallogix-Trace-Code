<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
$release = metadados_release();
$includeDetails = trace_detalhes_prontidao_autorizados();

// Readiness check for deploys and support. It stays separate from health.php
// so stale devices or a backed-up queue do not remove PHP from the load balancer.
$mysqlConnected = false;
try {
    $pdo = obter_conexao_banco();
    $pdo->query("SELECT 1");
    $mysqlConnected = true;
    $installationCompanyId = null;
    if (trace_e_instalacao_local()) {
        $installationCompanyId = $pdo->query(
            "SELECT company_id FROM instalacoes_locais WHERE id = 1 LIMIT 1",
        )->fetchColumn();
        $installationCompanyId = $installationCompanyId === false ? null : (int) $installationCompanyId;
    }
    $companyCondition = $installationCompanyId === null ? "" : " WHERE company_id = :company_id";
    $companyParams = $installationCompanyId === null ? [] : ["company_id" => $installationCompanyId];
    $queueStatement = $pdo->prepare(
        "SELECT SUM(status = 'PENDENTE') AS pending,
                SUM(status = 'PROCESSANDO') AS processing,
                SUM(status = 'ERRO') AS errors,
                TIMESTAMPDIFF(
                    SECOND,
                    MIN(CASE WHEN status IN ('PENDENTE', 'PROCESSANDO', 'ERRO') THEN created_at END),
                    NOW()
                ) AS oldest_age_seconds
         FROM fila_sincronizacao{$companyCondition}",
    );
    $queueStatement->execute($companyParams);
    $queue = $queueStatement->fetch() ?: [];
    $queueDepth = (int) ($queue["pending"] ?? 0) + (int) ($queue["processing"] ?? 0) + (int) ($queue["errors"] ?? 0);
    $oldestAge = $queue["oldest_age_seconds"] === null
        ? null
        : max(0, (int) $queue["oldest_age_seconds"]);
    $deadLetterStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM sync_dead_letter_queue
         WHERE resolved_at IS NULL" . ($installationCompanyId === null ? "" : " AND company_id = :company_id"),
    );
    $deadLetterStatement->execute($companyParams);
    $deadLetterPending = (int) $deadLetterStatement->fetchColumn();
    $staleSeconds = max(5, (int) (getenv("HEALTH_DEVICE_STALE_SECONDS") ?: 30));
    $staleDevicesStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM dispositivos WHERE active = 1
         AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL {$staleSeconds} SECOND))"
            . ($installationCompanyId === null ? "" : " AND company_id = :company_id"),
    );
    $staleDevicesStatement->execute($companyParams);
    $staleDevices = (int) $staleDevicesStatement->fetchColumn();
    $stuckCommandsStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitacoes_comandos_clp
         WHERE status = 'PROCESSANDO'
         AND (claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
              OR (expires_at IS NOT NULL AND expires_at < NOW()))"
            . ($installationCompanyId === null ? "" : " AND company_id = :company_id"),
    );
    $stuckCommandsStatement->execute($companyParams);
    $stuckCommands = (int) $stuckCommandsStatement->fetchColumn();
    $storagePath = dirname(__DIR__, 3) . "/armazenamento";
    $diskFree = is_dir($storagePath) ? disk_free_space($storagePath) : false;
    $diskTotal = is_dir($storagePath) ? disk_total_space($storagePath) : false;
    $storageWritable = is_dir($storagePath) && is_writable($storagePath);
    $diskFreePercent = is_numeric($diskFree) && is_numeric($diskTotal) && (float) $diskTotal > 0
        ? round(((float) $diskFree / (float) $diskTotal) * 100, 2)
        : null;
    $minDiskPercent = max(1, min(50, (int) (getenv("HEALTH_MIN_DISK_FREE_PERCENT") ?: 5)));
    $maxQueueAgeSeconds = max(60, (int) (getenv("HEALTH_MAX_QUEUE_AGE_SECONDS") ?: 604800));
    $schemaMigrations = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
    $checks = [
        "queue" => [
            "depth" => $queueDepth,
            "pending" => (int) ($queue["pending"] ?? 0),
            "processing" => (int) ($queue["processing"] ?? 0),
            "errors" => (int) ($queue["errors"] ?? 0),
            "oldest_age_seconds" => $oldestAge,
            "maximum_age_seconds" => $maxQueueAgeSeconds,
        ],
        "dead_letter_pending" => $deadLetterPending,
        "heartbeats" => [
            "active_devices" => (int) $pdo->query(
                "SELECT COUNT(*) FROM dispositivos WHERE active = 1"
                    . ($installationCompanyId === null ? "" : " AND company_id = " . (int) $installationCompanyId),
            )->fetchColumn(),
            "stale_devices" => $staleDevices,
            "stale_after_seconds" => $staleSeconds,
        ],
        "commands" => ["stuck_processing" => $stuckCommands],
        "disk" => [
            "free_bytes" => is_numeric($diskFree) ? (int) $diskFree : null,
            "free_percent" => $diskFreePercent,
            "minimum_free_percent" => $minDiskPercent,
        ],
        "storage" => [
            "writable" => $storageWritable,
            "path" => "armazenamento",
        ],
        "schema_migrations" => $schemaMigrations,
        "version" => $release["version"],
        "commit" => $release["commit"],
    ];
    $diskCritical = $diskFreePercent !== null && $diskFreePercent < $minDiskPercent;
    $queueStale = $oldestAge !== null && $oldestAge > $maxQueueAgeSeconds;
    $degraded = !$storageWritable || $schemaMigrations < 1 || (int) ($queue["errors"] ?? 0) > 0 || $deadLetterPending > 0 || $queueStale || $staleDevices > 0 || $stuckCommands > 0;
    $status = $diskCritical ? "critical" : ($degraded ? "degraded" : "ready");
    $response = [
        "status" => $status,
        "php" => true,
        "mysql" => true,
        "checked_at" => date("c"),
    ];
    if ($includeDetails) {
        $response["checks"] = $checks;
    }
    responder_json($response, $status === "ready" ? 200 : 503);
} catch (Throwable $error) {
    error_log("Readiness check failure: " . $error->getMessage());
    $response = [
        "status" => "not_ready",
        "php" => true,
        "mysql" => $mysqlConnected,
        "version" => $release["version"],
        "commit" => $release["commit"],
        "error_code" => $mysqlConnected ? "SCHEMA_INCOMPLETO" : "BANCO_INDISPONIVEL",
    ];
    if ($includeDetails) {
        $response["message"] = $mysqlConnected
            ? "O banco responde, mas o schema operacional não está pronto. Execute as migrations."
            : "Não foi possível conectar ao banco de dados.";
    }
    responder_json($response, 503);
}
