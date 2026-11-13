<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/temporizacao_leitor.php";
require_once __DIR__ . "/../../src/Aplicacao/ClassificadorLeitura.php";
require_once __DIR__ . "/../../src/Aplicacao/SolicitacaoLeitura.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoLeituras.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoComandoClp.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoPausasCarregamento.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioLeituras.php";

use App\Aplicacao\ClassificadorLeitura;
use App\Aplicacao\SolicitacaoLeitura;
use App\Aplicacao\ServicoComandoClp;
use App\Aplicacao\ServicoLeituras;
use App\Persistencia\RepositorioLeituras;

$dispositivoScanner = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && trim((string) ($_SERVER["HTTP_X_DEVICE_TOKEN"] ?? "")) !== "") {
    $dispositivoScanner = require_device_token(["SCANNER"]);
    $usuarioAtor = ["id" => null, "company_id" => $dispositivoScanner["company_id"]];
} else {
    $usuarioAtor = exigir_sessao_usuario();
}
if ($usuarioAtor["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $loadingId = filter_var($_GET["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$loadingId) responder_json(["error" => "Carregamento obrigatório."], 422);
    $page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
    $page = $page === false ? 1 : max(1, (int) $page);
    session_write_close();
    $pendencias = (new ServicoLeituras(new RepositorioLeituras(obter_conexao_banco())))->listarPendentes(
        (int) $usuarioAtor["company_id"],
        (int) $loadingId,
        $page,
    );
    responder_json($pendencias);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder_json(["error" => "Método não permitido."], 405);
}
if ($dispositivoScanner === null) {
    exigir_csrf();
}
session_write_close();

$payload = ler_json_da_requisicao();
try {
    $scannerObservedAtMs = trace_scanner_observed_at_ms($payload["scanner_received_at_epoch_ms"] ?? null);
} catch (InvalidArgumentException $exception) {
    responder_json(["error" => $exception->getMessage()], 422);
}
try {
    $solicitacao = SolicitacaoLeitura::fromPayload(
        $payload,
        (string) ($_SERVER["HTTP_X_TRACE_OFFLINE_ID"] ?? ""),
    );
} catch (InvalidArgumentException $exception) {
    responder_json(["error" => $exception->getMessage()], 422);
}
$loadingId = $solicitacao->loadingId;
$sensorEventId = $solicitacao->sensorEventId;
$barcode = $solicitacao->barcode;
$noRead = $solicitacao->noRead;
$offlineEventId = $solicitacao->offlineEventId;
$attemptNumber = $solicitacao->attemptNumber;

$pdo = db();
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});
$transientAttempt = 0;
// Deadlocks roll back the whole transaction, so the same offline event can be retried safely.
while (true) {
$sensorEventId = $solicitacao->sensorEventId;
$excessStop = null;
$excessoAlert = null;
$wrongProductStop = null;
$pdo->beginTransaction();
try {
$loadingStatement = $pdo->prepare(
    'SELECT c.id, c.state, c.stop_reason, c.operator_alert, c.romaneio_id, c.truck_id, c.equipment_id, c.leituras_validas
     FROM carregamentos c
     WHERE c.id = :id AND c.company_id = :company_id LIMIT 1 FOR UPDATE',
);
$loadingStatement->execute([
    "id" => $loadingId,
    "company_id" => $usuarioAtor["company_id"],
]);
$loading = $loadingStatement->fetch();
if (!$loading) {
    $pdo->rollBack();
    responder_json(
        ["error" => "Carregamento não encontrado para esta empresa."],
        404,
    );
}
if ($dispositivoScanner !== null && (int) $loading["equipment_id"] !== (int) $dispositivoScanner["equipment_id"]) {
    $pdo->rollBack();
    responder_json(["error" => "O scanner só pode registrar leituras na própria Dala."], 403);
}
$readingRepository = new RepositorioLeituras($pdo);
if ($offlineEventId !== "") {
    $registered = $readingRepository->buscarLeituraOfflineRegistrada(
        (int) $usuarioAtor["company_id"],
        $offlineEventId,
        (int) $loadingId,
    );
    if ($registered) {
        $original = json_decode((string) $registered["metadata"], true);
        if (
            !is_array($original) ||
            (int) ($original["carregamento_id"] ?? 0) !== (int) $loadingId ||
            (string) ($original["barcode"] ?? "") !== $barcode ||
            (bool) ($original["no_read"] ?? false) !== $noRead
        ) {
            $pdo->rollBack();
            responder_json(["error" => "Identificador já usado em outra leitura."], 409);
        }
        $pdo->rollBack();
        if ($registered["reading_id"] === null) {
            responder_json(["error" => "Leitura original não está mais disponível para reenvio."], 409);
        }
        responder_json([
            "data" => [
                "id" => (int) $registered["reading_id"],
                "result" => $registered["result"],
                "duplicate" => true,
                "load_status" => in_array($loading["state"], ["FINALIZANDO", "FINALIZADO"], true)
                    ? "COMPLETO" : "EM_ANDAMENTO",
            ],
        ], 200);
    }
}
if ($loading["state"] === "EMERGENCIA") {
    $pdo->rollBack();
    responder_json(
        [
            "error" =>
            "Contagem bloqueada: a máquina está em emergência. Libere a máquina antes de registrar leituras.",
        ],
        423,
    );
}
if (!in_array($loading["state"], ["CARREGANDO", "FINALIZANDO", "PAUSADO"], true)) {
    $pdo->rollBack();
    responder_json(
        ["error" => "Leitura ignorada: a esteira está em {$loading["state"]}."],
        409,
    );
}

$loadingValidCount = 0;
$scannerMatch = null;
if (($barcode !== "" || $noRead) && ($dispositivoScanner !== null || !$sensorEventId)) {
    $scannerMatch = trace_pending_scanner_event_match(
        $pdo,
        (int) $loadingId,
        (int) $loading["equipment_id"],
        $scannerObservedAtMs,
        false,
        $noRead,
    );
}

// Sensor upstream: só associe o código ao evento cuja chegada prevista ao
// início da área de leitura mais se aproxima do horário recebido pelo leitor.
if (!$sensorEventId && ($barcode !== "" || $noRead)) {
    if ($scannerMatch["ambiguous"]) {
        $pdo->rollBack();
        responder_json(["error" => "Leitura ambígua entre passagens do sensor; o código não foi associado."], 409);
    }
    if ($scannerMatch["best"] !== null) {
        $sensorEventId = $scannerMatch["best"]["sensor_event_id"];
    }
}
if (($barcode !== "" || $noRead) && !$sensorEventId) {
    $pdo->rollBack();
    responder_json(
        ["error" => "Leitura aguardando o evento de passagem do sensor M2052."],
        409,
    );
}
if ($loading["state"] === "PAUSADO" && !$sensorEventId) {
    $pdo->rollBack();
    responder_json(
        ["error" => "A esteira está parada; só é aceita a leitura que corresponde a uma passagem já detectada pelo sensor."],
        409,
    );
}
if ($sensorEventId) {
    $sensorStatement = $pdo->prepare(
        "SELECT id, event_at_epoch_ms, device_detected_at,
                CAST(ROUND(UNIX_TIMESTAMP(detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms,
                conta_na_meta
         FROM eventos_sensor
         WHERE id = :sensor_event_id AND carregamento_id = :carregamento_id
           AND equipment_id = :equipment_id LIMIT 1",
    );
    $sensorStatement->execute([
        "sensor_event_id" => $sensorEventId,
        "carregamento_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
    ]);
    $sensorEvent = $sensorStatement->fetch(PDO::FETCH_ASSOC);
    if ($sensorEvent === false) {
        $pdo->rollBack();
        responder_json(
            ["error" => "Evento de sensor não pertence ao carregamento."],
            422,
        );
    }
    if ((int) $sensorEvent["conta_na_meta"] !== 1) {
        $pdo->rollBack();
        responder_json(
            ["error" => "Esta passagem foi capturada durante a pausa e não pode gerar leitura operacional."],
            409,
        );
    }
}
if ($noRead && $sensorEventId) {
    $existingReading = $pdo->prepare(
        "SELECT id, result FROM leituras
         WHERE sensor_event_id = :sensor_event_id AND company_id = :company_id
           AND carregamento_id = :carregamento_id LIMIT 1",
    );
    $existingReading->execute([
        "sensor_event_id" => $sensorEventId,
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
    ]);
    $registeredReading = $existingReading->fetch(PDO::FETCH_ASSOC);
    if ($registeredReading !== false) {
        $pdo->rollBack();
        responder_json([
            "data" => [
                "id" => (int) $registeredReading["id"],
                "result" => (string) $registeredReading["result"],
                "duplicate" => true,
                "retry" => false,
                "final" => true,
                "load_status" => in_array($loading["state"], ["FINALIZANDO", "FINALIZADO"], true)
                    ? "COMPLETO" : "EM_ANDAMENTO",
            ],
        ], 200);
    }
}
if ($dispositivoScanner !== null) {
    if (!$sensorEventId || ($barcode === "" && !$noRead)) {
        $pdo->rollBack();
        responder_json(["error" => "A leitura Keyence precisa de um código e de um evento de passagem pendente."], 409);
    }
    if ($scannerMatch["ambiguous"] || $scannerMatch["best"] === null
        || $scannerMatch["best"]["sensor_event_id"] !== $sensorEventId) {
        $pdo->rollBack();
        responder_json(["error" => "A leitura não corresponde à janela prevista de uma passagem M2052."], 409);
    }
    $availableSensorEvent = $pdo->prepare(
        "SELECT s.id FROM eventos_sensor s
         WHERE s.id = :sensor_event_id AND s.carregamento_id = :carregamento_id
           AND s.equipment_id = :equipment_id
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
         LIMIT 1 FOR UPDATE",
    );
    $availableSensorEvent->execute([
        "sensor_event_id" => $sensorEventId,
        "carregamento_id" => $loadingId,
        "equipment_id" => $dispositivoScanner["equipment_id"],
    ]);
    if (!$availableSensorEvent->fetch()) {
        $pdo->rollBack();
        responder_json(["error" => "O evento de passagem já foi processado ou expirou."], 409);
    }
}

$matchedProductId = null;
$productPlannedQuantity = 0;
$productValidCount = 0;
if ($barcode !== "") {
    $matchedProductId = $readingRepository->buscarProdutoAtivoPorCodigo(
        (int) $usuarioAtor["company_id"],
        $barcode,
    );
}
$quantities = $readingRepository->obterQuantidadesParaClassificar(
    (int) $usuarioAtor["company_id"],
    (int) $loadingId,
    (int) $loading["romaneio_id"],
    $loading["truck_id"] === null ? null : (int) $loading["truck_id"],
    $matchedProductId,
);
$loadingPlannedQuantity = $quantities["loading_planned_quantity"];
$loadingValidCount = $quantities["loading_valid_count"];
$loadingSemLeituraCount = $quantities["loading_sem_leitura_count"];
$productPlannedQuantity = $quantities["product_planned_quantity"];
$productValidCount = $quantities["product_valid_count"];
$productSemLeituraCount = $quantities["product_sem_leitura_count"];
$physicalExcess = false;
$physicalEventPosition = null;
if ($sensorEventId && $loadingPlannedQuantity > 0) {
    // Ordena pelo instante da captura para conservar a posição quando eventos
    // pré-pausa chegam depois de eventos já sincronizados.
    $sensorEventAtEpochMs = (int) ($sensorEvent["event_at_epoch_ms"] ?? 0);
    if ($sensorEventAtEpochMs < 1) {
        $sensorEventAtEpochMs = $sensorEvent["device_detected_at"] !== null
            ? trace_sql_datetime_to_epoch_ms((string) $sensorEvent["device_detected_at"])
            : (int) $sensorEvent["server_detected_at_epoch_ms"];
    }
    $sensorPosition = $pdo->prepare(
        "SELECT COUNT(*) FROM eventos_sensor
         WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id
           AND conta_na_meta = 1 AND (
             (event_at_epoch_ms IS NULL AND id <= :legacy_sensor_event_id)
             OR event_at_epoch_ms < :event_at_before
             OR (event_at_epoch_ms = :event_at_equal AND id <= :sensor_event_id)
           )",
    );
    $sensorPosition->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
        "legacy_sensor_event_id" => $sensorEventId,
        "event_at_before" => $sensorEventAtEpochMs,
        "event_at_equal" => $sensorEventAtEpochMs,
        "sensor_event_id" => $sensorEventId,
    ]);
    $physicalEventPosition = (int) $sensorPosition->fetchColumn();
    $physicalExcess = $physicalEventPosition > $loadingPlannedQuantity;
}
$classification = (new ClassificadorLeitura())->classificar(
    $barcode !== "",
    $matchedProductId,
    $productPlannedQuantity,
    $productValidCount,
    $loadingPlannedQuantity,
    $loadingValidCount,
    $physicalExcess,
    $productSemLeituraCount,
    $loadingSemLeituraCount,
);
$result = $classification["result"];
$productId = $classification["product_id"];
$productPlannedQuantity = $classification["product_planned_quantity"];
$productValidCount = $classification["product_valid_count"];
$productQuotaReached = $classification["product_quota_reached"];
$noReadEvidenceRequired = $classification["no_read_evidence_required"];
$wrongProductDetected = $classification["wrong_product_detected"];
$barcodeUnmatched = $barcode !== "" && $matchedProductId === null;
$countsTowardPlan = in_array($result, ["VALIDO", "SEM_LEITURA"], true) && !$physicalExcess;
if ($physicalExcess && $sensorEventId) {
    // A posição após o limite não consome a meta, mesmo que a sincronização
    // do evento chegue antes da confirmação física da parada.
    $excludeLatePassage = $pdo->prepare(
        "UPDATE eventos_sensor SET conta_na_meta_origem = 0, conta_na_meta = 0
         WHERE id = :sensor_event_id AND carregamento_id = :loading_id",
    );
    $excludeLatePassage->execute([
        "sensor_event_id" => $sensorEventId,
        "loading_id" => $loadingId,
    ]);
}

$insert = $pdo->prepare(
    "INSERT INTO leituras
        (company_id, carregamento_id, sensor_event_id, product_id, barcode, attempt_number, result, conta_na_meta, read_at)
     VALUES (:company_id, :carregamento_id, :sensor_event_id, :product_id, :barcode, :attempt_number, :result, :conta_na_meta, NOW(3))",
);
try {
    $insert->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
        "sensor_event_id" => $sensorEventId ?: null,
        "product_id" => $productId,
        "barcode" => $barcode !== "" ? $barcode : null,
        "attempt_number" => $attemptNumber,
        "result" => $result,
        "conta_na_meta" => $countsTowardPlan ? 1 : 0,
    ]);
} catch (PDOException $exception) {
    if (
        $sensorEventId &&
        isset($exception->errorInfo[1]) &&
        (int) $exception->errorInfo[1] === 1062
    ) {
        $existing = $pdo->prepare(
            "SELECT id, result, barcode FROM leituras
             WHERE sensor_event_id = :sensor_event_id AND company_id = :company_id
               AND carregamento_id = :carregamento_id LIMIT 1",
        );
        $existing->execute([
            "sensor_event_id" => $sensorEventId,
            "company_id" => $usuarioAtor["company_id"],
            "carregamento_id" => $loadingId,
        ]);
        $reading = $existing->fetch();
        if (!$reading) {
            throw $exception;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) ($reading["barcode"] ?? "") !== $barcode) {
            responder_json(["error" => "Esta passagem já possui outro código de barras."], 409);
        }
        responder_json(
            [
                "data" => [
                    "id" => (int) $reading["id"],
                    "result" => $reading["result"],
                    "duplicate" => true,
                    "retry" => false,
                    "final" => true,
                ],
            ],
            200,
        );
    }
    throw $exception;
}
$readingId = (int) $pdo->lastInsertId();
if ($result === "VALIDO" && $countsTowardPlan) {
    $validCounter = $pdo->prepare(
        "UPDATE carregamentos SET leituras_validas = leituras_validas + 1 WHERE id = :id AND company_id = :company_id",
    );
    $validCounter->execute(["id" => $loadingId, "company_id" => $usuarioAtor["company_id"]]);
}
record_operational_event(
    $pdo,
    $usuarioAtor,
    "LEITURA_REGISTRADA",
    "leitura",
    $readingId,
    [
        "carregamento_id" => (int) $loadingId,
        "result" => $result,
        "barcode" => $barcode,
        "no_read" => $noRead,
        "barcode_unmatched" => $barcodeUnmatched,
        "attempt_number" => $attemptNumber,
        ...($productId !== null ? ["product_id" => (int) $productId] : []),
        "read_at" => date("Y-m-d H:i:s.v"),
        ...($offlineEventId !== "" ? ["event_uuid" => $offlineEventId] : []),
    ],
);
if (($physicalExcess || $result === "PRODUTO_INCORRETO") && $sensorEventId) {
    \App\Aplicacao\ServicoPausasCarregamento::reclassificarPassagens($pdo, (int) $loadingId);
}
$occurrenceId = null;
$evidenceStatus = "NAO_SOLICITADA";
if ($noReadEvidenceRequired) {
    $occurrence = $pdo->prepare(
        'INSERT INTO ocorrencias (company_id, carregamento_id, type, quantity, description, created_by) VALUES (:company_id, :carregamento_id, \'FALHA_SEM_LEITURA\', 1, :description, :created_by)',
    );
    $occurrence->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
        "description" => $barcodeUnmatched
            ? "Saco detectado pelo sensor com código sem correspondência no catálogo."
            : "Saco detectado pelo sensor sem código de barras válido.",
        "created_by" => $usuarioAtor["id"],
    ]);
    $occurrenceId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "FALHA_SEM_LEITURA",
        "ocorrencia",
        $occurrenceId,
        ["carregamento_id" => (int) $loadingId, "leitura_id" => $readingId],
    );
    $evidenceStatus = $sensorEventId ? "IMAGEM_PENDENTE_INCIDENTE" : "NAO_SOLICITADA";
}
if ($wrongProductDetected) {
    $occurrence = $pdo->prepare(
        'INSERT INTO ocorrencias (company_id, carregamento_id, type, quantity, description, created_by) VALUES (:company_id, :carregamento_id, \'PRODUTO_INCORRETO\', 1, :description, :created_by)',
    );
    $occurrence->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
        "description" => "Produto lido não está previsto para o carregamento.",
        "created_by" => $usuarioAtor["id"],
    ]);
    $occurrenceId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "PRODUTO_INCORRETO",
        "ocorrencia",
        $occurrenceId,
        ["carregamento_id" => (int) $loadingId, "leitura_id" => $readingId],
    );
    $evidenceStatus = "NAO_SOLICITADA";
    if ($result === "PRODUTO_INCORRETO") {
        $wrongProductStop = (new ServicoComandoClp($pdo))->solicitarParadaPorProdutoIncorreto(
            $usuarioAtor,
            (int) $loadingId,
        );
        record_operational_event(
            $pdo,
            $usuarioAtor,
            "PARADA_PRODUTO_INCORRETO",
            "leitura",
            $readingId,
            [
                "carregamento_id" => (int) $loadingId,
                "stop_reason" => $wrongProductStop["stop_reason"],
                "operator_alert" => $wrongProductStop["operator_alert"],
                "physical_stop_request_status" => $wrongProductStop["physical_stop_request_status"],
                "command_request_id" => $wrongProductStop["command_request_id"],
            ],
        );
    }
}
if ($sensorEventId && ($noReadEvidenceRequired || $result === "PRODUTO_INCORRETO")) {
    $evidenceReason = $result === "PRODUTO_INCORRETO" ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
    $evidenceRequest = $pdo->prepare(
        'INSERT INTO solicitacoes_imagens_evidencia (sensor_event_id, carregamento_id, equipment_id, reason, requested_at)
         VALUES (:sensor_event_id, :carregamento_id, :equipment_id, :reason, NOW(3))
         ON DUPLICATE KEY UPDATE
           reason = VALUES(reason),
           status = IF(status = \'ERRO\', \'PENDENTE\', status),
           requested_at = VALUES(requested_at)',
    );
    $evidenceRequest->execute([
        "sensor_event_id" => $sensorEventId,
        "carregamento_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
        "reason" => $evidenceReason,
    ]);
    $evidenceIdStatement = $pdo->prepare(
        "SELECT id FROM solicitacoes_imagens_evidencia WHERE sensor_event_id = :sensor_event_id LIMIT 1",
    );
    $evidenceIdStatement->execute(["sensor_event_id" => $sensorEventId]);
    $evidenceRequestId = (int) ($evidenceIdStatement->fetchColumn() ?: 0);
    if ($evidenceRequestId < 1) {
        throw new RuntimeException("Solicitação de imagem de evidência não encontrada após gravação.");
    }
    if ($evidenceReason === "PRODUTO_INCORRETO") {
        $relabelPreviousCapture = $pdo->prepare(
            "UPDATE imagens SET reason = 'PRODUTO_INCORRETO', reading_id = :reading_id
             WHERE carregamento_id = :carregamento_id AND reason IN ('SEM_LEITURA', 'EXCESSO')
               AND (reading_id IN (
                     SELECT id FROM leituras WHERE sensor_event_id = :sensor_event_id
                   ) OR path LIKE CONCAT('%keyence-', :evidence_request_id, '-%'))",
        );
        $relabelPreviousCapture->execute([
            "reading_id" => $readingId,
            "carregamento_id" => $loadingId,
            "sensor_event_id" => $sensorEventId,
            "evidence_request_id" => $evidenceRequestId,
        ]);
    }
    record_operational_event(
        $pdo,
        $usuarioAtor,
        $evidenceReason === "PRODUTO_INCORRETO" ? "PRODUTO_INCORRETO_EVIDENCIA_SOLICITADA" : "IMAGEM_EVIDENCIA_SOLICITADA",
        "evidence_request",
        $evidenceRequestId,
        ["sensor_event_id" => $sensorEventId, "reason" => $evidenceReason, "reading_result" => $result],
    );
    $evidenceStatus = "IMAGEM_PENDENTE_INCIDENTE";
}
$loadStatus = $loading["state"] === "FINALIZANDO" ? "COMPLETO" : "EM_ANDAMENTO";
$stopDetails = $excessStop ?? $wrongProductStop;
$pdo->commit();
responder_json(
    [
        "data" => [
            "id" => $readingId,
            "result" => $result,
            "barcode" => $barcode !== "" ? $barcode : null,
            "attempt_number" => $attemptNumber,
            "retry" => false,
            "final" => true,
            "occurrence_id" => $occurrenceId,
            "evidence_status" => $evidenceStatus,
            "load_status" => $loadStatus,
            "excesso" => false,
            "product_quota_reached" => $productQuotaReached,
            "stop_reason" => $stopDetails["stop_reason"] ?? $loading["stop_reason"] ?? null,
            "physical_stop_request_status" => $stopDetails["physical_stop_request_status"] ?? null,
            "command_request_id" => $stopDetails["command_request_id"] ?? null,
            "operator_alert" => $result === "PRODUTO_INCORRETO"
                ? ($wrongProductStop["operator_alert"] ?? "Produto fora do romaneio ou da quantidade prevista. Remova a unidade e confirme a parada física antes de continuar.")
                : ($loading["operator_alert"] ?? null),
        ],
    ],
    201,
);
} catch (PDOException $exception) {
    $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);
    if ($exception->getCode() !== "40001" && !in_array($driverErrorCode, [1205, 1213], true)) {
        throw $exception;
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($transientAttempt < 2) {
        $transientAttempt++;
        usleep(random_int(25_000, 75_000) * $transientAttempt);
        continue;
    }
    registrar_log_erro($exception, "conflito_transitorio_leitura");
    header("Retry-After: 0");
    responder_json(
        [
            "error" => "Conflito temporário ao registrar a leitura. Confirme o saco antes de prosseguir se a falha persistir.",
            "retryable" => true,
        ],
        503,
    );
}
}
