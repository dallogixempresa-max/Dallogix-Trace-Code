<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Consulta agrupada do estado e da idade da fila local de sincronização. */
final class RepositorioStatusSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array{summary:array<string,int>, oldest_at:?string}
     */
    public function obterResumoFila(int $companyId): array
    {
        $statement = $this->connection->prepare(
            "SELECT status, total,
                    MIN(CASE
                        WHEN status IN ('PENDENTE', 'PROCESSANDO', 'ERRO')
                        THEN status_oldest_at
                    END) OVER () AS oldest_at
             FROM (
                SELECT q.status, COUNT(*) AS total,
                       MIN(q.created_at) AS status_oldest_at
                FROM fila_sincronizacao q
                WHERE q.company_id = :company_id
                GROUP BY q.status
             ) AS fila_por_status",
        );
        $statement->execute(["company_id" => $companyId]);

        $summary = ["PENDENTE" => 0, "PROCESSANDO" => 0, "ENVIADO" => 0, "ERRO" => 0];
        $oldestAt = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[(string) $row["status"]] = (int) $row["total"];
            $oldestAt ??= $row["oldest_at"] === null ? null : (string) $row["oldest_at"];
        }

        return ["summary" => $summary, "oldest_at" => $oldestAt];
    }
}
