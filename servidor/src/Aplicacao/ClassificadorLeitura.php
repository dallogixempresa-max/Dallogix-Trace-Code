<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Aplica as regras de resultado de uma leitura sem acoplar classificação ao banco. */
final class ClassificadorLeitura
{
    /**
     * @return array{result: string, product_id: ?int, product_planned_quantity: int, product_valid_count: int}
     */
    public function classificar(
        bool $barcodePresent,
        ?int $matchedProductId,
        int $productPlannedQuantity,
        int $productValidCount,
        int $loadingPlannedQuantity,
        int $loadingValidCount,
    ): array {
        $result = $barcodePresent ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
        $productId = null;

        if ($barcodePresent && $matchedProductId !== null && $productPlannedQuantity > 0) {
            $productId = $matchedProductId;
            $result = "VALIDO";
            if (
                $productValidCount >= $productPlannedQuantity ||
                ($loadingPlannedQuantity > 0 && $loadingValidCount >= $loadingPlannedQuantity)
            ) {
                $result = "EXCESSO";
            }
        }

        return [
            "result" => $result,
            "product_id" => $productId,
            "product_planned_quantity" => $productPlannedQuantity,
            "product_valid_count" => $productValidCount,
        ];
    }
}
