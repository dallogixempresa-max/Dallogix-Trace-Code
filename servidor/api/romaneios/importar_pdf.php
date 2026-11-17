<?php
declare(strict_types=1);

use function App\Aplicacao\pdf_extract_text;
use function App\Aplicacao\pdf_field_value;
use function App\Aplicacao\pdf_normalize;
use function App\Aplicacao\pdf_parse_items;

require_once __DIR__ . "/../../configuracao/bootstrap.php";

// Importação de pedido PDF: os rótulos do cabeçalho e das colunas da tabela são
// configuráveis por empresa. O resultado preenche o formulário para revisão;
// a criação do romaneio continua sendo confirmada pelo operador.

require_once __DIR__ . "/../../src/Aplicacao/ImportadorRomaneioPdf.php";

$user = require_session_user();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
if (!in_array($user["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    json_response(
        ["error" => "Perfil sem permissão para importar romaneio."],
        403,
    );
}

$file = $_FILES["file"] ?? null;
if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"] ?? "")) {
    json_response(["error" => "Selecione o arquivo PDF do romaneio."], 422);
}
if (($file["size"] ?? 0) < 1 || ($file["size"] ?? 0) > 6 * 1024 * 1024) {
    json_response(["error" => "Arquivo maior que 6 MB."], 422);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
if ($mime !== "application/pdf") {
    json_response(["error" => "Tipo de arquivo inválido: envie um PDF."], 422);
}
$originalName = (string) ($file["name"] ?? "");
if (!str_ends_with(strtolower($originalName), ".pdf")) {
    json_response(["error" => "Formato inválido: envie um arquivo PDF."], 422);
}

$binary = (string) file_get_contents($file["tmp_name"]);
if (!str_starts_with($binary, "%PDF-")) {
    json_response(["error" => "Arquivo não parece um PDF válido."], 422);
}

set_time_limit(5);

$text = pdf_extract_text($binary);
if (trim($text) === "") {
    json_response(
        [
            "error" =>
                "Não foi possível extrair texto do PDF. O arquivo pode ser digitalizado (imagem) — utilize o cadastro manual.",
        ],
        422,
    );
}
$pdo = db();
$settingsStatement = $pdo->prepare(
    "SELECT pdf_field_mapping, pdf_search_field FROM configuracoes_empresa WHERE company_id = :company_id LIMIT 1",
);
$settingsStatement->execute(["company_id" => $user["company_id"]]);
$settings = $settingsStatement->fetch() ?: [];
$mapping = is_string($settings["pdf_field_mapping"] ?? null)
    ? json_decode((string) $settings["pdf_field_mapping"], true)
    : $settings["pdf_field_mapping"] ?? [];
if (!is_array($mapping)) {
    $mapping = [];
}
// Rótulos padrão quando a empresa ainda não configurou o mapeamento.
$defaultMapping = [
    "codigo" => "Código",
    "data" => "Data",
    "expedidor" => "Expedidor",
    "placa" => "Placa",
    "motorista" => "Motorista",
    "produto" => "Referência",
    "quantidade" => "Quantidade",
    "descricao" => "Descrição",
];
$searchField =
    ($settings["pdf_search_field"] ?? "barcode") === "sku" ? "sku" : "barcode";

$fields = [];
// Os nomes dos campos são definidos por empresa. Os aliases cobrem variações
// comuns sem impedir que cada operação personalize o próprio modelo.
$fieldAliases = [
    "codigo" => ["Ordem de Carga", "Número do pedido", "Pedido"],
    "data" => ["Data do carregamento", "Data de entrega"],
    "expedidor" => ["Empresa"],
    "placa" => ["Veículo", "Caminhão"],
    "motorista" => [],
];
foreach (["codigo", "data", "expedidor", "placa", "motorista"] as $key) {
    $label = trim((string) ($mapping[$key] ?? $defaultMapping[$key]));
    if ($label === "" || mb_strlen($label, "UTF-8") > 80) {
        continue;
    }
    foreach (array_values(array_unique([$label, ...($fieldAliases[$key] ?? [])])) as $candidateLabel) {
        $value = pdf_field_value($text, $candidateLabel);
        if ($value !== null && trim($value) !== "") {
            $fields[$key] = trim($value);
            break;
        }
    }
}

// Só usa datas associadas a um rótulo configurado; a data de impressão do PDF
// não é presumida como data de carregamento.
if (
    isset($fields["data"]) &&
    preg_match("/(\d{2})\/(\d{2})\/(\d{4})/", $fields["data"], $match)
) {
    $fields["data_iso"] = "{$match[3]}-{$match[2]}-{$match[1]}";
} elseif (isset($fields["data"]) && preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $fields["data"], $match)) {
    $fields["data_iso"] = "{$match[1]}-{$match[2]}-{$match[3]}";
}

$productLookup = [];
if ($searchField === "sku") {
    $productStatement = $pdo->prepare(
        "SELECT id, code AS identifier, code, name FROM produtos WHERE company_id = :company_id AND active = 1 AND code IS NOT NULL AND code <> ''",
    );
} else {
    $productStatement = $pdo->prepare(
        "SELECT p.id, pc.barcode AS identifier, p.code, p.name
         FROM codigos_produtos pc
         JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
         WHERE pc.company_id = :company_id
           AND pc.barcode IS NOT NULL
           AND pc.barcode <> ''
           AND p.active = 1",
    );
}
$productStatement->execute(["company_id" => $user["company_id"]]);
foreach ($productStatement->fetchAll() as $productRow) {
    $identifier = trim((string) ($productRow["identifier"] ?? ""));
    $normalizedIdentifier = pdf_normalize($identifier);
    if ($normalizedIdentifier !== "" && !isset($productLookup[$normalizedIdentifier])) {
        $productLookup[$normalizedIdentifier] = [
            "id" => (int) $productRow["id"],
            "name" => (string) $productRow["name"],
            "code" => (string) ($productRow["code"] ?? ""),
        ];
    }
}

$productLabel = trim((string) ($mapping["produto"] ?? $defaultMapping["produto"]));
$quantityLabel = trim((string) ($mapping["quantidade"] ?? $defaultMapping["quantidade"]));
$descriptionLabel = trim((string) ($mapping["descricao"] ?? $defaultMapping["descricao"]));
try {
    $items = pdf_parse_items($text, $productLabel, $quantityLabel, $descriptionLabel, $productLookup, $searchField);
} catch (RuntimeException $exception) {
    json_response(["error" => $exception->getMessage()], 422);
}
$missingFields = array_values(array_diff(
    ["codigo", "data", "expedidor", "placa", "motorista"],
    array_keys($fields),
));
$matchedItems = count(array_filter($items, static fn(array $item): bool => $item["product_id"] !== null));
$unmatchedItems = count($items) - $matchedItems;
$quantityTotal = array_sum(array_column($items, "quantity"));

$pdo->beginTransaction();
try {
    record_operational_event($pdo, $user, "ROMANEIO_PDF_IMPORTADO", "romaneio", 0, [
        "campos_encontrados" => array_keys($fields),
        "linhas_de_itens" => count($items),
        "itens_reconhecidos" => $matchedItems,
        "itens_pendentes" => $unmatchedItems,
        "quantidade_total" => $quantityTotal,
        "campo_de_busca_produto" => $searchField,
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

json_response([
    "data" => [
        "fields" => $fields,
        "missing" => $missingFields,
        "items" => $items,
        "matched_items" => $matchedItems,
        "unmatched_items" => $unmatchedItems,
        "quantity_total" => $quantityTotal,
        "search_field" => $searchField,
    ],
]);
