<?php
declare(strict_types=1);

namespace App\Aplicacao;

/** Interpreta a confirmação da fila sem converter valores inválidos em zero. */
final class ValidadorFilaSensor
{
    public static function pendentes(array $estado, int $carregamentoId): ?int
    {
        $raw = $estado["pending_events_by_loading"] ?? null;
        if ($raw !== null) {
            $map = is_string($raw) ? json_decode($raw) : null;
            if (!$map instanceof \stdClass) {
                return null;
            }
            $key = (string) $carregamentoId;
            $value = property_exists($map, $key) ? $map->{$key} : 0;
        } else {
            $value = $estado["pending_events"] ?? null;
        }
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $count = filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
        return $count === false ? null : $count;
    }
}
