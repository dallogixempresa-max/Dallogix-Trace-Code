<?php

declare(strict_types=1);

$version = trim((string) ($argv[1] ?? ""));
if ($version === "" || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $version) !== 1) {
    fwrite(STDERR, "Uso: php scripts/version_assets.php VERSION\n");
    exit(2);
}

$basePath = trim((string) ($argv[2] ?? dirname(__DIR__)));
$root = rtrim($basePath, "/") . "/interface";
$extensions = ["html", "js", "css", "json"];
$changed = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }
    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if ($contents === false) {
        continue;
    }
    // Delimita a referência capturada para que um versionamento iniciado por
    // número não seja interpretado como outro grupo pelo mecanismo de regex.
    $updated = preg_replace('/([?&]v=)[A-Za-z0-9._-]+/', '${1}' . $version, $contents);
    if ($updated === null || $updated === $contents) {
        continue;
    }
    file_put_contents($path, $updated);
    $changed++;
}

fwrite(STDOUT, "OK: {$changed} arquivos de assets versionados com {$version}.\n");
