<?php
declare(strict_types=1);

/**
 * Timing for a line where M2052 is upstream of the barcode reader.
 * The configured distance is measured from the sensor detection point to the
 * beginning of the reader's capture area. Belt speed is the measured 0.7 m/s.
 */
function trace_scanner_timing(): array
{
    static $timing = null;
    if ($timing !== null) {
        return $timing;
    }

    $rawGrace = getenv("TRACE_SCANNER_NO_READ_TIMEOUT_MS");
    $configuredGrace = filter_var(
        $rawGrace === false || $rawGrace === "" ? 1500 : $rawGrace,
        FILTER_VALIDATE_INT,
    );
    $graceMs = max(500, min(10000, $configuredGrace === false ? 1500 : (int) $configuredGrace));

    $rawDistance = getenv("TRACE_SENSOR_TO_READER_DISTANCE_M");
    $rawDistance = $rawDistance === false || trim($rawDistance) === "" ? "0.125" : trim($rawDistance);
    if (!is_numeric($rawDistance)) {
        throw new RuntimeException("TRACE_SENSOR_TO_READER_DISTANCE_M deve ser uma distância em metros.");
    }
    $distanceM = (float) $rawDistance;
    if (!is_finite($distanceM) || $distanceM < 0 || $distanceM > 20) {
        throw new RuntimeException("TRACE_SENSOR_TO_READER_DISTANCE_M deve estar entre 0 e 20 metros.");
    }

    $travelMs = (int) ceil(($distanceM / 0.7) * 1000);
    $timing = [
        "distance_m" => $distanceM,
        "travel_ms" => $travelMs,
        "grace_ms" => $graceMs,
        "no_read_deadline_ms" => $travelMs + $graceMs,
        "early_tolerance_ms" => 250,
        "ambiguity_margin_ms" => 250,
    ];
    return $timing;
}

function trace_epoch_ms_to_sql_datetime(int $epochMs): string
{
    if ($epochMs < 1) {
        throw new InvalidArgumentException("Horário do evento deve ser um epoch em milissegundos positivo.");
    }
    $seconds = intdiv($epochMs, 1000);
    $microseconds = ($epochMs % 1000) * 1000;
    $instant = DateTimeImmutable::createFromFormat(
        "U.u",
        sprintf("%d.%06d", $seconds, $microseconds),
        new DateTimeZone("UTC"),
    );
    if (!$instant) {
        throw new InvalidArgumentException("Horário do evento inválido.");
    }
    return $instant
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format("Y-m-d H:i:s.v");
}

/** Convert a DATETIME written by PHP in its configured timezone back to epoch ms. */
function trace_sql_datetime_to_epoch_ms(string $dateTime): int
{
    try {
        $instant = new DateTimeImmutable($dateTime, new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $exception) {
        throw new InvalidArgumentException("Data local do evento inválida.", 0, $exception);
    }
    return (int) round(((float) $instant->format("U.u")) * 1000);
}

function trace_scanner_observed_at_ms(mixed $value): int
{
    $nowMs = (int) round(microtime(true) * 1000);
    if ($value === null || $value === "") {
        return $nowMs;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false || (int) $parsed < $nowMs - 604800000 || (int) $parsed > $nowMs + 300000) {
        throw new InvalidArgumentException("Horário de recebimento do leitor inválido ou fora do período aceito.");
    }
    return (int) $parsed;
}

/**
 * Return pending M2052 events whose predicted reader-arrival window contains
 * the scan time. Candidates are ordered by distance from the predicted arrival;
 * near ties are deliberately rejected instead of attaching a code to the wrong sack.
 *
 * @return array{candidates:list<array{sensor_event_id:int,expected_read_at_epoch_ms:int,delta_ms:int}>,best:?array,ambiguous:bool}
 */
function trace_pending_scanner_event_match(
    PDO $pdo,
    int $loadingId,
    int $equipmentId,
    int $scanObservedAtMs,
    bool $forUpdate = false,
): array {
    $timing = trace_scanner_timing();
    $lookbackMs = $timing["no_read_deadline_ms"] + $timing["early_tolerance_ms"];
    $lookaheadMs = $timing["early_tolerance_ms"];
    $scanStartMs = $scanObservedAtMs - $lookbackMs;
    $scanEndMs = $scanObservedAtMs + $lookaheadMs;
    $lockSql = $forUpdate ? " FOR UPDATE" : "";
    $statement = $pdo->prepare(
        "SELECT s.id,
                s.device_detected_at,
                CAST(ROUND(UNIX_TIMESTAMP(s.detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
         FROM eventos_sensor s
         WHERE s.carregamento_id = :loading_id
           AND s.equipment_id = :equipment_id
           AND (
             (s.device_detected_at IS NOT NULL AND s.device_detected_at BETWEEN :device_scan_start AND :device_scan_end)
             OR (s.device_detected_at IS NULL
               AND s.detected_at BETWEEN FROM_UNIXTIME(:server_scan_start_ms / 1000.0)
                                     AND FROM_UNIXTIME(:server_scan_end_ms / 1000.0))
           )
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
         ORDER BY s.id ASC
         LIMIT 100{$lockSql}",
    );
    $statement->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $equipmentId,
        "device_scan_start" => trace_epoch_ms_to_sql_datetime($scanStartMs),
        "device_scan_end" => trace_epoch_ms_to_sql_datetime($scanEndMs),
        "server_scan_start_ms" => $scanStartMs,
        "server_scan_end_ms" => $scanEndMs,
    ]);

    $candidates = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $detectedAtMs = $row["device_detected_at"] !== null
            ? trace_sql_datetime_to_epoch_ms((string) $row["device_detected_at"])
            : (int) $row["server_detected_at_epoch_ms"];
        if ($detectedAtMs < 1) {
            continue;
        }
        $expectedAtMs = $detectedAtMs + $timing["travel_ms"];
        $deltaMs = $scanObservedAtMs - $expectedAtMs;
        if ($deltaMs < -$timing["early_tolerance_ms"] || $deltaMs > $timing["grace_ms"]) {
            continue;
        }
        $candidates[] = [
            "sensor_event_id" => (int) $row["id"],
            "expected_read_at_epoch_ms" => $expectedAtMs,
            "delta_ms" => $deltaMs,
        ];
    }

    usort($candidates, static function (array $left, array $right): int {
        $distanceOrder = abs($left["delta_ms"]) <=> abs($right["delta_ms"]);
        return $distanceOrder !== 0
            ? $distanceOrder
            : ($left["sensor_event_id"] <=> $right["sensor_event_id"]);
    });
    $ambiguous = count($candidates) > 1
        && abs(abs($candidates[1]["delta_ms"]) - abs($candidates[0]["delta_ms"]))
            <= $timing["ambiguity_margin_ms"];

    return [
        "candidates" => $candidates,
        "best" => !$ambiguous && $candidates !== [] ? $candidates[0] : null,
        "ambiguous" => $ambiguous,
    ];
}
