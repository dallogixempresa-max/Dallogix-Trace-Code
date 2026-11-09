<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$payload = request_json();
$loadingId = filter_var(
    $payload["carregamento_id"] ?? null,
    FILTER_VALIDATE_INT,
);
$justification = trim((string) ($payload["justification"] ?? ""));
$automatic = filter_var($payload["automatic"] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$loadingId) {
    json_response(["error" => "Carregamento obrigatório."], 422);
}

$pdo = db();
$pdo->beginTransaction();
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});
$statement = $pdo->prepare(
    "SELECT c.id, c.equipment_id, c.state, c.stop_reason, c.romaneio_id, c.truck_id,
            r.number AS romaneio_number, r.status AS romaneio_status
     FROM carregamentos c JOIN romaneios r ON r.id = c.romaneio_id
     WHERE c.id = :id AND c.company_id = :company_id LIMIT 1 FOR UPDATE",
);
$statement->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
$loading = $statement->fetch();
if (!$loading) {
    $pdo->rollBack();
    json_response(["error" => "Carregamento não encontrado."], 404);
}
$stopReason = strtoupper(trim((string) ($loading["stop_reason"] ?? "")));
$automaticQuotaStopReason = in_array($stopReason, ["COTA_ROMANEIO_ATINGIDA", "EXCESSO"], true);
if ($loading["state"] !== "PAUSADO" || ($stopReason !== "" && !$automaticQuotaStopReason)) {
    $pdo->rollBack();
    json_response(
        [
            "error" => "Pare a esteira pelo comando do CLP e resolva qualquer bloqueio antes de encerrar o carregamento.",
        ],
        409,
    );
}
$pendingCommand = $pdo->prepare(
    "SELECT COUNT(*) FROM solicitacoes_comandos_clp
     WHERE carregamento_id = :loading_id AND company_id = :company_id
       AND status IN ('PENDENTE', 'PROCESSANDO')",
);
$pendingCommand->execute(["loading_id" => $loadingId, "company_id" => $user["company_id"]]);
if ((int) $pendingCommand->fetchColumn() > 0) {
    $pdo->rollBack();
    json_response(["error" => "Aguarde o CLP confirmar o último comando antes de encerrar."], 409);
}
$automaticQuotaStopAcknowledged = false;
if ($automaticQuotaStopReason) {
    $stopAction = $stopReason === "EXCESSO"
        ? "EXCESSO_DETECTADO"
        : "QUANTIDADE_PLANEJADA_ATINGIDA";
    $confirmedStop = $pdo->prepare(
        "SELECT q.id FROM logs_auditoria a
         JOIN solicitacoes_comandos_clp q
           ON q.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
         WHERE a.company_id = :company_id AND a.action = :action
           AND a.entity_type = 'carregamento' AND a.entity_id = :loading_id
           AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.stop_reason')) = :stop_reason
           AND q.carregamento_id = :command_loading_id AND q.company_id = :command_company_id
           AND q.command = 'PAUSAR_CARREGAMENTO' AND q.status = 'APLICADO'
         ORDER BY a.id DESC LIMIT 1",
    );
    $confirmedStop->execute([
        "company_id" => $user["company_id"],
        "action" => $stopAction,
        "loading_id" => $loadingId,
        "stop_reason" => $stopReason,
        "command_loading_id" => $loadingId,
        "command_company_id" => $user["company_id"],
    ]);
    $automaticQuotaStopAcknowledged = (bool) $confirmedStop->fetchColumn();
    if (!$automaticQuotaStopAcknowledged) {
        $pdo->rollBack();
        json_response(
            ["error" => "Aguarde o PC industrial confirmar a parada da esteira antes de finalizar."],
            409,
        );
    }
}

$pendingSensorEvents = $pdo->prepare(
    "SELECT COUNT(*) FROM eventos_sensor s
     WHERE s.carregamento_id = :loading_id
       AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)",
);
$pendingSensorEvents->execute(["loading_id" => $loadingId]);
if ((int) $pendingSensorEvents->fetchColumn() > 0) {
    $pdo->rollBack();
    json_response(["error" => "Aguarde o leitor concluir ou expirar as passagens detectadas antes de encerrar."], 409);
}
$sensorOutbox = $pdo->prepare(
    "SELECT status,
            CASE WHEN last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND) THEN 1 ELSE 0 END AS heartbeat_fresh,
            JSON_UNQUOTE(JSON_EXTRACT(details, '$.sensor_event_outbox_pending')) AS pending_events,
            JSON_EXTRACT(details, '$.sensor_event_outbox_pending_by_loading') AS pending_events_by_loading
     FROM status_dispositivos
     WHERE equipment_id = :equipment_id AND device_type = 'SENSOR'
     LIMIT 1 FOR UPDATE",
);
$sensorOutbox->execute(["equipment_id" => (int) $loading["equipment_id"]]);
$sensorOutboxState = $sensorOutbox->fetch(PDO::FETCH_ASSOC);
$sensorOutboxFresh = $sensorOutboxState
    && $sensorOutboxState["status"] === "ONLINE"
    && (int) $sensorOutboxState["heartbeat_fresh"] === 1;
$pendingEventsByLoading = is_string($sensorOutboxState["pending_events_by_loading"] ?? null)
    ? json_decode($sensorOutboxState["pending_events_by_loading"], true)
    : null;
$pendingEvents = is_array($pendingEventsByLoading)
    ? (int) ($pendingEventsByLoading[(string) $loadingId] ?? 0)
    : ($sensorOutboxState["pending_events"] ?? null);
if (!$sensorOutboxFresh || $pendingEvents === null || (int) $pendingEvents > 0) {
    $pdo->rollBack();
    json_response(["error" => "Aguarde o sensor confirmar a fila local de passagens vazia antes de encerrar."], 409);
}

expirar_solicitacoes_imagem_keyence($pdo, null, [(int) $loadingId]);
$pending = $pdo->prepare(
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia WHERE carregamento_id = :id AND status IN ('PENDENTE','CAPTURANDO')",
);
$pending->execute(["id" => $loadingId]);
if ((int) $pending->fetchColumn() > 0) {
    $pdo->rollBack();
    json_response(
        [
            "error" =>
                "Existem imagens de falha do Keyence aguardando envio antes do encerramento.",
        ],
        409,
    );
}
// Só códigos VALIDO satisfazem a quantidade do item; SEM_LEITURA permanece divergência auditável.
$divergences = $pdo->prepare("SELECT ri.product_id, SUM(ri.planned_quantity) AS planned_quantity,
    GREATEST(0,
      COALESCE((SELECT COUNT(*) FROM leituras l WHERE l.carregamento_id = :loading_id AND l.product_id = ri.product_id AND l.result = 'VALIDO'), 0)
      - COALESCE((SELECT SUM(ret.quantity) FROM retornos ret JOIN leituras lr ON lr.id = ret.leitura_id
                  WHERE ret.carregamento_id = :return_loading_id AND lr.product_id = ri.product_id), 0)
    ) AS moved_quantity,
    p.name FROM romaneio_itens ri JOIN produtos p ON p.id = ri.product_id
    WHERE ri.romaneio_id = :romaneio_id AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)
    GROUP BY ri.product_id, p.name");
$divergences->execute([
    "loading_id" => $loadingId,
    "return_loading_id" => $loadingId,
    "romaneio_id" => $loading["romaneio_id"],
    "truck_id" => $loading["truck_id"],
]);
$divergenceRows = $divergences->fetchAll();
$hasDivergence = false;
$shortfall = 0;
$overfill = 0;
foreach ($divergenceRows as $divergence) {
    $difference = (int) $divergence["planned_quantity"] - (int) $divergence["moved_quantity"];
    $shortfall += max(0, $difference);
    $overfill += max(0, -$difference);
    if ($difference !== 0) {
        $hasDivergence = true;
    }
}
$loadingPlannedQuantityStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(ri.planned_quantity), 0)
     FROM romaneio_itens ri
     WHERE ri.romaneio_id = :romaneio_id
       AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
);
$loadingPlannedQuantityStatement->execute([
    "romaneio_id" => $loading["romaneio_id"],
    "truck_id" => $loading["truck_id"],
]);
$loadingPlannedQuantity = (int) $loadingPlannedQuantityStatement->fetchColumn();
$loadingDetectedQuantityStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM eventos_sensor
     WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id",
);
$loadingDetectedQuantityStatement->execute([
    "loading_id" => $loadingId,
    "equipment_id" => $loading["equipment_id"],
]);
$loadingDetectedQuantity = (int) $loadingDetectedQuantityStatement->fetchColumn();
$excessReadingsStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'EXCESSO'",
);
$excessReadingsStatement->execute(["loading_id" => $loadingId]);
$excessReadingCount = (int) $excessReadingsStatement->fetchColumn();
$excessEvidenceStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia
     WHERE carregamento_id = :loading_id AND reason = 'EXCESSO'",
);
$excessEvidenceStatement->execute(["loading_id" => $loadingId]);
$excessEvidenceCount = (int) $excessEvidenceStatement->fetchColumn();
$hasExcess = $loadingDetectedQuantity > $loadingPlannedQuantity
    || $excessReadingCount > 0
    || $excessEvidenceCount > 0;
$uncapturedExcessEvidenceStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia
     WHERE carregamento_id = :loading_id AND reason = 'EXCESSO' AND status <> 'CAPTURADA'",
);
$uncapturedExcessEvidenceStatement->execute(["loading_id" => $loadingId]);
$uncapturedExcessEvidence = (int) $uncapturedExcessEvidenceStatement->fetchColumn();
$uncoveredExcessPassagesStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM (
       SELECT l.sensor_event_id
       FROM leituras l
       WHERE l.carregamento_id = :reading_loading_id AND l.result = 'EXCESSO'
       UNION
       SELECT s.id AS sensor_event_id
       FROM eventos_sensor s
       WHERE s.carregamento_id = :event_loading_id AND s.equipment_id = :equipment_id
         AND (SELECT COUNT(*) FROM eventos_sensor earlier
              WHERE earlier.carregamento_id = :position_loading_id
                AND earlier.equipment_id = :position_equipment_id
                AND earlier.id <= s.id) > :planned_quantity
     ) excess_passages
     WHERE excess_passages.sensor_event_id IS NULL
        OR NOT EXISTS (
          SELECT 1 FROM solicitacoes_imagens_evidencia evidence
          WHERE evidence.sensor_event_id = excess_passages.sensor_event_id
            AND evidence.carregamento_id = :evidence_loading_id
            AND evidence.reason = 'EXCESSO' AND evidence.status = 'CAPTURADA'
        )",
);
$uncoveredExcessPassagesStatement->execute([
    "reading_loading_id" => $loadingId,
    "event_loading_id" => $loadingId,
    "equipment_id" => $loading["equipment_id"],
    "position_loading_id" => $loadingId,
    "position_equipment_id" => $loading["equipment_id"],
    "planned_quantity" => $loadingPlannedQuantity,
    "evidence_loading_id" => $loadingId,
]);
$uncoveredExcessPassages = (int) $uncoveredExcessPassagesStatement->fetchColumn();
$noReadCountStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'SEM_LEITURA'",
);
$noReadCountStatement->execute(["loading_id" => $loadingId]);
$noReadCount = (int) $noReadCountStatement->fetchColumn();
$uncoveredNoReadStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM leituras l
     WHERE l.carregamento_id = :loading_id AND l.result = 'SEM_LEITURA'
       AND (l.sensor_event_id IS NULL OR NOT EXISTS (
         SELECT 1 FROM solicitacoes_imagens_evidencia e
         WHERE e.carregamento_id = l.carregamento_id
           AND e.sensor_event_id = l.sensor_event_id
           AND e.reason = 'SEM_LEITURA' AND e.status = 'CAPTURADA'
       ))",
);
$uncoveredNoReadStatement->execute(["loading_id" => $loadingId]);
$uncoveredNoRead = (int) $uncoveredNoReadStatement->fetchColumn();
if ($uncoveredNoRead > 0) {
    $pdo->rollBack();
    json_response(["error" => "Aguarde a foto Keyence de cada saca sem leitura antes de finalizar."], 409);
}
$otherNonValidStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id
       AND result NOT IN ('VALIDO', 'SEM_LEITURA', 'EXCESSO')",
);
$otherNonValidStatement->execute(["loading_id" => $loadingId]);
$otherNonValid = (int) $otherNonValidStatement->fetchColumn();
$returnedStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(quantity), 0) FROM retornos WHERE carregamento_id = :loading_id",
);
$returnedStatement->execute(["loading_id" => $loadingId]);
$returned = (int) $returnedStatement->fetchColumn();
$evidenceCoveredDivergence = $noReadCount > 0
    && $loadingPlannedQuantity > 0
    && $loadingDetectedQuantity === $loadingPlannedQuantity
    && !$hasExcess
    && $shortfall === $noReadCount
    && $otherNonValid === 0 && $returned === 0
    && $overfill === 0
    && $uncoveredNoRead === 0
    && $uncapturedExcessEvidence === 0
    && $uncoveredExcessPassages === 0;
if ($automatic) {
    $plannedQuantityStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(ri.planned_quantity), 0)
         FROM romaneio_itens ri
         WHERE ri.romaneio_id = :romaneio_id
           AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
    );
    $plannedQuantityStatement->execute([
        "romaneio_id" => $loading["romaneio_id"],
        "truck_id" => $loading["truck_id"],
    ]);
    $plannedQuantity = (int) $plannedQuantityStatement->fetchColumn();
    $detectedQuantityStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM eventos_sensor
         WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id",
    );
    $detectedQuantityStatement->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
    ]);
    $detectedQuantity = (int) $detectedQuantityStatement->fetchColumn();
    $nonValidReadings = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result <> 'VALIDO'",
    );
    $nonValidReadings->execute(["loading_id" => $loadingId]);
    $scannerEventsRead = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras
         WHERE carregamento_id = :loading_id AND result = 'VALIDO'
           AND sensor_event_id IS NOT NULL",
    );
    $scannerEventsRead->execute(["loading_id" => $loadingId]);
    $targetStopAcknowledged = $pdo->prepare(
        "SELECT q.id FROM logs_auditoria a
         JOIN solicitacoes_comandos_clp q
           ON q.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
         WHERE a.company_id = :company_id AND a.action = 'QUANTIDADE_PLANEJADA_ATINGIDA'
           AND a.entity_type = 'carregamento' AND a.entity_id = :loading_id
           AND q.carregamento_id = :command_loading_id AND q.company_id = :command_company_id
           AND q.command = 'PAUSAR_CARREGAMENTO' AND q.status = 'APLICADO'
         ORDER BY a.id DESC LIMIT 1",
    );
    $targetStopAcknowledged->execute([
        "company_id" => $user["company_id"],
        "loading_id" => $loadingId,
        "command_loading_id" => $loadingId,
        "command_company_id" => $user["company_id"],
    ]);
    if (
        $hasDivergence
        || $plannedQuantity < 1
        || $detectedQuantity !== $plannedQuantity
        || (int) $nonValidReadings->fetchColumn() !== 0
        || (int) $scannerEventsRead->fetchColumn() !== $plannedQuantity
        || !$targetStopAcknowledged->fetchColumn()
    ) {
        $pdo->rollBack();
        json_response([
            "error" => "A finalização automática exige a meta exata lida pelo scanner e a parada da esteira confirmada pelo CLP.",
        ], 409);
    }
}
if ((($hasDivergence || $hasExcess) && !$evidenceCoveredDivergence)
    && !in_array($user["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    $pdo->rollBack();
    json_response(["error" => "Somente supervisores ou administradores podem autorizar divergência ou excesso."], 403);
}
if ((($hasDivergence || $hasExcess) && !$evidenceCoveredDivergence) && $justification === "") {
    $pdo->rollBack();
    json_response(["error" => "Informe uma justificativa para finalizar com divergência ou excesso."], 422);
}
if ($hasExcess && ($uncapturedExcessEvidence > 0 || $uncoveredExcessPassages > 0)) {
    $pdo->rollBack();
    json_response([
        "error" => "Não finalize a carga com excesso antes de receber a foto Keyence de cada passagem excedente.",
    ], 409);
}

try {
    $update = $pdo->prepare(
        "UPDATE carregamentos SET state = 'FINALIZADO', finished_at = NOW(), finish_justification = :justification WHERE id = :id AND company_id = :company_id AND state = :previous_state",
    );
    $update->execute([
        "id" => $loadingId,
        "company_id" => $user["company_id"],
        "justification" => $justification !== "" ? $justification : null,
        "previous_state" => $loading["state"],
    ]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(["error" => "O estado do carregamento mudou. Atualize a operação antes de encerrar."], 409);
    }
    // A divergência precisa virar ocorrência auditável: não é apenas um estado visual do romaneio.
    $registerDivergence = $pdo->prepare("INSERT INTO ocorrencias (company_id, carregamento_id, product_id, reference_key, type, quantity, description, created_by)
        VALUES (:company_id, :carregamento_id, :product_id, :reference_key, 'DIVERGENCIA_FINAL', :quantity, :description, :created_by)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), description = VALUES(description)");
    foreach ($divergenceRows as $divergence) {
        $planned = (int) $divergence["planned_quantity"];
        $moved = (int) $divergence["moved_quantity"];
        if ($planned === $moved) {
            continue;
        }
        $difference = abs($planned - $moved);
        $kind = $moved < $planned ? "Falta" : "Excesso";
        $registerDivergence->execute([
            "company_id" => $user["company_id"],
            "carregamento_id" => $loadingId,
            "product_id" => $divergence["product_id"],
            "reference_key" =>
                "FINAL:" . $loadingId . ":" . $divergence["product_id"],
            "quantity" => $difference,
            "description" => "{$kind} no fechamento: {$divergence["name"]} — previsto {$planned}, movido {$moved}.",
            "created_by" => $user["id"],
        ]);
        $divergenceIdStatement = $pdo->prepare(
            "SELECT id FROM ocorrencias WHERE carregamento_id = :carregamento_id AND reference_key = :reference_key LIMIT 1",
        );
        $divergenceIdStatement->execute([
            "carregamento_id" => $loadingId,
            "reference_key" => "FINAL:" . $loadingId . ":" . $divergence["product_id"],
        ]);
        $divergenceId = (int) ($divergenceIdStatement->fetchColumn() ?: 0);
        if ($divergenceId < 1) {
            throw new RuntimeException("Divergência final não encontrada após gravação.");
        }
        record_operational_event(
            $pdo,
            $user,
            "DIVERGENCIA_FINAL_REGISTRADA",
            "ocorrencia",
            $divergenceId,
            [
                "carregamento_id" => (int) $loadingId,
                "product_id" => (int) $divergence["product_id"],
                "planned_quantity" => $planned,
                "moved_quantity" => $moved,
            ],
        );
    }
    if ($hasExcess) {
        record_operational_event(
            $pdo,
            $user,
            "EXCESSO_FINALIZADO_COM_JUSTIFICATIVA",
            "carregamento",
            (int) $loadingId,
            [
                "sensor_detected" => $loadingDetectedQuantity,
                "planned_quantity" => $loadingPlannedQuantity,
                "excess_readings" => $excessReadingCount,
                "captured_excess_photos" => $excessEvidenceCount - $uncapturedExcessEvidence,
                "justification" => $justification,
            ],
        );
    }
    $remaining = $pdo->prepare(
        "SELECT COUNT(*) FROM carregamentos
         WHERE romaneio_id = :romaneio_id AND company_id = :company_id
           AND state <> 'FINALIZADO'",
    );
    $remaining->execute([
        "romaneio_id" => $loading["romaneio_id"],
        "company_id" => $user["company_id"],
    ]);
    $romaneioProntoParaEncerrar = (int) $remaining->fetchColumn() === 0;
    $romaneioFinalizado = false;
    if ($romaneioProntoParaEncerrar && !$hasDivergence) {
        $manifestLoadings = $pdo->prepare(
            "SELECT id, truck_id, state, finish_justification
             FROM carregamentos
             WHERE romaneio_id = :romaneio_id AND company_id = :company_id
             ORDER BY id FOR UPDATE",
        );
        $manifestLoadings->execute([
            "romaneio_id" => $loading["romaneio_id"],
            "company_id" => $user["company_id"],
        ]);
        $allLoadingsExact = true;
        $plannedForTruck = $pdo->prepare(
            "SELECT COALESCE(SUM(ri.planned_quantity), 0)
             FROM romaneio_itens ri
             WHERE ri.romaneio_id = :romaneio_id
               AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
        );
        $detectedForLoading = $pdo->prepare(
            "SELECT COUNT(*) FROM eventos_sensor WHERE carregamento_id = :loading_id",
        );
        $validForLoading = $pdo->prepare(
            "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'VALIDO'",
        );
        $returnsForLoading = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM retornos WHERE carregamento_id = :loading_id",
        );
        foreach ($manifestLoadings->fetchAll(PDO::FETCH_ASSOC) as $manifestLoading) {
            $manifestLoadingId = (int) $manifestLoading["id"];
            $plannedForTruck->execute([
                "romaneio_id" => $loading["romaneio_id"],
                "truck_id" => $manifestLoading["truck_id"],
            ]);
            $plannedForLoading = (int) $plannedForTruck->fetchColumn();
            $detectedForLoading->execute(["loading_id" => $manifestLoadingId]);
            $detectedForManifestLoading = (int) $detectedForLoading->fetchColumn();
            $validForLoading->execute(["loading_id" => $manifestLoadingId]);
            $validForManifestLoading = (int) $validForLoading->fetchColumn();
            $returnsForLoading->execute(["loading_id" => $manifestLoadingId]);
            $returnedForManifestLoading = (int) $returnsForLoading->fetchColumn();
            if (
                $manifestLoading["state"] !== "FINALIZADO"
                || trim((string) ($manifestLoading["finish_justification"] ?? "")) !== ""
                || $plannedForLoading < 1
                || $detectedForManifestLoading !== $plannedForLoading
                || max(0, $validForManifestLoading - $returnedForManifestLoading) !== $plannedForLoading
            ) {
                $allLoadingsExact = false;
                break;
            }
        }
        $pendingManifestEvidence = $pdo->prepare(
            "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
             JOIN carregamentos c ON c.id = i.carregamento_id
             WHERE c.romaneio_id = :romaneio_id AND c.company_id = :company_id
               AND i.status IN ('PENDENTE', 'CAPTURANDO')",
        );
        $pendingManifestEvidence->execute([
            "romaneio_id" => $loading["romaneio_id"],
            "company_id" => $user["company_id"],
        ]);
        if ($allLoadingsExact && (int) $pendingManifestEvidence->fetchColumn() === 0
            && $loading["romaneio_status"] === "EM_ANDAMENTO") {
            $finishManifest = $pdo->prepare(
                "UPDATE romaneios SET status = 'FINALIZADO'
                 WHERE id = :romaneio_id AND company_id = :company_id AND status = 'EM_ANDAMENTO'",
            );
            $finishManifest->execute([
                "romaneio_id" => $loading["romaneio_id"],
                "company_id" => $user["company_id"],
            ]);
            $romaneioFinalizado = $finishManifest->rowCount() === 1;
            if ($romaneioFinalizado) {
                record_operational_event(
                    $pdo,
                    $user,
                    "ROMANEIO_FINALIZADO",
                    "romaneio",
                    (int) $loading["romaneio_id"],
                    ["number" => (string) $loading["romaneio_number"], "automatic" => true],
                );
            }
        }
    }
    record_operational_event(
        $pdo,
        $user,
        "CARREGAMENTO_FINALIZADO",
        "carregamento",
        (int) $loadingId,
        [
            "romaneio_id" => (int) $loading["romaneio_id"],
            "romaneio_pronto_para_encerrar" => $romaneioProntoParaEncerrar,
            "romaneio_finalizado" => $romaneioFinalizado,
        ],
    );
    // Sacas que passaram pelo sensor sem leitura e sem product_id: ocorrem quando
    // o romaneio tem múltiplos produtos e o código não foi lido. São rastreáveis
    // pelas fotos do Keyence mas não são atribuídas a nenhum produto específico.
    $semProdutoQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'SEM_LEITURA' AND product_id IS NULL",
    );
    $semProdutoQuery->execute(["loading_id" => $loadingId]);
    $leiturasSemProduto = (int) $semProdutoQuery->fetchColumn();
    $pdo->commit();
    json_response([
        "data" => [
            "id" => (int) $loadingId,
            "state" => "FINALIZADO",
            "romaneio_id" => (int) $loading["romaneio_id"],
            "romaneio_number" => (string) $loading["romaneio_number"],
            "romaneio_pronto_para_encerrar" => $romaneioProntoParaEncerrar,
            "romaneio_finalizado" => $romaneioFinalizado,
            "leituras_sem_produto" => $leiturasSemProduto,
        ],
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Loading finish failed: " . $exception->getMessage());
    json_response(
        ["error" => "Não foi possível finalizar o carregamento."],
        500,
    );
}
