<?php

declare(strict_types=1);

function trace_id_aba_da_sessao(): string
{
    $tabId = strtolower(trim((string) ($_SERVER["HTTP_X_TRACE_TAB"] ?? "")));
    return preg_match('/\A[a-f0-9]{32}\z/', $tabId) === 1 ? $tabId : "default";
}

function &trace_contexto_sessao(): array
{
    $tabId = (string) ($GLOBALS["trace_tab_id"] ?? "default");
    if (!isset($_SESSION["_trace_tabs"][$tabId]) || !is_array($_SESSION["_trace_tabs"][$tabId])) {
        $_SESSION["_trace_tabs"][$tabId] = [];
    }
    return $_SESSION["_trace_tabs"][$tabId];
}

function trace_requisicao_conta_como_atividade(): bool
{
    $method = strtoupper(trim((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")));
    if (!in_array($method, ["GET", "HEAD", "OPTIONS"], true)) {
        return true;
    }
    return trim((string) ($_SERVER["HTTP_X_TRACE_USER_ACTIVITY"] ?? "")) === "1";
}

session_name("dallogix_trace_session");
$appUrl = strtolower(trim((string) (getenv("APP_URL") ?: "")));
$isSecureSession =
    str_starts_with($appUrl, "https://") ||
    !trace_e_instalacao_local() ||
    ambiente_atual() === "production" ||
    filter_var(getenv("SESSION_SECURE") ?: "false", FILTER_VALIDATE_BOOLEAN);
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "domain" => "",
    "secure" => $isSecureSession,
    "httponly" => true,
    "samesite" => "Strict",
]);
$skipSession = defined("TRACE_SKIP_SESSION");
$sessionIdleTimeout = max(300, (int) (getenv("SESSION_IDLE_TIMEOUT") ?: 1800));
$sessionGcLifetime = max(
    $sessionIdleTimeout,
    (int) (getenv("SESSION_GC_MAXLIFETIME") ?: 86400),
);
if (function_exists("ini_set")) {
    ini_set("session.gc_maxlifetime", (string) $sessionGcLifetime);
}
$hasSessionCookie = isset($_COOKIE[session_name()]);
$startSession = !$skipSession && ($hasSessionCookie || defined("TRACE_START_SESSION"));
if ($startSession) {
    session_start();
    $traceTabId = trace_id_aba_da_sessao();
    $GLOBALS["trace_tab_id"] = $traceTabId;
    if (!isset($_SESSION["_trace_tabs"]) || !is_array($_SESSION["_trace_tabs"])) {
        // Move a pre-upgrade session into the first tab context without
        // changing its cookie or silently logging the operator out.
        $legacySession = $_SESSION;
        unset($legacySession["_trace_tabs"]);
        $_SESSION = ["_trace_tabs" => [$traceTabId => $legacySession]];
    } elseif (!isset($_SESSION["_trace_tabs"][$traceTabId]) || !is_array($_SESSION["_trace_tabs"][$traceTabId])) {
        $_SESSION["_trace_tabs"][$traceTabId] = [];
    }
    foreach ($_SESSION["_trace_tabs"] as $tabId => $context) {
        $contextIsArray = is_array($context);
        $role = strtoupper(trim((string) ($contextIsArray ? ($context["user"]["role"] ?? "") : "")));
        $isOperatorSession = in_array($role, ["USUARIO", "OPERADOR"], true);
        // O operador pode manter a tela de operação aberta durante todo o
        // carregamento. Administradores e supervisores continuam protegidos
        // pelo limite de inatividade configurado no ambiente.
        $expiredByInactivity = !$contextIsArray
            || !isset($context["last_activity"])
            || time() - (int) $context["last_activity"] > $sessionIdleTimeout;
        if (!$contextIsArray || (!$isOperatorSession && $expiredByInactivity)) {
            unset($_SESSION["_trace_tabs"][$tabId]);
        }
    }
    $sessionContext =& trace_contexto_sessao();
    if (trace_requisicao_conta_como_atividade()) {
        $sessionContext["last_activity"] = time();
    }
    unset($sessionContext);
}
trace_correlation_id();

function responder_json(array $dados, int $status = 200): never
{
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function encerrar_sessao_atual(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $tabId = (string) ($GLOBALS["trace_tab_id"] ?? "default");
    unset($_SESSION["_trace_tabs"][$tabId]);
    if (!empty($_SESSION["_trace_tabs"])) {
        return;
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $configuracoes = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $configuracoes["path"],
            $configuracoes["domain"],
            (bool) $configuracoes["secure"],
            (bool) $configuracoes["httponly"],
        );
    }
    session_destroy();
}
