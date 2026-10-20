<?php

declare(strict_types=1);

namespace App\Persistencia;

use InvalidArgumentException;
use PDO;

/** Grava linhas de romaneio em lotes sem combinar produtos repetidos. */
final class RepositorioItensRomaneio
{
    private const MAX_LINHAS_POR_LOTE = 500;

    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param list<array{romaneio_id:int,product_id:int,truck_id:?int,quantity:int}> $items
     *
     * A ordem e as linhas duplicadas são preservadas. O limite por lote mantém
     * o número de parâmetros abaixo do teto do MySQL e o pacote de escrita menor.
     */
    public function inserirLinhas(int $companyId, array $items): void
    {
        if ($companyId < 1) {
            throw new InvalidArgumentException("Empresa inválida para os itens do romaneio.");
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("Linha de item do romaneio inválida.");
            }
            $manifestId = filter_var($item["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($item["product_id"] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item["quantity"] ?? null, FILTER_VALIDATE_INT);
            $truckId = $item["truck_id"] ?? null;
            $truckId = $truckId === null ? null : filter_var($truckId, FILTER_VALIDATE_INT);
            if (
                $manifestId === false || $manifestId < 1
                || $productId === false || $productId < 1
                || $quantity === false || $quantity < 1
                || ($truckId !== null && ($truckId === false || $truckId < 1))
            ) {
                throw new InvalidArgumentException("Vínculo ou quantidade do item do romaneio inválido.");
            }

            $normalizedItems[] = [
                "romaneio_id" => (int) $manifestId,
                "product_id" => (int) $productId,
                "truck_id" => $truckId === null ? null : (int) $truckId,
                "quantity" => (int) $quantity,
            ];
        }

        foreach (array_chunk($normalizedItems, self::MAX_LINHAS_POR_LOTE) as $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $index => $item) {
                $suffix = (string) $index;
                $values[] = "(:company_id_{$suffix}, :romaneio_id_{$suffix}, :product_id_{$suffix}, :truck_id_{$suffix}, :quantity_{$suffix})";
                $params["company_id_{$suffix}"] = $companyId;
                $params["romaneio_id_{$suffix}"] = $item["romaneio_id"];
                $params["product_id_{$suffix}"] = $item["product_id"];
                $params["truck_id_{$suffix}"] = $item["truck_id"];
                $params["quantity_{$suffix}"] = $item["quantity"];
            }

            $statement = $this->connection->prepare(
                "INSERT INTO romaneio_itens
                    (company_id, romaneio_id, product_id, truck_id, planned_quantity)
                 VALUES " . implode(", ", $values),
            );
            $statement->execute($params);
        }
    }
}
