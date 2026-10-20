<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Busca quantidades planejadas e leituras válidas para classificar um saco. */
final class RepositorioLeituras
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function buscarProdutoAtivoPorCodigo(int $companyId, string $barcode): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT p.id
             FROM codigos_produtos pc
             JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
             WHERE pc.company_id = :company_id
               AND pc.barcode = :barcode
               AND p.active = 1
             LIMIT 1',
        );
        $statement->execute([
            "company_id" => $companyId,
            "barcode" => $barcode,
        ]);
        $productId = $statement->fetchColumn();

        return $productId === false ? null : (int) $productId;
    }

    public function contarPendentes(int $companyId, int $loadingId): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM leituras l
             JOIN carregamentos c ON c.id = l.carregamento_id
             WHERE l.company_id = :reading_company_id
               AND l.carregamento_id = :loading_id
               AND c.company_id = :loading_company_id
               AND l.result = 'SEM_LEITURA'",
        );
        $statement->execute([
            "reading_company_id" => $companyId,
            "loading_id" => $loadingId,
            "loading_company_id" => $companyId,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Busca em uma consulta o evento idempotente e sua leitura dentro do escopo autorizado.
     * Uma linha sem reading_id indica que o evento existe, mas a leitura original não está disponível.
     *
     * @return array<string,mixed>|null
     */
    public function buscarLeituraOfflineRegistrada(int $companyId, string $eventUuid, int $loadingId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT a.entity_id, a.metadata, l.id AS reading_id, l.result
             FROM logs_auditoria a
             LEFT JOIN leituras l
               ON l.id = a.entity_id
              AND l.company_id = :reading_company_id
              AND l.carregamento_id = :loading_id
             WHERE a.company_id = :audit_company_id
               AND a.event_uuid = :event_uuid
               AND a.action = 'LEITURA_REGISTRADA'
             LIMIT 1",
        );
        $statement->execute([
            "reading_company_id" => $companyId,
            "loading_id" => $loadingId,
            "audit_company_id" => $companyId,
            "event_uuid" => $eventUuid,
        ]);
        $registered = $statement->fetch(PDO::FETCH_ASSOC);

        return $registered === false ? null : $registered;
    }

    /** @return list<array<string,mixed>> */
    public function listarPendentes(int $companyId, int $loadingId, int $perPage, int $offset): array
    {
        $statement = $this->connection->prepare(
            "SELECT l.id, l.carregamento_id, l.read_at, l.result
             FROM leituras l
             JOIN carregamentos c ON c.id = l.carregamento_id
             WHERE l.company_id = :reading_company_id
               AND l.carregamento_id = :loading_id
               AND c.company_id = :loading_company_id
               AND l.result = 'SEM_LEITURA'
             ORDER BY l.id DESC
             LIMIT :per_page OFFSET :offset",
        );
        $statement->bindValue("reading_company_id", $companyId, PDO::PARAM_INT);
        $statement->bindValue("loading_id", $loadingId, PDO::PARAM_INT);
        $statement->bindValue("loading_company_id", $companyId, PDO::PARAM_INT);
        $statement->bindValue("per_page", $perPage, PDO::PARAM_INT);
        $statement->bindValue("offset", $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Soma itens da empresa, do romaneio e do caminhão, incluindo itens globais.
     * A contagem por produto considera somente leituras válidas deste carregamento.
     *
     * @return array{loading_planned_quantity:int,product_planned_quantity:int,product_valid_count:int}
     */
    public function obterQuantidadesParaClassificar(
        int $companyId,
        int $loadingId,
        int $manifestId,
        ?int $truckId,
        ?int $productId,
    ): array {
        if ($productId === null) {
            $statement = $this->connection->prepare(
                "SELECT COALESCE(SUM(ri.planned_quantity), 0) AS loading_planned_quantity
                 FROM romaneio_itens ri
                 WHERE ri.company_id = :company_id
                   AND ri.romaneio_id = :manifest_id
                   AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
            );
            $statement->execute([
                "company_id" => $companyId,
                "manifest_id" => $manifestId,
                "truck_id" => $truckId,
            ]);
            $plannedQuantity = (int) ($statement->fetchColumn() ?: 0);

            return [
                "loading_planned_quantity" => $plannedQuantity,
                "product_planned_quantity" => 0,
                "product_valid_count" => 0,
            ];
        }

        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(ri.planned_quantity), 0) AS loading_planned_quantity,
                    COALESCE(SUM(CASE WHEN ri.product_id = :planned_product_id
                                      THEN ri.planned_quantity ELSE 0 END), 0) AS product_planned_quantity,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM leituras l
                        WHERE l.company_id = :reading_company_id
                          AND l.carregamento_id = :loading_id
                          AND l.product_id = :count_product_id
                          AND l.result = 'VALIDO'
                    ), 0) AS product_valid_count
             FROM romaneio_itens ri
             WHERE ri.company_id = :company_id
               AND ri.romaneio_id = :manifest_id
               AND (ri.truck_id = :truck_id OR ri.truck_id IS NULL)",
        );
        $statement->execute([
            "planned_product_id" => $productId,
            "reading_company_id" => $companyId,
            "loading_id" => $loadingId,
            "count_product_id" => $productId,
            "company_id" => $companyId,
            "manifest_id" => $manifestId,
            "truck_id" => $truckId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            "loading_planned_quantity" => (int) ($row["loading_planned_quantity"] ?? 0),
            "product_planned_quantity" => (int) ($row["product_planned_quantity"] ?? 0),
            "product_valid_count" => (int) ($row["product_valid_count"] ?? 0),
        ];
    }
}
