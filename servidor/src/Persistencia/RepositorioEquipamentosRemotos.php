<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

require_once __DIR__ . "/RepositorioFilaSincronizacao.php";

/** Persiste equipamentos remotos sem descartar configuração local pendente. */
final class RepositorioEquipamentosRemotos
{
    public function __construct(
        private readonly PDO $connection,
        private readonly RepositorioFilaSincronizacao $fila,
    ) {
    }

    /** @param list<int> $remoteEquipmentIds */
    public function removerEquipamentosRemotosObsoletos(int $companyId, array $remoteEquipmentIds): int
    {
        $remoteEquipmentIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $remoteEquipmentIds),
            static fn (int $id): bool => $id > 0,
        )));
        $where = ["company_id = :company_id", "remote_equipment_id IS NOT NULL"];
        $params = ["company_id" => $companyId];
        if ($remoteEquipmentIds !== []) {
            $placeholders = [];
            foreach ($remoteEquipmentIds as $index => $remoteId) {
                $key = "remote_equipment_id_{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $remoteId;
            }
            $where[] = "remote_equipment_id NOT IN (" . implode(",", $placeholders) . ")";
        }
        $stale = $this->connection->prepare(
            "SELECT id FROM equipamentos WHERE " . implode(" AND ", $where) . " ORDER BY id",
        );
        $stale->execute($params);
        $removed = 0;
        foreach ($stale->fetchAll() as $row) {
            $equipmentId = (int) $row["id"];
            if ($this->fila->possuiEventoPendente($companyId, "equipment", $equipmentId)) {
                continue;
            }
            $dependencies = $this->connection->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM carregamentos WHERE equipment_id = :equipment_id_a) +
                    (SELECT COUNT(*) FROM eventos_sensor WHERE equipment_id = :equipment_id_b) +
                    (SELECT COUNT(*) FROM imagens WHERE equipment_id = :equipment_id_c) +
                    (SELECT COUNT(*) FROM solicitacoes_imagens_evidencia WHERE equipment_id = :equipment_id_d) +
                    (SELECT COUNT(*) FROM solicitacoes_comandos_clp WHERE equipment_id = :equipment_id_e) +
                    (SELECT COUNT(*) FROM dispositivos WHERE equipment_id = :equipment_id_f) AS total",
            );
            $dependencies->execute([
                "equipment_id_a" => $equipmentId,
                "equipment_id_b" => $equipmentId,
                "equipment_id_c" => $equipmentId,
                "equipment_id_d" => $equipmentId,
                "equipment_id_e" => $equipmentId,
                "equipment_id_f" => $equipmentId,
            ]);
            if ((int) $dependencies->fetchColumn() > 0) {
                continue;
            }
            $this->connection->prepare("DELETE FROM gatilhos_dala WHERE equipment_id = :id")->execute(["id" => $equipmentId]);
            $this->connection->prepare("DELETE FROM acoes_dala WHERE equipment_id = :id")->execute(["id" => $equipmentId]);
            $this->connection->prepare("DELETE FROM status_dispositivos WHERE equipment_id = :id")->execute(["id" => $equipmentId]);
            $this->connection->prepare("DELETE FROM equipamentos WHERE id = :id AND company_id = :company_id")->execute([
                "id" => $equipmentId,
                "company_id" => $companyId,
            ]);
            $removed++;
        }
        return $removed;
    }

    /** @param array<string,mixed> $remote */
    /** @return array{id:int,codigo:string,provisionar:bool} */
    public function sincronizarEquipamento(int $companyId, array $remote): array
    {
        $remoteId = (int) ($remote["id"] ?? 0);
        $code = trim((string) ($remote["equipment_code"] ?? ""));
        if ($remoteId <= 0 || $code === "") {
            throw new RuntimeException("Dala remota sem identificador válido.");
        }
        $find = $this->connection->prepare(
            "SELECT id FROM equipamentos WHERE company_id = :company_id
             AND remote_equipment_id = :remote_id LIMIT 1",
        );
        $find->execute(["company_id" => $companyId, "remote_id" => $remoteId]);
        $localId = (int) ($find->fetchColumn() ?: 0);
        if ($localId === 0) {
            $find = $this->connection->prepare(
                "SELECT id FROM equipamentos WHERE company_id = :company_id
                 AND equipment_code = :equipment_code LIMIT 1",
            );
            $find->execute(["company_id" => $companyId, "equipment_code" => $code]);
            $localId = (int) ($find->fetchColumn() ?: 0);
        }
        $data = [
            "remote_equipment_id" => $remoteId,
            "equipment_code" => $code,
            "name" => trim((string) ($remote["name"] ?? $code)),
            "plc_ip" => $remote["plc_ip"] ?? null,
            "plc_port" => (int) $remote["plc_port"],
            "external_port" => $remote["external_port"] ?? null,
            "plc_protocol" => in_array(($remote["plc_protocol"] ?? "MODBUS_TCP"), ["MODBUS_TCP", "MODBUS_RTU"], true)
                ? $remote["plc_protocol"] : "MODBUS_TCP",
        ];
        if ($localId) {
            if ($this->fila->possuiEventoPendente($companyId, "equipment", $localId)) {
                $this->connection->prepare(
                    "UPDATE equipamentos SET remote_equipment_id = :remote_id
                     WHERE id = :id AND company_id = :company_id",
                )->execute(["remote_id" => $remoteId, "id" => $localId, "company_id" => $companyId]);
                return ["id" => $localId, "codigo" => $code, "provisionar" => false];
            }
            $update = $this->connection->prepare(
                "UPDATE equipamentos SET remote_equipment_id = :remote_equipment_id,
                 equipment_code = :equipment_code, name = :name, plc_ip = :plc_ip,
                 plc_port = :plc_port, external_port = :external_port, plc_protocol = :plc_protocol
                 WHERE id = :id AND company_id = :company_id",
            );
            $update->execute([...$data, "id" => $localId, "company_id" => $companyId]);
            return ["id" => $localId, "codigo" => $code, "provisionar" => true];
        }
        $insert = $this->connection->prepare(
            "INSERT INTO equipamentos
             (company_id, remote_equipment_id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol)
             VALUES (:company_id, :remote_equipment_id, :equipment_code, :name, :plc_ip, :plc_port, :external_port, :plc_protocol)",
        );
        $insert->execute(["company_id" => $companyId, ...$data]);
        $localId = (int) $this->connection->lastInsertId();
        return ["id" => $localId, "codigo" => $code, "provisionar" => true];
    }

}
