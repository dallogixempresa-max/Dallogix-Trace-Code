<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

final class RepositorioDashboard
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Conta romaneios finalizados com quantidade divergente ou ocorrência registrada. */
    public function contarRomaneiosDivergentes(int $companyId): int
    {
        $statement = $this->connection->prepare(
            "WITH romaneios_finalizados AS (
                SELECT id
                FROM romaneios
                WHERE company_id = :company_id_romaneios
                  AND status = 'FINALIZADO'
            ), quantidades_planejadas AS (
                SELECT ri.romaneio_id, SUM(ri.planned_quantity) AS total_planejado
                FROM romaneio_itens ri
                JOIN romaneios_finalizados rf ON rf.id = ri.romaneio_id
                WHERE ri.company_id = :company_id_itens
                GROUP BY ri.romaneio_id
            ), leituras_validas AS (
                SELECT c.romaneio_id, COUNT(*) AS total_validas
                FROM romaneios_finalizados rf
                JOIN carregamentos c ON c.romaneio_id = rf.id
                JOIN leituras l
                  ON l.carregamento_id = c.id
                 AND l.company_id = :company_id_leituras
                 AND l.result = 'VALIDO'
                WHERE c.company_id = :company_id_carregamentos_leituras
                GROUP BY c.romaneio_id
            ), ocorrencias_carregamento AS (
                SELECT c.romaneio_id, COUNT(*) AS total_ocorrencias
                FROM romaneios_finalizados rf
                JOIN carregamentos c ON c.romaneio_id = rf.id
                JOIN ocorrencias o
                  ON o.carregamento_id = c.id
                 AND o.company_id = :company_id_ocorrencias
                WHERE c.company_id = :company_id_carregamentos_ocorrencias
                GROUP BY c.romaneio_id
            )
            SELECT COUNT(*)
            FROM romaneios_finalizados rf
            LEFT JOIN quantidades_planejadas qp ON qp.romaneio_id = rf.id
            LEFT JOIN leituras_validas lv ON lv.romaneio_id = rf.id
            LEFT JOIN ocorrencias_carregamento oc ON oc.romaneio_id = rf.id
            WHERE COALESCE(qp.total_planejado, 0) <> COALESCE(lv.total_validas, 0)
               OR COALESCE(oc.total_ocorrencias, 0) > 0",
        );
        $statement->execute([
            "company_id_romaneios" => $companyId,
            "company_id_itens" => $companyId,
            "company_id_leituras" => $companyId,
            "company_id_carregamentos_leituras" => $companyId,
            "company_id_ocorrencias" => $companyId,
            "company_id_carregamentos_ocorrencias" => $companyId,
        ]);

        return (int) ($statement->fetchColumn() ?: 0);
    }
}
