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
    ): array {
        // Um código não encontrado no catálogo não prova que o saco contém um
        // produto fora do romaneio. Registre como leitura sem identificação e
        // exija evidência; só classifique como incorreto um produto reconhecido.
        $catalogProductIdentified = $barcodePresent && $matchedProductId !== null;
        $result = $catalogProductIdentified ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
        $productId = null;
        // Só produtos previstos e passagens sem leitura participam da cota.
        // Produto reconhecido além da quantidade do item precisa ser removido
        // e registrado como produto incorreto, sem consumir a meta.
        $loadingPassageCount = $loadingValidCount + $loadingSemLeituraCount;
        $productPassageCount = $productValidCount + $productSemLeituraCount;
        $productQuotaAlreadyReached = $productPlannedQuantity > 0
            && $productPassageCount >= $productPlannedQuantity;
        $loadingQuotaAlreadyReached = $loadingPlannedQuantity > 0
            && $loadingPassageCount >= $loadingPlannedQuantity;
        $wrongProductDetected = $catalogProductIdentified && (
            $productPlannedQuantity <= 0
            || $productQuotaAlreadyReached
            || (!$physicalExcess && $loadingQuotaAlreadyReached)
        );

        if ($catalogProductIdentified && $productPlannedQuantity > 0) {
            $productId = $matchedProductId;
            // Uma leitura válida depois da parada da meta geral permanece
            // auditada sem consumir a quantidade planejada. Passagem além da
            // cota do item é produto incorreto e exige parada e evidência.
            $result = $wrongProductDetected ? "PRODUTO_INCORRETO" : "VALIDO";
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
            "wrong_product_detected" => $wrongProductDetected,
        ];
    }
}
