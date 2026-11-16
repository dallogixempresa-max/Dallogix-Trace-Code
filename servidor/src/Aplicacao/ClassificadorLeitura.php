<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Aplica as regras de resultado de uma leitura sem acoplar classificação ao banco. */
final class ClassificadorLeitura
{
    /**
     * @return array{result: string, product_id: ?int, product_planned_quantity: int, product_valid_count: int, product_quota_reached: bool, no_read_evidence_required: bool, wrong_product_detected: bool, unregistered_product_detected: bool, stop_required: bool}
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
        bool $unregisteredCodeIsReliable = false,
    ): array {
        $catalogProductIdentified = $barcodePresent && $matchedProductId !== null;
        $unregisteredProductDetected = $barcodePresent && !$catalogProductIdentified && $unregisteredCodeIsReliable;
        $result = ($catalogProductIdentified || $unregisteredProductDetected) ? "PRODUTO_INCORRETO" : "SEM_LEITURA";
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
        // Um código decodificado confiável sem vínculo no catálogo é divergência,
        // não leitura ausente: pare para conferência e preserve o valor lido.
        $wrongProductDetected = $wrongProductDetected || $unregisteredProductDetected;
        $stopRequired = $wrongProductDetected;

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
            "no_read_evidence_required" => !$catalogProductIdentified && !$unregisteredProductDetected,
            "wrong_product_detected" => $wrongProductDetected,
            "unregistered_product_detected" => $unregisteredProductDetected,
            "stop_required" => $stopRequired,
        ];
    }
}
