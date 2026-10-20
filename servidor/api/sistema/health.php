<?php

declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
$release = metadados_release();

// Liveness check: load balancers must only use PHP and the basic database
// connection here. Operational readiness belongs to /api/prontidao.php.
try {
    $pdo = obter_conexao_banco();
    $pdo->query("SELECT 1");
    responder_json([
        "status" => "ok",
        "php" => true,
        "mysql" => true,
        "version" => $release["version"],
        "commit" => $release["commit"],
        "installation_mode" => trace_e_instalacao_local() ? "local" : "central",
        "checked_at" => date("c"),
    ]);
} catch (Throwable $error) {
    error_log("Healthcheck banco-de-dados failure: " . $error->getMessage());
    responder_json(
        [
            "status" => "degraded",
            "php" => true,
            "mysql" => false,
            "version" => $release["version"],
            "commit" => $release["commit"],
            "installation_mode" => trace_e_instalacao_local() ? "local" : "central",
        ],
        503,
    );
}
