<?php

declare(strict_types=1);

namespace App\Aplicacao;

/**
 * Converte PNG não interlaçado de 8 bits em recursos de imagem compatíveis com PDF.
 * Limita dimensões e memória inflada antes de reconstruir os pixels.
 */
final class DecodificadorImagemPng
{
    /** @return array{data:string,width:int,height:int,colorSpace:string,filter:string,colors:int,alpha?:array{data:string,width:int,height:int,colorSpace:string,filter:string,colors:int}}|null */
    public static function decodificar(string $png): ?array
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $offset = 8;
        $width = $height = $bitDepth = $colorType = $interlace = null;
        $palette = "";
        $transparency = "";
        $compressed = "";
        $length = strlen($png);
        while ($offset + 12 <= $length) {
            $chunkLength = unpack("N", substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $offset += 8;
            if ($chunkLength > $length - $offset - 4) {
                return null;
            }
            $chunk = substr($png, $offset, $chunkLength);
            $offset += $chunkLength + 4;
            if ($type === "IHDR" && $chunkLength === 13) {
                $header = unpack("Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace", $chunk);
                $width = (int) $header["width"];
                $height = (int) $header["height"];
                $bitDepth = (int) $header["bitDepth"];
                $colorType = (int) $header["colorType"];
                $interlace = (int) $header["interlace"];
                if ($header["compression"] !== 0 || $header["filter"] !== 0) {
                    return null;
                }
            } elseif ($type === "PLTE") {
                $palette = $chunk;
            } elseif ($type === "tRNS") {
                $transparency = $chunk;
            } elseif ($type === "IDAT") {
                $compressed .= $chunk;
            } elseif ($type === "IEND") {
                break;
            }
        }
        if (!$width || !$height || $width > 10000 || $height > 10000 || $width * $height > 6_000_000
            || $bitDepth !== 8 || $interlace !== 0 || $compressed === "") {
            return null;
        }
        $channels = match ($colorType) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            6 => 4,
            default => 0,
        };
        if ($channels === 0 || ($colorType === 3 && ($palette === "" || strlen($palette) % 3 !== 0))) {
            return null;
        }
        $rowLength = $width * $channels;
        $expectedLength = ($rowLength + 1) * $height;
        if ($expectedLength > 48 * 1024 * 1024) {
            return null;
        }
        $decoded = @gzuncompress($compressed, $expectedLength);
        if (!is_string($decoded) || strlen($decoded) !== $expectedLength) {
            return null;
        }
        $rgbRows = "";
        $grayRows = "";
        $alphaRows = "";
        $hasAlpha = false;
        $previous = str_repeat("\0", $rowLength);
        $dataOffset = 0;
        for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
            $filter = ord($decoded[$dataOffset++]);
            $row = substr($decoded, $dataOffset, $rowLength);
            $dataOffset += $rowLength;
            if ($filter > 4) {
                return null;
            }
            for ($i = 0; $i < $rowLength; $i++) {
                $value = ord($row[$i]);
                $left = $i >= $channels ? ord($row[$i - $channels]) : 0;
                $up = ord($previous[$i]);
                $upperLeft = $i >= $channels ? ord($previous[$i - $channels]) : 0;
                $predictor = match ($filter) {
                    0 => 0,
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    4 => self::preverFiltroPaeth($left, $up, $upperLeft),
                };
                $row[$i] = chr(($value + $predictor) & 0xff);
            }
            $previous = $row;
            if ($colorType === 0 || $colorType === 2) {
                if ($colorType === 0) {
                    $grayRows .= "\0" . $row;
                    if (strlen($transparency) === 2) {
                        $transparentGray = unpack("n", $transparency)[1];
                        $alpha = "";
                        for ($i = 0; $i < $width; $i++) {
                            $sample = ord($row[$i]);
                            $alpha .= chr($sample === $transparentGray ? 0 : 255);
                        }
                        $alphaRows .= "\0" . $alpha;
                        $hasAlpha = $hasAlpha || str_contains($alpha, "\0");
                    }
                } else {
                    $rgbRows .= "\0" . $row;
                    if (strlen($transparency) === 6) {
                        $transparent = unpack("nred/ngreen/nblue", $transparency);
                        $alpha = "";
                        for ($i = 0; $i < $width; $i++) {
                            $pixel = substr($row, $i * 3, 3);
                            $matches = ord($pixel[0]) === $transparent["red"]
                                && ord($pixel[1]) === $transparent["green"]
                                && ord($pixel[2]) === $transparent["blue"];
                            $alpha .= chr($matches ? 0 : 255);
                        }
                        $alphaRows .= "\0" . $alpha;
                        $hasAlpha = $hasAlpha || str_contains($alpha, "\0");
                    }
                }
                continue;
            }
            $colorRow = "";
            $alphaRow = "";
            if ($colorType === 3) {
                $paletteSize = intdiv(strlen($palette), 3);
                for ($i = 0; $i < $width; $i++) {
                    $index = ord($row[$i]);
                    if ($index >= $paletteSize) {
                        return null;
                    }
                    $colorRow .= substr($palette, $index * 3, 3);
                    $alpha = $index < strlen($transparency) ? ord($transparency[$index]) : 255;
                    $alphaRow .= chr($alpha);
                    $hasAlpha = $hasAlpha || $alpha !== 255;
                }
            } elseif ($colorType === 4) {
                for ($i = 0; $i < $width; $i++) {
                    $colorRow .= $row[$i * 2];
                    $alpha = $row[$i * 2 + 1];
                    $alphaRow .= $alpha;
                    $hasAlpha = $hasAlpha || ord($alpha) !== 255;
                }
            } else {
                for ($i = 0; $i < $width; $i++) {
                    $colorRow .= substr($row, $i * 4, 3);
                    $alpha = $row[$i * 4 + 3];
                    $alphaRow .= $alpha;
                    $hasAlpha = $hasAlpha || ord($alpha) !== 255;
                }
            }
            if ($colorType === 4) {
                $grayRows .= "\0" . $colorRow;
            } else {
                $rgbRows .= "\0" . $colorRow;
            }
            $alphaRows .= "\0" . $alphaRow;
        }
        $colorSpace = $colorType === 0 || $colorType === 4 ? "/DeviceGray" : "/DeviceRGB";
        $colors = $colorSpace === "/DeviceGray" ? 1 : 3;
        $imageData = @gzcompress($colorSpace === "/DeviceGray" ? $grayRows : $rgbRows, 6);
        if (!is_string($imageData)) {
            return null;
        }
        $resource = [
            "data" => $imageData,
            "width" => $width,
            "height" => $height,
            "colorSpace" => $colorSpace,
            "filter" => "FlateDecode",
            "colors" => $colors,
        ];
        if ($hasAlpha) {
            $alphaData = @gzcompress($alphaRows, 6);
            if (!is_string($alphaData)) {
                return null;
            }
            $resource["alpha"] = [
                "data" => $alphaData,
                "width" => $width,
                "height" => $height,
                "colorSpace" => "/DeviceGray",
                "filter" => "FlateDecode",
                "colors" => 1,
            ];
        }
        return $resource;
    }

    private static function preverFiltroPaeth(int $left, int $up, int $upperLeft): int
    {
        $base = $left + $up - $upperLeft;
        $leftDistance = abs($base - $left);
        $upDistance = abs($base - $up);
        $upperLeftDistance = abs($base - $upperLeft);
        if ($leftDistance <= $upDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }
        return $upDistance <= $upperLeftDistance ? $up : $upperLeft;
    }

}
