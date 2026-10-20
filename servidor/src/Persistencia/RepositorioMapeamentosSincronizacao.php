<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use PDOStatement;
use RuntimeException;

final class RepositorioMapeamentosSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $response
     */
    public function aplicar(array $events, array $response): void
    {
        $mappings = $response["data"]["mappings"] ?? [];
        if (!is_array($mappings) || $mappings === []) {
            return;
        }

        $eventsByUuid = [];
        foreach ($events as $event) {
            $eventUuid = trim((string) ($event["event_uuid"] ?? ""));
            $eventCompanyId = (int) ($event["company_id"] ?? 0);
            if ($eventUuid !== "" && $eventCompanyId > 0) {
                $eventsByUuid[$eventUuid] = $eventCompanyId;
            }
        }

        $this->connection->beginTransaction();
        try {
            // Prepara cada SQL só quando necessário e o reutiliza no restante do lote.
            $updateEquipment = null;
            $updateManifest = null;
            $updateTruck = null;
            $updateLoading = null;

            foreach ($mappings as $mapping) {
                if (!is_array($mapping)) {
                    throw new RuntimeException("Mapeamento remoto inválido.");
                }

                $eventUuid = trim((string) ($mapping["event_uuid"] ?? ""));
                if ($eventUuid === "" || !isset($eventsByUuid[$eventUuid])) {
                    throw new RuntimeException("Mapeamento remoto não corresponde ao lote enviado.");
                }

                $local = is_array($mapping["local"] ?? null) ? $mapping["local"] : [];
                $remote = is_array($mapping["remote"] ?? null) ? $mapping["remote"] : [];
                $companyId = $eventsByUuid[$eventUuid];

                $updateEquipment = $this->atualizarIdentificador(
                    $updateEquipment,
                    "UPDATE equipamentos SET remote_equipment_id = :remote_id
                     WHERE id = :id AND company_id = :company_id",
                    (int) ($local["equipment_id"] ?? 0),
                    (int) ($remote["equipment_id"] ?? 0),
                    $companyId,
                );
                $updateManifest = $this->atualizarIdentificador(
                    $updateManifest,
                    "UPDATE romaneios SET remote_romaneio_id = :remote_id
                     WHERE id = :id AND company_id = :company_id",
                    (int) ($local["romaneio_id"] ?? 0),
                    (int) ($remote["romaneio_id"] ?? 0),
                    $companyId,
                );

                $localTruckId = (int) ($local["truck_id"] ?? 0);
                $remoteTruckId = (int) ($remote["truck_id"] ?? 0);
                $localManifestId = (int) ($local["romaneio_id"] ?? 0);
                if ($localTruckId > 0 && $remoteTruckId > 0) {
                    $updateTruck = $this->prepararStatement(
                        $updateTruck,
                        "UPDATE romaneio_caminhoes SET remote_truck_id = :remote_id
                         WHERE id = :id AND romaneio_id = :romaneio_id
                           AND EXISTS (SELECT 1 FROM romaneios r
                                       WHERE r.id = romaneio_caminhoes.romaneio_id AND r.company_id = :company_id)",
                    );
                    $updateTruck->execute([
                        "remote_id" => $remoteTruckId,
                        "id" => $localTruckId,
                        "romaneio_id" => $localManifestId,
                        "company_id" => $companyId,
                    ]);
                }

                $updateLoading = $this->atualizarIdentificador(
                    $updateLoading,
                    "UPDATE carregamentos SET remote_carregamento_id = :remote_id
                     WHERE id = :id AND company_id = :company_id",
                    (int) ($local["carregamento_id"] ?? 0),
                    (int) ($remote["carregamento_id"] ?? 0),
                    $companyId,
                );
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function atualizarIdentificador(
        ?PDOStatement $statement,
        string $sql,
        int $localId,
        int $remoteId,
        int $companyId,
    ): ?PDOStatement {
        if ($localId <= 0 || $remoteId <= 0) {
            return $statement;
        }

        $statement = $this->prepararStatement($statement, $sql);
        $statement->execute([
            "remote_id" => $remoteId,
            "id" => $localId,
            "company_id" => $companyId,
        ]);
        return $statement;
    }

    private function prepararStatement(?PDOStatement $statement, string $sql): PDOStatement
    {
        $prepared = $statement ?? $this->connection->prepare($sql);
        if (!$prepared instanceof PDOStatement) {
            throw new RuntimeException("Não foi possível preparar o mapeamento remoto.");
        }
        return $prepared;
    }
}
