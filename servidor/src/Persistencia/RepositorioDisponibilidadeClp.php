<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Lê o último heartbeat do CLP sem decidir se o comando deve ser permitido. */
final class RepositorioDisponibilidadeClp
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array{status:string|null,last_seen_at:string|null,segundos_sem_sinal:int|string|null}|null */
    public function obterEstado(int $companyId, int $equipmentId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT d.status, d.last_seen_at,
                    TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW()) AS segundos_sem_sinal
             FROM equipamentos e
             LEFT JOIN status_dispositivos d
               ON d.equipment_id = e.id AND d.device_type = 'CLP'
             WHERE e.id = :equipment_id AND e.company_id = :company_id
             LIMIT 1",
        );
        $statement->execute([
            "equipment_id" => $equipmentId,
            "company_id" => $companyId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
