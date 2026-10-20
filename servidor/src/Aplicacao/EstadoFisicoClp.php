<?php

declare(strict_types=1);

namespace App\Aplicacao;

final class EstadoFisicoClp
{
    public static function estaEmFuncionamentoPelosDetalhes(mixed $details): ?bool
    {
        if (is_string($details)) {
            $details = json_decode($details, true);
        }
        if (!is_array($details)) {
            return null;
        }

        foreach (["physical_running", "conveyor_running", "machine_running", "running"] as $key) {
            if (array_key_exists($key, $details) && is_bool($details[$key])) {
                return $details[$key];
            }
        }

        return null;
    }
}
