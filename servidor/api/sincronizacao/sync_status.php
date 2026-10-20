<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioStatusSincronizacao.php";

use App\Persistencia\RepositorioStatusSincronizacao;

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}

session_write_close();
$pdo = db();
$scope = "q.company_id = :company_id";
$params = ["company_id" => $user["company_id"]];
$queueSummary = (new RepositorioStatusSincronizacao($pdo))->obterResumoFila((int) $user["company_id"]);
$summary = $queueSummary["summary"];
$oldestQueueAt = $queueSummary["oldest_at"];

$recent = $pdo->prepare(
    "SELECT q.id, q.aggregate_type, q.aggregate_id, q.status, q.attempts, q.transient_attempts, q.last_error, q.available_at, q.created_at FROM fila_sincronizacao q WHERE {$scope} AND q.status IN ('PENDENTE', 'ERRO') ORDER BY q.id DESC LIMIT 20",
);
$recent->execute($params);
$oldestQueueAgeSeconds = null;
if ($oldestQueueAt !== null && trim((string) $oldestQueueAt) !== "") {
    try {
        $oldestQueueDate = new DateTimeImmutable((string) $oldestQueueAt, new DateTimeZone("UTC"));
        $oldestQueueAgeSeconds = max(0, time() - $oldestQueueDate->getTimestamp());
    } catch (Throwable $exception) {
        $oldestQueueAgeSeconds = null;
    }
}
$maximumQueueAgeSeconds = max(60, (int) (getenv("HEALTH_MAX_QUEUE_AGE_SECONDS") ?: 604800));
$deadLetter = $pdo->prepare(
    "SELECT COUNT(*) FROM sync_dead_letter_queue
     WHERE resolved_at IS NULL AND company_id = :company_id",
);
$deadLetter->execute($params);
$deadLetterPending = (int) $deadLetter->fetchColumn();
$remoteUrl = trim((string) (getenv("SYNC_REMOTE_BATCH_URL") ?: ""));
if ($remoteUrl === "") {
    $remoteUrl = trim((string) (getenv("SYNC_REMOTE_URL") ?: ""));
}
if ($remoteUrl === "") {
    $settings = $pdo->prepare(
        "SELECT sync_remote_url FROM configuracoes_empresa WHERE company_id = :company_id LIMIT 1",
    );
    $settings->execute(["company_id" => $user["company_id"]]);
    $remoteUrl = trim((string) ($settings->fetchColumn() ?: ""));
}
$centralUrlConfigured = trim((string) (getenv("TRACE_CENTRAL_URL") ?: "")) !== "";
$installation = $pdo->prepare(
    "SELECT last_remote_sync_at, last_remote_sync_error
     FROM instalacoes_locais
     WHERE id = 1 AND company_id = :company_id LIMIT 1",
);
$installation->execute(["company_id" => $user["company_id"]]);
$installationStatus = $installation->fetch() ?: null;
$centralMode = !trace_e_instalacao_local();
$centralPcStatus = null;
if ($centralMode) {
    $industrialPcTableAvailable = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'instalacoes_industriais'
         LIMIT 1",
    )->fetchColumn();
    if ($industrialPcTableAvailable) {
        $centralPc = $pdo->prepare(
            "SELECT status, last_seen_at
             FROM instalacoes_industriais
             WHERE company_id = :company_id AND sync_token_hash IS NOT NULL
             ORDER BY last_seen_at DESC, id DESC LIMIT 1",
        );
        $centralPc->execute(["company_id" => $user["company_id"]]);
        $centralPcStatus = $centralPc->fetch() ?: null;
    }
}
$installationRegistered = $installationStatus !== null || $centralPcStatus !== null;
$remoteConfigured = $centralMode
    ? true
    : $remoteUrl !== "" || ($centralUrlConfigured && $installationRegistered);
$lastSyncAt = $centralMode
    ? ($centralPcStatus["last_seen_at"] ?? null)
    : ($installationStatus["last_remote_sync_at"] ?? null);
$lastSyncError = $centralMode
    ? ""
    : trim((string) ($installationStatus["last_remote_sync_error"] ?? ""));
$lastSyncAgeSeconds = null;
if ($lastSyncAt !== null && trim((string) $lastSyncAt) !== "") {
    try {
        $lastSync = new DateTimeImmutable((string) $lastSyncAt, new DateTimeZone("UTC"));
        $lastSyncAgeSeconds = max(
            0,
            time() - $lastSync->getTimestamp(),
        );
    } catch (Throwable $exception) {
        $lastSyncAgeSeconds = null;
    }
}
$pcSignalLimitSeconds = max(5, min(300, (int) (getenv("HEALTH_DEVICE_STALE_SECONDS") ?: 30)));
$reportedPcStatus = strtoupper(trim((string) ($centralPcStatus["status"] ?? "")));
if ($reportedPcStatus === "ERRO") {
    $pcStatus = "ERRO";
} elseif ($lastSyncAgeSeconds !== null && $lastSyncAgeSeconds <= $pcSignalLimitSeconds && $lastSyncError === "") {
    $pcStatus = "ONLINE";
} elseif ($reportedPcStatus === "OFFLINE" || $lastSyncAt !== null) {
    $pcStatus = "OFFLINE";
} else {
    $pcStatus = "DESCONHECIDO";
}
$pcOnline = $pcStatus === "ONLINE";
$centralSync = [
    "configured" => $remoteConfigured,
    "central_url_configured" => $centralUrlConfigured,
    "installation_registered" => $installationRegistered,
    "pc_online" => $pcOnline,
    "pc_status" => $pcStatus,
    "last_sync_at" => $lastSyncAt,
    "last_sync_age_seconds" => $lastSyncAgeSeconds,
    "pc_signal_limit_seconds" => $pcSignalLimitSeconds,
    "last_error" => $lastSyncError !== "" ? $lastSyncError : null,
];

json_response([
    "data" => [
        "summary" => $summary,
        "recent" => $recent->fetchAll(),
        "queue_health" => [
            "oldest_at" => $oldestQueueAt,
            "oldest_age_seconds" => $oldestQueueAgeSeconds,
            "maximum_age_seconds" => $maximumQueueAgeSeconds,
            "stale" => $oldestQueueAgeSeconds !== null && $oldestQueueAgeSeconds > $maximumQueueAgeSeconds,
            "dead_letter_pending" => $deadLetterPending,
        ],
        "remote_configured" => $remoteConfigured,
        "central_sync" => $centralSync,
        "checked_at" => date("c"),
    ],
]);
