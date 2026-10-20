<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));

if ($method === "POST") {
    $user = require_session_user();
    $companyId = $user["company_id"] === null ? null : (int) $user["company_id"];
    if ($companyId === null && $user["role"] !== "ADMIN_DALLOGIX") {
        json_response(["error" => "Usuário sem empresa vinculada."], 403);
    }
    require_csrf();
    session_write_close();

    $payload = request_json();
    $level = strtolower(trim((string) ($payload["nivel"] ?? "erro")));
    if (!in_array($level, ["aviso", "erro"], true)) {
        json_response(["error" => "Nível de log inválido."], 422);
    }
    $sourceContext = trim((string) ($payload["contexto"] ?? "geral"));
    $sourceContext = preg_replace('/[^a-zA-Z0-9_.:-]/', "_", $sourceContext) ?: "geral";
    $origin = "frontend." . mb_substr($sourceContext, 0, 100);
    $error = is_array($payload["erro"] ?? null) ? $payload["erro"] : [];
    $message = trim((string) ($error["mensagem"] ?? "Falha reportada no navegador."));
    $message = preg_replace('/[\x00-\x1F\x7F]+/u', " ", $message) ?: "Falha reportada no navegador.";
    $message = trace_redigir_segredos($message);
    $message = mb_substr($message, 0, 1000);
    $context = json_encode([
        "nivel" => $level,
        "contexto" => mb_substr($sourceContext, 0, 100),
        "correlation_id" => trace_correlation_id(),
        "upstream_correlation_id" => trace_correlacao_externa(
            isset($payload["correlation_id"]) ? (string) $payload["correlation_id"] : null,
        ) ?? trace_correlacao_externa(),
        "tipo_erro" => mb_substr((string) ($error["nome"] ?? ""), 0, 80),
        "codigo" => mb_substr((string) ($error["codigo"] ?? ""), 0, 80),
        "status_http" => filter_var($error["status"] ?? null, FILTER_VALIDATE_INT) ?: null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $pdo = db();
    $companyFilter = $companyId === null ? "company_id IS NULL" : "company_id = :company_id";
    $actorParams = ["user_id" => $user["id"]];
    if ($companyId !== null) {
        $actorParams["company_id"] = $companyId;
    }
    $duplicate = $pdo->prepare(
        "SELECT id FROM logs_erros
         WHERE {$companyFilter} AND user_id = :user_id
           AND origem = :origem AND mensagem = :mensagem
           AND criado_em >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
         LIMIT 1",
    );
    $windowKey = intdiv(time(), 60);
    $limiterKey = hash("sha256", ($companyId ?? 0) . ":" . (int) $user["id"]);
    $rateLimit = $pdo->prepare(
        "INSERT INTO limites_logs_frontend (limiter_key, window_key, request_count, last_seen_at)
         VALUES (:limiter_key, :window_key, 1, UTC_TIMESTAMP(3))
         ON DUPLICATE KEY UPDATE
           request_count = IF(window_key = VALUES(window_key), LEAST(request_count + 1, 31), 1),
           window_key = VALUES(window_key),
           last_seen_at = VALUES(last_seen_at)",
    );
    $rateLimit->execute([
        "limiter_key" => $limiterKey,
        "window_key" => $windowKey,
    ]);
    $rateLimitStatus = $pdo->prepare(
        "SELECT request_count FROM limites_logs_frontend WHERE limiter_key = :limiter_key",
    );
    $rateLimitStatus->execute(["limiter_key" => $limiterKey]);
    if ((int) $rateLimitStatus->fetchColumn() > 30) {
        json_response(["data" => ["recorded" => false, "reason" => "rate_limited"]], 202);
    }

    $duplicate->execute([
        ...$actorParams,
        "origem" => $origin,
        "mensagem" => $message,
    ]);
    if ($duplicate->fetchColumn()) {
        json_response(["data" => ["recorded" => false, "reason" => "duplicate"]], 202);
    }

    $insert = $pdo->prepare(
        "INSERT INTO logs_erros (company_id, user_id, origem, mensagem, contexto)
         VALUES (:company_id, :user_id, :origem, :mensagem, :contexto)",
    );
    $insert->execute([
        "company_id" => $companyId,
        "user_id" => $user["id"],
        "origem" => $origin,
        "mensagem" => $message,
        "contexto" => $context,
    ]);
    json_response(["data" => ["recorded" => true]], 201);
}

if ($method !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}

$user = require_role(["ADMIN_DALLOGIX", "ADMIN_EMPRESA"]);
$pdo = db();
$isDallogixAdmin = $user["role"] === "ADMIN_DALLOGIX";
if ($isDallogixAdmin) {
    $count = $pdo->query("SELECT COUNT(*) FROM logs_erros");
} else {
    $count = $pdo->prepare("SELECT COUNT(*) FROM logs_erros WHERE company_id = :company_id");
    $count->execute(["company_id" => $user["company_id"]]);
}
$total = (int) $count->fetchColumn();
$perPage = 100;
$pages = max(1, (int) ceil($total / $perPage));
$page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page < 1 ? 1 : min((int) $page, $pages);
$offset = ($page - 1) * $perPage;
$sql = $isDallogixAdmin
    ? "SELECT l.id, l.origem, l.mensagem, l.contexto, l.criado_em,
              e.name AS empresa, u.name AS usuario
       FROM logs_erros l
       LEFT JOIN empresas e ON e.id = l.company_id
       LEFT JOIN usuarios u ON u.id = l.user_id
       ORDER BY l.id DESC LIMIT :per_page OFFSET :offset"
    : "SELECT l.id, l.origem, l.mensagem, l.contexto, l.criado_em,
              e.name AS empresa, u.name AS usuario
       FROM logs_erros l
       LEFT JOIN empresas e ON e.id = l.company_id
       LEFT JOIN usuarios u ON u.id = l.user_id
       WHERE l.company_id = :company_id
       ORDER BY l.id DESC LIMIT :per_page OFFSET :offset";
$query = $pdo->prepare($sql);
if (!$isDallogixAdmin) {
    $query->bindValue(":company_id", (int) $user["company_id"], PDO::PARAM_INT);
}
$query->bindValue(":per_page", $perPage, PDO::PARAM_INT);
$query->bindValue(":offset", $offset, PDO::PARAM_INT);
$query->execute();
$configuredRetention = (int) (getenv("ERROR_LOG_RETENTION_DAYS") ?: 365);
$retentionDays = max(0, min(36500, $configuredRetention));
json_response([
    "data" => $query->fetchAll(),
    "pagination" => ["page" => $page, "per_page" => $perPage, "total" => $total, "pages" => $pages],
    "retention_days" => $retentionDays,
]);
