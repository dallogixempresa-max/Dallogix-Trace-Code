<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ValidadorParadaCarregamento.php";

$user = require_role(["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]);
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
$requiresConfirmedStop = $stopReason !== "";
if ($loading["state"] !== "PAUSADO") {
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
       AND command <> 'PAUSAR_CARREGAMENTO'
       AND status IN ('PENDENTE', 'PROCESSANDO')",
);
$pendingCommand->execute(["loading_id" => $loadingId, "company_id" => $user["company_id"]]);
if ((int) $pendingCommand->fetchColumn() > 0) {
    $pdo->rollBack();
    json_response(["error" => "Aguarde o CLP confirmar o último comando antes de encerrar."], 409);
}
$automaticQuotaStopRequested = !$requiresConfirmedStop
    || \App\Aplicacao\ValidadorParadaCarregamento::solicitada(
        $pdo,
        (int) $user["company_id"],
        (int) $loadingId,
    );
if (!$automaticQuotaStopRequested) {
        $pdo->rollBack();
        json_response(
            ["error" => "A solicitação de pausa da esteira não foi registrada. Solicite a pausa antes de finalizar."],
            409,
        );
}

// A passagem física ainda sem classificação é provisória: o leitor pode
// identificá-la como produto incorreto, que não consome a meta do romaneio.
$pendingReadings = $pdo->prepare(
    "SELECT COUNT(*) FROM eventos_sensor s
     WHERE s.carregamento_id = :loading_id
       AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)",
);
$pendingReadings->execute(["loading_id" => $loadingId]);
if ((int) $pendingReadings->fetchColumn() > 0) {
    $pdo->rollBack();
    json_response(
        ["error" => "Aguarde o leitor classificar as passagens detectadas antes de finalizar."],
        409,
    );
}

// Leitura, produto incorreto, excesso e evidência ficam auditados no relatório.
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
     WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id AND conta_na_meta = 1",
);
$loadingDetectedQuantityStatement->execute([
    "loading_id" => $loadingId,
    "equipment_id" => $loading["equipment_id"],
]);
$loadingDetectedQuantity = (int) $loadingDetectedQuantityStatement->fetchColumn();
if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity < $loadingPlannedQuantity) {
    $pdo->rollBack();
    json_response(
        ["error" => "O carregamento só pode ser finalizado depois de atingir a quantidade física prevista."],
        409,
    );
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
    if ($romaneioProntoParaEncerrar && $loading["romaneio_status"] === "EM_ANDAMENTO") {
        // Os demais carregamentos já foram concluídos operacionalmente.
        // Fotos e leituras pendentes permanecem vinculadas para o relatório.
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
            "report_processing_deferred" => true,
        ],
    );
    // Sacas que passaram pelo sensor sem leitura e sem product_id: ocorrem quando
    // o romaneio tem múltiplos produtos e o código não foi lido. São rastreáveis
    // pelas fotos do Keyence mas não são atribuídas a nenhum produto específico.
    $semProdutoQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'SEM_LEITURA' AND conta_na_meta = 1 AND product_id IS NULL",
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
            "report_processing_deferred" => true,
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
