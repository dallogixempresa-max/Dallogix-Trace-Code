<?php

declare(strict_types=1);

namespace App\Aplicacao;

final class ValidadorCodigoDala
{
    public static function ehValido(string $codigo): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,30}\z/', trim($codigo)) === 1;
    }
}
