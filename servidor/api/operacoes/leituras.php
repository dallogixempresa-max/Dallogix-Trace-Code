<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ClassificadorLeitura.php";
require_once __DIR__ . "/../../src/Aplicacao/SolicitacaoLeitura.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoLeituras.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioLeituras.php";

use App\Aplicacao\ClassificadorLeitura;
use App\Aplicacao\SolicitacaoLeitura;
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
$pdo->beginTransaction();
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});
$loadingStatement = $pdo->prepare(
    'SELECT c.id, c.state, c.romaneio_id, c.truck_id, c.equipment_id, c.leituras_validas
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
if (!in_array($loading["state"], ["CARREGANDO", "FINALIZANDO"], true)) {
    $pdo->rollBack();
    responder_json(
        ["error" => "Leitura ignorada: a esteira está em {$loading["state"]}."],
        409,
    );
}

$loadingValidCount = (int) $loading["leituras_validas"];
// O Keyence envia o código por TCP. Se a API precisar associar o evento,
// consome primeiro a passagem pendente mais antiga dentro da janela.
if (!$sensorEventId && ($barcode !== "" || $noRead)) {
    $associationWindowMs = max(500, min(10000, (int) (getenv("TRACE_SCANNER_NO_READ_TIMEOUT_MS") ?: 1500)));
    $associationWindowMicroseconds = $associationWindowMs * 1000;
    $pendingSensor = $pdo->prepare(
        "SELECT s.id
         FROM eventos_sensor s
         WHERE s.carregamento_id = :carregamento_id
           AND s.equipment_id = :equipment_id
           AND s.detected_at >= DATE_SUB(NOW(3), INTERVAL {$associationWindowMicroseconds} MICROSECOND)
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
         ORDER BY s.detected_at ASC, s.id ASC LIMIT 1 FOR UPDATE",
    );
    $pendingSensor->execute([
        "carregamento_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
    ]);
    $matchedSensorEventId = $pendingSensor->fetchColumn();
    if ($matchedSensorEventId !== false) {
        $sensorEventId = (int) $matchedSensorEventId;
    }
}
if (($barcode !== "" || $noRead) && !$sensorEventId) {
    $pdo->rollBack();
    responder_json(
        ["error" => "Leitura aguardando o evento de passagem do sensor M2052."],
        409,
    );
}
if ($sensorEventId) {
    $sensorStatement = $pdo->prepare(
        "SELECT id FROM eventos_sensor WHERE id = :sensor_event_id AND carregamento_id = :carregamento_id AND equipment_id = :equipment_id LIMIT 1",
    );
    $sensorStatement->execute([
        "sensor_event_id" => $sensorEventId,
        "carregamento_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
    ]);
    if (!$sensorStatement->fetch()) {
        $pdo->rollBack();
        responder_json(
            ["error" => "Evento de sensor não pertence ao carregamento."],
            422,
        );
    }
}
if ($dispositivoScanner !== null) {
    if (!$sensorEventId || ($barcode === "" && !$noRead)) {
        $pdo->rollBack();
        responder_json(["error" => "A leitura Keyence precisa de um código e de um evento de passagem pendente."], 409);
    }
    $availableSensorEvent = $pdo->prepare(
        "SELECT s.id FROM eventos_sensor s
         WHERE s.id = :sensor_event_id AND s.carregamento_id = :carregamento_id
           AND s.equipment_id = :equipment_id
           AND s.detected_at >= DATE_SUB(NOW(3), INTERVAL 10 SECOND)
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
$productPlannedQuantity = $quantities["product_planned_quantity"];
$productValidCount = $quantities["product_valid_count"];
$classification = (new ClassificadorLeitura())->classificar(
    $barcode !== "",
    $matchedProductId,
    $productPlannedQuantity,
    $productValidCount,
    $loadingPlannedQuantity,
    $loadingValidCount,
);
$result = $classification["result"];
$productId = $classification["product_id"];
$productPlannedQuantity = $classification["product_planned_quantity"];
$productValidCount = $classification["product_valid_count"];

$endProductAlert = null;
$alertThreshold = limite_alerta_fim_produto_sacas();
if ($result === "VALIDO" && $productId !== null && $productPlannedQuantity > 0) {
    $productValidCountAfter = $productValidCount + 1;
    $productRemainingAfter = max(0, $productPlannedQuantity - $productValidCountAfter);
    $alertAlreadyRecorded = false;
    if ($productRemainingAfter > 0 && $productRemainingAfter <= $alertThreshold) {
        $alertStatement = $pdo->prepare(
            "SELECT 1
             FROM logs_auditoria
             WHERE company_id = :company_id
               AND action = 'ALERTA_FIM_PRODUTO'
               AND entity_type = 'carregamento'
               AND entity_id = :carregamento_id
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.product_id')) = :product_id
             LIMIT 1",
        );
        $alertStatement->execute([
            "company_id" => $usuarioAtor["company_id"],
            "carregamento_id" => $loadingId,
            "product_id" => $productId,
        ]);
        $alertAlreadyRecorded = (bool) $alertStatement->fetchColumn();
    }
    if ($productRemainingAfter > 0 && $productRemainingAfter <= $alertThreshold && !$alertAlreadyRecorded) {
        $endProductAlert = sprintf(
            "Faltam %d sacas para finalizar o produto.",
            $productRemainingAfter,
        );
    }
}

$insert = $pdo->prepare(
    "INSERT INTO leituras (company_id, carregamento_id, sensor_event_id, product_id, barcode, attempt_number, result, read_at) VALUES (:company_id, :carregamento_id, :sensor_event_id, :product_id, :barcode, :attempt_number, :result, NOW(3))",
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
    ]);
} catch (PDOException $exception) {
    if (
        $sensorEventId &&
        isset($exception->errorInfo[1]) &&
        (int) $exception->errorInfo[1] === 1062
    ) {
        $existing = $pdo->prepare(
            "SELECT id, result FROM leituras WHERE sensor_event_id = :sensor_event_id LIMIT 1",
        );
        $existing->execute(["sensor_event_id" => $sensorEventId]);
        $reading = $existing->fetch();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
if ($result === "VALIDO") {
    $validCounter = $pdo->prepare(
        "UPDATE carregamentos SET leituras_validas = leituras_validas + 1 WHERE id = :id AND company_id = :company_id",
    );
    $validCounter->execute(["id" => $loadingId, "company_id" => $usuarioAtor["company_id"]]);
}
if ($endProductAlert !== null) {
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "ALERTA_FIM_PRODUTO",
        "carregamento",
        (int) $loadingId,
        [
            "carregamento_id" => (int) $loadingId,
            "leitura_id" => $readingId,
            "product_id" => $productId,
            "planned_quantity" => $productPlannedQuantity,
            "valid_readings" => $productValidCount + 1,
            "remaining_quantity" => max(0, $productPlannedQuantity - ($productValidCount + 1)),
            "threshold" => $alertThreshold,
            "message" => $endProductAlert,
        ],
    );
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
        "attempt_number" => $attemptNumber,
        ...($offlineEventId !== "" ? ["event_uuid" => $offlineEventId] : []),
    ],
);
$occurrenceId = null;
$evidenceStatus = "NAO_SOLICITADA";
if ($result === "SEM_LEITURA") {
    $occurrence = $pdo->prepare(
        'INSERT INTO ocorrencias (company_id, carregamento_id, type, quantity, description, created_by) VALUES (:company_id, :carregamento_id, \'FALHA_SEM_LEITURA\', 1, :description, :created_by)',
    );
    $occurrence->execute([
        "company_id" => $usuarioAtor["company_id"],
        "carregamento_id" => $loadingId,
        "description" =>
        "Saco detectado pelo sensor sem código de barras válido.",
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
if ($result === "PRODUTO_INCORRETO") {
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
}
if ($result === "EXCESSO") {
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "EXCESSO_DETECTADO",
        "leitura",
        $readingId,
        ["carregamento_id" => (int) $loadingId, "product_id" => $productId],
    );
}
if ($result === "PRODUTO_INCORRETO") {
    $stopReason = "PRODUTO_INCORRETO";
    $stopAlert = "Produto incorreto detectado. Remova a unidade e confirme para continuar.";
    $stop = $pdo->prepare(
        "UPDATE carregamentos SET state = 'PAUSADO', stop_reason = :stop_reason, operator_alert = :operator_alert WHERE id = :id AND company_id = :company_id AND state = 'CARREGANDO'",
    );
    $stop->execute([
        "stop_reason" => $stopReason,
        "operator_alert" => $stopAlert,
        "id" => $loadingId,
        "company_id" => $usuarioAtor["company_id"],
    ]);
    $pendingStop = $pdo->prepare(
        "SELECT id FROM solicitacoes_comandos_clp
         WHERE carregamento_id = :carregamento_id AND company_id = :company_id
           AND command = 'PAUSAR_CARREGAMENTO'
           AND status IN ('PENDENTE', 'PROCESSANDO')
         ORDER BY id DESC LIMIT 1 FOR UPDATE",
    );
    $pendingStop->execute([
        "carregamento_id" => $loadingId,
        "company_id" => $usuarioAtor["company_id"],
    ]);
    $pauseCommandRequestId = (int) ($pendingStop->fetchColumn() ?: 0);
    if ($pauseCommandRequestId < 1) {
        $queueStop = $pdo->prepare(
            "INSERT INTO solicitacoes_comandos_clp
             (company_id, equipment_id, carregamento_id, command, requested_by)
             VALUES (:company_id, :equipment_id, :carregamento_id, 'PAUSAR_CARREGAMENTO', :requested_by)",
        );
        $queueStop->execute([
            "company_id" => $usuarioAtor["company_id"],
            "equipment_id" => $loading["equipment_id"],
            "carregamento_id" => $loadingId,
            "requested_by" => $usuarioAtor["id"],
        ]);
        $pauseCommandRequestId = (int) $pdo->lastInsertId();
    }
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "ESTEIRA_PAUSADA_AUTOMATICAMENTE",
        "carregamento",
        (int) $loadingId,
        [
            "reason" => $stopReason,
            "safe_physical_action" => "gateway_clp_required",
            "command_request_id" => $pauseCommandRequestId,
        ],
    );
}
if (
    $result === "SEM_LEITURA" &&
    $sensorEventId
) {
    $evidenceRequest = $pdo->prepare(
        'INSERT INTO solicitacoes_imagens_evidencia (sensor_event_id, carregamento_id, equipment_id, reason, requested_at)
         VALUES (:sensor_event_id, :carregamento_id, :equipment_id, :reason, NOW(3))
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = IF(status = \'ERRO\', \'PENDENTE\', status), requested_at = VALUES(requested_at)',
    );
    $evidenceRequest->execute([
        "sensor_event_id" => $sensorEventId,
        "carregamento_id" => $loadingId,
        "equipment_id" => $loading["equipment_id"],
        "reason" => $result,
    ]);
    $evidenceIdStatement = $pdo->prepare(
        "SELECT id FROM solicitacoes_imagens_evidencia WHERE sensor_event_id = :sensor_event_id LIMIT 1",
    );
    $evidenceIdStatement->execute(["sensor_event_id" => $sensorEventId]);
    $evidenceRequestId = (int) ($evidenceIdStatement->fetchColumn() ?: 0);
    if ($evidenceRequestId < 1) {
        throw new RuntimeException("Solicitação de imagem de evidência não encontrada após gravação.");
    }
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "IMAGEM_EVIDENCIA_SOLICITADA",
        "evidence_request",
        $evidenceRequestId,
        ["sensor_event_id" => $sensorEventId, "reason" => $result],
    );
    $evidenceStatus = "IMAGEM_PENDENTE_INCIDENTE";
}
$loadStatus = $loading["state"] === "FINALIZANDO" ? "COMPLETO" : "EM_ANDAMENTO";
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
            "excesso" => $result === "EXCESSO",
            "stop_reason" => $result === "PRODUTO_INCORRETO" ? $result : null,
            "operator_alert" => $result === "EXCESSO"
                ? "Quantidade prevista para este produto atingida. Confira a leitura excedente."
                : ($stopAlert ?? $endProductAlert),
            "pause_command_request_id" => $pauseCommandRequestId ?? null,
            "end_product_alert" => $endProductAlert,
        ],
    ],
    201,
);
