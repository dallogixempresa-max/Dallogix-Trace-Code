<?php
declare(strict_types=1);

/**
 * Prepara e resolve um diretório de armazenamento criado pela aplicação.
 * Retorna null para caminhos inválidos, links simbólicos ou diretórios sem
 * permissão de escrita, mantendo o erro controlável pelo endpoint chamador.
 */
function trace_prepare_image_storage_directory(string $storageDirectory, string $relativeDirectory): ?string
{
    $storageRoot = realpath($storageDirectory);
    if ($storageRoot === false || trim($relativeDirectory) === '') {
        return null;
    }

    $relative = ltrim($relativeDirectory, '/');
    if (str_starts_with($relative, 'armazenamento/')) {
        $relative = substr($relative, strlen('armazenamento/'));
    }
    if (preg_match('/\A(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\z/', $relative) !== 1) {
        return null;
    }

    $segments = explode('/', $relative);
    $candidate = $storageRoot;
    foreach ($segments as $segment) {
        $candidate .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($candidate)) {
            return null;
        }
        if (!is_dir($candidate)) {
            set_error_handler(static function (): bool {
                return true;
            });
            $created = mkdir($candidate, 0700);
            restore_error_handler();
            if (!$created && !is_dir($candidate)) {
                return null;
            }
        }
        $resolvedSegment = realpath($candidate);
        if ($resolvedSegment === false
            || !str_starts_with($resolvedSegment, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_dir($resolvedSegment)) {
            return null;
        }
        $candidate = $resolvedSegment;
    }

    $resolved = realpath($candidate);
    if ($resolved === false
        || !str_starts_with($resolved, $storageRoot . DIRECTORY_SEPARATOR)
        || !is_dir($resolved)
        || !is_writable($resolved)) {
        return null;
    }
    return $resolved;
}
/** Resolve somente arquivos existentes dentro do armazenamento de evidências. */
function trace_image_storage_path(string $storageDirectory, string $storedPath): ?string
{
    $storageRoot = realpath($storageDirectory);
    if ($storageRoot === false || trim($storedPath) === '') {
        return null;
    }

    if (str_contains($storedPath, "\\") || str_contains($storedPath, "\0")) {
        return null;
    }
    $storagePrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($storedPath, DIRECTORY_SEPARATOR)) {
        if (!str_starts_with($storedPath, $storagePrefix)) {
            return null;
        }
        $relative = substr($storedPath, strlen($storagePrefix));
    } else {
        $relative = $storedPath;
    }
    if (str_starts_with($relative, 'armazenamento/')) {
        $relative = substr($relative, strlen('armazenamento/'));
    }

    $segments = explode('/', $relative);
    if ($relative === '' || in_array('', $segments, true)
        || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return null;
    }
    $candidate = $storageRoot;
    foreach ($segments as $segment) {
        $candidate .= DIRECTORY_SEPARATOR . $segment;
        // Reject links in every component, including the final file. A realpath
        // prefix check alone accepts internal links across company directories.
        if (is_link($candidate)) {
            return null;
        }
    }
    $resolved = realpath($candidate);
    if ($resolved === false || !str_starts_with($resolved, $storagePrefix) || !is_file($resolved)) {
        return null;
    }
    return $resolved;
}
/** Remove only an existing regular file resolved inside the evidence storage. */
function trace_remove_image_storage_file(string $storageDirectory, string $storedPath): ?bool
{
    $resolved = trace_image_storage_path($storageDirectory, $storedPath);
    if ($resolved === null) {
        return null;
    }

    // nosemgrep: php.lang.security.unlink-use.unlink-use -- Caminho resolvido sob a raiz canônica; os componentes com link simbólico são recusados acima.
    return unlink($resolved);
}

/** Confirma que a evidência enviada pertence exatamente à solicitação reservada. */
function trace_evidence_image_path(
    string $storageDirectory,
    int $companyId,
    int $equipmentId,
    int $requestId,
    string $storedPath,
): ?string {
    $prefix = "company_{$companyId}/equipment_{$equipmentId}/";
    if (!str_starts_with($storedPath, $prefix)) {
        return null;
    }
    $basename = substr($storedPath, strlen($prefix));
    $filenamePrefix = str_starts_with($basename, "keyence-{$requestId}-")
        ? "keyence-{$requestId}-"
        : "capture-{$requestId}-";
    if (preg_match('/\A(?:keyence|capture)-' . $requestId . '-[a-f0-9]{64}\.(jpg|png)\z/', $basename) !== 1) {
        return null;
    }
    $resolved = trace_image_storage_path($storageDirectory, $storedPath);
    if ($resolved === null) {
        return null;
    }
    $size = filesize($resolved);
    if ($size === false || $size < 1 || $size > 10 * 1024 * 1024) {
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($resolved);
    $expectedMime = str_ends_with($basename, '.jpg') ? 'image/jpeg' : 'image/png';
    if ($mime !== $expectedMime) {
        return null;
    }
    $expectedHash = substr($basename, strlen($filenamePrefix), 64);
    if (hash_file('sha256', $resolved) !== $expectedHash) {
        return null;
    }
    try {
        $dimensions = getimagesize($resolved);
    } catch (Throwable) {
        return null;
    }
    if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
        || $dimensions[0] > 10000 || $dimensions[1] > 10000) {
        return null;
    }
    return $resolved;
}
