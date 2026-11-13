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

$knownProductOutsideManifestAtLoadingQuota = $classifier->classificar(
    true, 30,
    0, 0,              // produto reconhecido, mas ausente do romaneio
    10, 9,             // 9 leituras válidas
    false,             // sem sinal físico de excesso
    0, 0,              // sem leituras sem código
    0, 1,              // 1 PRODUTO_INCORRETO anterior completa a cota do romaneio
);
if ($knownProductOutsideManifestAtLoadingQuota["result"] !== "EXCESSO"
    || !$knownProductOutsideManifestAtLoadingQuota["wrong_product_detected"]
    || $knownProductOutsideManifestAtLoadingQuota["no_read_evidence_required"]
) {
    throw new RuntimeException(
        "Produto incorreto após a cota física do romaneio deve ser EXCESSO, " .
        "obtido: {$knownProductOutsideManifestAtLoadingQuota['result']}"
    );
}

// --- Casos PRODUTO_INCORRETO contam para a cota (loadingProdutoIncorretoCount) ---

// Cota total do romaneio atingida com sacas incorretas: a próxima leitura sem código é EXCESSO.
// Situação: 10 previstas, 9 válidas + 1 incorreta = 10 passagens físicas => cota atingida.
$semLeituraComCotaRomaneioAtingidaPorIncorretas = $classifier->classificar(
    false, null,
    0, 0,              // produto: sem alvo (SEM_LEITURA path)
    10, 9,             // romaneio: 10 previstas, 9 válidas
    false,             // sem physicalExcess
    0, 0,              // SEM_LEITURA por produto/romaneio
    0, 1,              // loadingProdutoIncorretoCount = 1 → loadingPassageCount = 9+0+1 = 10
);
if ($semLeituraComCotaRomaneioAtingidaPorIncorretas["result"] !== "EXCESSO") {
    throw new RuntimeException(
        "Passagem sem código com cota total atingida por incorretas deve ser EXCESSO, " .
        "obtido: {$semLeituraComCotaRomaneioAtingidaPorIncorretas['result']}"
    );
}

// Cota por produto atingida com sacas incorretas do mesmo produto: próxima válida é EXCESSO.
// Situação: produto A tem 5 previstas, 3 válidas + 1 incorreta = 4 passagens físicas;
// romaneio tem 10 previstas e apenas 4 passagens totais => cota do produto (5) não atingida
// pelos válidos sozinhos, mas productPassageCount = 3+0+1 = 4 < 5 → ainda VALIDO.
$validoComQuotaProdutoNaoAtingida = $classifier->classificar(
    true, 10,
    5, 3,              // produto: 5 previstas, 3 válidas
    10, 3,             // romaneio: 10 previstas, 3 válidas totais
    false,
    0, 0,              // SEM_LEITURA
    1, 1,              // productProdutoIncorretoCount=1, loadingProdutoIncorretoCount=1 → productPassageCount=4 < 5
);
if ($validoComQuotaProdutoNaoAtingida["result"] !== "VALIDO") {
    throw new RuntimeException(
        "Produto com 4 passagens físicas (3 válidas + 1 incorreta) de 5 previstas deve ser VALIDO, " .
        "obtido: {$validoComQuotaProdutoNaoAtingida['result']}"
    );
}

// Cota por produto exatamente atingida com incorretas: próxima leitura válida é EXCESSO.
// Situação: produto A tem 5 previstas, 4 válidas + 1 incorreta = 5 passagens físicas => EXCESSO.
$excessoPorCotaProdutoAtingidaComIncorretas = $classifier->classificar(
    true, 10,
    5, 4,              // produto: 5 previstas, 4 válidas
    10, 4,             // romaneio: 10 previstas, 4 válidas totais
    false,
    0, 0,
    1, 1,              // productProdutoIncorretoCount=1 → productPassageCount=5 >= 5
);
if ($excessoPorCotaProdutoAtingidaComIncorretas["result"] !== "EXCESSO") {
    throw new RuntimeException(
        "Produto com 5 passagens físicas (4 válidas + 1 incorreta) de 5 previstas deve ser EXCESSO, " .
        "obtido: {$excessoPorCotaProdutoAtingidaComIncorretas['result']}"
    );
}

// productQuotaReached deve incluir incorretas no cálculo da sinalização de cota quase atingida.
// Situação: produto A tem 5 previstas, 3 válidas + 1 incorreta = 4 passagens; esta é a 4ª válida
// real, mas productPassageCount+1 = 4+1 = 5 >= 5 → quota_reached deve ser true.
$quotaReachedComIncorretas = $classifier->classificar(
    true, 10,
    5, 3,              // produto: 5 previstas, 3 válidas (esta leitura será a 4ª válida)
    10, 3,
    false,
    0, 0,
    1, 1,              // 1 incorreta → productPassageCount atual=4; +1 desta leitura=5 >= 5
);
if ($quotaReachedComIncorretas["result"] !== "VALIDO"
    || $quotaReachedComIncorretas["product_quota_reached"] !== true
) {
    throw new RuntimeException(
        "product_quota_reached deve ser true quando passagens físicas (incluindo incorretas) " .
        "atingem a cota com esta leitura. result={$quotaReachedComIncorretas['result']}, " .
        "quota_reached=" . ($quotaReachedComIncorretas["product_quota_reached"] ? "true" : "false")
    );
}

echo "ok\n";
