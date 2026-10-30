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
        if ($deviceType === "SCANNER") {
            $readerStatus = strtoupper(trim((string) ($details["reader_network_status"] ?? "DESCONHECIDO")));
            $normalized["reader_network_status"] = in_array($readerStatus, ["ONLINE", "OFFLINE"], true)
                ? $readerStatus
                : "DESCONHECIDO";

            $probeMethod = strtoupper(trim((string) ($details["reader_probe_method"] ?? "")));
            $probePort = filter_var($details["reader_probe_port"] ?? null, FILTER_VALIDATE_INT);
            if ($probeMethod === "ICMP_PING") {
                $normalized["reader_probe_method"] = "ICMP_PING";
            } elseif (($probeMethod === "TCP_CONNECT" || $probeMethod === "")
                && $probePort !== false && $probePort >= 1 && $probePort <= 65535) {
                $normalized["reader_probe_method"] = "TCP_CONNECT";
                $normalized["reader_probe_port"] = $probePort;
            }

            $readerIp = filter_var($details["reader_ip"] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($readerIp !== false) {
                $normalized["reader_ip"] = $readerIp;
            }

            $checkedAt = $details["reader_probe_checked_at"] ?? null;
            if (is_string($checkedAt) && strtotime($checkedAt) !== false) {
                $normalized["reader_probe_checked_at"] = mb_substr($checkedAt, 0, 64);
            }

            $lastReadAt = $details["reader_last_read_at"] ?? null;
            if (is_string($lastReadAt) && strtotime($lastReadAt) !== false) {
                $normalized["reader_last_read_at"] = mb_substr($lastReadAt, 0, 64);
            }
            $lastReadAgeMs = filter_var($details["reader_last_read_age_ms"] ?? null, FILTER_VALIDATE_INT);
            if ($lastReadAgeMs !== false && $lastReadAgeMs >= 0 && $lastReadAgeMs <= 86400000) {
                $normalized["reader_last_read_age_ms"] = $lastReadAgeMs;
            }
            return $normalized;
        }
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
