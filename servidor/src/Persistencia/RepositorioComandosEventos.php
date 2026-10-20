<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Persiste no Central o resultado do comando executado pela instalação. */
final class RepositorioComandosEventos
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function registrarRetorno(
        int $companyId,
        int $equipmentId,
        int $commandId,
        string $status,
        mixed $message,
    ): void {
        $this->connection->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET status = :status, completed_at = NOW(3), response_message = :message
             WHERE id = :id AND company_id = :company_id AND equipment_id = :equipment_id",
        )->execute([
            "status" => $status,
            "message" => $message,
            "id" => $commandId,
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
        ]);
    }
}
