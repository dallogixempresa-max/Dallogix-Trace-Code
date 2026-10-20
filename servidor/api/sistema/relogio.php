<?php

declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

/**
 * O Trace nunca altera o relógio do sistema operacional. Ele apenas informa
 * qual relógio deve ser usado pela aplicação e oferece o horário do central
 * quando a instalação local consegue alcançá-lo.
 */
function payload_horario_trace(string $source, bool $centralReachable = false): array
{
    $now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
    $unixMs = (int) floor((float) $now->format("U.u") * 1000);

    return [
        "status" => "ok",
        "source" => $source,
        "central_reachable" => $centralReachable,
        "server_now" => $now->format("Y-m-d\\TH:i:s.v\\Z"),
        "unix_ms" => $unixMs,
        "timezone" => date_default_timezone_get(),
        "checked_at" => $now->format("Y-m-d\\TH:i:s.v\\Z"),
    ];
}

function url_central_para_horario(): string
{
    $centralUrl = trim((string) (getenv("TRACE_CENTRAL_URL") ?: ""));
    if ($centralUrl === "") {
        $remoteUrl = trim((string) (getenv("SYNC_REMOTE_BATCH_URL") ?: getenv("SYNC_REMOTE_URL") ?: ""));
        $parts = parse_url($remoteUrl);
        if (is_array($parts) && isset($parts["scheme"], $parts["host"])) {
            $centralUrl = $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "");
        }
    }

    return url_remota_segura($centralUrl);
}

function consultar_horario_central(string $centralUrl): ?array
{
    if ($centralUrl === "" || !function_exists("curl_init")) {
        return null;
    }

    $handle = curl_init_url_remota_segura($centralUrl . "/api/relogio.php");
    if ($handle === false) {
        return null;
    }
    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($handle);
    $error = trim((string) curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($error !== "" || $status < 200 || $status >= 300) {
        return null;
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || ($decoded["status"] ?? "") !== "ok") {
        return null;
    }
    $unixMs = filter_var($decoded["unix_ms"] ?? null, FILTER_VALIDATE_INT);
    if ($unixMs === false || $unixMs <= 0) {
        return null;
    }

    return [
        "server_now" => (string) ($decoded["server_now"] ?? ""),
        "unix_ms" => (int) $unixMs,
        "timezone" => (string) ($decoded["timezone"] ?? "UTC"),
    ];
}

if (trace_e_instalacao_local()) {
    $centralTime = consultar_horario_central(url_central_para_horario());
    if ($centralTime !== null) {
        responder_json([
            "status" => "ok",
            "source" => "internet",
            "central_reachable" => true,
            "server_now" => $centralTime["server_now"],
            "unix_ms" => $centralTime["unix_ms"],
            "timezone" => $centralTime["timezone"],
            "checked_at" => (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("Y-m-d\\TH:i:s.v\\Z"),
        ]);
    }

    responder_json(payload_horario_trace("pc"));
}

// No servidor central, o horário desta própria instalação é a referência
// disponível para todas as máquinas e acessos remotos.
responder_json(payload_horario_trace("internet", true));
