<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

final class RepositorioCarregamentosEventos
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function localizarPorIdRemoto(int $companyId, int $remoteId, int $equipmentId): ?int
    {
        $statement = $this->connection->prepare(
            "SELECT id FROM carregamentos WHERE company_id = :company_id
             AND id = :remote_id AND equipment_id = :equipment_id LIMIT 1",
        );
        $statement->execute([
            "company_id" => $companyId,
            "remote_id" => $remoteId,
            "equipment_id" => $equipmentId,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function localizarPorOrigem(int $companyId, int $installationId, int $sourceId): ?int
    {
        $statement = $this->connection->prepare(
            "SELECT id FROM carregamentos WHERE company_id = :company_id
             AND remote_installation_id = :installation_id
             AND remote_carregamento_id = :source_id LIMIT 1",
        );
        $statement->execute([
            "company_id" => $companyId,
            "installation_id" => $installationId,
            "source_id" => $sourceId,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function localizar(
        int $companyId,
        int $installationId,
        ?int $equipmentId,
        int $candidateId,
        int $sourceId,
    ): ?int {
        if (($candidateId <= 0 && $sourceId <= 0) || $equipmentId === null) {
            return null;
        }
        $statement = $this->connection->prepare(
            "SELECT id FROM carregamentos WHERE company_id = :company_id
             AND ((:candidate_a > 0 AND id = :candidate_b AND equipment_id = :equipment_id)
               OR (:source_a > 0 AND remote_installation_id = :installation_id
                   AND remote_carregamento_id = :source_b)) LIMIT 1",
        );
        $statement->execute([
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
            "installation_id" => $installationId,
            "candidate_a" => $candidateId,
            "candidate_b" => $candidateId,
            "source_a" => $sourceId,
            "source_b" => $sourceId,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Insere um carregamento novo ou atualiza o registro associado à origem.
     * A transação pertence ao chamador.
     *
     * @param array{company_id:int,installation_id:int,source_id:int,equipment_id:int,manifest_id:int,truck_id:int,state:string,started_at:string} $data
     */
    public function salvar(array $data, ?int $loadingId): int
    {
        $values = [
            "remote_id" => $data["source_id"],
            "installation_id" => $data["installation_id"],
            "equipment_id" => $data["equipment_id"],
            "romaneio_id" => $data["manifest_id"],
            "truck_id" => $data["truck_id"],
            "state" => $data["state"],
            "started_at" => $data["started_at"],
            "company_id" => $data["company_id"],
        ];
        if ($loadingId !== null && $loadingId > 0) {
            $this->connection->prepare(
                "UPDATE carregamentos SET remote_installation_id = :installation_id,
                 remote_carregamento_id = :remote_id, equipment_id = :equipment_id,
                 romaneio_id = :romaneio_id, truck_id = :truck_id, state = :state,
                 started_at = :started_at
                 WHERE id = :id AND company_id = :company_id",
            )->execute([...$values, "id" => $loadingId]);
            return $loadingId;
        }

        $this->connection->prepare(
            "INSERT INTO carregamentos
             (company_id, remote_installation_id, remote_carregamento_id, equipment_id,
              romaneio_id, truck_id, state, started_at)
             VALUES (:company_id, :installation_id, :remote_id, :equipment_id,
                     :romaneio_id, :truck_id, :state, :started_at)",
        )->execute($values);

        return (int) $this->connection->lastInsertId();
    }

    public function desvincularDala(int $companyId, int $loadingId): void
    {
        $this->connection->prepare(
            "UPDATE carregamentos
             SET equipment_id = NULL, state = 'AGUARDANDO', started_at = NULL
             WHERE id = :id AND company_id = :company_id",
        )->execute(["id" => $loadingId, "company_id" => $companyId]);
    }

    public function vincularDala(int $companyId, int $loadingId, int $equipmentId): void
    {
        $this->connection->prepare(
            "UPDATE carregamentos
             SET equipment_id = :equipment_id, state = 'AGUARDANDO', started_at = NULL
             WHERE id = :id AND company_id = :company_id",
        )->execute([
            "equipment_id" => $equipmentId,
            "id" => $loadingId,
            "company_id" => $companyId,
        ]);
    }

    public function atualizarEstado(int $companyId, int $loadingId, string $state): void
    {
        $this->connection->prepare(
            "UPDATE carregamentos SET state = :state WHERE id = :id AND company_id = :company_id",
        )->execute(["state" => $state, "id" => $loadingId, "company_id" => $companyId]);
    }

    public function finalizar(int $companyId, int $loadingId): void
    {
        $this->connection->prepare(
            "UPDATE carregamentos SET state = 'FINALIZADO', finished_at = COALESCE(finished_at, NOW())
             WHERE id = :id AND company_id = :company_id",
        )->execute(["id" => $loadingId, "company_id" => $companyId]);
    }

    public function finalizarPorRomaneio(int $companyId, int $manifestId): void
    {
        $this->connection->prepare(
            "UPDATE carregamentos
             SET state = 'FINALIZADO', finished_at = COALESCE(finished_at, NOW()),
                 finish_justification = COALESCE(finish_justification, 'ROMANEIO CANCELADO POR SINCRONIZAÇÃO')
             WHERE company_id = :company_id AND romaneio_id = :romaneio_id
               AND state <> 'FINALIZADO'",
        )->execute(["company_id" => $companyId, "romaneio_id" => $manifestId]);
    }
}
