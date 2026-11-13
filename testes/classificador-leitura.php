<?php
declare(strict_types=1);

require_once __DIR__ . "/../servidor/src/Aplicacao/ClassificadorLeitura.php";

use App\Aplicacao\ClassificadorLeitura;

$classifier = new ClassificadorLeitura();
$cases = [
    ["quarto A completa o item", true, 10, 4, 3, 10, 3, false, "VALIDO"],
    ["quinto A excede o item", true, 10, 4, 4, 10, 4, false, "EXCESSO"],
    ["A excede mesmo com B pendente", true, 10, 4, 4, 10, 5, false, "EXCESSO"],
    ["passagem física excedente com código", true, 20, 6, 1, 10, 5, true, "EXCESSO"],
    ["passagem sem código sem excesso não recebe produto", false, null, 0, 0, 10, 4, false, "SEM_LEITURA"],
    ["passagem física excedente sem código não recebe produto", false, null, 0, 0, 10, 4, true, "EXCESSO"],
    ["produto ausente do romaneio", true, 30, 0, 0, 10, 4, false, "PRODUTO_INCORRETO"],
];

foreach ($cases as [$name, $barcodePresent, $productId, $productPlanned, $productValid, $loadingPlanned, $loadingValid, $physicalExcess, $expected]) {
    $result = $classifier->classificar(
        $barcodePresent,
        $productId,
        $productPlanned,
        $productValid,
        $loadingPlanned,
        $loadingValid,
        $physicalExcess,
    );
    if ($result["result"] !== $expected) {
        throw new RuntimeException("$name: esperado $expected, obtido {$result['result']}");
    }
    if (!$barcodePresent && $result["product_id"] !== null) {
        throw new RuntimeException("$name: passagem sem código não pode receber produto");
    }
}

$unmatchedBarcode = $classifier->classificar(true, null, 0, 0, 10, 4, false);
if ($unmatchedBarcode["result"] !== "SEM_LEITURA"
    || $unmatchedBarcode["product_id"] !== null
    || !$unmatchedBarcode["no_read_evidence_required"]
) {
    throw new RuntimeException("Código sem correspondência no catálogo deve exigir evidência como leitura não identificada.");
}

$unmatchedBarcodeAtExcess = $classifier->classificar(true, null, 0, 0, 10, 10, true);
if ($unmatchedBarcodeAtExcess["result"] !== "EXCESSO"
    || !$unmatchedBarcodeAtExcess["no_read_evidence_required"]
) {
    throw new RuntimeException("Código sem correspondência em passagem excedente deve manter o excesso e exigir evidência.");
}

$knownProductOutsideManifest = $classifier->classificar(true, 30, 0, 0, 10, 4, false);
if ($knownProductOutsideManifest["result"] !== "PRODUTO_INCORRETO"
    || $knownProductOutsideManifest["no_read_evidence_required"]
    || !$knownProductOutsideManifest["wrong_product_detected"]
) {
    throw new RuntimeException("Produto reconhecido fora do romaneio deve continuar sendo tratado como incorreto.");
}

$knownProductOutsideManifestAtQuota = $classifier->classificar(
    true, 30,
    0, 0,  // produto reconhecido, mas ausente do romaneio
    10, 10, // a cota válida já foi atingida
    true,
);
if ($knownProductOutsideManifestAtQuota["result"] !== "PRODUTO_INCORRETO"
    || !$knownProductOutsideManifestAtQuota["wrong_product_detected"]
    || $knownProductOutsideManifestAtQuota["no_read_evidence_required"]
) {
    throw new RuntimeException("Produto incorreto continua identificado como incorreto mesmo após atingir a cota.");
}

$noReadAfterWrongProduct = $classifier->classificar(
    false, null,
    0, 0,
    10, 9, // produto errado anterior não entra neste total
    false,
);
if ($noReadAfterWrongProduct["result"] !== "SEM_LEITURA") {
    throw new RuntimeException("Produto incorreto anterior não pode antecipar excesso nem ocupar a cota.");
}

$validProductAfterWrongProduct = $classifier->classificar(
    true, 10,
    5, 3, // produto A: três válidas; incorretas anteriores não entram
    10, 3,
    false,
);
if ($validProductAfterWrongProduct["result"] !== "VALIDO"
    || $validProductAfterWrongProduct["product_quota_reached"] !== false
) {
    throw new RuntimeException("Produto incorreto não pode antecipar o limite do produto previsto.");
}

$validProductCompletesQuotaAfterWrongProduct = $classifier->classificar(
    true, 10,
    5, 4, // esta é a quinta leitura válida; incorreta anterior não consome unidade
    10, 4,
    false,
);
if ($validProductCompletesQuotaAfterWrongProduct["result"] !== "VALIDO"
    || $validProductCompletesQuotaAfterWrongProduct["product_quota_reached"] !== true
) {
    throw new RuntimeException("Leitura correta deve completar a cota sem ser bloqueada por saco incorreto anterior.");
}

echo "ok\n";
