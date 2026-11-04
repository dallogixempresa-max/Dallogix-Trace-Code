<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;
use RuntimeException;

require_once __DIR__ . "/../../../scripts/image_storage_path.php";

/** Enfileira cópias imutáveis dos PDFs para publicação no Central. */
final class ServicoPublicacaoRelatoriosAuditoria
{
    public const ACAO = "RELATORIO_AUDITORIA_PUBLICADO";
    private const TAMANHO_MAXIMO_PDF = 24 * 1024 * 1024;
    private const CHAVE_BACKFILL = "relatorio_auditoria_pdf_pc_202611";

    private readonly string $storageRoot;

    public function __construct(private readonly PDO $connection, ?string $storageRoot = null)
    {
        $this->storageRoot = $storageRoot ?? dirname(__DIR__, 3) . "/armazenamento";
    }

    /** Enfileira o PDF recém-gerado ou já salvo no PC local. */
    public function enfileirar(int $companyId, int $romaneioId, string $relativeReportPath): bool
    {
        if (!function_exists("trace_e_instalacao_local") || !trace_e_instalacao_local()) {
            return false;
        }
        if ($companyId <= 0 || $romaneioId <= 0) {
            throw new RuntimeException("Romaneio inválido para sincronizar o relatório.");
        }

        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            $queued = $this->enfileirarArquivo($companyId, $romaneioId, $relativeReportPath);
            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $queued;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** Reenfileira uma vez os PDFs que já existiam antes da atualização. */
    public function reenfileirarExistentesUmaVez(): int
    {
        if (!function_exists("trace_e_instalacao_local") || !trace_e_instalacao_local()) {
            return 0;
        }
        $companyId = (int) ($this->connection->query(
            "SELECT company_id FROM instalacoes_locais WHERE id = 1 LIMIT 1",
        )->fetchColumn() ?: 0);
        if ($companyId <= 0) {
            return 0;
        }

        $this->connection->beginTransaction();
        try {
            $marker = $this->connection->prepare(
                "INSERT IGNORE INTO sync_backfill_runs (backfill_key) VALUES (:backfill_key)",
            );
            $marker->execute(["backfill_key" => self::CHAVE_BACKFILL]);
            if ($marker->rowCount() !== 1) {
                $this->connection->commit();
                return 0;
            }

            $relativeDirectory = "company_{$companyId}/reports";
            $directory = trace_prepare_image_storage_directory($this->storageRoot, $relativeDirectory);
            if ($directory === null) {
                throw new RuntimeException("Diretório dos relatórios de romaneio indisponível.");
            }

            $queued = 0;
            foreach (glob($directory . DIRECTORY_SEPARATOR . "romaneio-*-auditoria.pdf") ?: [] as $path) {
                $name = basename($path);
                if (preg_match('/\Aromaneio-([1-9][0-9]*)-auditoria\.pdf\z/', $name, $matches) !== 1) {
                    continue;
                }
                $relativePath = $relativeDirectory . "/" . $name;
                if ($this->enfileirarArquivo($companyId, (int) $matches[1], $relativePath)) {
                    $queued++;
                }
            }

            $saveCount = $this->connection->prepare(
                "UPDATE sync_backfill_runs SET affected_events = :affected_events WHERE backfill_key = :backfill_key",
            );
            $saveCount->execute([
                "affected_events" => $queued,
                "backfill_key" => self::CHAVE_BACKFILL,
            ]);
            $this->connection->commit();
            return $queued;
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{relative_path:string,sha256:string,size_bytes:int} */
    private function validarEPrepararSnapshot(int $companyId, int $romaneioId, string $relativeReportPath): array
    {
        $sourcePath = trace_image_storage_path($this->storageRoot, $relativeReportPath);
        if ($sourcePath === null) {
            throw new RuntimeException("PDF do romaneio não foi encontrado no armazenamento local.");
        }
        $size = filesize($sourcePath);
        $handle = fopen($sourcePath, "rb");
        if ($size === false || $size < 5 || $size > self::TAMANHO_MAXIMO_PDF || $handle === false) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException("Tamanho ou conteúdo do PDF do romaneio inválido para sincronização.");
        }
        try {
            if (fread($handle, 5) !== "%PDF-") {
                throw new RuntimeException("Arquivo do relatório de romaneio não é um PDF válido.");
            }
        } finally {
            fclose($handle);
        }

        $sha256 = hash_file("sha256", $sourcePath);
        if (!is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException("Não foi possível validar a integridade do PDF do romaneio.");
        }

        $snapshotName = "romaneio-{$romaneioId}-{$sha256}.pdf";
        return [
            "relative_path" => "company_{$companyId}/reports/outbox/" . $snapshotName,
            "sha256" => $sha256,
            "size_bytes" => (int) $size,
        ];
    }

    private function copiarSnapshot(string $sourceRelativePath, string $snapshotRelativePath, string $expectedHash): void
    {
        $sourcePath = trace_image_storage_path($this->storageRoot, $sourceRelativePath);
        if ($sourcePath === null) {
            throw new RuntimeException("PDF do romaneio não foi encontrado no armazenamento local.");
        }
        $segments = explode("/", $snapshotRelativePath);
        $snapshotName = array_pop($segments);
        $resolvedDirectory = trace_prepare_image_storage_directory(
            $this->storageRoot,
            implode("/", $segments),
        );
        if ($resolvedDirectory === null || $snapshotName === false || $snapshotName === "") {
            throw new RuntimeException("Diretório de saída para sincronização de relatórios indisponível.");
        }
        $snapshotPath = $resolvedDirectory . DIRECTORY_SEPARATOR . $snapshotName;
        if (is_link($snapshotPath)) {
            throw new RuntimeException("Snapshot do relatório de romaneio contém link simbólico.");
        }
        if (is_file($snapshotPath)) {
            if (hash_file("sha256", $snapshotPath) !== $expectedHash) {
                throw new RuntimeException("Snapshot existente do relatório não corresponde ao hash esperado.");
            }
            return;
        }
        if (!copy($sourcePath, $snapshotPath) || !chmod($snapshotPath, 0600)) {
            throw new RuntimeException("Não foi possível preparar o snapshot do relatório para sincronização.");
        }
        if (hash_file("sha256", $snapshotPath) !== $expectedHash) {
            trace_remove_image_storage_file($this->storageRoot, $snapshotRelativePath);
            throw new RuntimeException("O PDF mudou ao preparar o snapshot para sincronização.");
        }
    }

    private function enfileirarArquivo(int $companyId, int $romaneioId, string $relativeReportPath): bool
    {
        $snapshot = $this->validarEPrepararSnapshot($companyId, $romaneioId, $relativeReportPath);
        $evidenceRelativePath = $relativeReportPath . ".evidence.json";
        $evidencePath = trace_image_storage_path($this->storageRoot, $evidenceRelativePath);
        $evidenceBytes = $evidencePath === null ? false : file_get_contents($evidencePath);
        $evidenceManifest = is_string($evidenceBytes) ? json_decode($evidenceBytes, true) : null;
        if ($evidencePath !== null && (!is_array($evidenceManifest)
            || !hash_equals($snapshot["sha256"], (string) ($evidenceManifest["pdf_sha256"] ?? "")))) {
            throw new RuntimeException("Manifesto de evidências ausente ou incompatível com o PDF.");
        }
        $reportData = [
            "source_romaneio_id" => $romaneioId,
            "report_path" => $snapshot["relative_path"],
            "sha256" => $snapshot["sha256"],
            "size_bytes" => $snapshot["size_bytes"],
            "evidence_no_read_count" => (int) (is_array($evidenceManifest) ? ($evidenceManifest["no_read_count"] ?? 0) : 0),
            "evidence_count" => (int) (is_array($evidenceManifest) ? ($evidenceManifest["evidence_count"] ?? 0) : 0),
            "evidence_excess_count" => (int) (is_array($evidenceManifest) ? ($evidenceManifest["excess_count"] ?? 0) : 0),
            "evidence_excess_evidence_count" => (int) (is_array($evidenceManifest) ? ($evidenceManifest["excess_evidence_count"] ?? 0) : 0),
        ];
        $existing = $this->connection->prepare(
            "SELECT id, status, payload FROM fila_sincronizacao
             WHERE company_id = :company_id AND aggregate_type = 'romaneio' AND aggregate_id = :romaneio_id
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.action')) = :action
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.sha256')) = :sha256
             LIMIT 1 FOR UPDATE",
        );
        $existing->execute([
            "company_id" => $companyId,
            "romaneio_id" => $romaneioId,
            "action" => self::ACAO,
            "sha256" => $snapshot["sha256"],
        ]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow !== false) {
            $existingPayload = json_decode((string) $existingRow["payload"], true);
            if (!is_array($existingPayload) || !is_array($existingPayload["data"] ?? null)) {
                throw new RuntimeException("Evento anterior do relatório está inválido.");
            }
            $existingData = $existingPayload["data"];
            $metadataMatches = true;
            foreach ([
                "evidence_no_read_count",
                "evidence_count",
                "evidence_excess_count",
                "evidence_excess_evidence_count",
            ] as $key) {
                $metadataMatches = $metadataMatches
                    && array_key_exists($key, $existingData)
                    && (int) $existingData[$key] === (int) $reportData[$key];
            }
            if ($metadataMatches) {
                return false;
            }
            if ($existingRow["status"] === "PROCESSANDO") {
                return false;
            }
            $this->copiarSnapshot(
                $relativeReportPath,
                $snapshot["relative_path"],
                $snapshot["sha256"],
            );
            $existingPayload["data"] = array_merge($existingData, $reportData);
            $updatedPayload = json_encode(
                $existingPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $requeue = $this->connection->prepare(
                "UPDATE fila_sincronizacao
                 SET payload = :payload, status = 'PENDENTE', attempts = 0, transient_attempts = 0,
                     last_error = NULL, available_at = NOW(), processing_started_at = NULL
                 WHERE id = :id AND company_id = :company_id AND status <> 'PROCESSANDO'",
            );
            $requeue->execute([
                "payload" => $updatedPayload,
                "id" => (int) $existingRow["id"],
                "company_id" => $companyId,
            ]);
            return $requeue->rowCount() === 1;
        }
        $this->copiarSnapshot(
            $relativeReportPath,
            $snapshot["relative_path"],
            $snapshot["sha256"],
        );

        if (!function_exists("registrar_evento_operacional")) {
            throw new RuntimeException("Fila de sincronização de eventos indisponível.");
        }
        registrar_evento_operacional(
            $this->connection,
            ["id" => null, "company_id" => $companyId],
            self::ACAO,
            "romaneio",
            $romaneioId,
            $reportData,
        );
        return true;
    }
}
