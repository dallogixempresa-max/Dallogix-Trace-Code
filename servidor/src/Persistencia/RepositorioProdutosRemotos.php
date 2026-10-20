<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Aplica snapshots de produtos e protege alterações locais ainda pendentes. */
final class RepositorioProdutosRemotos
{
    /** Usa a mesma conexão para manter os dados dentro da transação do snapshot. */
    public function __construct(
        private readonly PDO $connection,
        private readonly RepositorioFilaSincronizacao $fila,
    ) {
    }

    /** @param list<array<string,mixed>> $products */
    public function sincronizar(int $companyId, array $products): int
    {
        $findByRemoteId = $this->connection->prepare(
            "SELECT id FROM produtos
             WHERE company_id = :company_id AND remote_product_id = :remote_product_id LIMIT 1",
        );
        $findUnlinkedByCode = $this->connection->prepare(
            "SELECT id FROM produtos
             WHERE company_id = :company_id AND code = :code
               AND remote_product_id IS NULL LIMIT 1",
        );
        $update = $this->connection->prepare(
            "UPDATE produtos
             SET remote_product_id = :remote_product_id, code = :code, name = :name,
                 category = :category, active = :active
             WHERE id = :id AND company_id = :company_id",
        );
        $insert = $this->connection->prepare(
            "INSERT INTO produtos (company_id, remote_product_id, code, name, category, active)
             VALUES (:company_id, :remote_product_id, :code, :name, :category, :active)",
        );
        $deleteProductBarcodes = $this->connection->prepare(
            "DELETE FROM codigos_produtos WHERE company_id = :company_id AND product_id = :product_id",
        );
        $deleteBarcodeOwner = $this->connection->prepare(
            "DELETE FROM codigos_produtos WHERE company_id = :company_id AND barcode = :barcode
               AND NOT EXISTS (SELECT 1 FROM fila_sincronizacao q
                   WHERE q.company_id = codigos_produtos.company_id
                     AND q.aggregate_type = 'produto'
                     AND q.aggregate_id = codigos_produtos.product_id
                     AND q.status <> 'ENVIADO')",
        );
        $findBarcodeOwner = $this->connection->prepare(
            "SELECT product_id FROM codigos_produtos WHERE company_id = :company_id AND barcode = :barcode LIMIT 1",
        );
        $insertBarcode = $this->connection->prepare(
            "INSERT INTO codigos_produtos (company_id, product_id, barcode)
             VALUES (:company_id, :product_id, :barcode)",
        );
        $remoteIds = [];
        $updated = 0;

        foreach ($products as $remoteProduct) {
            if (!is_array($remoteProduct)) {
                continue;
            }
            $remoteId = (int) ($remoteProduct["id"] ?? 0);
            $code = trim((string) ($remoteProduct["code"] ?? ""));
            $name = trim((string) ($remoteProduct["name"] ?? ""));
            if ($remoteId < 1 || $code === "" || $name === "") {
                continue;
            }
            $category = trim((string) ($remoteProduct["category"] ?? ""));
            $active = (int) (bool) ($remoteProduct["active"] ?? false);
            $barcodes = array_values(array_unique(array_filter(
                array_map(static fn (mixed $barcode): string => trim((string) $barcode), (array) ($remoteProduct["barcodes"] ?? [])),
                static fn (string $barcode): bool => $barcode !== "",
            )));

            $findByRemoteId->execute([
                "company_id" => $companyId,
                "remote_product_id" => $remoteId,
            ]);
            $localId = (int) ($findByRemoteId->fetchColumn() ?: 0);
            if (!$localId) {
                $findUnlinkedByCode->execute(["company_id" => $companyId, "code" => $code]);
                $localId = (int) ($findUnlinkedByCode->fetchColumn() ?: 0);
            }
            $params = [
                "company_id" => $companyId,
                "remote_product_id" => $remoteId,
                "code" => $code,
                "name" => $name,
                "category" => $category !== "" ? $category : null,
                "active" => $active,
            ];
            if ($localId) {
                if ($this->fila->possuiEventoPendente($companyId, "produto", $localId)) {
                    // Vincule a identidade remota, mas preserve as edições e
                    // códigos locais até a entrega dos eventos pendentes.
                    $this->connection->prepare(
                        "UPDATE produtos SET remote_product_id = :remote_id
                         WHERE id = :id AND company_id = :company_id",
                    )->execute(["remote_id" => $remoteId, "id" => $localId, "company_id" => $companyId]);
                    $remoteIds[] = $remoteId;
                    continue;
                }
                $update->execute([...$params, "id" => $localId]);
            } else {
                $insert->execute($params);
                $localId = (int) $this->connection->lastInsertId();
}
            // A lista recebida é a fonte de verdade: remove e recria todos
            // os códigos para refletir inclusões, edições e remoções remotas.
            $deleteProductBarcodes->execute(["company_id" => $companyId, "product_id" => $localId]);
            foreach ($barcodes as $barcode) {
                $deleteBarcodeOwner->execute(["company_id" => $companyId, "barcode" => $barcode]);
                $findBarcodeOwner->execute(["company_id" => $companyId, "barcode" => $barcode]);
                if ($findBarcodeOwner->fetchColumn() !== false) {
                    // Uma edição local ainda não sincronizada tem precedência.
                    continue;
                }
                $insertBarcode->execute([
                    "company_id" => $companyId,
                    "product_id" => $localId,
                    "barcode" => $barcode,
                ]);
            }
            $remoteIds[] = $remoteId;
            $updated++;
        }

        $staleSql = "UPDATE produtos SET active = 0
                     WHERE company_id = :company_id AND remote_product_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM fila_sincronizacao q
                           WHERE q.company_id = produtos.company_id
                             AND q.aggregate_type = 'produto'
                             AND q.aggregate_id = produtos.id
                             AND q.status <> 'ENVIADO')";
        $staleParams = ["company_id" => $companyId];
        if ($remoteIds !== []) {
            $placeholders = [];
            foreach (array_values(array_unique($remoteIds)) as $index => $remoteId) {
                $key = "remote_product_id_{$index}";
                $placeholders[] = ":{$key}";
                $staleParams[$key] = $remoteId;
            }
            $staleSql .= " AND remote_product_id NOT IN (" . implode(",", $placeholders) . ")";
        }
        $stale = $this->connection->prepare($staleSql);
        $stale->execute($staleParams);

        return $updated + $stale->rowCount();
    }
}
