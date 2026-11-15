<?php

declare(strict_types=1);

namespace App\Aplicacao;

require_once __DIR__ . "/RelatorioAuditoriaPdf.php";

/** Recupera os bytes das evidências embutidas em um relatório anterior. */
final class FontesEvidenciasRelatorio
{
    /**
     * @param array<string,mixed>|null $manifest
     * @param list<array<string,mixed>> $capturedRequests
     * @return array<string,list<array{bytes:string,mime:string,sha256:string}>>
     */
    public static function indexar(
        string $pdf,
        ?array $manifest,
        array $capturedRequests,
        int $pdfModifiedAt,
    ): array {
        $images = RelatorioAuditoriaPdf::extrairImagensOriginaisDoPdf($pdf);
        if ($images === []) {
            return [];
        }

        foreach ($images as &$image) {
            $image["sha256"] = hash("sha256", $image["bytes"]);
        }
        unset($image);

        $sourceMetadata = is_array($manifest["source_images"] ?? null)
            ? $manifest["source_images"]
            : [];
        if ($sourceMetadata !== [] && count($sourceMetadata) === count($images)) {
            $indexed = [];
            foreach ($sourceMetadata as $index => $source) {
                if (!is_array($source)
                    || !is_string($source["sha256"] ?? null)
                    || !hash_equals(strtolower($source["sha256"]), $images[$index]["sha256"])) {
                    return [];
                }
                self::adicionarReferencia($indexed, $source, $images[$index]);
            }
            return $indexed;
        }

        return self::indexarManifestoLegado($images, $manifest, $capturedRequests, $pdfModifiedAt);
    }

    /** @param array<string,list<array{bytes:string,mime:string,sha256:string}>> $indexed */
    private static function adicionarReferencia(array &$indexed, array $source, array $image): void
    {
        $requestId = filter_var($source["request_id"] ?? null, FILTER_VALIDATE_INT);
        if ($requestId !== false && (int) $requestId > 0) {
            $indexed["request:" . (int) $requestId][] = $image;
        }
        $imageId = filter_var($source["image_id"] ?? null, FILTER_VALIDATE_INT);
        if ($imageId !== false && (int) $imageId > 0) {
            $indexed["image:" . (int) $imageId][] = $image;
        }
    }

    /**
     * Manifestos antigos não guardam IDs por imagem. Só aceita a correspondência
     * cronológica quando totais, categorias e hashes conferem sem ambiguidade.
     *
     * @param list<array{bytes:string,mime:string,sha256:string}> $images
     * @param array<string,mixed>|null $manifest
     * @param list<array<string,mixed>> $capturedRequests
     * @return array<string,list<array{bytes:string,mime:string,sha256:string}>>
     */
    private static function indexarManifestoLegado(
        array $images,
        ?array $manifest,
        array $capturedRequests,
        int $pdfModifiedAt,
    ): array {
        if (!is_array($manifest)) {
            return [];
        }

        $expectedByReason = [
            "SEM_LEITURA" => (int) ($manifest["evidence_count"] ?? -1),
            "EXCESSO" => (int) ($manifest["excess_evidence_count"] ?? 0),
            "PRODUTO_INCORRETO" => (int) ($manifest["wrong_product_evidence_count"] ?? 0),
        ];
        if (in_array(-1, $expectedByReason, true)
            || array_sum($expectedByReason) !== count($images)) {
            return [];
        }

        usort($capturedRequests, static function (array $left, array $right): int {
            $timeOrder = strcmp((string) ($left["captured_at"] ?? ""), (string) ($right["captured_at"] ?? ""));
            return $timeOrder !== 0
                ? $timeOrder
                : ((int) ($left["request_id"] ?? 0) <=> (int) ($right["request_id"] ?? 0));
        });

        $priorRequests = [];
        $countsByReason = array_fill_keys(array_keys($expectedByReason), 0);
        foreach ($capturedRequests as $request) {
            $reason = strtoupper(trim((string) ($request["reason"] ?? "")));
            if (!array_key_exists($reason, $countsByReason)) {
                continue;
            }
            $capturedAt = trim((string) ($request["captured_at"] ?? ""));
            $capturedTimestamp = $capturedAt === "" ? false : strtotime($capturedAt . " UTC");
            if ($capturedTimestamp === false || $capturedTimestamp > $pdfModifiedAt) {
                continue;
            }
            $priorRequests[] = $request;
            $countsByReason[$reason]++;
        }

        if (count($priorRequests) !== count($images) || $countsByReason !== $expectedByReason) {
            return [];
        }

        $indexed = [];
        foreach ($priorRequests as $index => $request) {
            $requestId = (int) ($request["request_id"] ?? 0);
            $expectedHash = strtolower(trim((string) ($request["image_sha256"] ?? "")));
            if ($requestId < 1
                || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1
                || !hash_equals($expectedHash, $images[$index]["sha256"])) {
                return [];
            }
            $indexed["request:" . $requestId][] = $images[$index];
        }

        return $indexed;
    }
}
