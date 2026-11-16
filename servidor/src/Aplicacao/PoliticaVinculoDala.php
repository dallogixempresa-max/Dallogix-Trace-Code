<?php
declare(strict_types=1);
namespace App\Aplicacao;

/** Vincular uma máquina não libera emergência nem retoma uma operação pausada. */
final class PoliticaVinculoDala
{
    public static function estadoAposVinculo(array $loading): ?string
    {
        if (($loading['equipment_id'] ?? null) !== null) {
            return null;
        }
        return match ($loading['state'] ?? '') {
            'AGUARDANDO', 'PREPARANDO' => 'PREPARANDO',
            'PAUSADO' => 'PAUSADO',
            default => null,
        };
    }
}
