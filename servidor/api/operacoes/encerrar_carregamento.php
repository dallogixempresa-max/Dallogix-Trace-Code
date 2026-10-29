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
    "SELECT c.id, c.state, c.romaneio_id, c.truck_id, r.number AS romaneio_number
     FROM carregamentos c JOIN romaneios r ON r.id = c.romaneio_id
     WHERE c.id = :id AND c.company_id = :company_id LIMIT 1 FOR UPDATE",
);
$statement->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
$loading = $statement->fetch();
if (!$loading) {
    $pdo->rollBack();
    json_response(["error" => "Carregamento não encontrado."], 404);
}
if (!in_array($loading["state"], ["CARREGANDO", "FINALIZANDO"], true)) {
    $pdo->rollBack();
    json_response(
        [
            "error" => "Carregamento em estado {$loading["state"]} não pode ser encerrado.",
        ],
        409,
    );
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
$divergences = $pdo->prepare("SELECT ri.product_id, SUM(ri.planned_quantity) AS planned_quantity,
    COALESCE((SELECT COUNT(*) FROM leituras l WHERE l.carregamento_id = :loading_id AND l.product_id = ri.product_id AND l.result = 'VALIDO'), 0) AS moved_quantity,
    p.name FROM romaneio_itens ri JOIN produtos p ON p.id = ri.product_id
    WHERE ri.romaneio_id = :romaneio_id AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)
    GROUP BY ri.product_id, p.name");
$divergences->execute([
    "loading_id" => $loadingId,
    "romaneio_id" => $loading["romaneio_id"],
    "truck_id" => $loading["truck_id"],
]);
$divergenceRows = $divergences->fetchAll();
$hasDivergence = false;
foreach ($divergenceRows as $divergence) {
    if ((int) $divergence["planned_quantity"] !== (int) $divergence["moved_quantity"]) {
        $hasDivergence = true;
        break;
    }
}
if ($hasDivergence && !in_array($user["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    $pdo->rollBack();
    json_response(["error" => "Somente supervisores ou administradores podem finalizar uma divergência."], 403);
}
if ($hasDivergence && $justification === "") {
    $pdo->rollBack();
    json_response(["error" => "Informe uma justificativa para finalizar com divergência."], 422);
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
    record_operational_event(
        $pdo,
        $user,
        "CARREGAMENTO_FINALIZADO",
        "carregamento",
        (int) $loadingId,
        [
            "romaneio_id" => (int) $loading["romaneio_id"],
            "romaneio_pronto_para_encerrar" => $romaneioProntoParaEncerrar,
        ],
    );
    $pdo->commit();
    json_response([
        "data" => [
            "id" => (int) $loadingId,
            "state" => "FINALIZADO",
            "romaneio_id" => (int) $loading["romaneio_id"],
            "romaneio_number" => (string) $loading["romaneio_number"],
            "romaneio_pronto_para_encerrar" => $romaneioProntoParaEncerrar,
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
