<?php
declare(strict_types=1);

require_once __DIR__ . "/../servidor/src/Aplicacao/ImportadorRomaneioPdf.php";

use function App\Aplicacao\pdf_extract_text;
use function App\Aplicacao\pdf_field_value;
use function App\Aplicacao\pdf_normalize;
use function App\Aplicacao\pdf_parse_items;
use function App\Aplicacao\pdf_quantity_line;

function verificarImportacaoPdf(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = <<<'PDFTEXT'
PEDIDO DE ENTREGA AGRUPADO
Empresa:
Nutridani Alimentos Pet
Ordem de Carga.: 3978
PRODUTOS
DESCRIÇÃO
QTD
REFERÊNCIA
ANOTAÇÃO
6
R. CAES ADULTO BILLY DOG 15 KG
90,00
101L1
7890000000001
7890000000002
R. GATOS QUARTZ 15 KG
3
4
R. CAES ADULTO BILLY DOG 15 KG
7890000000001
5
R. PRODUTO SEM CADASTRO
7890000000999
TOTAL
18
PDFTEXT;

$catalog = [
    pdf_normalize("7890000000001") => ["id" => 11, "name" => "Billy Dog"],
    pdf_normalize("7890000000002") => ["id" => 12, "name" => "Quartz Gatos"],
];
$fields = [
    "codigo" => pdf_field_value($fixture, "Ordem de Carga"),
    "expedidor" => pdf_field_value($fixture, "Empresa"),
];
verificarImportacaoPdf($fields["codigo"] === "3978", "Não extraiu o número do pedido no mesmo rótulo.");
verificarImportacaoPdf($fields["expedidor"] === "Nutridani Alimentos Pet", "Não extraiu o valor na linha após o rótulo.");
verificarImportacaoPdf(pdf_normalize("NÃO É DOCUMENTO FISCAL") === "NAO E DOCUMENTO FISCAL", "Não normalizou acentos portugueses para reconhecer o rodapé.");
verificarImportacaoPdf(pdf_quantity_line("15 KG", []) === null, "Interpretou o peso da embalagem como quantidade de sacas.");

$items = pdf_parse_items($fixture, "Referência", "Quantidade", "Descrição", $catalog, "barcode");
verificarImportacaoPdf(count($items) === 3, "Não consolidou os itens em três produtos.");
verificarImportacaoPdf($items[0]["product_id"] === 11 && $items[0]["quantity"] === 10, "Não somou linhas repetidas do mesmo produto.");
verificarImportacaoPdf($items[1]["product_id"] === 12 && $items[1]["quantity"] === 3, "Não associou quantidade que aparece depois do código.");
verificarImportacaoPdf($items[2]["product_id"] === null && $items[2]["identifier"] === "7890000000999" && $items[2]["quantity"] === 5, "Não preservou produto sem cadastro para revisão manual.");
verificarImportacaoPdf(array_sum(array_column($items, "quantity")) === 18, "O total do pedido não corresponde às linhas importadas.");
echo "ok — fixture de importação PDF\n";

if (isset($argv[1])) {
    $path = $argv[1];
    $binary = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($binary) || !str_starts_with($binary, "%PDF-")) {
        throw new RuntimeException("O arquivo PDF informado não pôde ser lido.");
    }
    $text = pdf_extract_text($binary);
    $orderNumber = pdf_field_value($text, "Ordem de Carga");
    $company = pdf_field_value($text, "Empresa");
    preg_match_all('/(?<!\d)\d{8,14}(?!\d)/u', $text, $matches);
    $catalog = [];
    foreach (array_values(array_unique($matches[0])) as $index => $barcode) {
        $catalog[pdf_normalize($barcode)] = ["id" => $index + 1, "name" => "Produto de teste {$index}"];
    }
    $items = pdf_parse_items($text, "Referência", "Quantidade", "Descrição", $catalog, "barcode");
    $quantityTotal = array_sum(array_column($items, "quantity"));
    verificarImportacaoPdf($orderNumber === "3978", "O PDF real não retornou o pedido 3978.");
    verificarImportacaoPdf($company === "Nutridani Alimentos Pet", "O PDF real não retornou a empresa esperada.");
    verificarImportacaoPdf(count($items) >= 10, "O PDF real retornou poucos itens.");
    verificarImportacaoPdf($quantityTotal === 1421, "O total de sacas extraído do PDF real não é 1.421.");
    echo "ok — PDF 3978: " . count($items) . " itens consolidados, {$quantityTotal} sacas\n";
}
