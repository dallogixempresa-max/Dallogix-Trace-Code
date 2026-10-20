<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);
exigir_csrf();
encerrar_sessao_atual();

responder_json(["authenticated" => false]);
