<?php

declare(strict_types=1);

namespace App\Aplicacao;

use InvalidArgumentException;
use PDO;

require_once __DIR__ . "/../../../scripts/image_storage_path.php";

/** Confirma que cada leitura de incidente tem uma imagem original vinculada. */
final class ValidadorEvidenciasOperacionais
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storageRoot,
    ) {
    }

    public function contarLeiturasSemImagemValida(
        int $companyId,
        int $loadingId,
        int $equipmentId,
        string $reason,
    ): int {
        if (!in_array($reason, ["SEM_LEITURA", "PRODUTO_INCORRETO"], true)) {
            throw new InvalidArgumentException("Tipo de evidência operacional inválido.");
        }

        $statement = $this->pdo->prepare(
            "SELECT l.id AS reading_id, e.id AS request_id, e.status, e.image_sha256,
                    e.equipment_id AS request_equipment_id, i.path
             FROM leituras l
             JOIN carregamentos c ON c.id = l.carregamento_id AND c.company_id = l.company_id
             LEFT JOIN solicitacoes_imagens_evidencia e
               ON e.sensor_event_id = l.sensor_event_id
              AND e.carregamento_id = l.carregamento_id AND e.reason = :request_reason
             LEFT JOIN imagens i
               ON i.company_id = c.company_id AND i.carregamento_id = l.carregamento_id
              AND i.equipment_id = c.equipment_id AND i.reason = :image_reason
              AND (i.reading_id = l.id OR (
                i.reading_id IS NULL AND e.id IS NOT NULL
                AND i.path LIKE CONCAT('%keyence-', e.id, '-%')
              ))
             WHERE l.company_id = :company_id AND l.carregamento_id = :loading_id
               AND c.equipment_id = :equipment_id AND l.result = :result
             ORDER BY l.id",
        );
        $statement->execute([
            "request_reason" => $reason,
            "image_reason" => $reason,
            "company_id" => $companyId,
            "loading_id" => $loadingId,
            "equipment_id" => $equipmentId,
            "result" => $reason,
        ]);

        $requiredReadings = [];
        $validReadings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $readingId = (int) $row["reading_id"];
            $requiredReadings[$readingId] = true;
            $requestId = (int) ($row["request_id"] ?? 0);
            $requestHash = strtolower(trim((string) ($row["image_sha256"] ?? "")));
            $storedPath = trim((string) ($row["path"] ?? ""));
            if (
                $requestId < 1
                || ($row["status"] ?? null) !== "CAPTURADA"
                || (int) ($row["request_equipment_id"] ?? 0) !== $equipmentId
                || preg_match('/\A[a-f0-9]{64}\z/', $requestHash) !== 1
                || $storedPath === ""
            ) {
                continue;
            }

            $basename = basename($storedPath);
            if (preg_match(
                '/\A(?:keyence|capture)-' . $requestId . '-([a-f0-9]{64})\.(?:jpg|png)\z/',
                $basename,
                $matches,
            ) !== 1 || !hash_equals($requestHash, strtolower($matches[1]))) {
                continue;
            }
            // O PC valida o arquivo, o MIME, as dimensões e o SHA-256 antes de
            // marcar a solicitação como CAPTURADA. O Central recebe o vínculo e
            // o hash, mas não recebe o arquivo original separado do PDF publicado.
            if (function_exists("trace_e_instalacao_local") && trace_e_instalacao_local()
                && trace_evidence_image_path(
                    $this->storageRoot,
                    $companyId,
                    $equipmentId,
                    $requestId,
                    $storedPath,
                ) === null) {
                continue;
            }
            $validReadings[$readingId] = true;
        }

        return count(array_diff_key($requiredReadings, $validReadings));
    }

    /** @param list<string> $reasons */
    public function contarLeiturasSemImagemValidaNoRomaneio(
        int $companyId,
        int $romaneioId,
        array $reasons,
    ): int {
        $loadings = $this->pdo->prepare(
            "SELECT id, equipment_id FROM carregamentos
             WHERE company_id = :company_id AND romaneio_id = :romaneio_id ORDER BY id",
        );
        $loadings->execute(["company_id" => $companyId, "romaneio_id" => $romaneioId]);

        $missing = 0;
        foreach ($loadings->fetchAll(PDO::FETCH_ASSOC) as $loading) {
            foreach ($reasons as $reason) {
                $missing += $this->contarLeiturasSemImagemValida(
                    $companyId,
                    (int) $loading["id"],
                    (int) $loading["equipment_id"],
                    $reason,
                );
            }
        }
        return $missing;
    }
}
