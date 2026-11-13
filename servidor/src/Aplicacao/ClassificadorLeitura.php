<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Aplica as regras de resultado de uma leitura sem acoplar classificação ao banco. */
final class ClassificadorLeitura
{
    /**
     * @return array{result: string, product_id: ?int, product_planned_quantity: int, product_valid_count: int, product_quota_reached: bool, no_read_evidence_required: bool, wrong_product_detected: bool}
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
        int $productProdutoIncorretoCount = 0,
        int $loadingProdutoIncorretoCount = 0,
    ): array {
        // Um código não encontrado no catálogo não prova que o saco contém um
        // produto fora do romaneio. Registre como leitura sem identificação e
        // exija evidência; só classifique como incorreto um produto reconhecido.
        $catalogProductIdentified = $barcodePresent && $matchedProductId !== null;
        $result = $catalogProductIdentified ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
        $productId = null;
        // Passagens físicas incluem VALIDO, SEM_LEITURA e PRODUTO_INCORRETO:
        // qualquer saco que passou pelo sensor conta para a cota total,
        // independente do resultado da leitura do código de barras.
        $loadingPassageCount = $loadingValidCount + $loadingSemLeituraCount + $loadingProdutoIncorretoCount;
        $wrongProductDetected = $catalogProductIdentified && $productPlannedQuantity <= 0;

        if ($catalogProductIdentified && $productPlannedQuantity > 0) {
            $productId = $matchedProductId;
            $result = "VALIDO";
            // Conta também PRODUTO_INCORRETO por produto: um saco com código
            // reconhecido mas produto errado ainda ocupou fisicamente o lugar de
            // um saco deste produto no caminhão.
            $productPassageCount = $productValidCount + $productSemLeituraCount + $productProdutoIncorretoCount;
            if (
                $physicalExcess ||
                $productPassageCount >= $productPlannedQuantity ||
                ($loadingPlannedQuantity > 0 && $loadingPassageCount >= $loadingPlannedQuantity)
            ) {
                $result = "EXCESSO";
            }
        } elseif ($wrongProductDetected && (
            $physicalExcess ||
            ($loadingPlannedQuantity > 0 && $loadingPassageCount >= $loadingPlannedQuantity)
        )) {
            // Um produto conhecido fora do romaneio também é excesso se a cota
            // física já foi atingida antes desta passagem.
            $result = "EXCESSO";
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
            && $productValidCount + $productSemLeituraCount + $productProdutoIncorretoCount + 1 >= $productPlannedQuantity;

        return [
            "result" => $result,
            "product_id" => $productId,
            "product_planned_quantity" => $productPlannedQuantity,
            "product_valid_count" => $productValidCount,
            "product_quota_reached" => $productQuotaReached,
            "no_read_evidence_required" => !$catalogProductIdentified,
            "wrong_product_detected" => $wrongProductDetected,
        ];
    }
}
