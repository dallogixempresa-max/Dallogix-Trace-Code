<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

final class RepositorioRomaneiosLista
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @param array{date_from:?string,date_to:?string,number:string,expedidor:string,status:?string} $filters */
    public function contar(int $companyId, array $filters): int
    {
        [$where, $params] = $this->filtros($companyId, $filters);
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM romaneios r {$where}");
        $this->bindParameters($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Agrega apenas os romaneios da página solicitada. Isso evita varrer o
     * histórico completo a cada atualização da lista.
     *
     * @param array{date_from:?string,date_to:?string,number:string,expedidor:string,status:?string} $filters
     * @return list<array<string,mixed>>
     */
    public function listar(int $companyId, array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->filtros($companyId, $filters);
        $params["limit"] = max(1, min(100, $perPage));
        $params["offset"] = max(0, $offset);

        $statement = $this->connection->prepare(
            "WITH romaneios_paginados AS (
                SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor
                FROM romaneios r
                {$where}
                ORDER BY r.scheduled_date DESC, r.id DESC
                LIMIT :limit OFFSET :offset
            ), carregamento_ativo AS (
                SELECT c.id, c.romaneio_id, c.state, e.equipment_code,
                       ROW_NUMBER() OVER (PARTITION BY c.romaneio_id ORDER BY c.id DESC) AS rn
                FROM carregamentos c
                JOIN romaneios_paginados rp ON rp.id = c.romaneio_id
                JOIN romaneios r2 ON r2.id = c.romaneio_id
                LEFT JOIN equipamentos e ON e.id = c.equipment_id
                WHERE c.state <> 'FINALIZADO'
                  AND r2.status NOT IN ('FINALIZADO', 'CANCELADO')
            ), planned AS (
                SELECT ri.romaneio_id, SUM(ri.planned_quantity) AS planned_quantity
                FROM romaneio_itens ri
                JOIN romaneios_paginados rp ON rp.id = ri.romaneio_id
                GROUP BY ri.romaneio_id
            ), moved AS (
                -- Lidas por produto: só leituras VALIDO (código decodificado), descontadas
                -- as devoluções do produto e sem deixar a linha negativa. Passagens
                -- SEM_LEITURA ficam fora: são sacas que passaram pelo sensor sem código e
                -- aparecem separadas, em no_read_quantity na tela de operação.
                SELECT ri.romaneio_id, ri.product_id,
                       GREATEST(0,
                           (SELECT COUNT(*) FROM leituras l
                            JOIN carregamentos c ON c.id = l.carregamento_id
                            WHERE c.romaneio_id = ri.romaneio_id
                              AND l.result = 'VALIDO' AND l.conta_na_meta = 1 AND l.product_id = ri.product_id)
                           - COALESCE((SELECT SUM(ret.quantity) FROM retornos ret
                                       JOIN carregamentos c ON c.id = ret.carregamento_id
                                       JOIN leituras lr ON lr.id = ret.leitura_id
                                       WHERE c.romaneio_id = ri.romaneio_id AND lr.product_id = ri.product_id
                                         AND lr.conta_na_meta = 1), 0)
                       ) AS moved_quantity
                FROM (
                    SELECT DISTINCT ri0.romaneio_id, ri0.product_id
                    FROM romaneio_itens ri0
                    JOIN romaneios_paginados rp0 ON rp0.id = ri0.romaneio_id
                ) ri
            ), loaded AS (
                SELECT romaneio_id, SUM(moved_quantity) AS loaded_quantity
                FROM moved
                GROUP BY romaneio_id
            ), occurrences AS (
                SELECT c.romaneio_id, COUNT(*) AS occurrences_count
                FROM ocorrencias o
                JOIN carregamentos c ON c.id = o.carregamento_id
                JOIN romaneios_paginados rp ON rp.id = c.romaneio_id
                GROUP BY c.romaneio_id
            )
            SELECT rp.id, rp.number, rp.scheduled_date, rp.status, rp.expedidor,
                   (SELECT rt.plate FROM romaneio_caminhoes rt
                    WHERE rt.romaneio_id = rp.id ORDER BY rt.id LIMIT 1) AS plate,
                   (SELECT rt.driver_name FROM romaneio_caminhoes rt
                    WHERE rt.romaneio_id = rp.id ORDER BY rt.id LIMIT 1) AS driver_name,
                   COUNT(DISTINCT rt.id) AS trucks_count,
                   COALESCE(planned.planned_quantity, 0) AS planned_quantity,
                   COALESCE(loaded.loaded_quantity, 0) AS loaded_quantity,
                   COALESCE(occurrences.occurrences_count, 0) AS ocorrencias_count,
                   ca.id AS active_loading_id,
                   ca.state AS active_state,
                   ca.equipment_code AS active_equipment
            FROM romaneios_paginados rp
            LEFT JOIN romaneio_caminhoes rt ON rt.romaneio_id = rp.id
            LEFT JOIN planned ON planned.romaneio_id = rp.id
            LEFT JOIN loaded ON loaded.romaneio_id = rp.id
            LEFT JOIN occurrences ON occurrences.romaneio_id = rp.id
            LEFT JOIN carregamento_ativo ca
              ON ca.romaneio_id = rp.id AND ca.rn = 1
            GROUP BY rp.id, rp.number, rp.scheduled_date, rp.status, rp.expedidor,
                     ca.id, ca.state, ca.equipment_code,
                     planned.planned_quantity, loaded.loaded_quantity, occurrences.occurrences_count
            ORDER BY rp.scheduled_date DESC, rp.id DESC",
        );
        $this->bindParameters($statement, $params);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{date_from:?string,date_to:?string,number:string,expedidor:string,status:?string} $filters
     * @return array{0:string,1:array<string,int|string>}
     */
    private function filtros(int $companyId, array $filters): array
    {
        $conditions = ["r.company_id = :company_id"];
        $params = ["company_id" => $companyId];

        if ($filters["date_from"] !== null) {
            $conditions[] = "r.scheduled_date >= :date_from";
            $params["date_from"] = $filters["date_from"];
        }
        if ($filters["date_to"] !== null) {
            $conditions[] = "r.scheduled_date <= :date_to";
            $params["date_to"] = $filters["date_to"];
        }
        if ($filters["number"] !== "") {
            $conditions[] = "r.number LIKE :number";
            $params["number"] = "%" . addcslashes($filters["number"], "%_\\") . "%";
        }
        if ($filters["expedidor"] !== "") {
            $conditions[] = "r.expedidor LIKE :expedidor";
            $params["expedidor"] = "%" . addcslashes($filters["expedidor"], "%_\\") . "%";
        }
        if ($filters["status"] !== null) {
            $conditions[] = "r.status = :status";
            $params["status"] = $filters["status"];
        }

        return ["WHERE " . implode(" AND ", $conditions), $params];
    }

    /** @param array<string,int|string> $params */
    private function bindParameters(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(
                ":{$name}",
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR,
            );
        }
    }
}
