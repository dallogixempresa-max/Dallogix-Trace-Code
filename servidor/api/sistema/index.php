<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

responder_json([
    "service" => "dallogix-trace",
    "status" => "ok",
    "mode" => "local-first",
    "installation_mode" => trace_e_instalacao_local() ? "local" : "central",
]);
