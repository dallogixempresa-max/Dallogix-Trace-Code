<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

/** Persiste comandos recebidos do Central sem substituir o resultado do gateway local. */
final class RepositorioComandosRemotos
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function localizarIdAdministradorLocal(int $companyId): ?int
    {
        $statement = $this->connection->prepare(
            "SELECT id FROM usuarios
             WHERE company_id = :company_id AND role = 'ADMIN_EMPRESA' AND active = 1
             ORDER BY id LIMIT 1",
        );
        $statement->execute(["company_id" => $companyId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $remote */
    public function salvarOuAtualizar(
        int $companyId,
        int $equipmentId,
        int $loadingId,
        int $adminId,
        array $remote,
    ): void {
        $remoteId = (int) ($remote["id"] ?? 0);
        if ($remoteId < 1) {
            throw new RuntimeException("Comando remoto sem identificador válido.");
        }

        // Depois da importação, reserva e ACK pertencem ao gateway local.
        // Um snapshot atrasado nunca deve reabrir o comando ou apagar seu resultado.
        $find = $this->connection->prepare(
            "SELECT id FROM solicitacoes_comandos_clp
             WHERE company_id = :company_id AND remote_command_id = :remote_id LIMIT 1",
        );
        $find->execute(["company_id" => $companyId, "remote_id" => $remoteId]);
        if ($find->fetchColumn() !== false) {
            return;
        }

        $command = trim((string) ($remote["command"] ?? ""));
        if ($command === "") {
            throw new RuntimeException("Comando remoto sem ação explícita.");
        }

        $insert = $this->connection->prepare(
            "INSERT INTO solicitacoes_comandos_clp
             (company_id, remote_command_id, equipment_id, carregamento_id, command, status,
              requested_by, requested_at, response_message, completed_at)
             VALUES (:company_id, :remote_id, :equipment_id, :loading_id, :command, :status,
                     :requested_by, :requested_at, :message, :completed_at)",
        );
        $insert->execute([
            "company_id" => $companyId,
            "remote_id" => $remoteId,
            "equipment_id" => $equipmentId,
            "loading_id" => $loadingId,
            "command" => $command,
            // PROCESSANDO remoto não representa uma reserva no dispositivo local.
            "status" => "PENDENTE",
            "requested_by" => $adminId,
            "requested_at" => $remote["requested_at"] ?? date("Y-m-d H:i:s.v"),
            "message" => null,
            "completed_at" => null,
        ]);
    }
}
