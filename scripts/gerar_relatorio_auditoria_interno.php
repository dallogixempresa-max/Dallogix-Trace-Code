<?php
declare(strict_types=1);

// Gera e publica no central o relatório de auditoria de um romaneio, no modo
// interno de relatorio_auditoria.php (sem sessão, sem registrar download e sem
// devolver o PDF). Roda num processo próprio porque o endpoint encerra com exit.
// Uso: php gerar_relatorio_auditoria_interno.php <company_id> <romaneio_id>

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit(1);
}

$companyId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
$romaneioId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
if ($companyId === false || $romaneioId === false) {
    fwrite(STDERR, "Uso: php gerar_relatorio_auditoria_interno.php <company_id> <romaneio_id>\n");
    exit(2);
}

$GLOBALS["trace_relatorio_interno"] = ["company_id" => (int) $companyId];
$_GET["romaneio_id"] = (string) $romaneioId;
try {
    require __DIR__ . "/../servidor/api/relatorios/relatorio_auditoria.php";
} catch (Throwable $exception) {
    // Sem este catch o tratador global responderia {"error":...} com código 0.
    echo json_encode(
        ["status" => 500, "error" => mb_substr($exception->getMessage(), 0, 500)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ), "\n";
    exit(1);
}

// O endpoint sempre termina com exit; chegar aqui é uma falha.
fwrite(STDERR, "O gerador de relatório terminou sem resultado.\n");
exit(1);
