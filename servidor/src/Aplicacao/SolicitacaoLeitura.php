<?php

declare(strict_types=1);

namespace App\Aplicacao;

use InvalidArgumentException;

final readonly class SolicitacaoLeitura
{
    private function __construct(
        public int $loadingId,
        public ?int $sensorEventId,
        public string $barcode,
        public bool $noRead,
        public string $offlineEventId,
        public int $attemptNumber,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @throws InvalidArgumentException Quando a entrada viola o contrato atual da API.
     */
    public static function fromPayload(array $payload, string $offlineEventId): self
    {
        $loadingId = filter_var($payload["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
        $sensorEventInput = $payload["sensor_event_id"] ?? null;
        $sensorEventId = filter_var($sensorEventInput, FILTER_VALIDATE_INT);
        $barcode = trim((string) ($payload["barcode"] ?? ""));
        $noReadInput = $payload["no_read"] ?? false;
        if (!is_bool($noReadInput)) {
            throw new InvalidArgumentException("Indicador de falha de leitura inválido.");
        }
        $errorMarker = preg_match('/^ERROR(?:[0-9]{8,14})?$/i', $barcode) === 1;
        if ($noReadInput && $barcode !== "" && !$errorMarker) {
            throw new InvalidArgumentException("Uma falha de leitura não pode conter um código de produto.");
        }
        $noRead = $noReadInput || $errorMarker;
        if ($noRead) {
            $barcode = "";
        }
        $offlineEventId = trim($offlineEventId);
        $attemptNumber = filter_var($payload["attempt_number"] ?? 1, FILTER_VALIDATE_INT);

        if ($offlineEventId !== "" && preg_match('/^[a-f0-9-]{16,36}$/i', $offlineEventId) !== 1) {
            throw new InvalidArgumentException("Identificador da leitura inválido.");
        }
        if (!$loadingId) {
            throw new InvalidArgumentException("Carregamento obrigatório.");
        }
        if ($sensorEventInput !== null && $sensorEventInput !== "" && $sensorEventId === false) {
            throw new InvalidArgumentException("Evento de sensor inválido.");
        }
        if (mb_strlen($barcode, "UTF-8") > 128 || preg_match('/[\x00-\x1F\x7F]/', $barcode) === 1) {
            throw new InvalidArgumentException("Código de barras inválido ou longo demais.");
        }
        if ($attemptNumber !== 1) {
            throw new InvalidArgumentException("Cada saco possui uma única tentativa de leitura.");
        }

        return new self(
            (int) $loadingId,
            $sensorEventId === false ? null : $sensorEventId,
            $barcode,
            $noRead,
            $offlineEventId,
            (int) $attemptNumber,
        );
    }
}
