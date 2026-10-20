<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

require_once __DIR__ . "/RepositorioItensRomaneio.php";

/**
 * Persiste os itens recebidos em um evento de sincronização de romaneio.
 * A conexão e a transação são compartilhadas com o endpoint consumidor.
 */
final class RepositorioItensRomaneioSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param callable(int,list<array<string,mixed>>):list<int> $productWriter
     */
    public function substituirItens(
        int $companyId,
        int $manifestId,
        int $truckId,
        array $items,
        callable $productWriter,
    ): void {
        $items = array_values($items);
        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Item de romaneio recebido pela sincronização é inválido.");
            }
            $quantity = filter_var($item["planned_quantity"] ?? ($item["quantity"] ?? null), FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1) {
                throw new RuntimeException("Quantidade de item recebida pela sincronização é inválida.");
            }
            $quantities[] = (int) $quantity;
        }

        $productIds = $productWriter($companyId, $items);
        if (!is_array($productIds) || count($productIds) !== count($items)) {
            throw new RuntimeException("Produtos recebidos pela sincronização não correspondem aos itens do romaneio.");
        }

        $normalizedItems = [];
        foreach ($items as $index => $item) {
            $productId = filter_var($productIds[$index] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId === null || $productId < 1) {
                throw new RuntimeException("Produto recebido pela sincronização é inválido.");
            }
            $normalizedItems[] = [
                "product_id" => (int) $productId,
                "quantity" => $quantities[$index],
            ];
        }

        // Atualiza apenas o escopo do evento; linhas compartilhadas e de outros caminhões permanecem intactas.
        if ($truckId > 0) {
            $delete = $this->connection->prepare(
                "DELETE FROM romaneio_itens
                 WHERE company_id = :company_id AND romaneio_id = :romaneio_id
                   AND truck_id = :truck_id",
            );
            $delete->execute([
                "company_id" => $companyId,
                "romaneio_id" => $manifestId,
                "truck_id" => $truckId,
            ]);
        } else {
            $delete = $this->connection->prepare(
                "DELETE FROM romaneio_itens
                 WHERE company_id = :company_id AND romaneio_id = :romaneio_id
                   AND truck_id IS NULL",
            );
            $delete->execute(["company_id" => $companyId, "romaneio_id" => $manifestId]);
        }
        (new RepositorioItensRomaneio($this->connection))->inserirLinhas(
            $companyId,
            array_map(
                static fn (array $item): array => [
                    "romaneio_id" => $manifestId,
                    "product_id" => $item["product_id"],
                    "truck_id" => $truckId > 0 ? $truckId : null,
                    "quantity" => $item["quantity"],
                ],
                $normalizedItems,
            ),
        );
    }
}
