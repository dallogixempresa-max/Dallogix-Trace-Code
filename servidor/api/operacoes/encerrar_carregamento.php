<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

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
        "SELECT 1 FROM logs_auditoria a
         WHERE a.company_id = :company_id AND a.action = :action
           AND a.entity_type = 'carregamento' AND a.entity_id = :loading_id
           AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.stop_reason')) = :stop_reason
           AND (
             EXISTS (
               SELECT 1 FROM solicitacoes_comandos_clp q
               WHERE q.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                 AND q.carregamento_id = :command_loading_id AND q.company_id = :command_company_id
                 AND q.command = 'PAUSAR_CARREGAMENTO' AND q.status = 'APLICADO'
             )
             OR EXISTS (
               SELECT 1 FROM logs_auditoria ack
               WHERE ack.company_id = a.company_id
                 AND ack.action = 'COMANDO_CLP_CONCLUIDO'
                 AND ack.entity_type = 'solicitacao_comando_clp'
                 AND (
                   ack.entity_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                   OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.aggregate_id')) AS UNSIGNED)
                     = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                 )
                 AND JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.command')) = 'PAUSAR_CARREGAMENTO'
                 AND JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.status')) = 'APLICADO'
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_carregamento_id')) AS UNSIGNED) = :ack_remote_loading_id
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_equipment_id')) AS UNSIGNED) = :ack_remote_equipment_id
             )
           )
         ORDER BY a.id DESC LIMIT 1",
    );
    $confirmedStop->execute([
        "company_id" => $user["company_id"],
        "action" => $stopAction,
        "loading_id" => $loadingId,
        "stop_reason" => $stopReason,
        "command_loading_id" => $loadingId,
        "command_company_id" => $user["company_id"],
        "ack_remote_loading_id" => $loadingId,
        "ack_remote_equipment_id" => (int) $loading["equipment_id"],
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

// A conferência de leituras e imagens pertence ao relatório posterior.
// A conclusão operacional exige a meta física exata e o comando de parada confirmado.
// Só códigos VALIDO satisfazem a quantidade do item; SEM_LEITURA permanece divergência auditável.
$divergences = $pdo->prepare("SELECT ri.product_id, SUM(ri.planned_quantity) AS planned_quantity,
    GREATEST(0,
      COALESCE((SELECT COUNT(*) FROM leituras l WHERE l.carregamento_id = :loading_id AND l.product_id = ri.product_id AND l.result = 'VALIDO' AND l.conta_na_meta = 1), 0)
      - COALESCE((SELECT SUM(ret.quantity) FROM retornos ret JOIN leituras lr ON lr.id = ret.leitura_id
                  WHERE ret.carregamento_id = :return_loading_id
                    AND lr.conta_na_meta = 1 AND lr.product_id = ri.product_id), 0)
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
$overfill = 0;
foreach ($divergenceRows as $divergence) {
    $difference = (int) $divergence["planned_quantity"] - (int) $divergence["moved_quantity"];
    $overfill += max(0, -$difference);
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
     WHERE carregamento_id = :loading_id AND equipment_id = :equipment_id AND conta_na_meta = 1",
);
$loadingDetectedQuantityStatement->execute([
    "loading_id" => $loadingId,
    "equipment_id" => $loading["equipment_id"],
]);
$loadingDetectedQuantity = (int) $loadingDetectedQuantityStatement->fetchColumn();
$excessReadingsStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :loading_id AND result = 'EXCESSO' AND conta_na_meta = 1",
);
$excessReadingsStatement->execute(["loading_id" => $loadingId]);
$excessReadingCount = (int) $excessReadingsStatement->fetchColumn();
$excessEvidenceStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
     WHERE i.carregamento_id = :loading_id AND i.reason = 'EXCESSO'
       AND (i.sensor_event_id IS NULL OR EXISTS (
         SELECT 1 FROM eventos_sensor s WHERE s.id = i.sensor_event_id AND s.conta_na_meta = 1
       ))",
);
$excessEvidenceStatement->execute(["loading_id" => $loadingId]);
$excessEvidenceCount = (int) $excessEvidenceStatement->fetchColumn();
$hasExcess = $loadingDetectedQuantity > $loadingPlannedQuantity
    || $excessReadingCount > 0
    || $excessEvidenceCount > 0
    || $overfill > 0;
if ($hasExcess) {
    $pdo->rollBack();
    json_response(
        ["error" => "A quantidade detectada excedeu o previsto. O carregamento não pode ser finalizado; mantenha a esteira parada e trate a ocorrência."],
        409,
    );
}
if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity !== $loadingPlannedQuantity) {
    $pdo->rollBack();
    json_response(
        ["error" => "O carregamento só pode ser finalizado quando a quantidade prevista de sacas válidas ou sem leitura for atingida."],
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
    $quantityMismatch = $pdo->prepare(
        "SELECT COUNT(*) FROM carregamentos c
         WHERE c.romaneio_id = :romaneio_id AND c.company_id = :company_id
           AND (SELECT COUNT(*) FROM eventos_sensor s
                WHERE s.carregamento_id = c.id AND s.equipment_id = c.equipment_id AND s.conta_na_meta = 1)
             <> (SELECT COALESCE(SUM(ri.planned_quantity), 0) FROM romaneio_itens ri
                 WHERE ri.romaneio_id = c.romaneio_id
                   AND (ri.truck_id = c.truck_id OR ri.truck_id IS NULL))",
    );
    $quantityMismatch->execute(["romaneio_id" => $loading["romaneio_id"], "company_id" => $user["company_id"]]);
    $allLoadingsHaveExactQuota = (int) $quantityMismatch->fetchColumn() === 0;
    if ($romaneioProntoParaEncerrar && $allLoadingsHaveExactQuota && $loading["romaneio_status"] === "EM_ANDAMENTO") {
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
