<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

// Importação de romaneio em PDF (padrão da referência TracePlatform).
// Recebe o PDF, extrai o texto (best-effort, sem dependências externas) e localiza os
// valores dos campos a partir dos rótulos configurados em Configurações > Importação PDF.
// PENDENTE DE CONFIRMAÇÃO: layout definitivo dos romaneios em PDF de cada cliente —
// o mapeamento de rótulos é configurável justamente para acomodar variações.

/**
 * Extrai o texto visível de um conteúdo de stream PDF (operadores Tj, TJ, ' e ").
 */
function pdf_text_from_content(string $content): string
{
    $text = "";
    // Blocos [...] TJ e strings (...) seguidas de Tj / ' / ".
    if (
        !preg_match_all(
            '/\((?:\\\\.|[^\\\\()])*\)\s*(?:Tj|\'|\")|\[(?:[^\[\]]|\\\\.)*\]\s*TJ/',
            $content,
            $blocks,
        )
    ) {
        return "";
    }
    foreach ($blocks[0] as $block) {
        if (!preg_match_all("/\((?:\\\\.|[^\\\\()])*\)/", $block, $strings)) {
            continue;
        }
        foreach ($strings[0] as $raw) {
            $value = substr($raw, 1, -1);
            $value = str_replace(
                ["\\(", "\\)", "\\\\"],
                ["(", ")", "\\"],
                $value,
            );
            $value =
                preg_replace_callback(
                    "/\\\\([0-7]{1,3})/",
                    static fn($match) => chr(octdec($match[1])),
                    $value,
                ) ?? $value;
            $text .= $value;
        }
        $text .= "\n";
    }
    return $text;
}

/**
 * Concatena o texto de todos os streams do PDF (com e sem compressão FlateDecode).
 */
function pdf_extract_text(string $binary): string
{
    $maxInflatedStreamBytes = 4 * 1024 * 1024;
    $maxInflatedTotalBytes = 16 * 1024 * 1024;
    $text = "";
    $streams = [1 => []];
    $offset = 0;
    while (preg_match('/stream\r?\n/s', $binary, $marker, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $marker[0][1] + strlen($marker[0][0]);
        $end = strpos($binary, "endstream", $start);
        if ($end === false) {
            break;
        }
        $length = $end - $start;
        if ($length > $maxInflatedStreamBytes) {
            throw new RuntimeException("Stream do PDF excede o limite permitido.");
        }
        $streams[1][] = rtrim(substr($binary, $start, $length), "\r\n");
        $offset = $end + strlen("endstream");
    }
    if ($streams[1] === []) {
        return "";
    }
    $inflatedTotal = 0;
    foreach ($streams[1] as $stream) {
        if (strlen($stream) > $maxInflatedStreamBytes) {
            throw new RuntimeException("Stream do PDF excede o limite permitido.");
        }
        // PDFs FlateDecode normalmente carregam zlib completo; aceite também
        // o formato raw usado por alguns geradores.
        $inflated = @gzuncompress($stream, $maxInflatedStreamBytes);
        if ($inflated === false) {
            $inflated = @gzinflate($stream, $maxInflatedStreamBytes);
        }
        if ($inflated === false) {
            $inflated = @gzuncompress(rtrim($stream, "\r\n"), $maxInflatedStreamBytes);
        }
        if ($inflated === false) {
            $inflated = @gzinflate(rtrim($stream, "\r\n"), $maxInflatedStreamBytes);
        }
        $content = $inflated !== false ? $inflated : $stream;
        $inflatedTotal += strlen($content);
        if ($inflatedTotal > $maxInflatedTotalBytes) {
            throw new RuntimeException("Conteúdo descompactado do PDF excede o limite permitido.");
        }
        if (
            $inflated === false &&
            !preg_match('/\)\s*(?:Tj|TJ|\'|\")/', $content)
        ) {
            continue; // stream sem texto (imagens, fontes, etc.)
        }
        $text .= pdf_text_from_content($content);
    }
    return $text;
}

/**
 * Normaliza o texto para busca de rótulos (colapsa espaços, remove acentos, caixa alta).
 */
function pdf_normalize(string $value): string
{
    // Preserve quebras de linha: pdf_field_value usa cada linha para separar rótulo e valor.
    $value = preg_replace("/[ \t]+/u", " ", $value) ?? $value;
    $transliterated = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);
    if ($transliterated !== false) {
        $value = $transliterated;
    }
    return mb_strtoupper(trim($value), "UTF-8");
}

/**
 * Localiza o valor após um rótulo dentro do texto normalizado por linhas.
 */
function pdf_field_value(string $normalizedText, string $label): ?string
{
    $needle = pdf_normalize($label);
    if ($needle === "") {
        return null;
    }
    foreach (preg_split('/\r?\n/', $normalizedText) ?: [] as $line) {
        $position = strpos($line, $needle);
        if ($position === false) {
            continue;
        }
        $rest = trim(substr($line, $position + strlen($needle)));
        $rest = ltrim($rest, ":;-–. \t");
        if ($rest !== "") {
            return trim($rest);
        }
    }
    return null;
}

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
$normalized = pdf_normalize($text);

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
];
$searchField =
    ($settings["pdf_search_field"] ?? "barcode") === "sku" ? "sku" : "barcode";

$fields = [];
foreach (
    [
        "codigo",
        "data",
        "expedidor",
        "placa",
        "motorista",
        "produto",
        "quantidade",
    ]
    as $key
) {
    $label = trim((string) ($mapping[$key] ?? $defaultMapping[$key]));
    if ($label === "" || mb_strlen($label, "UTF-8") > 80) {
        continue;
    }
    $value = pdf_field_value($normalized, $label);
    if ($value !== null) {
        $fields[$key] = $value;
    }
}

// Normalizações úteis para preencher o formulário.
if (
    isset($fields["data"]) &&
    preg_match("/(\d{2})\/(\d{2})\/(\d{4})/", $fields["data"], $match)
) {
    $fields["data_iso"] = "{$match[3]}-{$match[2]}-{$match[1]}";
}
if (
    isset($fields["quantidade"]) &&
    preg_match("/\d+/", str_replace(".", "", $fields["quantidade"]), $match)
) {
    $fields["quantidade_numero"] = (int) $match[0];
}

// Resolve o produto pelo identificador encontrado, conforme "Buscar produtos por".
$product = null;
if (isset($fields["produto"]) && $fields["produto"] !== "") {
    $identifier = $fields["produto"];
    if ($searchField === "sku") {
        $productStatement = $pdo->prepare(
            "SELECT id, code, name FROM produtos WHERE company_id = :company_id AND active = 1 AND code = :identifier LIMIT 1",
        );
        $productStatement->execute([
            "company_id" => $user["company_id"],
            "identifier" => $identifier,
        ]);
    } else {
        $productStatement = $pdo->prepare(
            "SELECT p.id, p.code, p.name
             FROM codigos_produtos pc
             JOIN produtos p ON p.id = pc.product_id AND p.company_id = pc.company_id
             WHERE pc.company_id = :company_id
               AND pc.barcode = :identifier
               AND p.active = 1
             LIMIT 1",
        );
        $productStatement->execute([
            "company_id" => $user["company_id"],
            "identifier" => $identifier,
        ]);
    }
    $found = $productStatement->fetch();
    $product = $found ?: null;
}

$pdo->beginTransaction();
try {
    record_operational_event($pdo, $user, "ROMANEIO_PDF_IMPORTADO", "romaneio", 0, [
        "campos_encontrados" => array_keys($fields),
        "produto_encontrado" => (bool) $product,
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
        "missing" => array_values(
            array_diff(
                [
                    "codigo",
                    "data",
                    "expedidor",
                    "placa",
                    "motorista",
                    "produto",
                    "quantidade",
                ],
                array_keys($fields),
            ),
        ),
        "product" => $product,
        "search_field" => $searchField,
    ],
]);
