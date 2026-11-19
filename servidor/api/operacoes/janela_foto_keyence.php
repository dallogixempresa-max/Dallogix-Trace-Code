<?php
declare(strict_types=1);

require_once __DIR__ . "/temporizacao_leitor.php";

/** @return array<int, array{from_ms:int, until_ms:int}> */
function trace_janelas_recuperacao_keyence(PDO $pdo, array $device, int $loadingId): array
{
    $query = $pdo->prepare(
        "SELECT s.id, s.event_at_epoch_ms, s.device_detected_at,
                CAST(ROUND(UNIX_TIMESTAMP(c.finished_at) * 1000) AS UNSIGNED) AS finished_ms
         FROM carregamentos c
         JOIN romaneios rm ON rm.id = c.romaneio_id AND rm.company_id = c.company_id
         JOIN eventos_sensor s ON s.carregamento_id = c.id AND s.equipment_id = c.equipment_id
         WHERE c.id = ? AND c.equipment_id = ? AND c.company_id = ?
           AND c.state = 'FINALIZADO' AND c.finished_at IS NOT NULL AND rm.status = 'FINALIZADO'",
    );
    $query->execute([$loadingId, (int) $device["equipment_id"], (int) $device["company_id"]]);
    $events = [];
    $finishedMs = 0;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $time = (int) ($event["event_at_epoch_ms"] ?? 0);
        if ($time < 1 && $event["device_detected_at"] !== null) {
            $time = trace_sql_datetime_to_epoch_ms((string) $event["device_detected_at"]);
        }
        if ($time < 1) {
            // Um horário de recebimento seria insuficiente para garantir a
            // ordem entre dois sacos próximos.
            return [];
        }
        $events[] = ["id" => (int) $event["id"], "at" => $time];
        $finishedMs = (int) $event["finished_ms"];
    }
    usort($events, static fn(array $a, array $b): int => $a["at"] <=> $b["at"]);

    $travelMs = trace_scanner_timing()["travel_ms"];
    $lastWindow = filter_var(getenv("KEYENCE_IMAGE_ARCHIVE_MATCH_WINDOW_MS"), FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 500, "max_range" => 10000],
    ]);
    $windows = [];
    foreach ($events as $index => $event) {
        $current = $event["at"];
        $next = $events[$index + 1]["at"] ?? null;
        if ($next === $current || ($events[$index - 1]["at"] ?? null) === $current) {
            continue;
        }
        $from = $current + $travelMs;
        $until = $next !== null ? $next + $travelMs : $from + ($lastWindow === false ? 1500 : $lastWindow);
        $until = min($until, $finishedMs);
        if ($until > $from) {
            $windows[$event["id"]] = ["from_ms" => $from, "until_ms" => $until];
        }
    }
    return $windows;
}
