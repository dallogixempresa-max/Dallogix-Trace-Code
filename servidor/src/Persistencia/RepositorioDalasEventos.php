<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use PDOException;
use RuntimeException;
use App\Aplicacao\ValidadorCodigoDala;

require_once __DIR__ . "/../Aplicacao/ValidadorCodigoDala.php";

final class RepositorioDalasEventos
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Cria ou atualiza a única Dala vinculada à instalação que enviou o evento.
     * O chamador atualiza seu contexto em memória e inicializa as ações padrão.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,code:string}
     */
    public function salvarDalaVinculada(
        int $companyId,
        int $installationId,
        ?int $installationEquipmentId,
        string $assignedEquipmentCode,
        array $data,
    ): array {
        $code = trim((string) ($data["equipment_code"] ?? ""));
        $name = trim((string) ($data["name"] ?? ""));
        $plcIp = trim((string) ($data["plc_ip"] ?? ""));
        $plcPort = filter_var($data["plc_port"] ?? null, FILTER_VALIDATE_INT);
        $externalPort = ($data["external_port"] ?? null) === null || ($data["external_port"] ?? "") === ""
            ? null
            : filter_var($data["external_port"], FILTER_VALIDATE_INT);
        $protocol = strtoupper(trim((string) ($data["plc_protocol"] ?? "MODBUS_TCP")));
        $previousCode = trim((string) ($data["previous_equipment_code"] ?? ""));

        if (
            !ValidadorCodigoDala::ehValido($code)
            || $name === ""
            || $plcIp === ""
            || $plcPort === false
            || (int) $plcPort < 1
            || (int) $plcPort > 65535
            || ($externalPort !== null && ($externalPort === false || (int) $externalPort < 1 || (int) $externalPort > 65535))
            || !in_array($protocol, ["MODBUS_TCP", "MODBUS_RTU"], true)
        ) {
            throw new RuntimeException("Dados da Dala recebidos pela sincronização são inválidos.");
        }

        $parameters = [
            "equipment_code" => $code,
            "name" => $name,
            "plc_ip" => $plcIp,
            "plc_port" => (int) $plcPort,
            "external_port" => $externalPort === null ? null : (int) $externalPort,
            "plc_protocol" => $protocol,
            "company_id" => $companyId,
        ];

        if ($installationEquipmentId !== null) {
            if ($code !== $assignedEquipmentCode && $previousCode !== $assignedEquipmentCode) {
                throw new RuntimeException("O PC industrial tentou atualizar uma Dala que não está vinculada a ele.");
            }
            $equipmentId = $installationEquipmentId;
            $exists = $this->connection->prepare(
                "SELECT id FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
            );
            $exists->execute(["id" => $equipmentId, "company_id" => $companyId]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException("A Dala vinculada ao PC não existe no servidor central.");
            }
            $this->connection->prepare(
                "UPDATE equipamentos SET equipment_code = :equipment_code, name = :name, plc_ip = :plc_ip,
                 plc_port = :plc_port, external_port = :external_port, plc_protocol = :plc_protocol
                 WHERE id = :id AND company_id = :company_id",
            )->execute($parameters + ["id" => $equipmentId]);

            return ["id" => $equipmentId, "code" => $code];
        }

        $lockClause = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql" ? " FOR UPDATE" : "";
        $existing = $this->connection->prepare(
            "SELECT id FROM equipamentos
             WHERE company_id = :company_id AND equipment_code = :equipment_code
             LIMIT 1" . $lockClause,
        );
        $existing->execute(["company_id" => $companyId, "equipment_code" => $code]);
        $equipmentId = (int) ($existing->fetchColumn() ?: 0);
        if ($equipmentId > 0) {
            $assignedElsewhere = $this->connection->prepare(
                "SELECT id FROM instalacoes_industriais
                 WHERE equipment_id = :equipment_id AND id <> :installation_id LIMIT 1",
            );
            $assignedElsewhere->execute([
                "equipment_id" => $equipmentId,
                "installation_id" => $installationId,
            ]);
            if ($assignedElsewhere->fetchColumn()) {
                throw new RuntimeException("A Dala recebida já está vinculada a outro PC industrial.");
            }
            $this->connection->prepare(
                "UPDATE equipamentos SET name = :name, plc_ip = :plc_ip, plc_port = :plc_port,
                 external_port = :external_port, plc_protocol = :plc_protocol
                 WHERE id = :id AND company_id = :company_id",
            )->execute([
                "name" => $name,
                "plc_ip" => $plcIp,
                "plc_port" => (int) $plcPort,
                "external_port" => $externalPort === null ? null : (int) $externalPort,
                "plc_protocol" => $protocol,
                "id" => $equipmentId,
                "company_id" => $companyId,
            ]);
        } else {
            $insert = $this->connection->prepare(
                "INSERT INTO equipamentos
                 (company_id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol)
                 VALUES (:company_id, :equipment_code, :name, :plc_ip, :plc_port, :external_port, :plc_protocol)",
            );
            $insert->execute($parameters);
            $equipmentId = (int) $this->connection->lastInsertId();
        }

        $link = $this->connection->prepare(
            "UPDATE instalacoes_industriais SET equipment_id = :equipment_id
             WHERE id = :installation_id AND company_id = :company_id
               AND equipment_id IS NULL AND sync_token_hash IS NOT NULL",
        );
        $link->execute([
            "equipment_id" => $equipmentId,
            "installation_id" => $installationId,
            "company_id" => $companyId,
        ]);
        if ($link->rowCount() !== 1) {
            throw new RuntimeException("Não foi possível vincular a primeira Dala a este PC industrial.");
        }

        return ["id" => $equipmentId, "code" => $code];
    }

    /** @param array<string,mixed> $data */
    public function obterIdDalaVinculada(
        int $companyId,
        ?int $installationEquipmentId,
        string $assignedEquipmentCode,
        array $data,
    ): int {
        if ($installationEquipmentId === null) {
            throw new RuntimeException("O PC industrial ainda não possui uma Dala vinculada.");
        }
        $code = trim((string) ($data["equipment_code"] ?? ""));
        $previousCode = trim((string) ($data["previous_equipment_code"] ?? ""));
        if ($code !== "" && $code !== $assignedEquipmentCode && $previousCode !== $assignedEquipmentCode) {
            throw new RuntimeException("O PC industrial tentou atualizar uma operação de outra Dala.");
        }
        $statement = $this->connection->prepare(
            "SELECT id FROM equipamentos
             WHERE id = :equipment_id AND company_id = :company_id LIMIT 1",
        );
        $statement->execute(["equipment_id" => $installationEquipmentId, "company_id" => $companyId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("A Dala vinculada ao PC não existe no servidor central.");
        }
        return (int) $id;
    }

    /**
     * Desvincula os registros operacionais e remove a Dala da instalação.
     * A transação pertence ao consumidor do lote de eventos.
     */
    public function excluirDalaVinculada(int $companyId, int $installationId, int $equipmentId): void
    {
        $activeLoadings = $this->connection->prepare(
            "UPDATE carregamentos
             SET equipment_id = NULL, state = 'AGUARDANDO', started_at = NULL
             WHERE company_id = :company_id AND equipment_id = :equipment_id
               AND state <> 'FINALIZADO'",
        );
        $activeLoadings->execute([
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
        ]);

        $this->connection->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET equipment_id = NULL,
                 status = CASE WHEN status IN ('PENDENTE', 'PROCESSANDO') THEN 'ERRO' ELSE status END,
                 completed_at = COALESCE(completed_at, NOW(3)),
                 response_message = COALESCE(response_message, 'Dala excluída antes da conclusão do comando.')
             WHERE company_id = :company_id AND equipment_id = :equipment_id",
        )->execute(["company_id" => $companyId, "equipment_id" => $equipmentId]);

        $this->connection->prepare(
            "UPDATE solicitacoes_imagens_evidencia
             SET equipment_id = NULL,
                 status = CASE WHEN status IN ('PENDENTE', 'CAPTURANDO') THEN 'DESCARTADA' ELSE status END,
                 error_message = COALESCE(error_message, 'Dala excluída antes da captura.')
             WHERE equipment_id = :equipment_id",
        )->execute(["equipment_id" => $equipmentId]);

        $this->connection->prepare("UPDATE eventos_sensor SET equipment_id = NULL WHERE equipment_id = :equipment_id")
            ->execute(["equipment_id" => $equipmentId]);
        $this->connection->prepare("UPDATE imagens SET equipment_id = NULL WHERE equipment_id = :equipment_id")
            ->execute(["equipment_id" => $equipmentId]);
        $this->connection->prepare("DELETE FROM dispositivos WHERE company_id = :company_id AND equipment_id = :equipment_id")
            ->execute(["company_id" => $companyId, "equipment_id" => $equipmentId]);
        $this->connection->prepare("DELETE FROM gatilhos_dala WHERE equipment_id = :id")
            ->execute(["id" => $equipmentId]);
        $this->connection->prepare("DELETE FROM acoes_dala WHERE equipment_id = :id")
            ->execute(["id" => $equipmentId]);
        $this->connection->prepare("DELETE FROM status_dispositivos WHERE equipment_id = :id")
            ->execute(["id" => $equipmentId]);

        try {
            $this->connection->prepare(
                "DELETE FROM equipamentos WHERE id = :id AND company_id = :company_id",
            )->execute(["id" => $equipmentId, "company_id" => $companyId]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1451) {
                throw $exception;
            }
        }

        $this->connection->prepare(
            "UPDATE instalacoes_industriais SET equipment_id = NULL
             WHERE id = :installation_id AND company_id = :company_id AND equipment_id = :equipment_id",
        )->execute([
            "installation_id" => $installationId,
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
        ]);
    }
}
