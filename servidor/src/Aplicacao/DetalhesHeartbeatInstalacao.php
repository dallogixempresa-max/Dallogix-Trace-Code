<?php

declare(strict_types=1);

namespace App\Aplicacao;

/** Mantém somente os dados de capacidade necessários para o Central. */
final class DetalhesHeartbeatInstalacao
{
    /** @return array<string, mixed> */
    public static function normalizar(string $deviceType, string $status, mixed $details): array
    {
        $deviceType = strtoupper(trim($deviceType));
        $status = strtoupper(trim($status));
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : [];
        }
        $details = is_array($details) ? $details : [];

        $normalized = ["source" => "instalacao_local"];
        if ($deviceType !== "CLP") {
            return $normalized;
        }

        $capability = is_array($details["command_capability"] ?? null)
            ? $details["command_capability"]
            : [];
        $mode = strtoupper(trim((string) ($capability["mode"] ?? "DESCONHECIDO")));
        if (!in_array($mode, ["FISICO", "SIMULADO", "BLOQUEADO", "DESCONHECIDO"], true)) {
            $mode = "DESCONHECIDO";
        }

        $normalized["command_capability"] = [
            "available" => ($capability["available"] ?? false) === true
                && $status === "ONLINE"
                && in_array($mode, ["FISICO", "SIMULADO"], true),
            "mode" => $mode,
        ];

        return $normalized;
    }
}
