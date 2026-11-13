<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Projeta dados locais atuais no contrato dos eventos enviados ao Central. */
final class RepositorioPayloadSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return mixed */
    public function prepararEvento(array $event): mixed
    {
        $payload = json_decode((string) $event["payload"], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            return $payload;
        }

        $action = trim((string) ($payload["action"] ?? ""));
        $data = is_array($payload["data"] ?? null) ? $payload["data"] : [];
        $companyId = (int) ($event["company_id"] ?? 0);
        $entityId = (int) ($event["aggregate_id"] ?? 0);
        if ($companyId <= 0 || $entityId <= 0) {
            return $payload;
        }

        if ($action === "LEITURA_REGISTRADA") {
            $sourceLoadingId = filter_var($data["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            if ($sourceLoadingId !== false && (int) $sourceLoadingId > 0) {
                $remoteLoadingId = $this->buscarCarregamentoRemoto($companyId, (int) $sourceLoadingId);
                if ($remoteLoadingId !== null) {
                    $data["remote_carregamento_id"] = $remoteLoadingId;
                }
            }
            $sensorEventUuid = $this->buscarUuidEventoSensorDaLeitura($companyId, $entityId);
            if ($sensorEventUuid !== null) {
                $data["sensor_event_uuid"] = $sensorEventUuid;
            }
            $payload["data"] = $data;
            return $payload;
        }

        if ($action === "SENSOR_EVENTO_RECEBIDO") {
            $sourceLoadingId = filter_var($data["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
            if ($sourceLoadingId !== false && (int) $sourceLoadingId > 0) {
                $remoteLoadingId = $this->buscarCarregamentoRemoto($companyId, (int) $sourceLoadingId);
                if ($remoteLoadingId !== null) {
                    $data["remote_carregamento_id"] = $remoteLoadingId;
                }
                $sensorEvent = $this->buscarEventoSensor($companyId, $entityId, (int) $sourceLoadingId);
                if ($sensorEvent !== null) {
                    $data = array_merge($data, $sensorEvent);
                }
            }
            $payload["data"] = $data;
            return $payload;
        }

        if (in_array($action, ["DALA_CADASTRADA", "DALA_ATUALIZADA"], true)) {
            // Eventos antigos podem não ter todos os campos; o retry usa o cadastro atual.
            $equipment = $this->buscarEquipamento($companyId, $entityId);
            if ($equipment !== null) {
                $payload["data"] = array_merge($data, $equipment);
            }
            return $payload;
        }

        if (in_array($action, ["PRODUTO_CADASTRADO", "PRODUTO_ATUALIZADO", "PRODUTO_EXCLUIDO"], true)) {
            $product = $this->buscarProduto($companyId, $entityId);
            if ($product !== null) {
                $payload["data"] = array_merge($data, $product);
            }
            return $payload;
        }

        if (in_array($action, ["ROMANEIO_CRIADO", "ROMANEIO_ATUALIZADO", "ROMANEIO_CANCELADO", "ROMANEIO_FINALIZADO"], true)) {
            $manifest = $this->buscarRomaneio($companyId, $entityId);
            if ($manifest !== null) {
                $payload["data"] = array_merge($data, $manifest);
            }
            return $payload;
        }

        if ($action === "RELATORIO_AUDITORIA_PUBLICADO") {
            $sourceManifestId = filter_var(
                $data["source_romaneio_id"] ?? $entityId,
                FILTER_VALIDATE_INT,
            );
            if ($sourceManifestId !== false && (int) $sourceManifestId > 0) {
                $statement = $this->connection->prepare(
                    "SELECT remote_romaneio_id FROM romaneios
                     WHERE id = :id AND company_id = :company_id LIMIT 1",
                );
                $statement->execute([
                    "id" => (int) $sourceManifestId,
                    "company_id" => $companyId,
                ]);
                $remoteManifestId = filter_var($statement->fetchColumn(), FILTER_VALIDATE_INT);
                if ($remoteManifestId !== false && (int) $remoteManifestId > 0) {
                    $data["remote_romaneio_id"] = (int) $remoteManifestId;
                    $payload["data"] = $data;
                }
            }
            return $payload;
        }

        if (in_array($action, [
            "CARREGAMENTO_PREPARADO",
            "CARREGAMENTO_DALA_VINCULADO",
            "CARREGAMENTO_DALA_DESVINCULADO",
            "ESTADO_CARREGAMENTO_ALTERADO",
            "CARREGAMENTO_FINALIZADO",
            "EXCESSO_DETECTADO",
            "PARADA_PRODUTO_INCORRETO",
            "REMOCAO_UNIDADE_E_PARADA_FISICA_CONFIRMADAS",
            "PARADA_FISICA_META_PRODUTO_CONFIRMADA",
            "COTA_PRODUTO_ATINGIDA",
            "QUANTIDADE_PLANEJADA_ATINGIDA",
            "PARADA_FISICA_META_ROMANEIO_CONFIRMADA",
        ], true)) {
            $sourceLoadingId = filter_var(
                $data["carregamento_id"] ?? $data["source_carregamento_id"] ?? null,
                FILTER_VALIDATE_INT,
            );
            if ($sourceLoadingId === false || (int) $sourceLoadingId <= 0) {
                $sourceLoadingId = $entityId;
            }
            $loading = $this->buscarCarregamento($companyId, (int) $sourceLoadingId);
            if ($loading !== null) {
                $payload["data"] = array_merge($data, $loading);
            }
        }

        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function buscarEquipamento(int $companyId, int $equipmentId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT equipment_code, name, plc_ip, plc_port, external_port, plc_protocol
             FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $equipmentId, "company_id" => $companyId]);
        $equipment = $statement->fetch();
        if (!$equipment) {
            return null;
        }

        return [
            "remote_equipment_id" => $equipmentId,
            "equipment_code" => $equipment["equipment_code"],
            "name" => $equipment["name"],
            "plc_ip" => $equipment["plc_ip"],
            "plc_port" => (int) $equipment["plc_port"],
            "external_port" => $equipment["external_port"] === null ? null : (int) $equipment["external_port"],
            "plc_protocol" => $equipment["plc_protocol"],
        ];
    }

    /** @return array<string,mixed>|null */
    private function buscarProduto(int $companyId, int $productId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT p.code, p.name, p.category, p.active,
                    COALESCE((SELECT cp.barcode FROM codigos_produtos cp
                              WHERE cp.product_id = p.id AND cp.company_id = p.company_id
                              ORDER BY cp.id LIMIT 1), '') AS barcode
             FROM produtos p WHERE p.id = :id AND p.company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $productId, "company_id" => $companyId]);
        $product = $statement->fetch();
        if (!$product) {
            return null;
        }

        return [
            "remote_product_id" => $productId,
            "code" => $product["code"],
            "name" => $product["name"],
            "category" => $product["category"],
            "active" => (int) $product["active"],
            "barcode" => $product["barcode"],
        ];
    }

    /** @return array<string,mixed>|null */
    private function buscarRomaneio(int $companyId, int $manifestId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT r.id, r.remote_romaneio_id, r.number, r.scheduled_date, r.status, r.expedidor,
                    t.id AS truck_id, t.remote_truck_id, t.plate, t.driver_name
             FROM romaneios r
             LEFT JOIN romaneio_caminhoes t ON t.romaneio_id = r.id
             WHERE r.id = :id AND r.company_id = :company_id
             ORDER BY t.id LIMIT 1",
        );
        $statement->execute(["id" => $manifestId, "company_id" => $companyId]);
        $manifest = $statement->fetch();
        if (!$manifest) {
            return null;
        }

        $itemsStatement = $this->connection->prepare(
            "SELECT ri.product_id, ri.planned_quantity, p.code, p.name, p.category,
                    COALESCE((SELECT cp.barcode FROM codigos_produtos cp
                              WHERE cp.product_id = p.id AND cp.company_id = p.company_id
                              ORDER BY cp.id LIMIT 1), '') AS barcode
             FROM romaneio_itens ri JOIN produtos p ON p.id = ri.product_id
             WHERE ri.romaneio_id = :romaneio_id ORDER BY ri.id",
        );
        $itemsStatement->execute(["romaneio_id" => $manifestId]);
        $items = [];
        foreach ($itemsStatement->fetchAll() as $item) {
            $items[] = [
                "remote_product_id" => (int) $item["product_id"],
                "code" => $item["code"],
                "name" => $item["name"],
                "category" => $item["category"],
                "barcode" => $item["barcode"],
                "planned_quantity" => (int) $item["planned_quantity"],
            ];
        }

        $truck = $manifest["truck_id"] === null ? null : [
            "source_truck_id" => (int) $manifest["truck_id"],
            "remote_truck_id" => $manifest["remote_truck_id"] === null
                ? null : (int) $manifest["remote_truck_id"],
            "plate" => $manifest["plate"],
            "driver_name" => $manifest["driver_name"],
        ];

        return [
            "source_romaneio_id" => (int) $manifest["id"],
            "remote_romaneio_id" => $manifest["remote_romaneio_id"] === null
                ? null : (int) $manifest["remote_romaneio_id"],
            "number" => $manifest["number"],
            "scheduled_date" => $manifest["scheduled_date"],
            "status" => $manifest["status"],
            "expedidor" => $manifest["expedidor"],
            "truck" => $truck,
            "items" => $items,
        ];
    }

    /** @return array<string,mixed>|null */
    private function buscarCarregamento(int $companyId, int $loadingId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT c.id, c.remote_carregamento_id, c.state, c.equipment_id, c.started_at,
                    c.romaneio_id, c.truck_id, e.remote_equipment_id, e.equipment_code,
                    r.remote_romaneio_id, r.number, r.scheduled_date, r.status AS romaneio_status, r.expedidor,
                    t.remote_truck_id, t.plate, t.driver_name
             FROM carregamentos c
             JOIN romaneios r ON r.id = c.romaneio_id
             JOIN romaneio_caminhoes t ON t.id = c.truck_id
             LEFT JOIN equipamentos e ON e.id = c.equipment_id
             WHERE c.id = :id AND c.company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $loadingId, "company_id" => $companyId]);
        $loading = $statement->fetch();
        if (!$loading) {
            return null;
        }

        return [
            "source_carregamento_id" => (int) $loading["id"],
            "remote_carregamento_id" => $loading["remote_carregamento_id"] === null
                ? null : (int) $loading["remote_carregamento_id"],
            "carregamento_id" => (int) $loading["id"],
            "romaneio_id" => (int) $loading["romaneio_id"],
            "truck_id" => (int) $loading["truck_id"],
            "equipment_id" => $loading["equipment_id"] === null ? null : (int) $loading["equipment_id"],
            "started_at" => $loading["started_at"] ?? null,
            "source_romaneio_id" => (int) $loading["romaneio_id"],
            "remote_romaneio_id" => $loading["remote_romaneio_id"] === null
                ? null : (int) $loading["remote_romaneio_id"],
            "source_truck_id" => (int) $loading["truck_id"],
            "remote_truck_id" => $loading["remote_truck_id"] === null
                ? null : (int) $loading["remote_truck_id"],
            "remote_equipment_id" => $loading["equipment_id"] === null
                ? null : ($loading["remote_equipment_id"] === null
                    ? (int) $loading["equipment_id"] : (int) $loading["remote_equipment_id"]),
            "equipment_code" => $loading["equipment_code"],
            "state" => $loading["state"],
            "romaneio" => [
                "source_romaneio_id" => (int) $loading["romaneio_id"],
                "remote_romaneio_id" => $loading["remote_romaneio_id"] === null
                    ? null : (int) $loading["remote_romaneio_id"],
                "number" => $loading["number"],
                "scheduled_date" => $loading["scheduled_date"],
                "status" => $loading["romaneio_status"],
                "expedidor" => $loading["expedidor"],
            ],
            "romaneio_status" => $loading["romaneio_status"],
            "truck" => [
                "source_truck_id" => (int) $loading["truck_id"],
                "remote_truck_id" => $loading["remote_truck_id"] === null
                    ? null : (int) $loading["remote_truck_id"],
                "plate" => $loading["plate"],
                "driver_name" => $loading["driver_name"],
            ],
        ];
    }

    private function buscarCarregamentoRemoto(int $companyId, int $loadingId): ?int
    {
        $statement = $this->connection->prepare(
            "SELECT remote_carregamento_id FROM carregamentos
             WHERE id = :id AND company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $loadingId, "company_id" => $companyId]);
        $remoteLoadingId = filter_var($statement->fetchColumn(), FILTER_VALIDATE_INT);

        return $remoteLoadingId !== false && (int) $remoteLoadingId > 0
            ? (int) $remoteLoadingId
            : null;
    }

    private function buscarUuidEventoSensorDaLeitura(int $companyId, int $readingId): ?string
    {
        if ($readingId <= 0) {
            return null;
        }
        $statement = $this->connection->prepare(
            "SELECT s.event_uuid
             FROM leituras l
             JOIN eventos_sensor s ON s.id = l.sensor_event_id
             WHERE l.id = :reading_id AND l.company_id = :company_id LIMIT 1",
        );
        $statement->execute(["reading_id" => $readingId, "company_id" => $companyId]);
        $eventUuid = $statement->fetchColumn();

        return $eventUuid === false ? null : (string) $eventUuid;
    }

    /** @return array{event_uuid:string,detected_at:string,device_detected_at:?string,event_at_epoch_ms:?int,conta_na_meta:bool,debounce_ms:int}|null */
    private function buscarEventoSensor(int $companyId, int $sensorEventId, int $loadingId): ?array
    {
        if ($sensorEventId <= 0) {
            return null;
        }
        $statement = $this->connection->prepare(
            "SELECT s.event_uuid, s.detected_at, s.device_detected_at,
                    s.event_at_epoch_ms, s.conta_na_meta_origem, s.debounce_ms
             FROM eventos_sensor s
             JOIN carregamentos c ON c.id = s.carregamento_id
             WHERE s.id = :sensor_event_id AND s.carregamento_id = :loading_id
               AND c.company_id = :company_id LIMIT 1",
        );
        $statement->execute([
            "sensor_event_id" => $sensorEventId,
            "loading_id" => $loadingId,
            "company_id" => $companyId,
        ]);
        $event = $statement->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            return null;
        }

        return [
            "event_uuid" => (string) $event["event_uuid"],
            "detected_at" => (string) $event["detected_at"],
            "device_detected_at" => $event["device_detected_at"] === null
                ? null : (string) $event["device_detected_at"],
            "event_at_epoch_ms" => $event["event_at_epoch_ms"] === null
                ? null : (int) $event["event_at_epoch_ms"],
            "conta_na_meta" => (bool) $event["conta_na_meta_origem"],
            "debounce_ms" => (int) $event["debounce_ms"],
        ];
    }
}
