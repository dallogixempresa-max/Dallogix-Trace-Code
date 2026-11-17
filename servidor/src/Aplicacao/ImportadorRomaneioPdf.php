<?php
declare(strict_types=1);

namespace App\Aplicacao;

/**
 * Extrai o texto visível de um conteúdo de stream PDF (operadores Tj, TJ, ' e ").
 */
function pdf_text_from_content(string $content): string
{
    // Defesa em profundidade: limite explícito de backtracking para isolar esta
    // função de configurações externas, sem depender do valor global padrão do PHP.
    ini_set('pcre.backtrack_limit', '100000');
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
    if (!mb_check_encoding($text, "UTF-8")) {
        $text = mb_convert_encoding($text, "UTF-8", "Windows-1252");
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
    $value = strtr($value, [
        "á" => "a", "à" => "a", "ã" => "a", "â" => "a", "ä" => "a",
        "Á" => "A", "À" => "A", "Ã" => "A", "Â" => "A", "Ä" => "A",
        "é" => "e", "è" => "e", "ê" => "e", "ë" => "e",
        "É" => "E", "È" => "E", "Ê" => "E", "Ë" => "E",
        "í" => "i", "ì" => "i", "î" => "i", "ï" => "i",
        "Í" => "I", "Ì" => "I", "Î" => "I", "Ï" => "I",
        "ó" => "o", "ò" => "o", "õ" => "o", "ô" => "o", "ö" => "o",
        "Ó" => "O", "Ò" => "O", "Õ" => "O", "Ô" => "O", "Ö" => "O",
        "ú" => "u", "ù" => "u", "û" => "u", "ü" => "u",
        "Ú" => "U", "Ù" => "U", "Û" => "U", "Ü" => "U",
        "ç" => "c", "Ç" => "C", "ñ" => "n", "Ñ" => "N",
    ]);
    $transliterated = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);
    if ($transliterated !== false) {
        $value = $transliterated;
    }
    return mb_strtoupper(trim($value), "UTF-8");
}

/**
 * Localiza o valor após um rótulo dentro do texto normalizado por linhas.
 */
function pdf_field_value(string $text, string $label): ?string
{
    $needle = pdf_normalize($label);
    if ($needle === "") {
        return null;
    }
    $lines = preg_split('/\r?\n/u', $text) ?: [];
    foreach ($lines as $index => $line) {
        $normalizedLine = pdf_normalize($line);
        $position = mb_strpos($normalizedLine, $needle, 0, "UTF-8");
        if ($position === false) {
            continue;
        }
        $rest = trim(mb_substr($line, $position + mb_strlen($needle, "UTF-8"), null, "UTF-8"));
        $rest = ltrim($rest, ":;-–. \t");
        if ($rest !== "") {
            return trim($rest);
        }
        for ($next = $index + 1; $next < count($lines); $next++) {
            $candidate = trim($lines[$next]);
            if ($candidate === "" || preg_match('/:\s*$/u', $candidate) === 1) {
                continue;
            }
            return $candidate;
        }
    }
    return null;
}

function pdf_line_matches_label(string $line, string $label): bool
{
    $needle = pdf_normalize($label);
    if ($needle === "") {
        return false;
    }
    $normalizedLine = pdf_normalize($line);
    if ($normalizedLine === $needle) {
        return true;
    }
    return preg_match(
        '/(?<![\pL\pN])' . preg_quote($needle, '/') . '(?![\pL\pN.])/u',
        $normalizedLine,
    ) === 1;
}

function pdf_find_label_line(array $lines, array $labels): ?int
{
    foreach ($lines as $index => $line) {
        foreach ($labels as $label) {
            if (is_string($label) && trim($label) !== "" && pdf_line_matches_label($line, $label)) {
                return $index;
            }
        }
    }
    return null;
}

function pdf_item_section_noise(string $line): string
{
    $normalized = pdf_normalize($line);
    if ($normalized === "") {
        return "skip";
    }
    if (
        preg_match('/\bTOTAL\b|\bASSINATURA\b|NAO E DOCUMENTO FISCAL|PESO BRUTO/u', $normalized) === 1
    ) {
        return "stop";
    }
    if (
        preg_match('/\A(?:PAG\.?|PAGE\b|ORDEM DE CARGA\b)/u', $normalized) === 1 ||
        preg_match('/\A\d{2}\/\d{2}\/\d{4}\b/u', $normalized) === 1 ||
        in_array(
            $normalized,
            ["PRODUTOS", "DESCRICAO", "LOTE", "PESO", "LOTE ALT.", "QTD.ALT.", "ANOTACAO"],
            true,
        )
    ) {
        return "skip";
    }
    return "";
}

function pdf_quantity_line(string $line, array $productLookup): ?array
{
    $line = trim($line);
    if ($line === "" || isset($productLookup[pdf_normalize($line)])) {
        return null;
    }
    if (preg_match('/\A([1-9][0-9]{0,5})\z/u', $line, $matches) !== 1) {
        return null;
    }
    return [
        "quantity" => (int) $matches[1],
        "inline" => "",
    ];
}

function pdf_item_identifier_on_line(string $line, array $productLookup, string $searchField): ?array
{
    if ($searchField === "barcode" && preg_match_all('/(?<!\d)\d{8,14}(?!\d)/u', $line, $barcodes)) {
        foreach ($barcodes[0] as $barcode) {
            $normalizedBarcode = pdf_normalize($barcode);
            if (isset($productLookup[$normalizedBarcode])) {
                return ["identifier" => $barcode, "product" => $productLookup[$normalizedBarcode]];
            }
            return ["identifier" => $barcode, "product" => null];
        }
    }
    foreach (preg_split('/\s+/u', trim($line)) ?: [] as $token) {
        $token = trim($token, " \t\r\n.,;:()[]{}");
        $normalized = pdf_normalize($token);
        if ($normalized !== "" && isset($productLookup[$normalized])) {
            return ["identifier" => $token, "product" => $productLookup[$normalized]];
        }
    }
    if ($searchField === "barcode") {
        $token = trim($line);
        if (preg_match('/\A\d{8,14}\z/u', $token) === 1) {
            return ["identifier" => $token, "product" => null];
        }
    } else {
        $token = trim($line);
        if (
            preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{1,31}\z/i', $token) === 1 &&
            preg_match('/\d/u', $token) === 1 &&
            preg_match('/\A\d{2,5}L\d{1,3}\z/i', $token) !== 1
        ) {
            return ["identifier" => $token, "product" => null];
        }
    }
    return null;
}

function pdf_item_identifier(array $lines, array $productLookup, string $searchField): array
{
    foreach ($lines as $line) {
        $identifier = pdf_item_identifier_on_line((string) $line, $productLookup, $searchField);
        if ($identifier !== null) {
            return $identifier;
        }
    }
    return ["identifier" => "", "product" => null];
}

function pdf_item_description(array $lines, array $productLookup, string $identifier = ""): string
{
    $parts = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (
            $line === "" ||
            pdf_item_section_noise($line) !== "" ||
            preg_match('/\A\d+(?:[.,]\d+)?\z/u', $line) === 1 ||
            preg_match('/\A\d{8,14}\z/u', $line) === 1 ||
            preg_match('/\A\d{3,5}L\d{1,3}\z/i', $line) === 1 ||
            ($identifier !== "" && str_contains($line, $identifier)) ||
            isset($productLookup[pdf_normalize($line)])
        ) {
            continue;
        }
        $parts[] = $line;
    }
    $description = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? implode(' ', $parts));
    return mb_substr($description, 0, 240, "UTF-8");
}

function pdf_parse_item_group(array $group, array $productLookup, string $searchField): ?array
{
    if (($group["quantity"] ?? 0) < 1) {
        return null;
    }
    $lines = $group["lines"] ?? [];
    $identifier = pdf_item_identifier($lines, $productLookup, $searchField);
    $description = pdf_item_description($lines, $productLookup, $identifier["identifier"]);
    if ($identifier["identifier"] === "" && $description === "") {
        return null;
    }
    $product = $identifier["product"];
    return [
        "product_id" => $product ? (int) $product["id"] : null,
        "quantity" => (int) $group["quantity"],
        "identifier" => $identifier["identifier"],
        "description" => $description !== "" ? $description : (string) ($product["name"] ?? ""),
    ];
}

function pdf_parse_items(
    string $text,
    string $productLabel,
    string $quantityLabel,
    string $descriptionLabel,
    array $productLookup,
    string $searchField,
): array {
    $lines = preg_split('/\r?\n/u', $text) ?: [];
    $productHeader = pdf_find_label_line($lines, array_values(array_unique([
        $productLabel,
        "Referência",
        "Código de barras",
        "SKU",
        "EAN",
    ])));
    $quantityHeader = pdf_find_label_line($lines, array_values(array_unique([
        $quantityLabel,
        "Quantidade",
        "QTD",
        "QTDE",
    ])));
    if ($productHeader === null || $quantityHeader === null) {
        throw new RuntimeException(
            "Não localizei os cabeçalhos das colunas de produto e quantidade. Ajuste os campos do importador PDF nas configurações desta empresa.",
        );
    }

    $descriptionHeader = pdf_find_label_line($lines, array_values(array_unique([
        $descriptionLabel,
        "Descrição",
    ])));
    $groups = [];
    $current = null;
    $headerLines = array_filter([$productHeader, $quantityHeader, $descriptionHeader], static fn($value): bool => $value !== null);
    $firstItemLine = max($headerLines) + 1;
    for ($index = $firstItemLine; $index < count($lines); $index++) {
        $line = trim($lines[$index]);
        $noise = pdf_item_section_noise($line);
        if ($noise === "stop") {
            break;
        }
        if ($noise === "skip") {
            continue;
        }
        if (
            pdf_line_matches_label($line, $productLabel) ||
            pdf_line_matches_label($line, $quantityLabel) ||
            pdf_line_matches_label($line, $descriptionLabel) ||
            pdf_line_matches_label($line, "Referência") ||
            pdf_line_matches_label($line, "Código de barras") ||
            pdf_line_matches_label($line, "SKU") ||
            pdf_line_matches_label($line, "EAN") ||
            pdf_line_matches_label($line, "Quantidade") ||
            pdf_line_matches_label($line, "QTD") ||
            pdf_line_matches_label($line, "QTDE")
        ) {
            continue;
        }
        $identifierOnLine = pdf_item_identifier_on_line($line, $productLookup, $searchField);
        if ($current !== null && $identifierOnLine !== null) {
            $currentIdentifier = pdf_item_identifier($current["lines"], $productLookup, $searchField);
            if ($currentIdentifier["identifier"] !== "") {
                $groups[] = $current;
                $current = ["quantity" => 0, "lines" => []];
            }
        }

        $quantity = pdf_quantity_line($line, $productLookup);
        if ($quantity !== null) {
            if ($current !== null && $current["quantity"] > 0) {
                $groups[] = $current;
                $current = ["quantity" => 0, "lines" => []];
            }
            if ($current === null) {
                $current = ["quantity" => 0, "lines" => []];
            }
            $current["quantity"] = $quantity["quantity"];
            if ($quantity["inline"] !== "") {
                $current["lines"][] = $quantity["inline"];
            }
            continue;
        }
        if ($current === null) {
            $current = ["quantity" => 0, "lines" => []];
        }
        $current["lines"][] = $line;
    }
    if ($current !== null) {
        $groups[] = $current;
    }

    $parsed = [];
    foreach ($groups as $group) {
        $item = pdf_parse_item_group($group, $productLookup, $searchField);
        if ($item !== null) {
            $parsed[] = $item;
        }
    }
    if ($parsed === []) {
        throw new RuntimeException(
            "Não consegui identificar linhas de produtos no PDF. Confira os cabeçalhos configurados para esta empresa.",
        );
    }

    $consolidated = [];
    foreach ($parsed as $item) {
        $key = $item["product_id"] !== null
            ? "product:" . $item["product_id"]
            : ($item["identifier"] !== ""
                ? "identifier:" . pdf_normalize($item["identifier"])
                : "description:" . pdf_normalize($item["description"]));
        if (!isset($consolidated[$key])) {
            $consolidated[$key] = $item;
            continue;
        }
        $consolidated[$key]["quantity"] += $item["quantity"];
        if (
            $item["description"] !== "" &&
            !str_contains($consolidated[$key]["description"], $item["description"])
        ) {
            $consolidated[$key]["description"] = mb_substr(
                $consolidated[$key]["description"] . " · " . $item["description"],
                0,
                240,
                "UTF-8",
            );
        }
    }
    if (count($consolidated) > 100) {
        throw new RuntimeException("O PDF contém mais de 100 itens diferentes. Divida o pedido antes de importar.");
    }
    return array_values($consolidated);
}
