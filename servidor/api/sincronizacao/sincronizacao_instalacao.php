<?php

declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/DetalhesHeartbeatInstalacao.php";

if (trace_e_instalacao_local()) {
    responder_json(["error" => "Endpoint disponível somente no servidor central."], 403);
}

exigir_metodo_http(["GET", "POST"]);
$company = exigir_instalacao_remota();
$pdo = obter_conexao_banco();
$companyId = (int) $company["id"];
$installationId = (int) $company["installation_id"];
$equipmentId = (int) $company["equipment_id"];
$snapshotQueueCursor = static function (PDO $connection, int $companyId): int {
    $statement = $connection->prepare(
        "SELECT COALESCE(MAX(q.id), 0)
         FROM fila_sincronizacao q
         WHERE q.company_id = :company_id
           AND q.status IN ('PENDENTE', 'ERRO')
           AND NOT EXISTS (
             SELECT 1 FROM fila_sincronizacao processing
             WHERE processing.company_id = q.company_id
               AND processing.status = 'PROCESSANDO'
               AND processing.id <= q.id
           )",
    );
    $statement->execute(["company_id" => $companyId]);
    return (int) $statement->fetchColumn();
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $payload = ler_json_da_requisicao();
    if (($payload["action"] ?? "") !== "heartbeat") {
        responder_json(["error" => "Ação de sincronização inválida."], 422);
    }
    $industrialPcReceived = false;
    $industrialPc = $payload["industrial_pc"] ?? null;
    if (is_array($industrialPc)) {
        $pcStatus = strtoupper(trim((string) ($industrialPc["status"] ?? "")));
        if (in_array($pcStatus, ["ONLINE", "OFFLINE", "ERRO", "DESCONHECIDO"], true)) {
            $reportedAt = trim((string) ($industrialPc["reported_at"] ?? ""));
            $pcDetails = ["source" => "instalacao_local"];
            if ($reportedAt !== "") {
                $pcDetails["reported_at"] = mb_substr($reportedAt, 0, 64);
            }
            $disk = $industrialPc["disk"] ?? null;
            if (is_array($disk)) {
                $freeBytes = filter_var($disk["free_bytes"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
                $totalBytes = filter_var($disk["total_bytes"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
                $freePercent = is_numeric($disk["free_percent"] ?? null) ? (float) $disk["free_percent"] : null;
                if ($freeBytes !== false && $totalBytes !== false && $freePercent !== null && $freePercent >= 0 && $freePercent <= 100) {
                    $pcDetails["disk"] = [
                        "free_bytes" => (int) $freeBytes,
                        "total_bytes" => (int) $totalBytes,
                        "free_percent" => round($freePercent, 2),
                    ];
                }
            }
            $pcUpsert = $pdo->prepare(
                "UPDATE instalacoes_industriais
                 SET status = :status, last_seen_at = NOW(3), details = :details
                 WHERE id = :installation_id AND company_id = :company_id
                   AND equipment_id = :equipment_id AND sync_token_hash IS NOT NULL",
            );
            $pcUpsert->execute([
                "status" => $pcStatus,
                "details" => json_encode($pcDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                "installation_id" => $installationId,
                "company_id" => $companyId,
                "equipment_id" => $equipmentId,
            ]);
            $industrialPcReceived = $pcUpsert->rowCount() === 1;

            // Compatibilidade com instalações que ainda expõem o contrato
            // legado de status do PC industrial. A tabela é verificada antes
            // para que o heartbeat continue funcionando após a migração para
            // instalacoes_industriais.
            $legacyTableExists = (bool) $pdo->query(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'status_pc_industrial'
                 LIMIT 1",
            )->fetchColumn();
            if ($legacyTableExists) {
                $legacyPcUpsert = $pdo->prepare(
                    "INSERT INTO status_pc_industrial
                         (company_id, status, last_seen_at, details)
                     VALUES (:company_id, :status, NOW(3), :details)
                     ON DUPLICATE KEY UPDATE
                         status = VALUES(status),
                         last_seen_at = VALUES(last_seen_at),
                         details = VALUES(details)",
                );
                $legacyPcUpsert->execute([
                    "company_id" => $companyId,
                    "status" => $pcStatus,
                    "details" => json_encode($pcDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
                $industrialPcReceived = true;
            }
        }
    }
    $upsert = $pdo->prepare(
        "INSERT INTO status_dispositivos
         (equipment_id, device_type, status, last_seen_at, details)
         SELECT e.id, :device_type, :status, NOW(3), :details
         FROM equipamentos e
         WHERE e.company_id = :company_id
           AND e.id = :installation_equipment_id
           AND ((:remote_equipment_id_a IS NOT NULL AND e.id = :remote_equipment_id_b)
             OR (:equipment_code_a <> '' AND e.equipment_code = :equipment_code_b))
         LIMIT 1
         ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen_at = VALUES(last_seen_at), details = VALUES(details)",
    );
    $received = 0;
    foreach (($payload["heartbeats"] ?? []) as $heartbeat) {
        $deviceType = strtoupper(trim((string) ($heartbeat["device_type"] ?? "")));
        $status = strtoupper(trim((string) ($heartbeat["status"] ?? "")));
        if (!in_array($deviceType, ["CLP", "SCANNER", "SENSOR", "SERVER"], true)) {
            continue;
        }
        if (!in_array($status, ["ONLINE", "OFFLINE", "ERRO", "DESCONHECIDO"], true)) {
            continue;
        }
        $remoteEquipmentId = filter_var($heartbeat["remote_equipment_id"] ?? null, FILTER_VALIDATE_INT);
        $equipmentCode = trim((string) ($heartbeat["equipment_code"] ?? ""));
        $details = json_encode(
            App\Aplicacao\DetalhesHeartbeatInstalacao::normalizar(
                $deviceType,
                $status,
                $heartbeat["details"] ?? null,
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $upsert->execute([
            "device_type" => $deviceType,
            "status" => $status,
            "details" => $details,
            "company_id" => $companyId,
            "remote_equipment_id_a" => $remoteEquipmentId === false ? null : (int) $remoteEquipmentId,
            "remote_equipment_id_b" => $remoteEquipmentId === false ? null : (int) $remoteEquipmentId,
            "equipment_code_a" => $equipmentCode,
            "equipment_code_b" => $equipmentCode,
            "installation_equipment_id" => $equipmentId,
        ]);
        // MySQL pode retornar rowCount() = 0 quando o heartbeat repete
        // exatamente os mesmos valores. A requisição ainda foi aceita e deve
        // ser contabilizada como recebida.
        $received++;
    }
    $deliveredQueueId = filter_var($payload["delivered_queue_id"] ?? null, FILTER_VALIDATE_INT);
    $deliveredEvents = 0;
    if ($deliveredQueueId !== false && $deliveredQueueId !== null && (int) $deliveredQueueId > 0) {
        $acknowledgedThrough = min((int) $deliveredQueueId, $snapshotQueueCursor($pdo, $companyId));
        if ($acknowledgedThrough > 0) {
            $acknowledgeInstallation = $pdo->prepare(
                "UPDATE instalacoes_industriais
                 SET last_delivered_queue_id = GREATEST(last_delivered_queue_id, :queue_id)
                 WHERE id = :installation_id AND company_id = :company_id
                   AND equipment_id = :equipment_id AND sync_token_hash IS NOT NULL",
            );
            $acknowledgeInstallation->execute([
                "queue_id" => $acknowledgedThrough,
                "installation_id" => $installationId,
                "company_id" => $companyId,
                "equipment_id" => $equipmentId,
            ]);
            $markDelivered = $pdo->prepare(
                "UPDATE fila_sincronizacao q
                 JOIN (
                   SELECT company_id, MIN(last_delivered_queue_id) AS acknowledged_through
                   FROM instalacoes_industriais
                   WHERE company_id = :installation_company_id
                     AND sync_token_hash IS NOT NULL AND equipment_id IS NOT NULL
                   GROUP BY company_id
                 ) acknowledged ON acknowledged.company_id = q.company_id
                   AND q.id <= acknowledged.acknowledged_through
                 SET q.status = 'ENVIADO', q.last_error = NULL, q.processing_started_at = NULL
                 WHERE q.company_id = :queue_company_id
                   AND q.status IN ('PENDENTE', 'ERRO')",
            );
            $markDelivered->execute([
                "installation_company_id" => $companyId,
                "queue_company_id" => $companyId,
            ]);
            $deliveredEvents = $markDelivered->rowCount();
        }
    }
    responder_json(["data" => [
        "received" => $received,
        "industrial_pc_received" => $industrialPcReceived,
        "delivered_events" => $deliveredEvents,
    ]]);
}

$syncCursorValue = $snapshotQueueCursor($pdo, $companyId);

$equipmentStatement = $pdo->prepare(
    "SELECT id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol
     FROM equipamentos WHERE company_id = :company_id AND id = :equipment_id ORDER BY id",
);
$equipmentStatement->execute(["company_id" => $companyId, "equipment_id" => $equipmentId]);
$equipamentos = $equipmentStatement->fetchAll();

$loadingStatement = $pdo->prepare(
    "SELECT c.id, c.remote_carregamento_id, c.equipment_id, c.romaneio_id, c.truck_id,
            c.state, c.started_at, r.status AS romaneio_status, r.number AS romaneio_number, r.expedidor,
            r.scheduled_date, t.plate, t.driver_name, e.equipment_code,
            e.name AS equipment_name
     FROM carregamentos c
     JOIN romaneios r ON r.id = c.romaneio_id
     JOIN romaneio_caminhoes t ON t.id = c.truck_id
     LEFT JOIN equipamentos e ON e.id = c.equipment_id
     WHERE c.company_id = :company_id AND c.equipment_id = :equipment_id
       AND c.state <> 'FINALIZADO'
       AND r.status NOT IN ('FINALIZADO', 'CANCELADO')
     ORDER BY c.id",
);
$loadingStatement->execute(["company_id" => $companyId, "equipment_id" => $equipmentId]);
$carregamentos = $loadingStatement->fetchAll();
$cancelledManifestsStatement = $pdo->prepare(
    "SELECT id, number, scheduled_date, status, expedidor
     FROM romaneios
     WHERE company_id = :company_id AND status = 'CANCELADO'
     ORDER BY id",
);
$cancelledManifestsStatement->execute(["company_id" => $companyId]);
$cancelledManifests = $cancelledManifestsStatement->fetchAll();
$availableManifestsStatement = $pdo->prepare(
    "SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor,
            t.id AS truck_id, t.plate, t.driver_name
     FROM romaneios r
     JOIN romaneio_caminhoes t ON t.romaneio_id = r.id
     WHERE r.company_id = :company_id
       AND r.assigned_installation_id = :installation_id
       AND r.status IN ('IMPORTADO', 'AGUARDANDO')
       AND NOT EXISTS (
         SELECT 1 FROM carregamentos c
         WHERE c.company_id = r.company_id AND c.romaneio_id = r.id
       )
     ORDER BY r.id, t.id",
);
$availableManifestsStatement->execute([
    "company_id" => $companyId,
    "installation_id" => $installationId,
]);
$manifestsById = [];
$availableManifestTruckIds = [];
foreach ($availableManifestsStatement->fetchAll() as $row) {
    $manifestId = (int) $row["id"];
    $truckId = (int) $row["truck_id"];
    if (!isset($manifestsById[$manifestId])) {
        $manifestsById[$manifestId] = [
            "id" => $manifestId,
            "number" => $row["number"],
            "scheduled_date" => $row["scheduled_date"],
            "status" => $row["status"],
            "expedidor" => $row["expedidor"],
            "trucks" => [],
        ];
    }
    $manifestsById[$manifestId]["trucks"][] = [
        "id" => $truckId,
        "plate" => $row["plate"],
        "driver_name" => $row["driver_name"],
        "items" => [],
    ];
    $availableManifestTruckIds[$truckId] = ["manifest_id" => $manifestId, "truck_index" => count($manifestsById[$manifestId]["trucks"]) - 1];
}
$availableTruckIds = array_keys($availableManifestTruckIds);
if ($availableTruckIds !== []) {
    $placeholders = implode(",", array_fill(0, count($availableTruckIds), "?"));
    $availableItems = $pdo->prepare(
        "SELECT ri.romaneio_id, ri.truck_id, p.code, p.name, ri.planned_quantity
         FROM romaneio_itens ri
         JOIN produtos p ON p.id = ri.product_id
         WHERE ri.truck_id IN ({$placeholders})
            OR (ri.truck_id IS NULL AND ri.romaneio_id IN (
                SELECT t.romaneio_id FROM romaneio_caminhoes t WHERE t.id IN ({$placeholders})
            ))
         ORDER BY ri.romaneio_id, ri.truck_id, ri.id",
    );
    $availableItems->execute([...$availableTruckIds, ...$availableTruckIds]);
    foreach ($availableItems->fetchAll() as $item) {
        $manifestId = (int) $item["romaneio_id"];
        $truckId = $item["truck_id"] === null ? null : (int) $item["truck_id"];
        $targetTrucks = $truckId === null
            ? array_filter($availableManifestTruckIds, static fn (array $truckRef): bool => $truckRef["manifest_id"] === $manifestId)
            : (isset($availableManifestTruckIds[$truckId]) ? [$truckId => $availableManifestTruckIds[$truckId]] : []);
        foreach ($targetTrucks as $truckRef) {
            $manifestsById[$truckRef["manifest_id"]]["trucks"][$truckRef["truck_index"]]["items"][] = [
                "code" => $item["code"],
                "name" => $item["name"],
                "planned_quantity" => (int) $item["planned_quantity"],
            ];
        }
    }
}
$availableManifests = array_values($manifestsById);
$loadingIds = array_map(static fn (array $row): int => (int) $row["id"], $carregamentos);
foreach ($carregamentos as &$loading) {
    $loading["id"] = (int) $loading["id"];
    $loading["equipment_id"] = $loading["equipment_id"] === null ? null : (int) $loading["equipment_id"];
    $loading["romaneio_id"] = (int) $loading["romaneio_id"];
    $loading["truck_id"] = (int) $loading["truck_id"];
    $loading["items"] = [];
}
unset($loading);

if ($loadingIds !== []) {
    $placeholders = implode(",", array_fill(0, count($loadingIds), "?"));
    $items = $pdo->prepare(
        "SELECT c.id AS loading_id, p.code, p.name, SUM(ri.planned_quantity) AS planned_quantity
         FROM carregamentos c
         JOIN romaneio_itens ri ON ri.romaneio_id = c.romaneio_id
           AND (ri.truck_id = c.truck_id OR ri.truck_id IS NULL)
         JOIN produtos p ON p.id = ri.product_id
         WHERE c.id IN ({$placeholders})
         GROUP BY c.id, p.code, p.name
         ORDER BY c.id, p.code",
    );
    $items->execute($loadingIds);
    $itemsByLoading = [];
    foreach ($items->fetchAll() as $item) {
        $itemsByLoading[(int) $item["loading_id"]][] = [
            "code" => $item["code"],
            "name" => $item["name"],
            "planned_quantity" => (int) $item["planned_quantity"],
        ];
    }
    foreach ($carregamentos as &$loading) {
        $loading["items"] = $itemsByLoading[(int) $loading["id"]] ?? [];
    }
    unset($loading);
}

$productStatement = $pdo->prepare(
    "SELECT p.id, p.code, p.name, p.category, p.active, cp.barcode
     FROM produtos p
     LEFT JOIN codigos_produtos cp
       ON cp.product_id = p.id AND cp.company_id = p.company_id
     WHERE p.company_id = :company_id
     ORDER BY p.id, cp.id",
);
$productStatement->execute(["company_id" => $companyId]);
$productsById = [];
foreach ($productStatement->fetchAll() as $productRow) {
    $productId = (int) $productRow["id"];
    if (!isset($productsById[$productId])) {
        $productsById[$productId] = [
            "id" => $productId,
            "code" => $productRow["code"],
            "name" => $productRow["name"],
            "category" => $productRow["category"],
            "active" => (int) $productRow["active"],
            "barcodes" => [],
        ];
    }
    $barcode = trim((string) ($productRow["barcode"] ?? ""));
    if ($barcode !== "") {
        $productsById[$productId]["barcodes"][] = $barcode;
    }
}
$produtos = array_values($productsById);

$commandStatement = $pdo->prepare(
    "SELECT r.id, r.remote_command_id, r.equipment_id, r.carregamento_id, r.command,
            r.status, r.requested_at, r.claimed_at, r.completed_at, r.response_message,
            c.remote_carregamento_id, e.equipment_code
     FROM solicitacoes_comandos_clp r
     JOIN carregamentos c ON c.id = r.carregamento_id
     JOIN equipamentos e ON e.id = r.equipment_id
     WHERE r.company_id = :company_id AND r.equipment_id = :equipment_id
       AND r.status IN ('PENDENTE','PROCESSANDO')
     ORDER BY r.id",
);
$commandStatement->execute(["company_id" => $companyId, "equipment_id" => $equipmentId]);

responder_json([
    "data" => [
        "empresa" => [
            "id" => $companyId,
            "name" => $company["name"],
            "login_domain" => $company["login_domain"],
            "license_status" => $company["license_status"],
            "license_reason" => $company["license_reason"],
        ],
        "instalacao" => [
            "id" => $installationId,
            "name" => $company["installation_name"],
            "equipment_id" => $equipmentId,
        ],
        "equipamentos" => $equipamentos,
        "produtos" => $produtos,
        "romaneios_disponiveis" => $availableManifests,
        "romaneios_cancelados" => $cancelledManifests,
        "carregamentos_ativos" => $carregamentos,
        "comandos" => $commandStatement->fetchAll(),
        "sync_cursor" => $syncCursorValue,
        "sent_at" => date("c"),
    ],
]);
