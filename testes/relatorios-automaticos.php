<?php
declare(strict_types=1);

// Romaneio "teste novo ms" (25/11/2026): o PDF com as fotos do Keyence só era
// gerado quando alguém abria o relatório no PC; quem baixava pelo central
// recebia a versão sem fotos. O report-worker do PC gera e publica sozinho.

require __DIR__ . "/../scripts/gerar_relatorios_auditoria.php"; // só as funções (guarda de inclusão)

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 1) Quando gerar: impressão digital do romaneio e das fotos capturadas, não horário de arquivo.
$base = ["status" => "FINALIZADO", "romaneio_updated" => 1000, "content_fingerprint" => "7:633:990/9:700/40:812/36:3152:30/0:0/2:15"];
$publicado = relatorio_impressao_digital($base);
verificar(relatorio_precisa_gerar(null, $publicado), "Sem publicação confirmada, gera.");
verificar(!relatorio_precisa_gerar($publicado, relatorio_impressao_digital($base)), "Mesma impressão já publicada não é regerada.");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["content_fingerprint" => "8:640:990/9:700/40:812/36:3152:30/0:0/2:15"] + $base)), "Foto nova no mesmo segundo do PDF regera (contagem e id mudam).");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["content_fingerprint" => "7:633:995/9:700/40:812/36:3152:30/0:0/2:15"] + $base)), "Foto recapturada regera.");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["status" => "CANCELADO"] + $base)), "Mudança de estado do romaneio regera.");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["content_fingerprint" => "7:633:990/10:701/40:812/36:3152:30/0:0/2:15"] + $base)), "Foto adicional de um pedido já capturado regera.");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["content_fingerprint" => "7:633:990/9:700/41:813/36:3152:30/0:0/2:15"] + $base)), "Leitura que chega depois da geração regera.");
verificar(relatorio_precisa_gerar($publicado, relatorio_impressao_digital(["content_fingerprint" => "7:633:990/9:700/40:812/36:3152:30/1:4/2:15"] + $base)), "Retorno registrado depois da geração regera.");

// 1b) Sucesso só com confirmação explícita do gerador.
verificar(relatorio_saida_indica_sucesso(0, '{"status":200,"data":{"romaneio_id":149}}'), "Confirmação do gerador é sucesso.");
verificar(!relatorio_saida_indica_sucesso(0, '{"error":"Ocorreu um erro inesperado."}'), "Tratador global (código 0 com erro) não é sucesso.");
verificar(!relatorio_saida_indica_sucesso(0, ''), "Saída vazia não é sucesso.");
verificar(!relatorio_saida_indica_sucesso(1, '{"status":200,"data":{}}'), "Código de falha não é sucesso.");
verificar(!relatorio_saida_indica_sucesso(0, '{"status":503,"error":"publicação"}'), "Publicação não enfileirada não é sucesso.");
verificar(relatorio_saida_indica_sucesso(0, "aviso qualquer\n" . '{"status":200,"data":{"cached":true}}'), "Confirmação em qualquer linha da saída vale.");

// 2) Espera crescente entre falhas, limitada a 1 hora.
verificar(relatorios_espera_apos_falhas(1) === 60 && relatorios_espera_apos_falhas(2) === 120 && relatorios_espera_apos_falhas(3) === 240, "Espera dobra a cada falha.");
verificar(relatorios_espera_apos_falhas(20) === 3600, "Espera máxima de 1 hora.");

// 3) Estado em arquivo, gravado de forma atômica.
$dir = sys_get_temp_dir() . "/trace-relatorios-teste-" . bin2hex(random_bytes(4));
mkdir($dir);
$statePath = $dir . "/estado.json";
verificar(relatorios_estado_ler($statePath) === [], "Estado ausente começa vazio.");
relatorios_estado_gravar($statePath, ["149" => ["failures" => 2, "next_attempt" => 123, "last_error" => "x"]]);
verificar(relatorios_estado_ler($statePath)["149"]["failures"] === 2, "Estado gravado é lido de volta.");
verificar(glob($dir . "/estado.json.tmp-*") === [], "Arquivo temporário não fica para trás.");
file_put_contents($statePath, "{corrompido");
verificar(relatorios_estado_ler($statePath) === [], "Estado corrompido não derruba o worker.");

// 4) Processo filho: saída, código e tempo máximo.
$ok = relatorio_executar_filho([PHP_BINARY, "-r", "echo 'feito'; exit(0);"], 10);
verificar($ok["exit"] === 0 && $ok["stdout"] === "feito" && !$ok["timed_out"], "Filho com sucesso devolve saída e código 0.");
$avisos = relatorio_executar_filho([PHP_BINARY, "-r", 'echo json_encode(["status" => 200, "data" => ["romaneio_id" => 149]]), "\n"; for ($i = 0; $i < 30; $i++) { fwrite(STDERR, str_repeat("aviso de evidência ", 6) . "\n"); } exit(0);'], 10);
verificar(strlen($avisos["stderr"]) > 2000, "O teste precisa de mais de 2.000 caracteres de avisos.");
verificar(relatorio_saida_indica_sucesso($avisos["exit"], $avisos["stdout"]), "Avisos na saída de erros não escondem a confirmação de sucesso.");
$falha = relatorio_executar_filho([PHP_BINARY, "-r", "fwrite(STDERR, 'erro'); exit(3);"], 10);
verificar($falha["exit"] === 3 && str_contains($falha["output"], "erro"), "Código e erro do filho são preservados.");
$inicio = microtime(true);
$lento = relatorio_executar_filho([PHP_BINARY, "-r", "sleep(30);"], 1);
verificar($lento["timed_out"] && $lento["exit"] === 124 && microtime(true) - $inicio < 10, "Filho preso é encerrado no tempo máximo.");

// 5) Script filho recusa argumentos inválidos sem tocar no banco.
$child = relatorio_executar_filho([PHP_BINARY, __DIR__ . "/../scripts/gerar_relatorio_auditoria_interno.php", "0", "abc"], 10);
verificar($child["exit"] === 2, "Argumentos inválidos encerram com código 2.");

// 6) Modo interno do gerador: sem sessão, sem registrar download, sem devolver o PDF.
$endpoint = (string) file_get_contents(__DIR__ . "/../servidor/api/relatorios/relatorio_auditoria.php");
verificar(str_contains($endpoint, '$internalReport = $GLOBALS["trace_relatorio_interno"] ?? null;'), "Modo interno precisa existir no gerador.");
verificar(preg_match('/is_array\(\$internalReport\) => \[\s*"id" => null,/', $endpoint) === 1, "Modo interno não usa sessão de usuário.");
verificar(preg_match('/if \(is_array\(\$internalReport\)\) \{\s*echo json_encode\(\["status" => \$status\] \+ \$payload[^;]*;\s*exit\(\$status >= 400 \? 1 : 0\);/', $endpoint) === 1, "Erro no modo interno sai com código de falha, sem resposta HTTP.");
$download = strpos($endpoint, '$downloadAuditPdf = static function');
verificar($download !== false && preg_match('/\$downloadAuditPdf = static function[^{]*\{\s*\$finishInternalReport\(/', $endpoint) === 1, "PDF em cache no modo interno termina antes de registrar download.");
foreach ([strpos($endpoint, "record_operational_event(\$pdo, \$user, \"RELATORIO_AUDITORIA_BAIXADO\", \"romaneio\", (int) \$romaneioId, []);\n    }\n    header_remove"), strrpos($endpoint, 'record_operational_event($pdo, $user, "RELATORIO_AUDITORIA_BAIXADO"')] as $position) {
    verificar($position !== false, "Registro de download esperado no gerador.");
    $before = substr($endpoint, max(0, $position - 1200), 1200);
    verificar(str_contains($before, '$finishInternalReport('), "Todo registro de download vem depois da saída do modo interno.");
}
verificar(str_contains($endpoint, 'if (is_array($internalReport) && $reportPublishFailed) {'), "Falha ao enfileirar a publicação faz o worker tentar de novo.");

// 7) Serviço próprio só no PC, com escrita nos relatórios.
$compose = (string) file_get_contents(__DIR__ . "/../docker-compose.yml");
verificar(preg_match('/\n  report-worker:\n    profiles: \["industrial"\]/', $compose) === 1, "report-worker só roda no perfil industrial (PC).");
$service = substr($compose, (int) strpos($compose, "\n  report-worker:"), 4000);
verificar(str_contains($service, "- ./armazenamento:/var/www/armazenamento\n"), "report-worker precisa gravar no armazenamento.");
verificar(str_contains($service, "php /var/www/scripts/gerar_relatorios_auditoria.php"), "report-worker executa o gerador automático.");
verificar(!str_contains((string) file_get_contents(__DIR__ . "/../scripts/sync_worker.php"), "gerar_relatorios_auditoria"), "A geração não pode rodar dentro da sincronização.");
verificar(str_contains($service, "> 600) ? 1 : 0);"), "Healthcheck precisa devolver 1 ou 0 explicitamente (exit(bool) dá 0 no PHP 8.3).");

// 8) Orquestrador: mais recentes primeiro, erro sai com falha; filho captura exceções.
$worker = (string) file_get_contents(__DIR__ . "/../scripts/gerar_relatorios_auditoria.php");
verificar(str_contains($worker, "ORDER BY r.updated_at DESC, r.id DESC"), "O romaneio recém-encerrado vem primeiro.");
foreach (["solicitacoes_imagens_evidencia e", "imagens i", "leituras l", "eventos_sensor s", "retornos t", "ocorrencias o"] as $fonte) {
    verificar(str_contains($worker, "FROM {$fonte} JOIN carregamentos c"), "A impressão digital precisa cobrir {$fonte}.");
}
verificar(str_contains($worker, '$updatedAgo < 30 || ((int) ($candidate["photos_waiting"] ?? 0) > 0 && $updatedAgo < 150)'), "A primeira geração espera 30 s, ou até 150 s com foto do Keyence ainda aguardando.");
verificar(str_contains($worker, "e.status IN ('PENDENTE', 'CAPTURANDO')"), "A geração conta as fotos ainda aguardando.");
verificar(str_contains($worker, "e.status = 'ERRO'"), "Foto que expirou muda a impressão digital e regenera o PDF.");
verificar(str_contains($worker, 'relatorio_saida_indica_sucesso($result["exit"], $result["stdout"])'), "O sucesso é lido só da saída normal.");
verificar(preg_match('/\} catch \(Throwable \$exception\) \{\s*fwrite\(STDERR, "Geração automática de relatórios falhou/', $worker) === 1, "Erro no orquestrador sai com falha.");
$child = (string) file_get_contents(__DIR__ . "/../scripts/gerar_relatorio_auditoria_interno.php");
verificar(preg_match('/try \{\s*require __DIR__ \. "\/\.\.\/servidor\/api\/relatorios\/relatorio_auditoria\.php";\s*\} catch \(Throwable/', $child) === 1, "Exceção no gerador sai com falha, não pelo tratador global.");

array_map("unlink", glob($dir . "/*") ?: []);
rmdir($dir);
echo "Relatório de auditoria com fotos é gerado e publicado pelo PC sem depender de abrir o relatório.\n";
