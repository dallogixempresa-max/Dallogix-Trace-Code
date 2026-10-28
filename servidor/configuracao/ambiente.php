<?php

declare(strict_types=1);

// Configuração do ambiente, validações e handlers globais de erro.

function ambiente_atual(): string
{
    return strtolower(trim((string) (getenv("APP_ENV") ?: "local")));
}

function trace_e_instalacao_local(): bool
{
    $modo = strtolower(trim((string) (getenv("TRACE_INSTALLATION_MODE") ?: "")));
    if ($modo === "local" || $modo === "industrial") {
        return true;
    }
    if ($modo === "central" || $modo === "remoto" || $modo === "server") {
        return false;
    }
    // An omitted or unknown mode must never silently enable local-only flows.
    return false;
}

function trace_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . "-"
        . substr($hex, 8, 4) . "-"
        . substr($hex, 12, 4) . "-"
        . substr($hex, 16, 4) . "-"
        . substr($hex, 20, 12);
}

function url_remota_segura(string $url): string
{
    $url = trim($url);
    if ($url === "" || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return "";
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts["scheme"] ?? "")) !== "https") {
        return "";
    }
    if (($parts["host"] ?? "") === "" || isset($parts["user"]) || isset($parts["pass"])) {
        return "";
    }
    if (isset($parts["query"]) || isset($parts["fragment"])) {
        return "";
    }
    if (isset($parts["port"]) && ((int) $parts["port"] < 1 || (int) $parts["port"] > 65535)) {
        return "";
    }

    $host = trim((string) $parts["host"], "[]");
    $publicIp = static function (string $ip): bool {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    };
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return $publicIp($host) ? rtrim($url, "/") : "";
    }
    if (preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) !== 1) {
        return "";
    }

    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if ($records === false || $records === []) {
        return "";
    }
    $resolvedIps = [];
    foreach ($records as $record) {
        $ip = (string) ($record["ip"] ?? $record["ipv6"] ?? "");
        if ($ip === "" || !$publicIp($ip)) {
            return "";
        }
        $resolvedIps[] = $ip;
    }

    // Reuse the validated DNS answers when opening the connection to prevent
    // a second, attacker-controlled resolution between validation and cURL.
    $GLOBALS["trace_remote_dns"] ??= [];
    $GLOBALS["trace_remote_dns"][strtolower($host)] = array_values(array_unique($resolvedIps));
    return rtrim($url, "/");
}

/** @return CurlHandle|false */
function curl_init_url_remota_segura(string $url)
{
    $safeUrl = url_remota_segura($url);
    if ($safeUrl === "") {
        return false;
    }
    $parts = parse_url($safeUrl);
    if (!is_array($parts)) {
        return false;
    }
    $host = strtolower(trim((string) ($parts["host"] ?? ""), "[]"));
    $handle = curl_init($safeUrl);
    if ($handle === false || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return $handle;
    }

    $ips = $GLOBALS["trace_remote_dns"][$host] ?? [];
    if ($ips === []) {
        curl_close($handle);
        return false;
    }
    $addresses = array_map(
        static fn (string $ip): string => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? "[{$ip}]"
            : $ip,
        $ips,
    );
    $port = (int) ($parts["port"] ?? 443);
    if (!curl_setopt($handle, CURLOPT_RESOLVE, ["{$host}:{$port}:" . implode(",", $addresses)])) {
        curl_close($handle);
        return false;
    }

    return $handle;
}

function metadados_release(): array
{
    $release = [
        "version" => trim((string) (getenv("TRACE_VERSION") ?: "development")),
        "commit" => trim((string) (getenv("TRACE_COMMIT") ?: "unknown")),
    ];
    $releaseFile = dirname(__DIR__) . "/.release.json";
    if (!is_file($releaseFile) || !is_readable($releaseFile)) {
        return $release;
    }
    $decoded = json_decode((string) file_get_contents($releaseFile), true);
    if (!is_array($decoded)) {
        return $release;
    }
    foreach (["version", "commit"] as $field) {
        $value = trim((string) ($decoded[$field] ?? ""));
        if ($value !== "" && strlen($value) <= 128) {
            $release[$field] = $value;
        }
    }
    return $release;
}

function limite_sinal_clp_segundos(): int
{
    $configurado = filter_var(
        getenv("CLP_SIGNAL_LIMIT_SECONDS") ?: 3,
        FILTER_VALIDATE_INT,
    );
    return max(1, min(60, $configurado === false ? 3 : (int) $configurado));
}

function limite_sinal_scanner_segundos(): int
{
    $configurado = filter_var(
        getenv("SCANNER_SIGNAL_LIMIT_SECONDS") ?: 15,
        FILTER_VALIDATE_INT,
    );
    return max(1, min(300, $configurado === false ? 15 : (int) $configurado));
}

/** Finaliza solicitações sem foto depois da janela reservada ao leitor Keyence. */
function expirar_solicitacoes_imagem_keyence(PDO $pdo, ?int $equipmentId = null, array $loadingIds = []): int
{
    $configured = filter_var(getenv("KEYENCE_IMAGE_RECEIVE_TIMEOUT_MS") ?: 6000, FILTER_VALIDATE_INT);
    $timeoutMicroseconds = max(1000, min(120000, $configured === false ? 6000 : (int) $configured)) * 1000;
    $conditions = [
        "reason = 'SEM_LEITURA'",
        "status IN ('PENDENTE', 'CAPTURANDO')",
        "requested_at <= NOW(3) - INTERVAL {$timeoutMicroseconds} MICROSECOND",
    ];
    $parameters = [];

    if ($equipmentId !== null) {
        if ($equipmentId < 1) {
            return 0;
        }
        $conditions[] = "equipment_id = :equipment_id";
        $parameters["equipment_id"] = $equipmentId;
    }

    if ($loadingIds !== []) {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int) $id, $loadingIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return 0;
        }
        $loadingPlaceholders = [];
        foreach ($ids as $index => $id) {
            $key = "loading_id_{$index}";
            $loadingPlaceholders[] = ":{$key}";
            $parameters[$key] = $id;
        }
        $conditions[] = "carregamento_id IN (" . implode(",", $loadingPlaceholders) . ")";
    }

    if ($equipmentId === null && $loadingIds === []) {
        return 0;
    }

    $statement = $pdo->prepare(
        "UPDATE solicitacoes_imagens_evidencia
         SET status = 'ERRO', error_message = 'Imagem do Keyence não recebida no prazo configurado.', updated_at = NOW(3)
         WHERE " . implode(" AND ", $conditions),
    );
    $statement->execute($parameters);
    return $statement->rowCount();
}

/**
 * Confirma que o último sinal recebido representa uma leitura Modbus real
 * para o mesmo destino atualmente cadastrado. Uma conexão TCP aberta, por si
 * só, não confirma protocolo, unidade, função ou registrador.
 */
function heartbeat_modbus_valido(mixed $details, string $hostEsperado, int $portaEsperada): bool
{
    if (is_string($details)) {
        $details = json_decode($details, true);
    }
    if (!is_array($details)) {
        return false;
    }

    $funcao = filter_var($details["modbus_function"] ?? null, FILTER_VALIDATE_INT);
    $unidade = filter_var($details["modbus_unit_id"] ?? null, FILTER_VALIDATE_INT);
    $registrador = filter_var($details["diagnostic_register"] ?? null, FILTER_VALIDATE_INT);
    $porta = filter_var($details["plc_port"] ?? null, FILTER_VALIDATE_INT);

    return strtolower(trim((string) ($details["communication"] ?? ""))) === "modbus_tcp"
        && trim((string) ($details["plc_ip"] ?? "")) === trim($hostEsperado)
        && $porta !== false && (int) $porta === $portaEsperada
        && $unidade !== false && (int) $unidade >= 0 && (int) $unidade <= 255
        && $funcao !== false && in_array((int) $funcao, [1, 2, 3, 4], true)
        && $registrador !== false && (int) $registrador >= 0 && (int) $registrador <= 65535
        && array_key_exists("diagnostic_value", $details);
}

function limite_alerta_fim_produto_sacas(): int
{
    $configurado = filter_var(
        getenv("TRACE_END_PRODUCT_ALERT_THRESHOLD") ?: 5,
        FILTER_VALIDATE_INT,
    );
    return max(1, min(1000, $configurado === false ? 5 : (int) $configurado));
}

/**
 * Placas são exibidas em várias telas e também atravessam a sincronização.
 * Mantenha o valor em um conjunto simples de caracteres antes de persistir;
 * a interface continua fazendo escape como defesa adicional.
 */
function placa_caminhao_valida(string $placa): bool
{
    return $placa !== "" && mb_strlen($placa) <= 20 && preg_match(
        '/^[A-Z0-9À-ÿ _.,\/-]+$/u',
        $placa,
    ) === 1;
}

function nome_empresa_valido(string $nome): bool
{
    return $nome !== "" && mb_strlen($nome) <= 160 && preg_match(
        '/^[^\x00-\x1F\x7F]+$/u',
        $nome,
    ) === 1;
}

$configuredTimezone = trim((string) (getenv("TZ") ?: "America/Sao_Paulo"));
if ($configuredTimezone !== "") {
    date_default_timezone_set($configuredTimezone);
}

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header("Cross-Origin-Resource-Policy: same-origin");
header("Cross-Origin-Opener-Policy: same-origin");

// Converte avisos do PHP em exceções para que nenhuma resposta de API receba
// HTML misturado ao JSON esperado pelo navegador.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        // Depreciações devem ser registradas pelo PHP sem transformar toda a
        // resposta JSON em 500 durante uma atualização de runtime.
        return false;
    }
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (function_exists("ini_set")) {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Strict");
}
