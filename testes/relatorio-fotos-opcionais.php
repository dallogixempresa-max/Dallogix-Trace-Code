<?php
declare(strict_types=1);

require_once __DIR__ . '/../servidor/src/Aplicacao/ConferidorEvidenciasRelatorio.php';
require_once __DIR__ . '/../servidor/src/Aplicacao/RelatorioAuditoriaPdf.php';
require_once __DIR__ . '/../scripts/image_storage_path.php';

use App\Aplicacao\ConferidorEvidenciasRelatorio;
use App\Aplicacao\RelatorioAuditoriaPdf;

function verificar(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function registrar_log_erro(Throwable $error, string $context): void
{
    $GLOBALS['evidence_test_errors'][] = [$context, $error->getMessage()];
}
function pngTeste(int $r, int $g): string
{
    $chunk = static fn(string $type, string $bytes): string => pack('N', strlen($bytes))
        . $type . $bytes . pack('N', crc32($type . $bytes));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', 1, 1, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress("\0" . chr($r) . chr($g) . "\0\xff")) . $chunk('IEND', '');
}

$source = file_get_contents(__DIR__ . '/../servidor/api/relatorios/relatorio_auditoria.php');
$start = strpos($source, '$evidenceImages = [];');
$end = strpos($source, '$report = new RelatorioAuditoriaPdf(', $start);
$pipeline = substr($source, $start, $end - $start);
$start = strpos($source, 'if ($evidenceImages !== []) {');
$end = strpos($source, "try {\n    \$pdfBytes =", $start);
$render = substr($source, $start, $end - $start);
$start = strpos($source, '$report->escreverTitulo("Falhas de leitura do código de barras");');
$end = strpos($source, 'if ($romaneio["status"] === "CANCELADO")', $start);
$incidentTables = substr($source, $start, $end - $start);
$root = sys_get_temp_dir() . '/trace-evidence-' . bin2hex(random_bytes(8));
mkdir($root, 0700);

// Executa os trechos reais do endpoint de seleção de bytes, rótulos e renderização.
// Não substitui testes HTTP/MySQL nem representa captura física de câmera.
function emitir(string $pipeline, string $render, string $storageRoot, array $images, array $requests, array $previous = []): array
{
    global $incidentTables;
    $selection = ConferidorEvidenciasRelatorio::selecionarDisponiveis($images, $requests);
    $incidentImages = $selection['images'];
    $evidenceAttachmentFailure = count(array_filter(
        $selection['issues'],
        static fn(array $issue): bool => (int) ($issue['request_id'] ?? 0) > 0,
    )) > 0 ? new RuntimeException('Evidência recebida sem vínculo íntegro.') : null;
    $reportFailure = static function (Throwable $exception): never {
        throw new RuntimeException('Relatório incompleto recusado.', 0, $exception);
    };
    $evidenceWarnings = array_map(static fn(array $issue): string => 'Vínculo não confirmado: ' . $issue['detail'], $selection['issues']);
    $previousEvidenceImagesByReference = $previous;
    $formatDateTime = static fn(string $value): string => $value;
    $sackReferencesByEvent = [101 => 'Saca 1', 102 => 'Saca 2'];
    $operationSummary = [];
    $noReadRows = [['sensor_event_id' => 101, 'read_at' => '2026-11-16 13:00:00', 'product_name' => null,
        'sack_reference' => 'Saca 1', 'return_reason' => 'Retirada confirmada']];
    $wrongProductRows = [['sensor_event_id' => 102, 'read_at' => '2026-11-16 13:00:00', 'product_name' => 'Produto externo',
        'barcode' => '789123', 'sack_reference' => 'Saca 2']];
    eval('use App\\Aplicacao\\RelatorioAuditoriaPdf;' . $pipeline);
    $report = new RelatorioAuditoriaPdf('Teste sintético', 'Fotos opcionais — sem operação real');
    eval($incidentTables);
    eval($render);
    $pdf = $report->obterArquivoPdf();
    verificar(str_starts_with($pdf, '%PDF-') && str_contains($pdf, '%%EOF'), 'PDF não foi concluído');
    foreach (['indispon', 'recebida', 'Pendências', 'Pendencias', 'inválida', 'invalida', 'Foto n'] as $forbidden) {
        verificar(!str_contains($pdf, $forbidden), 'Aviso de ausência de foto apareceu no PDF: ' . $forbidden);
    }
    verificar(str_contains($pdf, 'Retirada:') && str_contains($pdf, 'confirmada'), 'Tratamento da retirada sumiu do PDF');
    verificar(str_contains($pdf, 'Produto externo'), 'Incidente sem foto sumiu do PDF');
    return compact('pdf', 'evidenceImages', 'evidenceWarnings', 'noReadRows', 'operationSummary');
}

$bytes = [pngTeste(255, 0), pngTeste(0, 255)];
$requests = $images = [];
foreach (['SEM_LEITURA', 'PRODUTO_INCORRETO'] as $i => $reason) {
    $id = $i + 1;
    $hash = hash('sha256', $bytes[$i]);
    $requests[] = ['request_id' => $id, 'company_id' => 7, 'equipment_id' => 3, 'carregamento_id' => 42,
        'sensor_event_id' => 100 + $id, 'sensor_event_uuid' => 'event-' . $id,
        'reading_id' => 200 + $id, 'image_sha256' => $hash, 'reason' => $reason, 'captured_at' => '2026-11-16 13:00:00'];
    $images[] = $requests[$i] + ['image_id' => $id, 'path' => "keyence-{$id}-{$hash}.png"];
}
try {
    try { emitir($pipeline, $render, $root, $images, $requests); throw new LogicException('PDF sem fotos recebidas foi aceito'); }
    catch (RuntimeException $exception) { if ($exception instanceof LogicException) throw $exception; }

    file_put_contents($root . '/' . $images[0]['path'], $bytes[0]);
    try { emitir($pipeline, $render, $root, $images, $requests); throw new LogicException('PDF parcial foi aceito'); }
    catch (RuntimeException $exception) { if ($exception instanceof LogicException) throw $exception; }

    file_put_contents($root . '/' . $images[1]['path'], $bytes[1]);
    $complete = emitir($pipeline, $render, $root, $images, $requests);
    verificar(count($complete['evidenceImages']) === 2 && $complete['evidenceWarnings'] === [], 'Foto tardia não incorporada na nova emissão');

    $captionReport = new RelatorioAuditoriaPdf('Teste', 'Legenda longa');
    $cursor = new ReflectionProperty($captionReport, 'y');
    $before = $cursor->getValue($captionReport);
    verificar($captionReport->inserirDadosDaImagemDaOcorrencia($bytes[0], 'image/png', str_repeat('Evidência vinculada ao evento. ', 8)), 'Legenda longa não aceita');
    verificar($before - $cursor->getValue($captionReport) > 60, 'Legenda longa não reservou espaço antes do próximo título');

    file_put_contents($root . '/' . $images[0]['path'], $bytes[1]);
    try { emitir($pipeline, $render, $root, $images, $requests); throw new LogicException('Foto trocada foi aceita'); }
    catch (RuntimeException $exception) { if ($exception instanceof LogicException) throw $exception; }

    $wrong = $images;
    $wrong[0]['sensor_event_id'] = 999;
    try { emitir($pipeline, $render, $root, $wrong, $requests); throw new LogicException('Vínculo duvidoso foi aceito'); }
    catch (RuntimeException $exception) { if ($exception instanceof LogicException) throw $exception; }

    unlink($root . '/' . $images[0]['path']);
    $recovered = emitir($pipeline, $render, $root, $images, $requests,
        ['request:1' => [['bytes' => $bytes[0], 'mime' => 'image/png', 'sha256' => $requests[0]['image_sha256']]]]);
    verificar(count($recovered['evidenceImages']) === 2, 'Recuperação do PDF anterior deixou de funcionar');

    if (isset($argv[1])) file_put_contents($argv[1], $complete['pdf']);
} finally {
    foreach ($images as $image) {
        $path = $root . '/' . $image['path'];
        if (is_file($path)) unlink($path);
    }
    rmdir($root);
}
echo "ok\n";
