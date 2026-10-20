<?php

declare(strict_types=1);

namespace App\Persistencia;

use DateTime;
use PDO;
use RuntimeException;

final class RepositorioRomaneiosEventos
{
    public function __construct(
        private readonly PDO $connection,
        private readonly int $installationId,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,remote_id:int}
     */
    public function salvarRomaneio(int $companyId, int $sourceManifestId, array $data): array
    {
        $knownRemoteId = filter_var($data["remote_romaneio_id"] ?? null, FILTER_VALIDATE_INT);
        $knownRemoteId = $knownRemoteId !== false && $knownRemoteId !== null && $knownRemoteId > 0
            ? (int) $knownRemoteId : null;
        $sourceId = filter_var($data["source_romaneio_id"] ?? $sourceManifestId, FILTER_VALIDATE_INT);
        $sourceId = $sourceId !== false && $sourceId !== null && $sourceId > 0 ? (int) $sourceId : 0;
        if ($sourceId < 1) {
            throw new RuntimeException("Identificador do romaneio recebido pela sincronização é inválido.");
        }

        $number = trim((string) ($data["number"] ?? ""));
        $scheduledDate = trim((string) ($data["scheduled_date"] ?? date("Y-m-d")));
        $status = strtoupper(trim((string) ($data["status"] ?? "AGUARDANDO")));
        $allowedStatuses = ["IMPORTADO", "AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];
        if ($number === "" || !in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException("Romaneio recebido pela sincronização é inválido.");
        }
        $date = DateTime::createFromFormat("Y-m-d", $scheduledDate);
        if (!$date || $date->format("Y-m-d") !== $scheduledDate) {
            throw new RuntimeException("Data do romaneio recebido pela sincronização é inválida.");
        }

        $manifestId = $this->localizarRomaneio($companyId, $sourceId, $knownRemoteId);
        if ($manifestId > 0) {
            $statusAtual = $this->obterStatusRomaneio($companyId, $manifestId);
            // Eventos atrasados da instalação não reabrem romaneios encerrados no Central.
            if (in_array($statusAtual, ["FINALIZADO", "CANCELADO"], true) && $status !== $statusAtual) {
                $status = $statusAtual;
            }
        }

        $values = [
            "remote_id" => $sourceId,
            "number" => $number,
            "scheduled_date" => $scheduledDate,
            "status" => $status,
            "expedidor" => trim((string) ($data["expedidor"] ?? "")) ?: null,
            "company_id" => $companyId,
            "installation_id" => $this->installationId,
        ];
        if ($manifestId > 0) {
            $this->connection->prepare(
                "UPDATE romaneios SET number = :number,
                 scheduled_date = :scheduled_date, status = :status, expedidor = :expedidor
                 WHERE id = :id AND company_id = :company_id",
            )->execute([
                "number" => $number,
                "scheduled_date" => $scheduledDate,
                "status" => $status,
                "expedidor" => $values["expedidor"],
                "id" => $manifestId,
                "company_id" => $companyId,
            ]);
        } else {
            $this->connection->prepare(
                "INSERT INTO romaneios
                 (company_id, remote_installation_id, remote_romaneio_id, number, scheduled_date, status, expedidor)
                 VALUES (:company_id, :installation_id, :remote_id, :number, :scheduled_date, :status, :expedidor)",
            )->execute($values);
            $manifestId = (int) $this->connection->lastInsertId();
        }

        return ["id" => $manifestId, "remote_id" => $sourceId];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,remote_id:?int}
     */
    public function salvarCaminhao(int $manifestId, array $data): array
    {
        $knownRemoteId = filter_var($data["remote_truck_id"] ?? null, FILTER_VALIDATE_INT);
        $knownRemoteId = $knownRemoteId !== false && $knownRemoteId !== null && $knownRemoteId > 0
            ? (int) $knownRemoteId : null;
        $sourceId = filter_var($data["source_truck_id"] ?? null, FILTER_VALIDATE_INT);
        $sourceId = $sourceId !== false && $sourceId !== null && $sourceId > 0 ? (int) $sourceId : null;
        $plate = strtoupper(trim((string) ($data["plate"] ?? "")));
        if (!\placa_caminhao_valida($plate)) {
            throw new RuntimeException("Caminhão recebido pela sincronização é inválido.");
        }

        $truckId = $this->localizarCaminhao($manifestId, $knownRemoteId, $sourceId, $plate);
        $values = [
            "remote_id" => $sourceId,
            "plate" => $plate,
            "driver_name" => trim((string) ($data["driver_name"] ?? "")) ?: null,
        ];
        if ($truckId > 0) {
            $this->connection->prepare(
                "UPDATE romaneio_caminhoes SET remote_truck_id = :remote_id,
                 plate = :plate, driver_name = :driver_name
                 WHERE id = :id AND romaneio_id = :romaneio_id",
            )->execute([...$values, "id" => $truckId, "romaneio_id" => $manifestId]);
        } else {
            $this->connection->prepare(
                "INSERT INTO romaneio_caminhoes
                 (romaneio_id, remote_truck_id, plate, driver_name)
                 VALUES (:romaneio_id, :remote_id, :plate, :driver_name)",
            )->execute([...$values, "romaneio_id" => $manifestId]);
            $truckId = (int) $this->connection->lastInsertId();
        }

        return ["id" => $truckId, "remote_id" => $sourceId];
    }

    /**
     * Atualiza o romaneio após um evento, sem reabrir estados finais e sem encerrar
     * o romaneio enquanto houver outro carregamento ativo associado a ele.
     */
    public function sincronizarStatusCarregamento(
        int $companyId,
        int $loadingId,
        string $state,
        ?bool $manifestFinalized = null,
    ): void {
        $loading = $this->connection->prepare(
            "SELECT romaneio_id FROM carregamentos
             WHERE id = :id AND company_id = :company_id LIMIT 1",
        );
        $loading->execute(["id" => $loadingId, "company_id" => $companyId]);
        $manifestId = (int) ($loading->fetchColumn() ?: 0);
        if ($manifestId < 1) {
            return;
        }

        $active = $this->connection->prepare(
            "SELECT COUNT(*) FROM carregamentos
             WHERE company_id = :company_id AND romaneio_id = :romaneio_id
               AND id <> :loading_id AND state <> 'FINALIZADO'",
        );
        $active->execute([
            "company_id" => $companyId,
            "romaneio_id" => $manifestId,
            "loading_id" => $loadingId,
        ]);
        $hasOtherActiveLoading = (int) $active->fetchColumn() > 0;

        $status = match (strtoupper(trim($state))) {
            "FINALIZADO" => ($manifestFinalized === true || !$hasOtherActiveLoading) ? "FINALIZADO" : "EM_ANDAMENTO",
            "AGUARDANDO" => $hasOtherActiveLoading ? "EM_ANDAMENTO" : "AGUARDANDO",
            default => "EM_ANDAMENTO",
        };
        $this->connection->prepare(
            "UPDATE romaneios SET status = :status
             WHERE id = :id AND company_id = :company_id
               AND status NOT IN ('FINALIZADO', 'CANCELADO')",
        )->execute([
            "status" => $status,
            "id" => $manifestId,
            "company_id" => $companyId,
        ]);
    }

    private function localizarRomaneio(int $companyId, int $sourceId, ?int $knownRemoteId): int
    {
        if ($knownRemoteId !== null) {
            $findById = $this->connection->prepare(
                "SELECT id FROM romaneios WHERE company_id = :company_id
                 AND id = :remote_id LIMIT 1",
            );
            $findById->execute(["company_id" => $companyId, "remote_id" => $knownRemoteId]);
            $manifestId = (int) ($findById->fetchColumn() ?: 0);
            if ($manifestId > 0) {
                return $manifestId;
            }
        }

        $findBySource = $this->connection->prepare(
            "SELECT id FROM romaneios WHERE company_id = :company_id
             AND remote_installation_id = :installation_id
             AND remote_romaneio_id = :source_id LIMIT 1",
        );
        $findBySource->execute([
            "company_id" => $companyId,
            "installation_id" => $this->installationId,
            "source_id" => $sourceId,
        ]);

        return (int) ($findBySource->fetchColumn() ?: 0);
    }

    private function obterStatusRomaneio(int $companyId, int $manifestId): string
    {
        $statement = $this->connection->prepare(
            "SELECT status FROM romaneios WHERE id = :id AND company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $manifestId, "company_id" => $companyId]);

        return strtoupper(trim((string) ($statement->fetchColumn() ?: "")));
    }

    private function localizarCaminhao(int $manifestId, ?int $knownRemoteId, ?int $sourceId, string $plate): int
    {
        if ($knownRemoteId !== null) {
            $findById = $this->connection->prepare(
                "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id
                 AND id = :remote_id LIMIT 1",
            );
            $findById->execute(["romaneio_id" => $manifestId, "remote_id" => $knownRemoteId]);
            $truckId = (int) ($findById->fetchColumn() ?: 0);
            if ($truckId > 0) {
                return $truckId;
            }
        }

        if ($sourceId !== null) {
            $findBySource = $this->connection->prepare(
                "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id
                 AND remote_truck_id = :source_id LIMIT 1",
            );
            $findBySource->execute(["romaneio_id" => $manifestId, "source_id" => $sourceId]);
            $truckId = (int) ($findBySource->fetchColumn() ?: 0);
            if ($truckId > 0) {
                return $truckId;
            }
        }

        $findByPlate = $this->connection->prepare(
            "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id
             AND plate = :plate LIMIT 1",
        );
        $findByPlate->execute(["romaneio_id" => $manifestId, "plate" => $plate]);

        return (int) ($findByPlate->fetchColumn() ?: 0);
    }
}
