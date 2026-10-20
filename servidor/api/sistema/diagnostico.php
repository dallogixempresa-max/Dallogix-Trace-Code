<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["GET"]);
$user = exigir_perfil(["ADMIN_DALLOGIX", "ADMIN_EMPRESA", "SUPERVISOR"]);
$pdo = obter_conexao_banco();
$companyId = $user["role"] === "ADMIN_DALLOGIX" ? null : (int) $user["company_id"];

$companyWhere = $companyId === null ? "" : " WHERE company_id = :company_id";
$companyParams = $companyId === null ? [] : ["company_id" => $companyId];

$queue = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(status = 'PENDENTE') AS pending,
            SUM(status = 'PROCESSANDO') AS processing,
            SUM(status = 'ERRO') AS errors,
            MIN(CASE WHEN status IN ('PENDENTE', 'PROCESSANDO', 'ERRO') THEN created_at END) AS oldest_at
     FROM fila_sincronizacao{$companyWhere}",
);
$queue->execute($companyParams);
$queueRow = $queue->fetch() ?: [];

$deviceWhere = $companyId === null ? "" : " AND d.company_id = :company_id";
$devices = $pdo->prepare(
    "SELECT d.device_type, COUNT(*) AS total,
            SUM(d.active = 1) AS active,
            MAX(d.last_seen_at) AS last_seen_at,
            SUM(d.active = 1 AND (d.last_seen_at IS NULL OR d.last_seen_at < DATE_SUB(NOW(), INTERVAL 30 SECOND))) AS stale
     FROM dispositivos d
     WHERE 1 = 1{$deviceWhere}
     GROUP BY d.device_type
     ORDER BY d.device_type",
);
$devices->execute($companyParams);

$equipmentWhere = $companyId === null ? "" : " WHERE e.company_id = :company_id";
$commands = $pdo->prepare(
    "SELECT c.id, c.command, c.status, c.requested_at, c.completed_at, c.response_message
     FROM solicitacoes_comandos_clp c
     JOIN equipamentos e ON e.id = c.equipment_id{$equipmentWhere}
     ORDER BY c.id DESC LIMIT 10",
);
$commands->execute($companyParams);

$industrialPcTableAvailable = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'status_pc_industrial'
     LIMIT 1",
)->fetchColumn();
$errorWhere = $companyId === null ? "1=1" : "l.company_id = :company_id";
$errors = $pdo->prepare(
    "SELECT l.id, l.origem, l.mensagem, l.criado_em
     FROM logs_erros l WHERE {$errorWhere}
     ORDER BY l.id DESC LIMIT 10",
);
$errors->execute($companyParams);

$deadLetter = $pdo->prepare(
    "SELECT COUNT(*) FROM sync_dead_letter_queue WHERE resolved_at IS NULL"
        . ($companyId === null ? "" : " AND company_id = :company_id"),
);
$deadLetter->execute($companyParams);

$installation = null;
if ($companyId !== null) {
    $installationQuery = $pdo->prepare(
        "SELECT last_remote_sync_at, last_remote_sync_error
         FROM instalacoes_locais WHERE id = 1 AND company_id = :company_id LIMIT 1",
    );
    $installationQuery->execute(["company_id" => $companyId]);
    $installation = $installationQuery->fetch() ?: null;
}

$industrialPc = null;
if ($companyId !== null && !trace_e_instalacao_local()) {
    if ($industrialPcTableAvailable) {
        $industrialPcQuery = $pdo->prepare(
            "SELECT status, last_seen_at, details,
                    CASE WHEN status = 'ONLINE'
                           AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 30 SECOND)
                         THEN 1 ELSE 0 END AS online
             FROM status_pc_industrial
             WHERE company_id = :company_id LIMIT 1",
        );
        $industrialPcQuery->execute(["company_id" => $companyId]);
        $industrialPc = $industrialPcQuery->fetch() ?: null;
    }
}

$storagePath = dirname(__DIR__, 3) . "/armazenamento";
$diskFree = is_dir($storagePath) ? disk_free_space($storagePath) : false;
$diskTotal = is_dir($storagePath) ? disk_total_space($storagePath) : false;
$diskFreePercent = is_numeric($diskFree) && is_numeric($diskTotal) && (float) $diskTotal > 0
    ? round(((float) $diskFree / (float) $diskTotal) * 100, 2)
    : null;
$diskFreeBytes = is_numeric($diskFree) ? (int) $diskFree : null;
$diskTotalBytes = is_numeric($diskTotal) ? (int) $diskTotal : null;
$diskUsedBytes = $diskFreeBytes !== null && $diskTotalBytes !== null
    ? max(0, $diskTotalBytes - $diskFreeBytes)
    : null;
$localInstallation = trace_e_instalacao_local();
$companyUsesRemotePc = $companyId !== null && !$localInstallation;
$disk = [
    "scope" => $localInstallation || $companyUsesRemotePc ? "pc_industrial" : "servidor_central",
    "free_bytes" => $companyUsesRemotePc ? null : $diskFreeBytes,
    "total_bytes" => $companyUsesRemotePc ? null : $diskTotalBytes,
    "used_bytes" => $companyUsesRemotePc ? null : $diskUsedBytes,
    "free_percent" => $companyUsesRemotePc ? null : $diskFreePercent,
    "online" => !$companyUsesRemotePc,
    "last_seen_at" => null,
];
if ($industrialPc !== null) {
    $pcDetails = is_string($industrialPc["details"] ?? null)
        ? json_decode($industrialPc["details"], true)
        : [];
    $pcDisk = is_array($pcDetails) && is_array($pcDetails["disk"] ?? null)
        ? $pcDetails["disk"]
        : [];
    $pcFreeBytes = isset($pcDisk["free_bytes"]) ? (int) $pcDisk["free_bytes"] : null;
    $pcTotalBytes = isset($pcDisk["total_bytes"]) ? (int) $pcDisk["total_bytes"] : null;
    $disk = [
        "scope" => "pc_industrial",
        "free_bytes" => $pcFreeBytes,
        "total_bytes" => $pcTotalBytes,
        "used_bytes" => $pcFreeBytes !== null && $pcTotalBytes !== null
            ? max(0, $pcTotalBytes - $pcFreeBytes)
            : null,
        "free_percent" => isset($pcDisk["free_percent"]) ? (float) $pcDisk["free_percent"] : null,
        "online" => (bool) $industrialPc["online"],
        "status" => $industrialPc["status"],
        "last_seen_at" => $industrialPc["last_seen_at"],
    ];
}

json_response([
    "data" => [
        "release" => metadados_release(),
        "database" => ["status" => "ok"],
        "queue" => [
            "total" => (int) ($queueRow["total"] ?? 0),
            "pending" => (int) ($queueRow["pending"] ?? 0),
            "processing" => (int) ($queueRow["processing"] ?? 0),
            "errors" => (int) ($queueRow["errors"] ?? 0),
            "oldest_at" => $queueRow["oldest_at"] ?? null,
        ],
        "devices" => $devices->fetchAll(),
        "commands" => $commands->fetchAll(),
        "errors" => $errors->fetchAll(),
        "dead_letter_pending" => (int) $deadLetter->fetchColumn(),
        "installation" => $installation,
        "disk" => $disk,
        "checked_at" => date("c"),
    ],
]);
