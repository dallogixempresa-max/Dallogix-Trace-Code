<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/temporizacao_leitor.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoComandoClp.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoPausasCarregamento.php";

use App\Aplicacao\ServicoComandoClp;
use App\Aplicacao\ServicoPausasCarregamento;

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
            "SELECT id, romaneio_id, truck_id FROM carregamentos
             WHERE equipment_id = :equipment_id AND company_id = :company_id
               AND state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
        );
        $loadingStatement->execute([
            "equipment_id" => $device["equipment_id"],
            "company_id" => $device["company_id"],
        ]);
        $loading = $loadingStatement->fetch(PDO::FETCH_ASSOC);
        if ($loading === false) {
            $pdo->commit();
            json_response(["data" => ["finalized" => 0, "reason" => "no_active_loading"]]);
        }
        $loadingId = (int) $loading["id"];

        $plannedStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(ri.planned_quantity), 0)
             FROM romaneio_itens ri
             WHERE ri.romaneio_id = :romaneio_id
               AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
        );
        $plannedStatement->execute([
            "romaneio_id" => (int) $loading["romaneio_id"],
            "truck_id" => $loading["truck_id"],
        ]);
        $plannedQuantity = (int) $plannedStatement->fetchColumn();

        $deviceDeadlineMs = (int) round(microtime(true) * 1000) - $timeoutMs;
        $timeoutMicroseconds = $timeoutMs * 1000;
        $events = $pdo->prepare(
            "SELECT s.id, s.event_at_epoch_ms, s.device_detected_at,
                    CAST(ROUND(UNIX_TIMESTAMP(s.detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
             FROM eventos_sensor s
             WHERE s.carregamento_id = :loading_id
               AND s.equipment_id = :equipment_id
               AND s.conta_na_meta = 1
               AND (
                 (s.device_detected_at IS NOT NULL AND s.device_detected_at <= :device_deadline)
                 OR (s.device_detected_at IS NULL
                   AND s.detected_at <= DATE_SUB(NOW(3), INTERVAL {$timeoutMicroseconds} MICROSECOND))
               )
               AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
            ORDER BY s.event_at_epoch_ms, s.id ASC LIMIT 20 FOR UPDATE SKIP LOCKED",
        );
        $events->execute([
            "loading_id" => (int) $loadingId,
            "equipment_id" => $device["equipment_id"],
            "device_deadline" => trace_epoch_ms_to_sql_datetime($deviceDeadlineMs),
        ]);

        $finalized = [];
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $pendingSensorEvent) {
            $sensorEventId = (int) $pendingSensorEvent["id"];
            $eventAtEpochMs = (int) ($pendingSensorEvent["event_at_epoch_ms"] ?? 0);
            if ($eventAtEpochMs < 1) {
                $eventAtEpochMs = $pendingSensorEvent["device_detected_at"] !== null
                    ? trace_sql_datetime_to_epoch_ms((string) $pendingSensorEvent["device_detected_at"])
                    : (int) $pendingSensorEvent["server_detected_at_epoch_ms"];
            }
            $positionStatement = $pdo->prepare(
                "SELECT COUNT(*) FROM eventos_sensor
                 WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id
                   AND conta_na_meta = 1 AND (
                     (event_at_epoch_ms IS NULL AND id <= :legacy_sensor_event_id)
                     OR event_at_epoch_ms < :event_at_before
                     OR (event_at_epoch_ms = :event_at_equal AND id <= :sensor_event_id)
                   )",
            );
            $positionStatement->execute([
                "loading_id" => (int) $loadingId,
                "equipment_id" => $device["equipment_id"],
                "legacy_sensor_event_id" => $sensorEventId,
                "event_at_before" => $eventAtEpochMs,
                "event_at_equal" => $eventAtEpochMs,
                "sensor_event_id" => $sensorEventId,
            ]);
            $physicalExcess = $plannedQuantity > 0
                && (int) $positionStatement->fetchColumn() > $plannedQuantity;
            $readingResult = "SEM_LEITURA";
            $evidenceReason = "SEM_LEITURA";
            if ($physicalExcess) {
                $excludeLatePassage = $pdo->prepare(
                    "UPDATE eventos_sensor SET conta_na_meta_origem = 0, conta_na_meta = 0
                     WHERE id = :sensor_event_id AND carregamento_id = :loading_id",
                );
                $excludeLatePassage->execute([
                    "sensor_event_id" => $sensorEventId,
                    "loading_id" => (int) $loadingId,
                ]);
            }
            $reading = $pdo->prepare(
                "INSERT INTO leituras
                 (company_id, carregamento_id, sensor_event_id, product_id, barcode, attempt_number, result, conta_na_meta, read_at)
                 VALUES (:company_id, :loading_id, :sensor_event_id, NULL, NULL, 1, :result, :conta_na_meta, NOW(3))",
            );
            $reading->execute([
                "company_id" => $device["company_id"],
                "loading_id" => (int) $loadingId,
                "sensor_event_id" => (int) $sensorEventId,
                "result" => $readingResult,
                "conta_na_meta" => $physicalExcess ? 0 : 1,
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
                "description" => $physicalExcess
                    ? "Saco detectado sem código de barras válido após a meta; passagem não considerada na quantidade planejada."
                    : "Saco detectado pelo sensor sem código de barras válido após o tempo de leitura.",
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
                    "result" => $readingResult,
                    "source" => "sensor_timeout",
                    "read_at" => date("Y-m-d H:i:s.v"),
                ],
            );
            if ($physicalExcess) {
                // A passagem permanece auditada e fotografada como sem leitura,
                // mas fica fora da quantidade planejada.
                ServicoPausasCarregamento::reclassificarPassagens($pdo, (int) $loadingId);
            }
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
                 VALUES (:sensor_event_id, :loading_id, :equipment_id, :reason, NOW(3))
                 ON DUPLICATE KEY UPDATE
                   reason = VALUES(reason),
                   status = IF(status = 'ERRO', 'PENDENTE', status),
                   requested_at = VALUES(requested_at)",
            );
            $evidenceRequest->execute([
                "sensor_event_id" => (int) $sensorEventId,
                "loading_id" => (int) $loadingId,
                "equipment_id" => $device["equipment_id"],
                "reason" => $evidenceReason,
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
                ["sensor_event_id" => (int) $sensorEventId, "reason" => $evidenceReason],
            );
            $finalized[] = [
                "sensor_event_id" => (int) $sensorEventId,
                "reading_id" => $readingId,
                "evidence_request_id" => $evidenceRequestId,
                "result" => $readingResult,
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

    // ATENÇÃO: eventos_sensor guarda dois relógios diferentes na mesma linha.
    // detected_at vem de NOW(3), no fuso da sessão MySQL, fixado em UTC pelo compose.
    // device_detected_at vem de trace_epoch_ms_to_sql_datetime(), que converte para
    // o fuso da aplicação. Cada ramo das comparações abaixo precisa continuar usando
    // a referência do seu próprio relógio: comparar as duas colunas entre si, ou medir
    // latência entre elas, dá um erro silencioso do tamanho do deslocamento do fuso.
    $eventAtEpochMs = $deviceDetectedAt !== null
        ? (int) round(((float) $parsedDeviceDate->format("U.u")) * 1000)
        : (int) round(microtime(true) * 1000);
    $countsTowardPlan = !ServicoPausasCarregamento::estaNaPausa(
        $pdo,
        (int) $loadingId,
        $eventAtEpochMs,
    );
    $debounceReferenceMs = $eventAtEpochMs;
    $debounceStartMs = $debounceReferenceMs - $debounceMs;
    $debounce = $pdo->prepare(
        "SELECT id FROM eventos_sensor
         WHERE carregamento_id = :carregamento_id
           AND equipment_id = :equipment_id
           AND conta_na_meta = :counts_toward_plan
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
        "counts_toward_plan" => $countsTowardPlan ? 1 : 0,
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
        "INSERT INTO eventos_sensor
            (carregamento_id, equipment_id, event_uuid, detected_at, device_detected_at,
             event_at_epoch_ms, conta_na_meta_origem, conta_na_meta, debounce_ms)
         VALUES (:carregamento_id, :equipment_id, :event_uuid, NOW(3), :device_detected_at,
                 :event_at_epoch_ms, :conta_na_meta_origem, :conta_na_meta, :debounce_ms)",
    );
    $insert->execute([
        "carregamento_id" => $loadingId,
        "equipment_id" => $equipmentId,
        "event_uuid" => $eventUuid,
        "device_detected_at" => $deviceDetectedAt,
        "event_at_epoch_ms" => $eventAtEpochMs,
        "conta_na_meta_origem" => $countsTowardPlan ? 1 : 0,
        "conta_na_meta" => $countsTowardPlan ? 1 : 0,
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
            "event_at_epoch_ms" => $eventAtEpochMs,
            "conta_na_meta" => $countsTowardPlan,
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
         WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id
           AND conta_na_meta = 1",
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
    $targetStop = null;
    $automaticStop = false;
    if (
        $countsTowardPlan &&
        in_array($loading["state"], ["CARREGANDO", "FINALIZANDO"], true)
        && $plannedQuantity > 0
        && $detectedBags === $plannedQuantity
    ) {
        $targetStop = (new ServicoComandoClp($pdo))->solicitarParadaAoAtingirMetaRomaneio(
            ["id" => null, "company_id" => (int) $device["company_id"]],
            (int) $loadingId,
        );
        $automaticStop = ($targetStop["command_request_id"] ?? null) !== null;
        $nearEndWarning = $targetStop["operator_alert"];
        $targetAlreadyRecorded = $pdo->prepare(
            "SELECT id FROM logs_auditoria
             WHERE company_id = :company_id AND action = 'QUANTIDADE_PLANEJADA_ATINGIDA'
               AND entity_type = 'carregamento' AND entity_id = :loading_id
             ORDER BY id DESC LIMIT 1",
        );
        $targetAlreadyRecorded->execute([
            "company_id" => $device["company_id"],
            "loading_id" => $loadingId,
        ]);
        $targetAuditId = (int) ($targetAlreadyRecorded->fetchColumn() ?: 0);
        if ($targetAuditId < 1) {
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
                    "automatic_stop" => $automaticStop,
                    "stop_reason" => $targetStop["stop_reason"],
                    "physical_stop_request_status" => $targetStop["physical_stop_request_status"],
                    "command_request_id" => $targetStop["command_request_id"],
                    "operator_action" => "CONFIRMAR_PARADA_E_CONFERIR_LEITURAS",
                ],
            );
        } elseif ($targetStop["command_request_id"] !== null) {
            // Associa a solicitação de parada ao evento existente quando uma
            // tentativa posterior conseguir enfileirar o comando no gateway.
            $updateTargetAudit = $pdo->prepare(
                "UPDATE logs_auditoria
                 SET metadata = JSON_SET(
                   COALESCE(metadata, JSON_OBJECT()),
                   '$.automatic_stop', :automatic_stop,
                   '$.physical_stop_request_status', :physical_stop_request_status,
                   '$.command_request_id', :command_request_id,
                   '$.detected_bags', :detected_bags,
                   '$.updated_at', DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f')
                 )
                 WHERE id = :id AND company_id = :company_id
                   AND action = 'QUANTIDADE_PLANEJADA_ATINGIDA'
                   AND entity_type = 'carregamento' AND entity_id = :loading_id",
            );
            $updateTargetAudit->execute([
                "automatic_stop" => $automaticStop ? 1 : 0,
                "physical_stop_request_status" => $targetStop["physical_stop_request_status"],
                "command_request_id" => (int) $targetStop["command_request_id"],
                "detected_bags" => $detectedBags,
                "id" => $targetAuditId,
                "company_id" => (int) $device["company_id"],
                "loading_id" => (int) $loadingId,
            ]);
        }
    }
    $pdo->commit();
    // A evidência será solicitada somente se a leitura indicar falta de código
    // ou produto fora do romaneio.
    json_response(
        [
            "data" => [
                "id" => $eventId,
                "duplicate" => false,
                "detected_bags" => $detectedBags,
                "counted_toward_plan" => $countsTowardPlan,
                "planned_quantity" => $plannedQuantity,
                "operator_warning" => $nearEndWarning,
                "automatic_stop" => $automaticStop,
                "stop_reason" => $targetStop["stop_reason"] ?? null,
                "physical_stop_request_status" => $targetStop["physical_stop_request_status"] ?? null,
                "command_request_id" => $targetStop["command_request_id"] ?? null,
                "operator_alert" => $targetStop["operator_alert"] ?? $nearEndWarning,
                "load_status" => ($targetStop["stop_reason"] ?? null) === "COTA_ROMANEIO_ATINGIDA"
                    ? "EM_ANDAMENTO"
                    : ($loading["state"] === "FINALIZANDO"
                        ? "COMPLETO" : "EM_ANDAMENTO"),
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
