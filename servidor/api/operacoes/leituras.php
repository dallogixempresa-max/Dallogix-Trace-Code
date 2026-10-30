<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/temporizacao_leitor.php";
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
$pdo->beginTransaction();
register_shutdown_function(static function () use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});
try {
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
        true,
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
if ($result === "VALIDO") {
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
            "stop_reason" => null,
            "operator_alert" => match ($result) {
                "EXCESSO" => "Quantidade prevista para este produto atingida. Confira a leitura excedente.",
                "PRODUTO_INCORRETO" => "Código recebido, mas o produto não está previsto no romaneio. A passagem foi contada e a operação continua.",
                default => null,
            },
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
