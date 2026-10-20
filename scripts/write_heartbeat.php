<?php

declare(strict_types=1);

$path = trim((string) ($argv[1] ?? ""));
if ($path === "") {
    fwrite(STDERR, "Caminho do heartbeat não informado.\n");
    exit(2);
}
if (file_put_contents($path, (string) time(), LOCK_EX) === false) {
    fwrite(STDERR, "Não foi possível atualizar o heartbeat {$path}.\n");
    exit(1);
}
