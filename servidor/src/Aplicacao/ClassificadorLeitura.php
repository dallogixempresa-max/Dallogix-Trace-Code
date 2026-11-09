<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Aplica as regras de resultado de uma leitura sem acoplar classificação ao banco. */
final class ClassificadorLeitura
{
    /**
     * @return array{result: string, product_id: ?int, product_planned_quantity: int, product_valid_count: int, product_quota_reached: bool, no_read_evidence_required: bool}
     */
    public function classificar(
        bool $barcodePresent,
        ?int $matchedProductId,
        int $productPlannedQuantity,
        int $productValidCount,
        int $loadingPlannedQuantity,
        int $loadingValidCount,
        bool $physicalExcess = false,
        int $productSemLeituraCount = 0,
        int $loadingSemLeituraCount = 0,
    ): array {
        // Um código não encontrado no catálogo não prova que o saco contém um
        // produto fora do romaneio. Registre como leitura sem identificação e
        // exija evidência; só classifique como incorreto um produto reconhecido.
        $catalogProductIdentified = $barcodePresent && $matchedProductId !== null;
        $result = $catalogProductIdentified ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
        $productId = null;
        $loadingPassageCount = $loadingValidCount + $loadingSemLeituraCount;

        if ($catalogProductIdentified && $productPlannedQuantity > 0) {
            $productId = $matchedProductId;
            $result = "VALIDO";
            if (
                $physicalExcess ||
                $productValidCount + $productSemLeituraCount >= $productPlannedQuantity ||
                ($loadingPlannedQuantity > 0 && $loadingPassageCount >= $loadingPlannedQuantity)
            ) {
                $result = "EXCESSO";
            }
        } elseif (!$catalogProductIdentified && (
            $physicalExcess ||
            ($loadingPlannedQuantity > 0 && $loadingPassageCount >= $loadingPlannedQuantity)
        )) {
            // Uma passagem sem leitura identificável continua sendo evidência
            // física. Se ela exceder a meta total, classifique-a como excesso.
            $result = "EXCESSO";
        }

        $productQuotaReached = $result === "VALIDO"
            && $productPlannedQuantity > 0
            && $productValidCount + $productSemLeituraCount + 1 >= $productPlannedQuantity;

        return [
            "result" => $result,
            "product_id" => $productId,
            "product_planned_quantity" => $productPlannedQuantity,
            "product_valid_count" => $productValidCount,
            "product_quota_reached" => $productQuotaReached,
            "no_read_evidence_required" => !$catalogProductIdentified,
        ];
    }
}
