<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Busca em lote os produtos referenciados no cadastro de um romaneio. */
final class RepositorioProdutosRomaneio
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @param list<int> $productIds @return array<int,array{id:int,code:string,name:string}> */
    public function buscarAtivosPorIds(int $companyId, array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $params = ["company_id" => $companyId];
        $placeholders = $this->criarPlaceholders($ids, "product_id_", $params);
        $statement = $this->connection->prepare(
            "SELECT id, code, name
             FROM produtos
             WHERE company_id = :company_id AND active = 1 AND id IN ({$placeholders})",
        );
        $statement->execute($params);

        $products = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $product["id"] = (int) $product["id"];
            $products[$product["id"]] = $product;
        }

        return $products;
    }

    /** @param list<string> $identifiers @return array<string,array{id:int,code:string,name:string}> */
    public function buscarAtivosPorCodigos(int $companyId, array $identifiers): array
    {
        $identifiers = $this->normalizarListaIdentificadores($identifiers);

        return $identifiers === [] ? [] : $this->buscarPorCodigo($companyId, $identifiers);
    }

    /** @param list<string> $identifiers @return array<string,array{id:int,code:string,name:string}> */
    public function buscarAtivosPorCodigoOuBarras(int $companyId, array $identifiers): array
    {
        $identifiers = $this->normalizarListaIdentificadores($identifiers);
        if ($identifiers === []) {
            return [];
        }

        $products = $this->buscarPorCodigo($companyId, $identifiers);
        $missing = array_values(array_filter(
            $identifiers,
            fn (string $identifier): bool => !isset($products[$this->normalizarIdentificador($identifier)]),
        ));
        if ($missing === []) {
            return $products;
        }

        $params = ["company_id" => $companyId];
        $placeholders = $this->criarPlaceholders($missing, "barcode_", $params);
        $statement = $this->connection->prepare(
            "SELECT p.id, p.code, p.name, pc.barcode AS matched_identifier
             FROM codigos_produtos pc
             JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
             WHERE pc.company_id = :company_id AND p.active = 1
               AND pc.barcode IN ({$placeholders})",
        );
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $identifier = $this->normalizarIdentificador((string) $product["matched_identifier"]);
            $product["id"] = (int) $product["id"];
            unset($product["matched_identifier"]);
            $products[$identifier] = $product;
        }

        return $products;
    }

    /** @param list<string> $identifiers @return list<string> */
    private function normalizarListaIdentificadores(array $identifiers): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $identifiers),
            static fn (string $value): bool => $value !== "",
        )));
    }

    /** @param list<string> $identifiers @return array<string,array{id:int,code:string,name:string}> */
    private function buscarPorCodigo(int $companyId, array $identifiers): array
    {
        $params = ["company_id" => $companyId];
        $placeholders = $this->criarPlaceholders($identifiers, "product_code_", $params);
        $statement = $this->connection->prepare(
            "SELECT id, code, name
             FROM produtos
             WHERE company_id = :company_id AND active = 1
               AND code IN ({$placeholders})",
        );
        $statement->execute($params);

        $products = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $product["id"] = (int) $product["id"];
            $products[$this->normalizarIdentificador((string) $product["code"])] = $product;
        }

        return $products;
    }

    /** @param list<int|string> $values @param array<string,int|string> $params */
    private function criarPlaceholders(array $values, string $prefix, array &$params): string
    {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $name = $prefix . $index;
            $placeholders[] = ":{$name}";
            $params[$name] = $value;
        }

        return implode(", ", $placeholders);
    }

    private function normalizarIdentificador(string $identifier): string
    {
        return mb_strtolower(trim($identifier), "UTF-8");
    }
}
