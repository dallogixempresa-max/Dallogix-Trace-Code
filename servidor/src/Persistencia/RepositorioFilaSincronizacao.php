<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

final class RepositorioFilaSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Mantém a pendência ativa até que o evento correspondente seja enviado. */
    public function possuiEventoPendente(int $companyId, string $type, int $aggregateId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM fila_sincronizacao
             WHERE company_id = :company_id AND aggregate_type = :type
               AND aggregate_id = :aggregate_id AND status <> 'ENVIADO'
             LIMIT 1",
        );
        $statement->execute([
            "company_id" => $companyId,
            "type" => $type,
            "aggregate_id" => $aggregateId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function reservarLote(int $limit = 50, ?int $companyId = null, ?int $queueId = null): array
    {
        $limit = max(1, min(500, $limit));
        $this->connection->beginTransaction();
        try {
            $where = [
                "q.status IN ('PENDENTE', 'ERRO')",
                "(q.available_at IS NULL OR q.available_at <= NOW())",
                // Mantém a ordem de eventos do mesmo agregado apesar do backoff.
                "NOT EXISTS (
                    SELECT 1 FROM fila_sincronizacao anterior
                    WHERE anterior.company_id = q.company_id
                      AND anterior.aggregate_type = q.aggregate_type
                      AND anterior.aggregate_id = q.aggregate_id
                      AND anterior.id < q.id
                      AND anterior.status IN ('PENDENTE', 'PROCESSANDO', 'ERRO')
                )",
            ];
            $params = [];
            if ($companyId !== null) {
                $where[] = "q.company_id = :company_id";
                $params["company_id"] = $companyId;
            }
            if ($queueId !== null) {
                $where[] = "q.id = :queue_id";
                $params["queue_id"] = $queueId;
            }

            $statement = $this->connection->prepare(
                "SELECT q.id, q.company_id, e.remote_company_id, q.event_uuid, q.aggregate_type, q.aggregate_id, q.payload,
                        q.status, q.attempts, q.transient_attempts, q.last_error, q.available_at, q.created_at
                 FROM fila_sincronizacao q
                 LEFT JOIN empresas e ON e.id = q.company_id
                 WHERE " . implode(" AND ", $where) .
                    " ORDER BY q.id ASC LIMIT {$limit} FOR UPDATE SKIP LOCKED",
            );
            $statement->execute($params);
            $events = $statement->fetchAll();
            if ($events === []) {
                $this->connection->commit();
                return [];
            }

            $update = $this->connection->prepare(
                "UPDATE fila_sincronizacao
                 SET status = 'PROCESSANDO', processing_started_at = NOW(3), last_error = NULL
                 WHERE id = :id AND status IN ('PENDENTE', 'ERRO')",
            );
            foreach ($events as $event) {
                $update->execute(["id" => $event["id"]]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException("Não foi possível reservar evento de sincronização.");
                }
                $event["status"] = "PROCESSANDO";
                $event["attempts"] = (int) $event["attempts"];
            }
            $this->connection->commit();
            return $events;
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $events */
    public function marcarComoEnviados(array $events): void
    {
        $this->connection->beginTransaction();
        try {
            $queue = $this->connection->prepare(
                "UPDATE fila_sincronizacao
                 SET status = 'ENVIADO', last_error = NULL, processing_started_at = NULL
                 WHERE id = :id AND company_id = :company_id AND status = 'PROCESSANDO'",
            );
            $audit = $this->connection->prepare(
                "UPDATE logs_auditoria
                 SET delivered_at = COALESCE(delivered_at, NOW(3))
                 WHERE company_id = :company_id AND event_uuid = :event_uuid",
            );
            foreach ($events as $event) {
                $queue->execute([
                    "id" => (int) $event["id"],
                    "company_id" => (int) $event["company_id"],
                ]);
                if ($queue->rowCount() !== 1) {
                    throw new RuntimeException("Evento de sincronização não está mais reservado.");
                }
                $audit->execute([
                    "company_id" => (int) $event["company_id"],
                    "event_uuid" => $event["event_uuid"],
                ]);
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $events */
    public function marcarComoFalha(array $events, string $error, bool $permanent, int $maxAttempts): void
    {
        $incrementPermanentAttempts = $permanent ? 1 : 0;
        $this->connection->beginTransaction();
        try {
            $failed = $this->connection->prepare(
                "UPDATE fila_sincronizacao
                 SET status = 'ERRO', attempts = attempts + :increment_attempts,
                     transient_attempts = transient_attempts + 1,
                     last_error = :last_error,
                     available_at = DATE_ADD(NOW(), INTERVAL LEAST(POWER(2, LEAST(transient_attempts, 8)) * 5, 900) SECOND),
                     processing_started_at = NULL
                 WHERE id = :id AND company_id = :company_id AND status = 'PROCESSANDO'",
            );
            foreach ($events as $event) {
                $failed->execute([
                    "id" => (int) $event["id"],
                    "company_id" => (int) $event["company_id"],
                    "last_error" => $error,
                    "increment_attempts" => $incrementPermanentAttempts,
                ]);
                if ($failed->rowCount() !== 1) {
                    throw new RuntimeException("Evento de sincronização não está mais reservado para falha.");
                }
                $nextPermanentAttempts = (int) ($event["attempts"] ?? 0) + $incrementPermanentAttempts;
                if ($permanent && $nextPermanentAttempts >= $maxAttempts) {
                    $this->moverParaFilaMorta($event, $error);
                }
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function recuperarReservasVencidas(int $expiredAfterMinutes): void
    {
        $expiredAfterMinutes = max(1, min(1440, $expiredAfterMinutes));
        $statement = $this->connection->prepare(
            "UPDATE fila_sincronizacao
             SET status = 'ERRO',
                 last_error = 'Reserva recuperada após expirar o tempo de processamento.',
                 available_at = NOW(), processing_started_at = NULL
             WHERE status = 'PROCESSANDO'
               AND processing_started_at < DATE_SUB(NOW(), INTERVAL {$expiredAfterMinutes} MINUTE)",
        );
        $statement->execute();
    }

    /** @return array<string,mixed>|null */
    public function localizarEvento(int $queueId, int $companyId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM fila_sincronizacao
             WHERE id = :id AND company_id = :company_id
               AND status IN ('PENDENTE', 'ERRO')
             LIMIT 1",
        );
        $statement->execute(["id" => $queueId, "company_id" => $companyId]);
        $event = $statement->fetch();
        return $event ?: null;
    }

    public function urlRemotaDaEmpresa(int $companyId): string
    {
        $statement = $this->connection->prepare(
            "SELECT sync_remote_url FROM configuracoes_empresa WHERE company_id = :company_id LIMIT 1",
        );
        $statement->execute(["company_id" => $companyId]);

        return trim((string) ($statement->fetchColumn() ?: ""));
    }

    /**
     * Retorna cada destino uma vez, mesmo que a empresa tenha vários eventos pendentes.
     * @return list<array{company_id:int,sync_remote_url:string}>
     */
    public function destinosRemotosDisponiveis(): array
    {
        $statement = $this->connection->query(
            "SELECT DISTINCT q.company_id, c.sync_remote_url
             FROM fila_sincronizacao q
             JOIN configuracoes_empresa c ON c.company_id = q.company_id
             WHERE q.status IN ('PENDENTE', 'ERRO')
               AND (q.available_at IS NULL OR q.available_at <= CURRENT_TIMESTAMP)
               AND c.sync_remote_url IS NOT NULL AND TRIM(c.sync_remote_url) <> ''
             ORDER BY q.company_id",
        );
        return array_map(static fn (array $row): array => [
            "company_id" => (int) $row["company_id"],
            "sync_remote_url" => (string) $row["sync_remote_url"],
        ], $statement->fetchAll());
    }

    /** @param array<string,mixed> $event */
    private function moverParaFilaMorta(array $event, string $error): void
    {
        $insert = $this->connection->prepare(
            "INSERT INTO sync_dead_letter_queue
                (original_event_id, company_id, event_uuid, event_type, aggregate_id, payload, last_error, failed_attempts)
             SELECT id, company_id, event_uuid, aggregate_type, aggregate_id, payload, :last_error, attempts
             FROM fila_sincronizacao
             WHERE id = :id AND company_id = :company_id AND status = 'ERRO'
             ON DUPLICATE KEY UPDATE
                last_error = VALUES(last_error), failed_attempts = VALUES(failed_attempts),
                resolved_at = NULL, resolved_by = NULL, resolution_note = NULL",
        );
        $insert->execute([
            "id" => (int) $event["id"],
            "company_id" => (int) $event["company_id"],
            "last_error" => $error,
        ]);
        if ($insert->rowCount() < 1) {
            throw new RuntimeException("Não foi possível arquivar o evento na fila morta.");
        }

        $delete = $this->connection->prepare(
            "DELETE FROM fila_sincronizacao
             WHERE id = :id AND company_id = :company_id AND status = 'ERRO'",
        );
        $delete->execute([
            "id" => (int) $event["id"],
            "company_id" => (int) $event["company_id"],
        ]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException("Evento não foi removido da fila após arquivamento.");
        }
    }
}
