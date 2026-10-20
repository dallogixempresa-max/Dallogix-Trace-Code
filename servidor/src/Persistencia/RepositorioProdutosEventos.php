<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

/**
 * Persiste inclusões e exclusões de produtos recebidas pela fila de eventos.
 * Compartilha a conexão do lote; a transação pertence ao endpoint consumidor.
 */
final class RepositorioProdutosEventos
{
    private const MAX_LINHAS_POR_LOTE = 500;

    public function __construct(private readonly PDO $connection)
    {
    }

    /** @param array<string,mixed> $data */
    public function salvar(int $companyId, array $data): int
    {
        return $this->salvarVarios($companyId, [$data])[0];
    }

    /**
     * Persiste produtos em lotes e devolve os IDs na mesma ordem da entrada.
     * Produtos repetidos continuam sendo aplicados na ordem recebida; o último
     * evento do mesmo código prevalece, e o primeiro vínculo de cada código de
     * barras permanece como proprietário.
     *
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    public function salvarVarios(int $companyId, array $items): array
    {
        $normalizedItems = [];
        foreach ($items as $data) {
            if (!is_array($data)) {
                throw new RuntimeException("Produto recebido pela sincronização é inválido.");
            }
            $normalizedItems[] = $this->normalizarProduto($data);
        }
        if ($normalizedItems === []) {
            return [];
        }

        $driver = (string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ["mysql", "sqlite"], true)) {
            return $this->salvarIndividualmente($companyId, $normalizedItems);
        }

        $this->persistirProdutosEmLotes($companyId, $normalizedItems, $driver);
        $productIdsByCode = $this->buscarIdsPorCodigo(
            $companyId,
            array_values(array_unique(array_column($normalizedItems, "code"))),
        );

        $productIds = [];
        $barcodeRows = [];
        foreach ($normalizedItems as $item) {
            $productId = $productIdsByCode[$item["code"]] ?? 0;
            if ($productId < 1) {
                throw new RuntimeException("Produto recebido pela sincronização não pôde ser localizado após a gravação.");
            }
            $productIds[] = $productId;
            if ($item["barcode"] !== "") {
                $barcodeRows[] = [
                    "company_id" => $companyId,
                    "product_id" => $productId,
                    "barcode" => $item["barcode"],
                ];
            }
        }

        $this->persistirCodigosDeBarrasEmLotes($barcodeRows, $driver);

        return $productIds;
    }

    /** @param array<string,mixed> $data
     * @return array{code:string,name:string,category:?string,active:int,barcode:string}
     */
    private function normalizarProduto(array $data): array
    {
        $code = trim((string) ($data["code"] ?? ""));
        $name = trim((string) ($data["name"] ?? $code));
        $category = trim((string) ($data["category"] ?? ""));
        if ($code === "" || $name === "" || !preg_match('/^[A-Za-z0-9À-ÿ _.,\/-]{1,80}$/u', $code)) {
            throw new RuntimeException("Produto recebido pela sincronização é inválido.");
        }

        return [
            "code" => $code,
            "name" => $name,
            "category" => $category !== "" ? $category : null,
            "active" => array_key_exists("active", $data) ? (int) (bool) $data["active"] : 1,
            "barcode" => trim((string) ($data["barcode"] ?? "")),
        ];
    }

    /** @param list<array{code:string,name:string,category:?string,active:int,barcode:string}> $items */
    private function persistirProdutosEmLotes(int $companyId, array $items, string $driver): void
    {
        foreach (array_chunk($items, $this->tamanhoLote($driver)) as $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $index => $item) {
                $suffix = (string) $index;
                $values[] = "(:company_id_{$suffix}, :code_{$suffix}, :name_{$suffix}, :category_{$suffix}, :active_{$suffix})";
                $params["company_id_{$suffix}"] = $companyId;
                $params["code_{$suffix}"] = $item["code"];
                $params["name_{$suffix}"] = $item["name"];
                $params["category_{$suffix}"] = $item["category"];
                $params["active_{$suffix}"] = $item["active"];
            }

            $query = "INSERT INTO produtos (company_id, code, name, category, active)
                      VALUES " . implode(", ", $values);
            $query .= $driver === "mysql"
                ? " AS incoming ON DUPLICATE KEY UPDATE
                    name = incoming.name, category = incoming.category, active = incoming.active"
                : " ON CONFLICT(company_id, code) DO UPDATE SET
                    name = excluded.name, category = excluded.category, active = excluded.active";
            $this->connection->prepare($query)->execute($params);
        }
    }

    /** @param list<string> $codes
     * @return array<string,int>
     */
    private function buscarIdsPorCodigo(int $companyId, array $codes): array
    {
        $idsByCode = [];
        foreach (array_chunk($codes, self::MAX_LINHAS_POR_LOTE) as $batch) {
            [$placeholders, $params] = $this->criarListaParametros($batch, "code");
            $statement = $this->connection->prepare(
                "SELECT id, code FROM produtos
                 WHERE company_id = :company_id AND code IN (" . implode(", ", $placeholders) . ")",
            );
            $statement->execute(["company_id" => $companyId] + $params);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $idsByCode[(string) $row["code"]] = (int) $row["id"];
            }
        }

        // A collation do banco pode considerar equivalentes códigos que não
        // são idênticos em PHP (por exemplo, diferenças de caixa).
        if (count($idsByCode) !== count($codes)) {
            $find = $this->connection->prepare(
                "SELECT id FROM produtos WHERE company_id = :company_id AND code = :code LIMIT 1",
            );
            foreach ($codes as $code) {
                if (isset($idsByCode[$code])) {
                    continue;
                }
                $find->execute(["company_id" => $companyId, "code" => $code]);
                $id = (int) ($find->fetchColumn() ?: 0);
                if ($id > 0) {
                    $idsByCode[$code] = $id;
                }
            }
        }

        return $idsByCode;
    }

    /** @param list<array{company_id:int,product_id:int,barcode:string}> $rows */
    private function persistirCodigosDeBarrasEmLotes(array $rows, string $driver): void
    {
        foreach (array_chunk($rows, $this->tamanhoLote($driver)) as $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $index => $row) {
                $suffix = (string) $index;
                $values[] = "(:company_id_{$suffix}, :product_id_{$suffix}, :barcode_{$suffix})";
                $params["company_id_{$suffix}"] = $row["company_id"];
                $params["product_id_{$suffix}"] = $row["product_id"];
                $params["barcode_{$suffix}"] = $row["barcode"];
            }

            $conflictClause = $driver === "mysql"
                ? " ON DUPLICATE KEY UPDATE product_id = codigos_produtos.product_id"
                : " ON CONFLICT(company_id, barcode) DO NOTHING";
            $this->connection->prepare(
                "INSERT INTO codigos_produtos (company_id, product_id, barcode)
                 VALUES " . implode(", ", $values) . $conflictClause,
            )->execute($params);
        }
    }

    /** @param list<array{code:string,name:string,category:?string,active:int,barcode:string}> $items
     * @return list<int>
     */
    private function salvarIndividualmente(int $companyId, array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $find = $this->connection->prepare(
                "SELECT id FROM produtos WHERE company_id = :company_id AND code = :code LIMIT 1",
            );
            $find->execute(["company_id" => $companyId, "code" => $item["code"]]);
            $productId = (int) ($find->fetchColumn() ?: 0);
            if ($productId > 0) {
                $this->connection->prepare(
                    "UPDATE produtos SET name = :name, category = :category,
                     active = :active WHERE id = :id AND company_id = :company_id",
                )->execute([
                    "name" => $item["name"],
                    "category" => $item["category"],
                    "active" => $item["active"],
                    "id" => $productId,
                    "company_id" => $companyId,
                ]);
            } else {
                $this->connection->prepare(
                    "INSERT INTO produtos (company_id, code, name, category, active)
                     VALUES (:company_id, :code, :name, :category, :active)",
                )->execute([
                    "company_id" => $companyId,
                    "code" => $item["code"],
                    "name" => $item["name"],
                    "category" => $item["category"],
                    "active" => $item["active"],
                ]);
                $productId = (int) $this->connection->lastInsertId();
            }
            $this->salvarCodigoDeBarras($companyId, $productId, $item["barcode"]);
            $productIds[] = $productId;
        }

        return $productIds;
    }

    /** @param list<string> $values
     * @return array{list<string>,array<string,string>}
     */
    private function criarListaParametros(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($values as $index => $value) {
            $key = $prefix . "_" . $index;
            $placeholders[] = ":" . $key;
            $params[$key] = $value;
        }

        return [$placeholders, $params];
    }

    private function tamanhoLote(string $driver): int
    {
        // SQLite pode impor limites de parâmetros menores que o MySQL.
        return $driver === "sqlite" ? 150 : self::MAX_LINHAS_POR_LOTE;
    }

    /**
     * Desvincula leituras e ocorrências históricas antes de remover o produto.
     * @param array<string,mixed> $data
     */
    public function excluir(int $companyId, array $data, int $sourceId): int
    {
        $remoteId = filter_var($data["remote_product_id"] ?? $sourceId, FILTER_VALIDATE_INT);
        $remoteId = $remoteId !== false && $remoteId !== null && $remoteId > 0 ? (int) $remoteId : $sourceId;
        $code = trim((string) ($data["code"] ?? ""));
        $find = $this->connection->prepare(
            $code !== ""
                ? "SELECT id FROM produtos
                   WHERE company_id = :company_id AND code = :code LIMIT 1"
                : "SELECT id FROM produtos
                   WHERE company_id = :company_id
                     AND (id = :remote_id OR remote_product_id = :remote_id)
                   LIMIT 1",
        );
        $find->execute($code !== ""
            ? ["company_id" => $companyId, "code" => $code]
            : ["company_id" => $companyId, "remote_id" => $remoteId]);
        $productId = (int) ($find->fetchColumn() ?: 0);
        if ($productId < 1) {
            return $remoteId;
        }

        $this->connection->prepare(
            "DELETE FROM codigos_produtos WHERE company_id = :company_id AND product_id = :id",
        )->execute(["company_id" => $companyId, "id" => $productId]);
        $this->connection->prepare(
            "UPDATE leituras SET product_id = NULL WHERE company_id = :company_id AND product_id = :id",
        )->execute(["company_id" => $companyId, "id" => $productId]);
        $this->connection->prepare(
            "UPDATE ocorrencias SET product_id = NULL WHERE company_id = :company_id AND product_id = :id",
        )->execute(["company_id" => $companyId, "id" => $productId]);
        $this->connection->prepare(
            "DELETE FROM romaneio_itens WHERE company_id = :company_id AND product_id = :id",
        )->execute(["company_id" => $companyId, "id" => $productId]);
        $this->connection->prepare("DELETE FROM produtos WHERE id = :id AND company_id = :company_id")
            ->execute(["id" => $productId, "company_id" => $companyId]);

        return $productId;
    }

    private function salvarCodigoDeBarras(int $companyId, int $productId, string $barcode): void
    {
        if ($barcode === "") {
            return;
        }

        $barcodeOwner = $this->connection->prepare(
            "SELECT product_id FROM codigos_produtos
             WHERE company_id = :company_id AND barcode = :barcode LIMIT 1",
        );
        $barcodeOwner->execute(["company_id" => $companyId, "barcode" => $barcode]);
        if ((int) ($barcodeOwner->fetchColumn() ?: 0) !== 0) {
            return;
        }

        $this->connection->prepare(
            "INSERT INTO codigos_produtos (company_id, product_id, barcode)
             VALUES (:company_id, :product_id, :barcode)",
        )->execute([
            "company_id" => $companyId,
            "product_id" => $productId,
            "barcode" => $barcode,
        ]);
    }
}
