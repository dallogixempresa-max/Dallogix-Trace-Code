<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

/** Persiste o snapshot de carregamentos sem sobrescrever eventos locais pendentes. */
final class RepositorioCarregamentosRemotos
{
    public function __construct(
        private readonly PDO $connection,
        private readonly RepositorioFilaSincronizacao $fila,
        private readonly RepositorioRomaneiosRemotos $romaneios,
    ) {
    }

    /** @param array<string,mixed> $remote */
    public function salvarOuAtualizar(int $companyId, ?int $equipmentId, array $remote): int
    {
        $remoteLoadingId = (int) ($remote["id"] ?? 0);
        $remoteManifestId = (int) ($remote["romaneio_id"] ?? 0);
        if ($remoteLoadingId < 1 || $remoteManifestId < 1) {
            throw new RuntimeException("Carregamento remoto sem identificador válido.");
        }

        $manifestId = $this->romaneios->salvarOuAtualizarRomaneio($companyId, $remoteManifestId, $remote);
        $truckId = $this->romaneios->salvarOuAtualizarCaminhao(
            $companyId,
            $manifestId,
            (int) ($remote["truck_id"] ?? 0),
            $remote,
        );
        $find = $this->connection->prepare(
            "SELECT id FROM carregamentos
             WHERE company_id = :company_id AND remote_carregamento_id = :remote_id LIMIT 1",
        );
        $find->execute(["company_id" => $companyId, "remote_id" => $remoteLoadingId]);
        $localId = (int) ($find->fetchColumn() ?: 0);
        $state = $this->normalizarEstado((string) ($remote["state"] ?? "PREPARANDO"));
        $hasPendingLoadingEvent = false;

        if ($localId > 0) {
            $hasPendingLoadingEvent = $this->fila->possuiEventoPendente($companyId, "carregamento", $localId);
            if (!$hasPendingLoadingEvent) {
                $update = $this->connection->prepare(
                    "UPDATE carregamentos
                     SET equipment_id = :equipment_id, romaneio_id = :romaneio_id,
                         truck_id = :truck_id, state = :state, started_at = :started_at
                     WHERE id = :id AND company_id = :company_id",
                );
                $update->execute([
                    "equipment_id" => $equipmentId,
                    "romaneio_id" => $manifestId,
                    "truck_id" => $truckId,
                    "state" => $state,
                    "started_at" => $remote["started_at"] ?? null,
                    "id" => $localId,
                    "company_id" => $companyId,
                ]);
            }
        } else {
            $insert = $this->connection->prepare(
                "INSERT INTO carregamentos
                 (company_id, remote_carregamento_id, equipment_id, romaneio_id, truck_id, state, started_at)
                 VALUES (:company_id, :remote_id, :equipment_id, :romaneio_id, :truck_id, :state, :started_at)",
            );
            $insert->execute([
                "company_id" => $companyId,
                "remote_id" => $remoteLoadingId,
                "equipment_id" => $equipmentId,
                "romaneio_id" => $manifestId,
                "truck_id" => $truckId,
                "state" => $state,
                "started_at" => $remote["started_at"] ?? null,
            ]);
            $localId = (int) $this->connection->lastInsertId();
        }

        $hasPendingManifestEvent = $this->fila->possuiEventoPendente($companyId, "romaneio", $manifestId);
        if (!$hasPendingLoadingEvent && !$hasPendingManifestEvent) {
            $this->romaneios->sincronizarItens($manifestId, $companyId, $truckId, $remote["items"] ?? []);
        }

        return $localId;
    }

    public function localizarId(int $companyId, int $remoteLoadingId): ?int
    {
        if ($remoteLoadingId < 1) {
            return null;
        }

        $statement = $this->connection->prepare(
            "SELECT id FROM carregamentos
             WHERE company_id = :company_id AND remote_carregamento_id = :remote_id LIMIT 1",
        );
        $statement->execute(["company_id" => $companyId, "remote_id" => $remoteLoadingId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param list<int> $remoteLoadingIds */
    public function finalizarAusentes(int $companyId, array $remoteLoadingIds): void
    {
        $remoteLoadingIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $remoteLoadingIds),
            static fn (int $id): bool => $id > 0,
        )));
        $notIn = "";
        $parameters = ["company_id" => $companyId];

        if ($remoteLoadingIds !== []) {
            $placeholders = [];
            foreach ($remoteLoadingIds as $index => $remoteId) {
                $parameter = "remote_id_{$index}";
                $placeholders[] = ":{$parameter}";
                $parameters[$parameter] = $remoteId;
            }
            $notIn = " AND remote_carregamento_id NOT IN (" . implode(",", $placeholders) . ")";
        }

        $timestamp = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
            ? "CURRENT_TIMESTAMP"
            : "NOW(3)";
        $statement = $this->connection->prepare(
            "UPDATE carregamentos
             SET state = 'FINALIZADO', finished_at = COALESCE(finished_at, {$timestamp})
             WHERE company_id = :company_id AND remote_carregamento_id IS NOT NULL
               AND state <> 'FINALIZADO'{$notIn}
               AND NOT EXISTS (
                   SELECT 1 FROM fila_sincronizacao q
                   WHERE q.company_id = carregamentos.company_id
                     AND q.aggregate_type = 'carregamento'
                     AND q.aggregate_id = carregamentos.id
                     AND q.status <> 'ENVIADO'
               )",
        );
        $statement->execute($parameters);
    }

    private function normalizarEstado(string $state): string
    {
        $state = strtoupper(trim($state));
        $allowedStates = ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA"];

        return in_array($state, $allowedStates, true) ? $state : "PREPARANDO";
    }
}
