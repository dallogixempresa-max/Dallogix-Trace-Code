<?php

declare(strict_types=1);

namespace App\Aplicacao;

use RuntimeException;

final class ExcecaoDisponibilidadeClp extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
