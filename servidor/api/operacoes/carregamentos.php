<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoMonitoramento.php";

use App\Aplicacao\ServicoMonitoramento;

$usuarioAtor = exigir_sessao_usuario();
if ($usuarioAtor["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = obter_conexao_banco();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $service = new ServicoMonitoramento($pdo);
    responder_json(["data" => $service->carregamentosAtivos((int) $usuarioAtor["company_id"])]);
}

if ($_SERVER["REQUEST_METHOD"] === "PATCH") {
    exigir_csrf();
    if (!in_array($usuarioAtor["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
        responder_json(["error" => "Perfil sem permissão para trocar a Dala."], 403);
    }
    validar_licenca_ativa(obter_conexao_banco(), (int) $usuarioAtor["company_id"]);
    $payload = ler_json_da_requisicao();
    $loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
    $equipmentId = filter_var($payload["equipment_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$loadingId || !$equipmentId) {
        responder_json(["error" => "Carregamento e nova Dala são obrigatórios."], 422);
    }

    try {
        $pdo->beginTransaction();
        $loading = $pdo->prepare(
            "SELECT id, state, equipment_id, remote_carregamento_id
             FROM carregamentos
             WHERE id = :id AND company_id = :company_id
             LIMIT 1 FOR UPDATE",
        );
        $loading->execute([
            "id" => $loadingId,
            "company_id" => $usuarioAtor["company_id"],
        ]);
        $current = $loading->fetch();
        if (!$current || $current["state"] === "FINALIZADO" || $current["equipment_id"] !== null) {
            $pdo->rollBack();
            responder_json(["error" => "Carregamento não está aguardando uma Dala."], 409);
        }
        $equipment = $pdo->prepare(
            "SELECT id, equipment_code FROM equipamentos
             WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
        );
        $equipment->execute([
            "id" => $equipmentId,
            "company_id" => $usuarioAtor["company_id"],
        ]);
        $target = $equipment->fetch();
        if (!$target) {
            $pdo->rollBack();
            responder_json(["error" => "Nova Dala não encontrada para esta empresa."], 404);
        }
        $conflict = $pdo->prepare(
            "SELECT c.id FROM carregamentos c
             JOIN romaneios r ON r.id = c.romaneio_id
             WHERE c.company_id = :company_id AND c.id <> :id
               AND c.state <> 'FINALIZADO'
               AND r.status NOT IN ('FINALIZADO', 'CANCELADO')
               AND c.equipment_id = :equipment_id
             LIMIT 1 FOR UPDATE",
        );
        $conflict->execute([
            "company_id" => $usuarioAtor["company_id"],
            "id" => $loadingId,
            "equipment_id" => $equipmentId,
        ]);
        if ($conflict->fetch()) {
            $pdo->rollBack();
            responder_json(["error" => "A nova Dala já possui um carregamento em andamento."], 409);
        }
        $update = $pdo->prepare(
            "UPDATE carregamentos
             SET equipment_id = :equipment_id, state = 'AGUARDANDO', started_at = NULL
             WHERE id = :id AND company_id = :company_id",
        );
        $update->execute([
            "equipment_id" => $equipmentId,
            "id" => $loadingId,
            "company_id" => $usuarioAtor["company_id"],
        ]);
        record_operational_event(
            $pdo,
            $usuarioAtor,
            "CARREGAMENTO_DALA_VINCULADO",
            "carregamento",
            (int) $loadingId,
            [
                "equipment_id" => $equipmentId,
                "equipment_code" => $target["equipment_code"],
                "previous_equipment_id" => null,
                "remote_carregamento_id" => $current["remote_carregamento_id"] === null ? null : (int) $current["remote_carregamento_id"],
                "state" => "AGUARDANDO",
            ],
        );
        $pdo->commit();
        responder_json(["data" => [
            "id" => (int) $loadingId,
            "equipment_id" => (int) $equipmentId,
            "equipment_code" => $target["equipment_code"],
            "state" => "AGUARDANDO",
        ]]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Loading equipment reassignment failed: " . $exception->getMessage());
        responder_json(["error" => "Não foi possível vincular a nova Dala."], 500);
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder_json(["error" => "Método não permitido."], 405);
}
if (is_file(dirname(__DIR__, 3) . "/armazenamento/.maintenance")) {
    responder_json(["error" => "Atualização do sistema em andamento; tente preparar o carregamento novamente em instantes."], 503);
}
exigir_csrf();
if (!in_array($usuarioAtor["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    responder_json(
        [
            "error" =>
            "A preparação do carregamento é permitida somente para administração ou supervisão.",
        ],
        403,
    );
}
validar_licenca_ativa(obter_conexao_banco(), (int) $usuarioAtor["company_id"]);

$payload = ler_json_da_requisicao();
$romaneioId = filter_var($payload["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
$truckId = filter_var($payload["truck_id"] ?? null, FILTER_VALIDATE_INT);
$equipmentId = filter_var(
    $payload["equipment_id"] ?? ($payload["esteira_id"] ?? null),
    FILTER_VALIDATE_INT,
);
if (!$romaneioId || !$truckId || !$equipmentId) {
    responder_json(
        ["error" => "Romaneio, caminhão e esteira são obrigatórios."],
        422,
    );
}

$statement = $pdo->prepare(
    'SELECT r.id AS romaneio_id, rt.id AS truck_id, e.id AS equipment_id
     FROM romaneios r
     JOIN romaneio_caminhoes rt ON rt.romaneio_id = r.id
     JOIN equipamentos e ON e.id = :equipment_id AND e.company_id = r.company_id
     WHERE r.id = :romaneio_id AND rt.id = :truck_id AND r.company_id = :company_id LIMIT 1',
);
$statement->execute([
    "equipment_id" => $equipmentId,
    "romaneio_id" => $romaneioId,
    "truck_id" => $truckId,
    "company_id" => $usuarioAtor["company_id"],
]);
$valid = $statement->fetch();
if (!$valid) {
    responder_json(
        [
            "error" =>
            "Romaneio, caminhão ou equipamento não pertence à empresa.",
        ],
        422,
    );
}

try {
    $pdo->beginTransaction();
    $available = $pdo->prepare(
        "SELECT r.status
         FROM romaneios r
         WHERE r.id = :romaneio_id AND r.company_id = :company_id FOR UPDATE",
    );
    $available->execute([
        "romaneio_id" => $romaneioId,
        "company_id" => $usuarioAtor["company_id"],
    ]);
    $romaneio = $available->fetch();
    if (
        !$romaneio ||
        in_array($romaneio["status"], ["FINALIZADO", "CANCELADO"], true)
    ) {
        $pdo->rollBack();
        responder_json(
            [
                "error" =>
                "Este romaneio não está disponível para novo carregamento.",
            ],
            409,
        );
    }
    $conflict = $pdo->prepare(
        "SELECT c.id FROM carregamentos c
         JOIN romaneios r ON r.id = c.romaneio_id
         WHERE c.company_id = :company_id AND c.state <> 'FINALIZADO'
           AND r.status NOT IN ('FINALIZADO', 'CANCELADO')
           AND (c.equipment_id = :equipment_id OR (c.romaneio_id = :romaneio_id AND c.truck_id = :truck_id))
         LIMIT 1 FOR UPDATE",
    );
    $conflict->execute([
        "company_id" => $usuarioAtor["company_id"],
        "equipment_id" => $equipmentId,
        "romaneio_id" => $romaneioId,
        "truck_id" => $truckId,
    ]);
    if ($conflict->fetch()) {
        $pdo->rollBack();
        responder_json(
            [
                "error" =>
                "A Dala ou este caminhão já possui um carregamento em andamento.",
            ],
            409,
        );
    }
    $insert = $pdo->prepare(
        'INSERT INTO carregamentos (company_id, equipment_id, romaneio_id, truck_id, state, started_at) VALUES (:company_id, :equipment_id, :romaneio_id, :truck_id, \'PREPARANDO\', NOW())',
    );
    $insert->execute([
        "company_id" => $usuarioAtor["company_id"],
        "equipment_id" => $equipmentId,
        "romaneio_id" => $romaneioId,
        "truck_id" => $truckId,
    ]);
    $loadingId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "UPDATE romaneios SET status = 'EM_ANDAMENTO' WHERE id = :id AND company_id = :company_id",
    )->execute(["id" => $romaneioId, "company_id" => $usuarioAtor["company_id"]]);
    record_operational_event(
        $pdo,
        $usuarioAtor,
        "CARREGAMENTO_PREPARADO",
        "carregamento",
        $loadingId,
        [
            "romaneio_id" => $romaneioId,
            "truck_id" => $truckId,
            "equipment_id" => $equipmentId,
        ],
    );
    $pdo->commit();
    responder_json(
        ["data" => ["id" => $loadingId, "state" => "PREPARANDO"]],
        201,
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Loading preparation failed: " . $exception->getMessage());
    responder_json(
        ["error" => "Não foi possível preparar o carregamento."],
        500,
    );
}
