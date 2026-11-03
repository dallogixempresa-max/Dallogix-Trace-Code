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
        int $loadingSemLeituraCount = 0,
        int $productSemLeituraCount = 0,
    ): array {
        // Total de passagens físicas = válidas + sem leitura (retornos já descontados no válidas)
        $loadingTotalCount = $loadingValidCount + $loadingSemLeituraCount;
        $productTotalCount = $productValidCount + $productSemLeituraCount;

        $result = $barcodePresent ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
        $productId = null;

        // SEM_LEITURA: sensor detectou mas leitor falhou — conta a passagem, verifica excesso pelo total
        if (!$barcodePresent) {
            if ($loadingPlannedQuantity > 0 && $loadingTotalCount >= $loadingPlannedQuantity) {
                $result = "EXCESSO";
            }
            return [
                "result" => $result,
                "product_id" => null,
                "product_planned_quantity" => $productPlannedQuantity,
                "product_valid_count" => $productValidCount,
            ];
        }

        if ($matchedProductId !== null && $productPlannedQuantity > 0) {
            $productId = $matchedProductId;
            $result = "VALIDO";
            if (
                $productTotalCount >= $productPlannedQuantity ||
                ($loadingPlannedQuantity > 0 && $loadingTotalCount >= $loadingPlannedQuantity)
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
