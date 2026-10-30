<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/temporizacao_leitor.php";

exigir_metodo_http(["POST"]);
$device = require_device_token(["CLP", "SENSOR"]);

$payload = request_json();
$action = strtoupper(trim((string) ($payload["action"] ?? "REGISTER_EVENT")));
if ($action === "FINALIZE_EXPIRED") {
    if ($device["device_type"] !== "CLP") {
        json_response(["error" => "Somente o gateway do CLP pode finalizar eventos sem leitura."], 403);
    }
    $timeoutMs = trace_scanner_timing()["no_read_deadline_ms"];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // O mesmo lock do carregamento usado pela leitura do scanner serializa
        // a associação do código e o timeout, evitando dois resultados no saco.
        $loadingStatement = $pdo->prepare(
            "SELECT id FROM carregamentos
             WHERE equipment_id = :equipment_id AND company_id = :company_id
               AND state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
        );
        $loadingStatement->execute([
            "equipment_id" => $device["equipment_id"],
            "company_id" => $device["company_id"],
        ]);
        $loadingId = $loadingStatement->fetchColumn();
        if ($loadingId === false) {
            $pdo->commit();
            json_response(["data" => ["finalized" => 0, "reason" => "no_active_loading"]]);
        }

        $deviceDeadlineMs = (int) round(microtime(true) * 1000) - $timeoutMs;
        $timeoutMicroseconds = $timeoutMs * 1000;
        $events = $pdo->prepare(
            "SELECT s.id
             FROM eventos_sensor s
             WHERE s.carregamento_id = :loading_id
               AND s.equipment_id = :equipment_id
               AND (
                 (s.device_detected_at IS NOT NULL AND s.device_detected_at <= :device_deadline)
                 OR (s.device_detected_at IS NULL
                   AND s.detected_at <= DATE_SUB(NOW(3), INTERVAL {$timeoutMicroseconds} MICROSECOND))
               )
               AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
             ORDER BY s.id ASC LIMIT 20 FOR UPDATE SKIP LOCKED",
        );
        $events->execute([
            "loading_id" => (int) $loadingId,
            "equipment_id" => $device["equipment_id"],
            "device_deadline" => trace_epoch_ms_to_sql_datetime($deviceDeadlineMs),
        ]);

        $finalized = [];
        foreach ($events->fetchAll(PDO::FETCH_COLUMN) as $sensorEventId) {
            $reading = $pdo->prepare(
                "INSERT INTO leituras
                 (company_id, carregamento_id, sensor_event_id, product_id, barcode, attempt_number, result, read_at)
                 VALUES (:company_id, :loading_id, :sensor_event_id, NULL, NULL, 1, 'SEM_LEITURA', NOW(3))",
            );
            $reading->execute([
                "company_id" => $device["company_id"],
                "loading_id" => (int) $loadingId,
                "sensor_event_id" => (int) $sensorEventId,
            ]);
            $readingId = (int) $pdo->lastInsertId();

            $occurrence = $pdo->prepare(
                "INSERT INTO ocorrencias
                 (company_id, carregamento_id, type, quantity, description, created_by)
                 VALUES (:company_id, :loading_id, 'FALHA_SEM_LEITURA', 1, :description, NULL)",
            );
            $occurrence->execute([
                "company_id" => $device["company_id"],
                "loading_id" => (int) $loadingId,
                "description" => "Saco detectado pelo sensor sem código de barras válido após o tempo de leitura.",
            ]);
            $occurrenceId = (int) $pdo->lastInsertId();
            $actor = ["id" => null, "company_id" => $device["company_id"]];
            record_operational_event(
                $pdo,
                $actor,
                "LEITURA_REGISTRADA",
                "leitura",
                $readingId,
                [
                    "carregamento_id" => (int) $loadingId,
                    "sensor_event_id" => (int) $sensorEventId,
                    "result" => "SEM_LEITURA",
                    "source" => "sensor_timeout",
                ],
            );
            record_operational_event(
                $pdo,
                $actor,
                "FALHA_SEM_LEITURA",
                "ocorrencia",
                $occurrenceId,
                [
                    "carregamento_id" => (int) $loadingId,
                    "leitura_id" => $readingId,
                    "sensor_event_id" => (int) $sensorEventId,
                ],
            );

            $evidenceRequest = $pdo->prepare(
                "INSERT INTO solicitacoes_imagens_evidencia
                 (sensor_event_id, carregamento_id, equipment_id, reason, requested_at)
                 VALUES (:sensor_event_id, :loading_id, :equipment_id, 'SEM_LEITURA', NOW(3))
                 ON DUPLICATE KEY UPDATE
                   reason = VALUES(reason),
                   status = IF(status = 'ERRO', 'PENDENTE', status),
                   requested_at = VALUES(requested_at)",
            );
            $evidenceRequest->execute([
                "sensor_event_id" => (int) $sensorEventId,
                "loading_id" => (int) $loadingId,
                "equipment_id" => $device["equipment_id"],
            ]);
            $evidenceId = $pdo->prepare(
                "SELECT id FROM solicitacoes_imagens_evidencia WHERE sensor_event_id = :sensor_event_id LIMIT 1",
            );
            $evidenceId->execute(["sensor_event_id" => (int) $sensorEventId]);
            $evidenceRequestId = (int) $evidenceId->fetchColumn();
            if ($evidenceRequestId < 1) {
                throw new RuntimeException("Solicitação de imagem não foi criada para a passagem sem leitura.");
            }
            record_operational_event(
                $pdo,
                $actor,
                "EVIDENCIA_SEM_LEITURA_SOLICITADA",
                "evidence_request",
                $evidenceRequestId,
                ["sensor_event_id" => (int) $sensorEventId, "reason" => "SEM_LEITURA"],
            );
            $finalized[] = [
                "sensor_event_id" => (int) $sensorEventId,
                "reading_id" => $readingId,
                "evidence_request_id" => $evidenceRequestId,
            ];
        }
        $pdo->commit();
        json_response(["data" => ["finalized" => count($finalized), "events" => $finalized]]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
if ($action !== "REGISTER_EVENT") {
    json_response(["error" => "Ação de evento do sensor inválida."], 422);
}

$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
$equipmentId = filter_var(
    $payload["equipment_id"] ?? ($payload["esteira_id"] ?? null),
    FILTER_VALIDATE_INT,
);
$eventUuid = trim((string) ($payload["event_uuid"] ?? ""));
$detectedAt = trim((string) ($payload["detected_at"] ?? ""));
$detectedAtEpochMs = $payload["detected_at_epoch_ms"] ?? null;
if (!$loadingId || !$equipmentId || !preg_match('/^[a-f0-9-]{36}$/i', $eventUuid)) {
    json_response(
        ["error" => "Carregamento, esteira e event_uuid válido são obrigatórios."],
        422,
    );
}
if ((int) $equipmentId !== (int) $device["equipment_id"]) {
    json_response(["error" => "O dispositivo só pode registrar eventos da própria Dala."], 403);
}

$deviceDetectedAt = null;
$parsedDeviceDate = null;
if ($detectedAtEpochMs !== null && $detectedAtEpochMs !== "") {
    $parsedEpochMs = filter_var($detectedAtEpochMs, FILTER_VALIDATE_INT);
    if ($parsedEpochMs === false || (int) $parsedEpochMs < 1) {
        json_response(["error" => "Data do evento inválida."], 422);
    }
    try {
        $parsedDeviceDate = new DateTimeImmutable(trace_epoch_ms_to_sql_datetime((int) $parsedEpochMs));
    } catch (InvalidArgumentException $exception) {
        json_response(["error" => "Data do evento inválida."], 422);
    }
} elseif ($detectedAt !== "") {
    $parsedDeviceDate = DateTime::createFromFormat("!Y-m-d H:i:s.v", $detectedAt);
    $dateErrors = DateTime::getLastErrors();
    if (
        !$parsedDeviceDate ||
        ($dateErrors !== false && ($dateErrors["warning_count"] > 0 || $dateErrors["error_count"] > 0)) ||
        $parsedDeviceDate->format("Y-m-d H:i:s.v") !== $detectedAt
    ) {
        json_response(["error" => "Data do evento inválida."], 422);
    }
}
if ($parsedDeviceDate !== null) {
    // Keep gateway event time for debounce, scan correlation and evidence timing.
    $now = new DateTimeImmutable("now");
    $oldestAccepted = $now->modify("-7 days");
    $latestAccepted = $now->modify("+5 minutes");
    if ($parsedDeviceDate >= $oldestAccepted && $parsedDeviceDate <= $latestAccepted) {
        $deviceDetectedAt = $parsedDeviceDate->format("Y-m-d H:i:s.v");
    }
}

$rawDebounce = getenv("TRACE_DEBOUNCE_MS");
$configuredDebounce = filter_var(
    $rawDebounce === false || $rawDebounce === "" ? 400 : $rawDebounce,
    FILTER_VALIDATE_INT,
);
$debounceMs = max(0, min(5000, $configuredDebounce === false ? 400 : (int) $configuredDebounce));
$pdo = db();
$pdo->beginTransaction();
try {
    // Lock the loading so simultaneous sensor events use one authoritative
    // debounce window. Quando o dispositivo informa o horário, a janela usa
    // esse horário e continua correta após uma reconexão offline.
    $loadingStatement = $pdo->prepare(
        'SELECT c.id, c.state, c.romaneio_id, c.truck_id, c.started_at, c.finished_at FROM carregamentos c
         JOIN equipamentos e ON e.id = c.equipment_id AND e.id = :equipment_id
         WHERE c.id = :loading_id AND c.company_id = :company_id LIMIT 1 FOR UPDATE',
    );
    $loadingStatement->execute([
        "equipment_id" => $equipmentId,
        "loading_id" => $loadingId,
        "company_id" => $device["company_id"],
    ]);
    $loading = $loadingStatement->fetch();
    $historicalEventWithinLoading = false;
    if (
        $loading && $deviceDetectedAt !== null &&
        $loading["started_at"] !== null && $loading["finished_at"] !== null
    ) {
        $eventTime = new DateTimeImmutable($deviceDetectedAt);
        $loadingStart = new DateTimeImmutable((string) $loading["started_at"]);
        $loadingFinish = new DateTimeImmutable((string) $loading["finished_at"]);
        $historicalEventWithinLoading = $eventTime >= $loadingStart && $eventTime <= $loadingFinish;
    }
    if (
        !$loading ||
        (!in_array($loading["state"], ["CARREGANDO", "FINALIZANDO", "PAUSADO"], true) && !$historicalEventWithinLoading)
    ) {
        $pdo->rollBack();
        json_response(
            ["error" => "O evento só pode ser registrado durante o carregamento informado."],
            422,
        );
    }

    $debounceReferenceMs = $deviceDetectedAt !== null
        ? (int) round(((float) $parsedDeviceDate->format("U.u")) * 1000)
        : (int) round(microtime(true) * 1000);
    $debounceStartMs = $debounceReferenceMs - $debounceMs;
    $debounce = $pdo->prepare(
        "SELECT id FROM eventos_sensor
         WHERE carregamento_id = :carregamento_id
           AND equipment_id = :equipment_id
           AND (
             (device_detected_at IS NOT NULL AND device_detected_at BETWEEN :device_debounce_start AND :device_debounce_end)
             OR (device_detected_at IS NULL
               AND detected_at BETWEEN FROM_UNIXTIME(:server_debounce_start_ms / 1000.0)
                                   AND FROM_UNIXTIME(:server_debounce_end_ms / 1000.0))
           )
         ORDER BY id DESC LIMIT 1",
    );
    $debounce->execute([
        "carregamento_id" => $loadingId,
        "equipment_id" => $equipmentId,
        "device_debounce_start" => trace_epoch_ms_to_sql_datetime($debounceStartMs),
        "device_debounce_end" => trace_epoch_ms_to_sql_datetime($debounceReferenceMs),
        "server_debounce_start_ms" => $debounceStartMs,
        "server_debounce_end_ms" => $debounceReferenceMs,
    ]);
    $debouncedId = $debounce->fetchColumn();
    if ($debouncedId !== false) {
        $pdo->rollBack();
        json_response([
            "data" => [
                "id" => (int) $debouncedId,
                "duplicate" => true,
                "debounced" => true,
                "debounce_ms" => $debounceMs,
            ],
        ]);
    }
    $insert = $pdo->prepare(
        "INSERT INTO eventos_sensor (carregamento_id, equipment_id, event_uuid, detected_at, device_detected_at, debounce_ms)
         VALUES (:carregamento_id, :equipment_id, :event_uuid, NOW(3), :device_detected_at, :debounce_ms)",
    );
    $insert->execute([
        "carregamento_id" => $loadingId,
        "equipment_id" => $equipmentId,
        "event_uuid" => $eventUuid,
        "device_detected_at" => $deviceDetectedAt,
        "debounce_ms" => $debounceMs,
    ]);
    $eventId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        ["id" => null, "company_id" => $device["company_id"]],
        "SENSOR_EVENTO_RECEBIDO",
        "sensor_event",
        $eventId,
        [
            "carregamento_id" => (int) $loadingId,
            "event_uuid" => $eventUuid,
            "device_id" => $device["id"],
            "device_code" => $device["device_code"],
            "scanner_correlated_reading_id" => null,
        ],
    );
    $planned = $pdo->prepare(
        "SELECT COALESCE(SUM(ri.planned_quantity), 0)
         FROM romaneio_itens ri
         WHERE ri.romaneio_id = :romaneio_id
           AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
    );
    $planned->execute([
        "romaneio_id" => $loading["romaneio_id"],
        "truck_id" => $loading["truck_id"],
    ]);
    $plannedQuantity = (int) $planned->fetchColumn();
    $detected = $pdo->prepare(
        "SELECT COUNT(*) FROM eventos_sensor
         WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id",
    );
    $detected->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $equipmentId,
    ]);
    $detectedBags = (int) $detected->fetchColumn();
    $nearEndWarning = null;
    $remainingQuantity = $plannedQuantity - $detectedBags;
    $warningThreshold = $plannedQuantity > 0
        ? max(1, (int) ceil($plannedQuantity * 0.1))
        : 0;
    if ($remainingQuantity > 0 && $remainingQuantity <= $warningThreshold) {
        $existingWarning = $pdo->prepare(
            "SELECT 1 FROM logs_auditoria
             WHERE company_id = :company_id AND action = 'ALERTA_FIM_CARGA'
               AND entity_type = 'carregamento' AND entity_id = :loading_id LIMIT 1",
        );
        $existingWarning->execute([
            "company_id" => $device["company_id"],
            "loading_id" => $loadingId,
        ]);
        if (!$existingWarning->fetchColumn()) {
            $nearEndWarning = sprintf(
                "A carga está próxima do fim: faltam %d sacas para a quantidade prevista.",
                $remainingQuantity,
            );
            record_operational_event(
                $pdo,
                ["id" => null, "company_id" => $device["company_id"]],
                "ALERTA_FIM_CARGA",
                "carregamento",
                (int) $loadingId,
                [
                    "planned_quantity" => $plannedQuantity,
                    "detected_bags" => $detectedBags,
                    "remaining_quantity" => $remainingQuantity,
                    "threshold" => $warningThreshold,
                    "message" => $nearEndWarning,
                ],
            );
        }
    }
    $becameFinalizing = false;
    if ($loading["state"] === "CARREGANDO" && $plannedQuantity > 0 && $detectedBags >= $plannedQuantity) {
        $transition = $pdo->prepare(
            "UPDATE carregamentos SET state = 'FINALIZANDO'
             WHERE id = :id AND company_id = :company_id AND state = 'CARREGANDO'",
        );
        $transition->execute([
            "id" => $loadingId,
            "company_id" => $device["company_id"],
        ]);
        $becameFinalizing = $transition->rowCount() === 1;
        if ($becameFinalizing) {
            $targetAlreadyRecorded = $pdo->prepare(
                "SELECT 1 FROM logs_auditoria
                 WHERE company_id = :company_id AND action = 'QUANTIDADE_PLANEJADA_ATINGIDA'
                   AND entity_type = 'carregamento' AND entity_id = :loading_id LIMIT 1",
            );
            $targetAlreadyRecorded->execute([
                "company_id" => $device["company_id"],
                "loading_id" => $loadingId,
            ]);
            if (!$targetAlreadyRecorded->fetchColumn()) {
                record_operational_event(
                    $pdo,
                    ["id" => null, "company_id" => $device["company_id"]],
                    "QUANTIDADE_PLANEJADA_ATINGIDA",
                    "carregamento",
                    (int) $loadingId,
                    [
                        "planned_quantity" => $plannedQuantity,
                        "detected_bags" => $detectedBags,
                        "valid_readings" => null,
                        "automatic_stop" => false,
                        "operator_action" => "PARAR_ESTEIRA_MANUALMENTE",
                    ],
                );
            }
        }
    }
    $pdo->commit();
    // O sensor apenas correlaciona o saco à leitura. A imagem só é solicitada
    // quando o Trace confirma uma falha de leitura.
    json_response(
        [
            "data" => [
                "id" => $eventId,
                "duplicate" => false,
                "detected_bags" => $detectedBags,
                "planned_quantity" => $plannedQuantity,
                "operator_warning" => $nearEndWarning,
                "automatic_stop" => false,
                "load_status" => ($loading["state"] === "FINALIZANDO" || $becameFinalizing)
                    ? "COMPLETO" : "EM_ANDAMENTO",
                "image_action" => "AGUARDANDO_RESULTADO_LEITURA",
                "scanner_correlated" => false,
            ],
        ],
        201,
    );
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (
        isset($exception->errorInfo[1]) &&
        (int) $exception->errorInfo[1] === 1062
    ) {
        $existing = $pdo->prepare(
            "SELECT s.id FROM eventos_sensor s
             JOIN carregamentos c ON c.id = s.carregamento_id
             WHERE s.event_uuid = :event_uuid AND s.equipment_id = :equipment_id
               AND s.carregamento_id = :carregamento_id AND c.company_id = :company_id LIMIT 1",
        );
        $existing->execute([
            "event_uuid" => $eventUuid,
            "equipment_id" => $equipmentId,
            "carregamento_id" => $loadingId,
            "company_id" => $device["company_id"],
        ]);
        $existingId = $existing->fetchColumn();
        if ($existingId === false) {
            json_response(["error" => "Identificador do evento já pertence a outro contexto."], 409);
        }
        json_response(
            [
                "data" => [
                    "id" => (int) $existingId,
                    "duplicate" => true,
                ],
            ],
            200,
        );
    }
    json_response(
        ["error" => "Não foi possível registrar o evento do sensor."],
        500,
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
