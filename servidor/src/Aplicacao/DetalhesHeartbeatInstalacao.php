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
            foreach (["ftp_image_receiver_listening", "image_archive_directory_available"] as $booleanField) {
                if (is_bool($details[$booleanField] ?? null)) {
                    $normalized[$booleanField] = $details[$booleanField];
                }
            }
            $queueStatus = strtoupper(trim((string) ($details["image_queue_status"] ?? "")));
            if (in_array($queueStatus, ["OK", "ERRO", "AGUARDANDO_CORRELACAO"], true)) {
                $normalized["image_queue_status"] = $queueStatus;
            }
            foreach (["unmatched_image_count", "ftp_images_received"] as $countField) {
                $count = filter_var($details[$countField] ?? null, FILTER_VALIDATE_INT);
                if ($count !== false && $count >= 0 && $count <= 1000000000) {
                    $normalized[$countField] = $count;
                }
            }
            $oldestImageAgeMs = filter_var($details["oldest_unmatched_image_age_ms"] ?? null, FILTER_VALIDATE_INT);
            if ($oldestImageAgeMs !== false && $oldestImageAgeMs >= 0 && $oldestImageAgeMs <= 31536000000) {
                $normalized["oldest_unmatched_image_age_ms"] = $oldestImageAgeMs;
            }
            $ftpLastImageAt = $details["ftp_last_image_received_at"] ?? null;
            if (is_string($ftpLastImageAt) && strtotime($ftpLastImageAt) !== false) {
                $normalized["ftp_last_image_received_at"] = mb_substr($ftpLastImageAt, 0, 64);
            }
            $ftpTransferError = trim((string) ($details["ftp_transfer_error_code"] ?? ""));
            if (preg_match('/^[a-z0-9_]{1,80}$/', $ftpTransferError) === 1) {
                $normalized["ftp_transfer_error_code"] = $ftpTransferError;
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
