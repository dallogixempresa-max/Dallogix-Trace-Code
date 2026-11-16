<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Classifica se um código recebido pelo leitor é forte o bastante para apontar divergência. */
final class ValidadorCodigoBarras
{
    public static function ehFormatoGtinNumerico(string $barcode): bool
    {
        return ctype_digit(trim($barcode)) && in_array(strlen(trim($barcode)), [8, 12, 13, 14], true);
    }

    /**
     * Códigos numéricos em formatos GTIN conhecidos precisam ter dígito verificador válido.
     * Outros códigos decodificados não vazios são considerados válidos pelo leitor HID.
     */
    public static function codigoDecodificadoConfiavel(string $barcode): bool
    {
        $codigo = trim($barcode);
        if ($codigo === "") {
            return false;
        }

        if (!ctype_digit($codigo)) {
            return true;
        }

        if (!self::ehFormatoGtinNumerico($codigo)) {
            return false;
        }

        $soma = 0;
        $peso = 3;
        for ($indice = strlen($codigo) - 2; $indice >= 0; $indice--) {
            $soma += ((int) $codigo[$indice]) * $peso;
            $peso = $peso === 3 ? 1 : 3;
        }

        $digitoVerificador = (10 - ($soma % 10)) % 10;
        return $digitoVerificador === (int) $codigo[strlen($codigo) - 1];
    }
}
