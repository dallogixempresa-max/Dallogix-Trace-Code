<?php

declare(strict_types=1);

/** Confirma que o arquivo atual, e não só seu registro, está no PDF sincronizado. */
function trace_prune_candidate_is_in_report(array $manifest, array $candidate, string $currentFileHash): bool
{
    $imageId = (int) ($candidate["image_id"] ?? 0);
    $requestId = (int) ($candidate["request_id"] ?? 0);
    $expectedHash = strtolower(trim((string) ($candidate["image_sha256"] ?? "")));
    $currentFileHash = strtolower(trim($currentFileHash));
    if (preg_match('/\A[a-f0-9]{64}\z/', $currentFileHash) !== 1) {
        return false;
    }
    if ($expectedHash === ""
        && preg_match('/(?:^|\/)keyence-[1-9][0-9]*-([a-f0-9]{64})\.(?:jpg|png)\z/i', (string) ($candidate["path"] ?? ""), $matches) === 1) {
        $expectedHash = strtolower($matches[1]);
    }
    if ($expectedHash !== "" && !hash_equals($expectedHash, $currentFileHash)) {
        return false;
    }

    foreach (($manifest["source_images"] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $sameImage = $imageId > 0 && (int) ($source["image_id"] ?? 0) === $imageId;
        $sameRequest = $requestId > 0 && (int) ($source["request_id"] ?? 0) === $requestId;
        if (!$sameImage && !$sameRequest) {
            continue;
        }
        $sourceHash = strtolower(trim((string) ($source["sha256"] ?? "")));
        if (preg_match('/\A[a-f0-9]{64}\z/', $sourceHash) !== 1
            || !hash_equals($sourceHash, $currentFileHash)) {
            continue;
        }
        return true;
    }
    return false;
}
