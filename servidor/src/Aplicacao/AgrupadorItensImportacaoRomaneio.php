<?php

declare(strict_types=1);

namespace App\Aplicacao;

/**
 * Mantém a regra da importação CSV: soma linhas do mesmo produto, romaneio e caminhão.
 * O cadastro manual e a API preservam linhas repetidas como entradas separadas.
 */
final class AgrupadorItensImportacaoRomaneio
{
    /**
     * @param list<array{romaneio_id:int,product_id:int,truck_id:int,quantity:int}> $rows
     * @return list<array{romaneio_id:int,product_id:int,truck_id:int,quantity:int}>
     */
    public function agrupar(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = implode("|", [
                $row["romaneio_id"],
                $row["truck_id"],
                $row["product_id"],
            ]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $row;
                continue;
            }

            $grouped[$key]["quantity"] += $row["quantity"];
        }

        return array_values($grouped);
    }
}
