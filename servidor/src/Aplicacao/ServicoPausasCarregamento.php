<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;

/** Mantém a janela física da pausa separada do estado lógico do comando pendente. */
final class ServicoPausasCarregamento
{
    public static function estaNaPausa(PDO $connection, int $loadingId, int $eventAtEpochMs): bool
    {
        $statement = $connection->prepare(
            "SELECT 1 FROM pausas_carregamento
             WHERE carregamento_id = :loading_id
               AND paused_at_epoch_ms <= :event_at_open
               AND (resumed_at_epoch_ms IS NULL OR resumed_at_epoch_ms > :event_at_close)
             LIMIT 1",
        );
        $statement->execute([
            "loading_id" => $loadingId,
            "event_at_open" => $eventAtEpochMs,
            "event_at_close" => $eventAtEpochMs,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** Registra o instante em que o gateway confirmou a pausa ou o reinício físico. */
    public static function registrarComandoAplicado(
        PDO $connection,
        int $companyId,
        int $loadingId,
        string $command,
        int $appliedAtEpochMs,
        ?int $pauseStartedAtEpochMs = null,
    ): ?int {
        if ($companyId <= 0 || $loadingId <= 0 || $appliedAtEpochMs <= 0) {
            return null;
        }

        if ($command === "PAUSAR_CARREGAMENTO") {
            $open = $connection->prepare(
                "SELECT paused_at_epoch_ms FROM pausas_carregamento
                 WHERE carregamento_id = :loading_id AND resumed_at_epoch_ms IS NULL
                 ORDER BY paused_at_epoch_ms DESC LIMIT 1 FOR UPDATE",
            );
            $open->execute(["loading_id" => $loadingId]);
            $pausedAt = $open->fetchColumn();
            if ($pausedAt === false) {
                $pausedAt = $appliedAtEpochMs;
                $insert = $connection->prepare(
                    "INSERT INTO pausas_carregamento (company_id, carregamento_id, paused_at_epoch_ms)
                     VALUES (:company_id, :loading_id, :paused_at)
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
                );
                $insert->execute([
                    "company_id" => $companyId,
                    "loading_id" => $loadingId,
                    "paused_at" => $pausedAt,
                ]);
            } else {
                $pausedAt = (int) $pausedAt;
            }
            self::reclassificarPassagens($connection, $loadingId);
            return (int) $pausedAt;
        }

        if ($command !== "INICIAR_CARREGAMENTO") {
            return null;
        }

        $open = $connection->prepare(
            "SELECT id, paused_at_epoch_ms FROM pausas_carregamento
             WHERE carregamento_id = :loading_id AND resumed_at_epoch_ms IS NULL
             ORDER BY paused_at_epoch_ms DESC LIMIT 1 FOR UPDATE",
        );
        $open->execute(["loading_id" => $loadingId]);
        $pause = $open->fetch(PDO::FETCH_ASSOC);
        if ($pause !== false) {
            $pausedAt = (int) $pause["paused_at_epoch_ms"];
            $close = $connection->prepare(
                "UPDATE pausas_carregamento SET resumed_at_epoch_ms = :resumed_at
                 WHERE id = :id AND resumed_at_epoch_ms IS NULL",
            );
            $close->execute([
                "resumed_at" => max($appliedAtEpochMs, $pausedAt),
                "id" => (int) $pause["id"],
            ]);
            self::reclassificarPassagens($connection, $loadingId);
            return $pausedAt;
        }

        // Se a sincronização entregar o reinício antes do evento de pausa,
        // conserve a janela fechada usando o início enviado pelo PC de origem.
        if ($pauseStartedAtEpochMs !== null && $pauseStartedAtEpochMs > 0) {
            $insertClosed = $connection->prepare(
                "INSERT INTO pausas_carregamento
                    (company_id, carregamento_id, paused_at_epoch_ms, resumed_at_epoch_ms)
                 VALUES (:company_id, :loading_id, :paused_at, :resumed_at)
                 ON DUPLICATE KEY UPDATE
                    resumed_at_epoch_ms = COALESCE(resumed_at_epoch_ms, VALUES(resumed_at_epoch_ms))",
            );
            $insertClosed->execute([
                "company_id" => $companyId,
                "loading_id" => $loadingId,
                "paused_at" => $pauseStartedAtEpochMs,
                "resumed_at" => max($appliedAtEpochMs, $pauseStartedAtEpochMs),
            ]);
            self::reclassificarPassagens($connection, $loadingId);
            return $pauseStartedAtEpochMs;
        }

        return null;
    }

    /** Reavalia eventos já recebidos, inclusive passagens sincronizadas fora de ordem. */
    public static function reclassificarPassagens(PDO $connection, int $loadingId): void
    {
        $events = $connection->prepare(
            "UPDATE eventos_sensor s
             SET conta_na_meta = CASE WHEN s.conta_na_meta_origem = 0 THEN 0
                 WHEN s.event_at_epoch_ms IS NULL THEN 1
                 WHEN EXISTS (
                     SELECT 1 FROM pausas_carregamento p
                     WHERE p.carregamento_id = s.carregamento_id
                       AND p.paused_at_epoch_ms <= s.event_at_epoch_ms
                       AND (p.resumed_at_epoch_ms IS NULL OR p.resumed_at_epoch_ms > s.event_at_epoch_ms)
                 ) THEN 0 ELSE 1 END
             WHERE s.carregamento_id = :loading_id",
        );
        $events->execute(["loading_id" => $loadingId]);

        $readings = $connection->prepare(
            "UPDATE leituras l
             JOIN eventos_sensor s ON s.id = l.sensor_event_id
             SET l.conta_na_meta = s.conta_na_meta
             WHERE l.carregamento_id = :loading_id",
        );
        $readings->execute(["loading_id" => $loadingId]);

        $counter = $connection->prepare(
            "UPDATE carregamentos c SET leituras_validas = (
                 SELECT COUNT(*) FROM leituras l
                 WHERE l.company_id = c.company_id AND l.carregamento_id = c.id
                   AND l.result = 'VALIDO' AND l.conta_na_meta = 1
             ) WHERE c.id = :loading_id",
        );
        $counter->execute(["loading_id" => $loadingId]);
    }
}
