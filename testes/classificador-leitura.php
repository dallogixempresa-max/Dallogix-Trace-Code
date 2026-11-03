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
    ["passagem sem código não recebe produto", false, null, 0, 0, 10, 4, true, "SEM_LEITURA"],
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

echo "ok\n";
