<?php
declare(strict_types=1);

namespace App\Aplicacao;

require_once __DIR__ . "/DecodificadorImagemPng.php";

/**
 * Gerador PDF leve, sem dependência externa, para auditoria local/offline.
 * Mantém cabeçalho e rodapé em todas as páginas e evita que linhas de tabela
 * sejam separadas no meio durante a paginação.
 */
final class RelatorioAuditoriaPdf
{
    private const LARGURA = 595;
    private const ALTURA = 842;
    private const MARGEM_ESQUERDA_CONTEUDO = 40;
    private const MARGEM_DIREITA_CONTEUDO = 555;
    private const TOPO = 758;
    private const BASE = 56;
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $content = [];
    /** @var array<string, array{data:string,width:int,height:int,colorSpace:string,filter:string,colors:int,alpha?:array{data:string,width:int,height:int,colorSpace:string,filter:string,colors:int},sourceData?:string,sourceMime?:string}> */
    private array $images = [];
    private int $imageSequence = 0;
    private float $y = self::TOPO;

    public function __construct(
        private readonly string $companyName,
        private readonly string $reportTitle,
    ) {
        $this->novaPagina();
    }

    public function escreverTitulo(string $text): void
    {
        $this->garantirEspaco(76);
        $this->escreverTexto($text, self::MARGEM_ESQUERDA_CONTEUDO, $this->y, 12, true);
        $this->desenharLinha(
            self::MARGEM_ESQUERDA_CONTEUDO,
            $this->y - 8,
            self::MARGEM_DIREITA_CONTEUDO,
            $this->y - 8,
        );
        $this->y -= 26;
    }

    /** @param array<string, scalar|null> $rows */
    public function escreverCartoesResumo(array $rows): void
    {
        $gap = 14;
        $cellWidth = (self::MARGEM_DIREITA_CONTEUDO - self::MARGEM_ESQUERDA_CONTEUDO - $gap * 2) / 3;
        $pairs = array_chunk($rows, 3, true);
        foreach ($pairs as $pair) {
            $this->garantirEspaco(48);
            $x = (float) self::MARGEM_ESQUERDA_CONTEUDO;
            foreach ($pair as $label => $value) {
                $this->escreverTexto((string) $label, $x, $this->y, 7.5);
                foreach (
                    $this->quebrarTexto((string) ($value ?? "—"), max(12, (int) floor($cellWidth / 5.5)))
                    as $index => $line
                ) {
                    if ($index > 1) {
                        break;
                    }
                    $this->escreverTexto(
                        $line,
                        $x,
                        $this->y - 14 - $index * 10,
                        9.5,
                        true,
                    );
                }
                $this->desenharLinha($x, $this->y - 34, $x + $cellWidth, $this->y - 34);
                $x += $cellWidth + $gap;
            }
            $this->y -= 46;
        }
        $this->y -= 8;
    }

    /** @param list<string> $headers @param list<list<string|int|float|null>> $rows @param list<int> $widths */
    public function escreverTabela(array $headers, array $rows, array $widths): void
    {
        $this->escreverCabecalhoDaTabela($headers, $widths);
        foreach ($rows as $row) {
            $linesByCell = [];
            $lineCount = 1;
            foreach ($row as $index => $value) {
                $linesByCell[$index] = $this->quebrarTexto(
                    (string) ($value ?? "—"),
                    max(8, (int) floor($widths[$index] / 5.8)),
                );
                $lineCount = max($lineCount, count($linesByCell[$index]));
            }
            $height = max(18, $lineCount * 12 + 8);
            if ($this->y - $height < self::BASE) {
                $this->novaPagina();
                $this->escreverCabecalhoDaTabela($headers, $widths);
            }
            $x = (float) self::MARGEM_ESQUERDA_CONTEUDO;
            foreach ($widths as $index => $width) {
                foreach ($linesByCell[$index] as $lineIndex => $line) {
                    $this->escreverTexto(
                        $line,
                        $x + 4,
                        $this->y - 13 - $lineIndex * 12,
                        8.5,
                    );
                }
                $x += $width;
            }
            $this->desenharLinha(
                self::MARGEM_ESQUERDA_CONTEUDO,
                $this->y - $height,
                self::MARGEM_DIREITA_CONTEUDO,
                $this->y - $height,
            );
            $this->y -= $height;
        }
        $this->y -= 10;
    }

    public function escreverParagrafo(string $text): void
    {
        foreach ($this->quebrarTexto($text, 92) as $line) {
            $this->garantirEspaco(13);
            $this->escreverTexto($line, self::MARGEM_ESQUERDA_CONTEUDO, $this->y, 8.5);
            $this->y -= 13;
        }
        $this->y -= 3;
    }

    /**
     * Adiciona uma evidência JPEG ou PNG ao relatório.
     * Arquivos sem suporte ou ilegíveis não interrompem a geração do PDF.
     */
    public function inserirImagemOcorrencia(string $path, string $caption = ""): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $data = @file_get_contents($path);
        if ($data === false || $data === "") {
            return false;
        }
        $info = @getimagesizefromstring($data);
        if ($info === false || !in_array($info["mime"], ["image/jpeg", "image/png"], true)) {
            return false;
        }
        return $this->inserirDadosDaImagemDaOcorrencia($data, $info["mime"], $caption);
    }

    /**
     * Incorpora uma imagem JPEG ou PNG diretamente no PDF.
     * Quando $preserveSource é true, os bytes originais ficam em um fluxo interno
     * do PDF para permitir regenerar o relatório sem salvar a imagem separadamente.
     */
    public function inserirDadosDaImagemDaOcorrencia(
        string $data,
        string $mime,
        string $caption = "",
        bool $preserveSource = false,
    ): bool {
        if ($data === "" || strlen($data) > 5 * 1024 * 1024) {
            return false;
        }
        $info = @getimagesizefromstring($data);
        if ($info === false || $info["mime"] !== $mime
            || !in_array($mime, ["image/jpeg", "image/png"], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            return false;
        }
        $image = $mime === "image/jpeg"
            ? [
                "data" => $data,
                "width" => (int) $info[0],
                "height" => (int) $info[1],
                "colorSpace" => (($info["channels"] ?? 3) === 4 ? "/DeviceCMYK" : "/DeviceRGB"),
                "filter" => "DCTDecode",
                "colors" => (($info["channels"] ?? 3) === 4 ? 4 : 3),
            ]
            : DecodificadorImagemPng::decodificar($data);
        if ($image === null) {
            return false;
        }
        if ($preserveSource) {
            $image["sourceData"] = $data;
            $image["sourceMime"] = $mime;
        }
        $alias = "Im" . (++$this->imageSequence);
        $this->images[$alias] = $image;
        $maxWidth = 245.0;
        $maxHeight = 170.0;
        $scale = min($maxWidth / $info[0], $maxHeight / $info[1], 1.0);
        $width = $info[0] * $scale;
        $height = $info[1] * $scale;
        $blockHeight = $height + ($caption !== "" ? 28 : 8);
        $this->garantirEspaco($blockHeight);
        $x = (float) self::MARGEM_ESQUERDA_CONTEUDO;
        $y = $this->y - $height;
        $this->content[] = sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q",
            $width,
            $height,
            $x,
            $y,
            $alias,
        );
        if ($caption !== "") {
            foreach ($this->quebrarTexto($caption, 52) as $index => $line) {
                $this->escreverTexto($line, $x, $y - 14 - $index * 10, 8);
                if ($index >= 1) {
                    break;
                }
            }
        }
        $this->y -= $blockHeight;
        return true;
    }

    /** @return list<array{bytes:string,mime:string}> */
    public static function extrairImagensOriginaisDoPdf(string $pdf): array
    {
        $images = [];
        $cursor = 0;
        while (($marker = strpos($pdf, "/Type /TraceSourceImage", $cursor)) !== false) {
            $tail = substr($pdf, $marker);
            if (preg_match(
                '/\\A\/Type \/TraceSourceImage \/Subtype \/(JPEG|PNG) \/Length ([0-9]+) >>\\nstream\\n/',
                $tail,
                $matches,
            ) !== 1) {
                $cursor = $marker + 1;
                continue;
            }
            $length = (int) $matches[2];
            $dataStart = $marker + strlen($matches[0]);
            if ($length < 1 || $length > 5 * 1024 * 1024 || $dataStart + $length > strlen($pdf)) {
                $cursor = $marker + 1;
                continue;
            }
            $bytes = substr($pdf, $dataStart, $length);
            $mime = $matches[1] === "JPEG" ? "image/jpeg" : "image/png";
            $info = @getimagesizefromstring($bytes);
            if ($info !== false && $info["mime"] === $mime) {
                $images[] = ["bytes" => $bytes, "mime" => $mime];
            }
            $cursor = $dataStart + $length;
        }
        return $images;
    }

    /** @param array{data:string,width:int,height:int,colorSpace:string,filter:string,colors:int} $image */
    private static function criarObjetoDeImagemPdf(array $image, ?int $alphaReference = null): string
    {
        $decodeParameters = $image["filter"] === "FlateDecode"
            ? " /DecodeParms << /Predictor 15 /Colors {$image["colors"]} /BitsPerComponent 8 /Columns {$image["width"]} >>"
            : "";
        $softMask = $alphaReference === null ? "" : " /SMask {$alphaReference} 0 R";
        return "<< /Type /XObject /Subtype /Image /Width {$image["width"]} /Height {$image["height"]}"
            . " /ColorSpace {$image["colorSpace"]} /BitsPerComponent 8 /Filter /{$image["filter"]}"
            . $decodeParameters . $softMask . " /Length " . strlen($image["data"]) . " >>\nstream\n"
            . $image["data"] . "\nendstream";
    }

    public function obterArquivoPdf(): string
    {
        $this->finalizarPagina();
        $objects = ["<< /Type /Catalog /Pages 2 0 R >>", ""];
        $imageRefs = [];
        foreach ($this->images as $alias => $image) {
            $alphaReference = null;
            if (isset($image["alpha"])) {
                $alphaReference = count($objects) + 1;
                $objects[] = self::criarObjetoDeImagemPdf($image["alpha"]);
            }
            $imageId = count($objects) + 1;
            $imageRefs[$alias] = $imageId;
            $objects[] = self::criarObjetoDeImagemPdf($image, $alphaReference);
            if (isset($image["sourceData"], $image["sourceMime"])) {
                $subtype = $image["sourceMime"] === "image/jpeg" ? "JPEG" : "PNG";
                $objects[] = "<< /Type /TraceSourceImage /Subtype /{$subtype} /Length "
                    . strlen($image["sourceData"]) . " >>\nstream\n" . $image["sourceData"] . "\nendstream";
            }
        }
        $pageRefs = [];
        foreach ($this->pages as $page) {
            $contentId = count($objects) + 1;
            $objects[] =
                "<< /Length " .
                strlen($page) .
                " >>\nstream\n{$page}\nendstream";
            $pageId = count($objects) + 1;
            $pageRefs[] = "{$pageId} 0 R";
            $xObjects = "";
            foreach ($imageRefs as $alias => $imageId) {
                $xObjects .= "/{$alias} {$imageId} 0 R ";
            }
            $objects[] =
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " .
                self::LARGURA .
                " " .
                self::ALTURA .
                "] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> /F2 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >> >> /XObject << {$xObjects} >> >> /Contents {$contentId} 0 R >>";
        }
        $objects[1] =
            "<< /Type /Pages /Kids [" .
            implode(" ", $pageRefs) .
            "] /Count " .
            count($pageRefs) .
            " >>";
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= $index + 1 . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n ", $offsets[$index]) . "\n";
        }
        return $pdf .
            "trailer\n<< /Size " .
            (count($objects) + 1) .
            " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function novaPagina(): void
    {
        if ($this->content !== []) {
            $this->finalizarPagina();
        }
        $this->content = [];
        $this->y = self::TOPO;
        $this->escreverTexto(
            $this->companyName . " — Relatório de auditoria",
            self::MARGEM_ESQUERDA_CONTEUDO,
            812,
            9,
            true,
        );
        $this->escreverTexto($this->reportTitle, self::MARGEM_ESQUERDA_CONTEUDO, 796, 8);
        $this->desenharLinha(self::MARGEM_ESQUERDA_CONTEUDO, 782, self::MARGEM_DIREITA_CONTEUDO, 782);
        $this->y = self::TOPO;
    }

    private function finalizarPagina(): void
    {
        $page = count($this->pages) + 1;
        $this->desenharLinha(self::MARGEM_ESQUERDA_CONTEUDO, 34, self::MARGEM_DIREITA_CONTEUDO, 34);
        $this->escreverTexto("Dallogix Trace — Relatório de auditoria", self::MARGEM_ESQUERDA_CONTEUDO, 20, 8);
        $this->escreverTexto("Página " . $page, 500, 20, 8);
        $this->pages[] = implode("\n", $this->content);
        $this->content = [];
    }

    /** @param list<string> $headers @param list<int> $widths */
    private function escreverCabecalhoDaTabela(array $headers, array $widths): void
    {
        $this->garantirEspaco(48);
        $this->desenharRetangulo(
            self::MARGEM_ESQUERDA_CONTEUDO,
            $this->y - 22,
            array_sum($widths),
            22,
            true,
        );
        $x = (float) self::MARGEM_ESQUERDA_CONTEUDO;
        foreach ($headers as $index => $header) {
            $this->escreverTexto($header, $x + 4, $this->y - 14, 8, true);
            $x += $widths[$index];
        }
        $this->y -= 22;
    }

    private function garantirEspaco(float $height): void
    {
        if ($this->y - $height < self::BASE) {
            $this->novaPagina();
        }
    }
    private function escreverTexto(
        string $text,
        float $x,
        float $y,
        float $size,
        bool $bold = false,
    ): void {
        // The minimal production image has mbstring conversion tables but its
        // iconv build cannot convert to Windows-1252, which is used by WinAnsi.
        $encoded = mb_convert_encoding($text, "Windows-1252", "UTF-8");
        $encoded = str_replace(
            ["\\", "(", ")"],
            ["\\\\", "\\(", "\\)"],
            $encoded,
        );
        $this->content[] = sprintf(
            "BT /%s %.1F Tf %.1F %.1F Td (%s) Tj ET",
            $bold ? "F2" : "F1",
            $size,
            $x,
            $y,
            $encoded,
        );
    }
    private function desenharLinha(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->content[] = sprintf(
            "0.72 G %.1F %.1F m %.1F %.1F l S 0 G",
            $x1,
            $y1,
            $x2,
            $y2,
        );
    }
    private function desenharRetangulo(
        float $x,
        float $y,
        float $width,
        float $height,
        bool $fill,
    ): void {
        $this->content[] = sprintf(
            "%s %.1F %.1F %.1F %.1F re %s",
            $fill ? "0.94 g" : "0.84 G",
            $x,
            $y,
            $width,
            $height,
            $fill ? "f 0 g" : "S 0 G",
        );
    }
    /** @return list<string> */
    private function quebrarTexto(string $text, int $limit): array
    {
        $words = preg_split("/\s+/u", trim($text)) ?: ["—"];
        $lines = [];
        $current = "";
        foreach ($words as $word) {
            $candidate = $current === "" ? $word : $current . " " . $word;
            if (mb_strlen($candidate) > $limit && $current !== "") {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== "") {
            $lines[] = $current;
        }
        return $lines ?: ["—"];
    }
}
