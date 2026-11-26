<?php
declare(strict_types=1);

// report-worker do PC industrial. Gera e publica o relatório de auditoria com as
// fotos do Keyence assim que o romaneio é encerrado ou cancelado, sem depender
// de alguém abrir o relatório no PC. Antes, quem baixava o relatório pelo
// central recebia a versão sem fotos (romaneio "teste novo ms", 25/11/2026).
//
// - Roda fora da sincronização (serviço próprio), para não atrasar o sinal do
//   sensor nem a fila.
// - Gera no máximo um romaneio por execução, num processo separado com tempo
//   máximo; falhas esperam cada vez mais antes de nova tentativa.
// - Um romaneio só é dado como publicado quando o gerador confirma sucesso. O
//   que foi publicado é guardado como uma impressão digital (estado do romaneio
//   e fotos capturadas) calculada ANTES da geração: uma foto que chega durante
//   ou depois dela muda a impressão e provoca nova geração. Horário de arquivo
//   não serve (mesmo segundo, PDF salvo com publicação falha).

/** @return array<string,array<string,mixed>> */
function relatorios_estado_ler(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function relatorios_estado_gravar(string $path, array $state): void
{
    $temporary = $path . ".tmp-" . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Não foi possível gravar o estado da geração automática de relatórios.");
    }
}

/** Segundos de espera depois de N falhas seguidas: 1, 2, 4... minutos, até 1 hora. */
function relatorios_espera_apos_falhas(int $failures): int
{
    return (int) min(3600, 60 * (2 ** max(0, $failures - 1)));
}

/**
 * Impressão digital do que o relatório mostra: romaneio, pedidos de foto
 * capturados, fotos (inclusive as adicionais de um pedido já capturado),
 * leituras, passagens, retornos e ocorrências. Leitura ou foto que chega
 * depois da geração muda a impressão e provoca nova geração.
 */
function relatorio_impressao_digital(array $candidate): string
{
    return implode("|", [
        (string) ($candidate["status"] ?? ""),
        (string) ($candidate["romaneio_updated"] ?? "0"),
        (string) ($candidate["content_fingerprint"] ?? ""),
    ]);
}

function relatorio_precisa_gerar(?string $publishedFingerprint, string $currentFingerprint): bool
{
    return $publishedFingerprint !== $currentFingerprint;
}

/**
 * O gerador só confirma com {"status":200,"data":...} e código 0. Qualquer
 * outra saída é falha, inclusive o tratador global do sistema, que responde
 * {"error":...} e encerra com código 0.
 */
function relatorio_saida_indica_sucesso(int $exitCode, string $output): bool
{
    if ($exitCode !== 0) {
        return false;
    }
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded) && ($decoded["status"] ?? null) === 200 && is_array($decoded["data"] ?? null)) {
            return true;
        }
    }
    return false;
}

/**
 * Saída normal e de erros ficam separadas: a confirmação do gerador está na
 * saída normal, e avisos na saída de erros não podem empurrá-la para fora.
 *
 * @return array{exit:int,stdout:string,stderr:string,output:string,timed_out:bool}
 */
function relatorio_executar_filho(array $command, int $timeoutSeconds): array
{
    $process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($process)) {
        $message = "Não foi possível iniciar o gerador de relatório.";
        return ["exit" => 1, "stdout" => "", "stderr" => $message, "output" => $message, "timed_out" => false];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = "";
    $stderr = "";
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr = substr($stderr . (string) stream_get_contents($pipes[2]), -65536);
        $status = proc_get_status($process);
        if (!$status["running"]) {
            $exit = (int) $status["exitcode"];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, 9);
            $exit = 124;
            break;
        }
        usleep(200000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr = substr($stderr . (string) stream_get_contents($pipes[2]), -65536);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $stdout = substr($stdout, -262144);
    return [
        "exit" => $exit,
        "stdout" => trim($stdout),
        "stderr" => trim($stderr),
        "output" => trim(mb_substr(trim($stdout) . "\n" . trim($stderr), -2000)),
        "timed_out" => $timedOut,
    ];
}

if (PHP_SAPI !== "cli" || basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? "")) !== basename(__FILE__)) {
    return; // incluído por testes: só as funções acima.
}

require_once __DIR__ . "/../servidor/configuracao/bootstrap.php";

// O tratador global do bootstrap encerra com código 0; aqui qualquer erro tem
// que sair com falha para o heartbeat não ser atualizado.
try {
    if (!function_exists("trace_e_instalacao_local") || !trace_e_instalacao_local()) {
        exit(0); // só o PC tem as fotos do Keyence.
    }
    $lock = fopen(sys_get_temp_dir() . "/trace-relatorios-auditoria.lock", "c");
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0); // uma geração ainda está em andamento.
    }

    $storageRoot = realpath(__DIR__ . "/../armazenamento");
    if ($storageRoot === false || !is_dir($storageRoot) || !is_writable($storageRoot)) {
        throw new RuntimeException("Armazenamento indisponível para os relatórios.");
    }
    $statePath = $storageRoot . DIRECTORY_SEPARATOR . "relatorios-auditoria-automaticos.json";
    $state = relatorios_estado_ler($statePath);
    $now = time();

    // Mais recentes primeiro: o romaneio que acabou de ser encerrado não espera
    // os antigos saírem da janela.
    $candidates = db()->query(
        "SELECT r.id, r.company_id, r.status,
                COALESCE(UNIX_TIMESTAMP(r.updated_at), 0) AS romaneio_updated,
                CONCAT_WS('/',
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(e.id), 0), ':', COALESCE(UNIX_TIMESTAMP(MAX(e.updated_at)), 0))
                     FROM solicitacoes_imagens_evidencia e JOIN carregamentos c ON c.id = e.carregamento_id
                    WHERE c.romaneio_id = r.id AND e.status = 'CAPTURADA'),
                  (SELECT CONCAT('erro:', COUNT(*))
                     FROM solicitacoes_imagens_evidencia e JOIN carregamentos c ON c.id = e.carregamento_id
                    WHERE c.romaneio_id = r.id AND e.status = 'ERRO'),
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(i.id), 0))
                     FROM imagens i JOIN carregamentos c ON c.id = i.carregamento_id
                    WHERE c.romaneio_id = r.id AND i.reason <> 'NORMAL'),
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(l.id), 0))
                     FROM leituras l JOIN carregamentos c ON c.id = l.carregamento_id
                    WHERE c.romaneio_id = r.id),
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(s.id), 0), ':', COALESCE(SUM(s.conta_na_meta), 0))
                     FROM eventos_sensor s JOIN carregamentos c ON c.id = s.carregamento_id
                    WHERE c.romaneio_id = r.id),
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(t.id), 0))
                     FROM retornos t JOIN carregamentos c ON c.id = t.carregamento_id
                    WHERE c.romaneio_id = r.id),
                  (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(o.id), 0))
                     FROM ocorrencias o JOIN carregamentos c ON c.id = o.carregamento_id
                    WHERE c.romaneio_id = r.id)
                ) AS content_fingerprint,
                (SELECT COUNT(*)
                   FROM solicitacoes_imagens_evidencia e JOIN carregamentos c ON c.id = e.carregamento_id
                  WHERE c.romaneio_id = r.id AND e.status IN ('PENDENTE', 'CAPTURANDO')) AS photos_waiting
         FROM romaneios r
         WHERE r.status IN ('FINALIZADO', 'CANCELADO')
           AND r.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND EXISTS (SELECT 1 FROM carregamentos c WHERE c.romaneio_id = r.id)
         ORDER BY r.updated_at DESC, r.id DESC
         LIMIT 200",
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $candidate) {
        $romaneioId = (int) $candidate["id"];
        $companyId = (int) $candidate["company_id"];
        $key = (string) $romaneioId;
        $entry = is_array($state[$key] ?? null) ? $state[$key] : [];
        $fingerprint = relatorio_impressao_digital($candidate);
        if (!relatorio_precisa_gerar(isset($entry["published"]) ? (string) $entry["published"] : null, $fingerprint)) {
            continue;
        }
        if ((int) ($entry["next_attempt"] ?? 0) > $now) {
            continue;
        }
        // Espera a última leitura e as fotos chegarem antes da primeira geração.
        // A foto do Keyence pode levar até 120 s (KEYENCE_IMAGE_RECEIVE_TIMEOUT_MS):
        // com foto ainda aguardando, espera até 150 s para não publicar um PDF
        // sem ela e logo outro com ela.
        $updatedAgo = $now - (int) $candidate["romaneio_updated"];
        if ($updatedAgo < 30 || ((int) ($candidate["photos_waiting"] ?? 0) > 0 && $updatedAgo < 150)) {
            continue;
        }

        $result = relatorio_executar_filho(
            [PHP_BINARY, "-d", "memory_limit=384M", __DIR__ . "/gerar_relatorio_auditoria_interno.php", (string) $companyId, (string) $romaneioId],
            // Com 0,5 CPU, um romaneio com muitas fotos pode passar de 3 min.
            300,
        );
        if (relatorio_saida_indica_sucesso($result["exit"], $result["stdout"])) {
            // Guarda a impressão calculada antes da geração: foto que chegou
            // durante a geração provoca outra no próximo ciclo.
            $state[$key] = ["published" => $fingerprint];
            echo json_encode(["relatorio_automatico" => "gerado", "romaneio_id" => $romaneioId, "saida" => $result["output"]], JSON_UNESCAPED_UNICODE), "\n";
        } else {
            $failures = (int) ($entry["failures"] ?? 0) + 1;
            $state[$key] = array_filter([
                "published" => $entry["published"] ?? null,
                "failures" => $failures,
                "next_attempt" => $now + relatorios_espera_apos_falhas($failures),
                "last_error" => $result["timed_out"] ? "tempo máximo excedido" : $result["output"],
            ], static fn (mixed $value): bool => $value !== null);
            fwrite(STDERR, json_encode(["relatorio_automatico" => "falhou", "romaneio_id" => $romaneioId, "tentativas" => $failures, "erro" => $state[$key]["last_error"]], JSON_UNESCAPED_UNICODE) . "\n");
        }
        break; // um por execução: o próximo ciclo continua.
    }

    // Esquece estados de romaneios que saíram da janela.
    $activeIds = array_map(static fn (array $row): string => (string) $row["id"], $candidates);
    $state = array_intersect_key($state, array_flip($activeIds));
    relatorios_estado_gravar($statePath, $state);
} catch (Throwable $exception) {
    fwrite(STDERR, "Geração automática de relatórios falhou: " . mb_substr($exception->getMessage(), 0, 500) . "\n");
    exit(1);
}
exit(0);
