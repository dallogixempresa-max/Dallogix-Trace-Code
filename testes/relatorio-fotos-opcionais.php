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
$root = sys_get_temp_dir() . '/trace-evidence-' . bin2hex(random_bytes(8));
mkdir($root, 0700);

// Executa os trechos reais do endpoint de seleção de bytes, rótulos e renderização.
// Não substitui testes HTTP/MySQL nem representa captura física de câmera.
function emitir(string $pipeline, string $render, string $storageRoot, array $images, array $requests, array $previous = []): array
{
    $selection = ConferidorEvidenciasRelatorio::selecionarDisponiveis($images, $requests);
    $incidentImages = $selection['images'];
    $evidenceWarnings = array_map(static fn(array $issue): string => 'Vínculo não confirmado: ' . $issue['detail'], $selection['issues']);
    $previousEvidenceImagesByReference = $previous;
    $formatDateTime = static fn(string $value): string => $value;
    $sackReferencesByEvent = [101 => 'Saca 1', 102 => 'Saca 2'];
    $operationSummary = [];
    $noReadRows = [['sensor_event_id' => 101, 'sack_reference' => 'Saca 1', 'return_reason' => 'Retirada confirmada']];
    $wrongProductRows = [['sensor_event_id' => 102, 'sack_reference' => 'Saca 2']];
    eval('use App\\Aplicacao\\RelatorioAuditoriaPdf;' . $pipeline);
    $report = new RelatorioAuditoriaPdf('Teste sintético', 'Fotos opcionais — sem operação real');
    $report->escreverTitulo('Incidentes');
    foreach ([...$noReadRows, ...$wrongProductRows] as $row) {
        $report->escreverParagrafo($row['sack_reference'] . ': ' . $row['evidence_label']);
    }
    eval($render);
    $pdf = $report->obterArquivoPdf();
    verificar(str_starts_with($pdf, '%PDF-') && str_contains($pdf, '%%EOF'), 'PDF não foi concluído');
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
    $missing = emitir($pipeline, $render, $root, $images, $requests);
    verificar(count($missing['evidenceImages']) === 0 && count($missing['evidenceWarnings']) >= 2, 'Foto ausente não foi registrada');
    verificar($missing['operationSummary']['no_read_images'] === 0, 'Foto ausente contada como anexada');
    verificar(str_contains($missing['noReadRows'][0]['evidence_label'], 'Retirada confirmada'), 'Histórico de retirada perdido');

    file_put_contents($root . '/' . $images[0]['path'], $bytes[0]);
    $partial = emitir($pipeline, $render, $root, $images, $requests);
    verificar(count($partial['evidenceImages']) === 1, 'Uma foto ausente impediu anexar a outra');
    verificar(count(RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($partial['pdf'])) === 1, 'Imagem válida não entrou no PDF');

    file_put_contents($root . '/' . $images[1]['path'], $bytes[1]);
    $complete = emitir($pipeline, $render, $root, $images, $requests);
    verificar(count($complete['evidenceImages']) === 2 && $complete['evidenceWarnings'] === [], 'Foto tardia não incorporada na nova emissão');

    $captionReport = new RelatorioAuditoriaPdf('Teste', 'Legenda longa');
    $cursor = new ReflectionProperty($captionReport, 'y');
    $before = $cursor->getValue($captionReport);
    verificar($captionReport->inserirDadosDaImagemDaOcorrencia($bytes[0], 'image/png', str_repeat('Evidência vinculada ao evento. ', 8)), 'Legenda longa não aceita');
    verificar($before - $cursor->getValue($captionReport) > 60, 'Legenda longa não reservou espaço antes do próximo título');

    file_put_contents($root . '/' . $images[0]['path'], $bytes[1]);
    $swapped = emitir($pipeline, $render, $root, $images, $requests);
    verificar(count($swapped['evidenceImages']) === 1 && $swapped['evidenceWarnings'] !== [], 'Foto trocada não foi isolada');
    verificar($GLOBALS['evidence_test_errors'] !== [], 'Falha de integridade não foi registrada');

    $wrong = $images;
    $wrong[0]['sensor_event_id'] = 999;
    $mismatch = emitir($pipeline, $render, $root, $wrong, $requests);
    verificar(count($mismatch['evidenceImages']) === 1 && $mismatch['evidenceWarnings'] !== [], 'Vínculo duvidoso entrou no relatório');

    unlink($root . '/' . $images[0]['path']);
    $recovered = emitir($pipeline, $render, $root, $images, $requests,
        ['request:1' => [['bytes' => $bytes[0], 'mime' => 'image/png', 'sha256' => $requests[0]['image_sha256']]]]);
    verificar(count($recovered['evidenceImages']) === 2, 'Recuperação do PDF anterior deixou de funcionar');

    if (isset($argv[1])) file_put_contents($argv[1], $partial['pdf']);
} finally {
    foreach ($images as $image) {
        $path = $root . '/' . $image['path'];
        if (is_file($path)) unlink($path);
    }
    rmdir($root);
}
echo "ok\n";
