<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Registra o recebimento idempotente de eventos da instalação remota. */
final class RepositorioAuditoriaEventosSincronizacao
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Busca ações já auditadas em lote para evitar uma consulta por evento.
     *
     * @param list<string> $eventUuids
     * @return array<string, string> Ações indexadas pelo UUID em minúsculas.
     */
    public function buscarAcoesPorUuid(int $companyId, array $eventUuids): array
    {
        $uuids = [];
        foreach ($eventUuids as $eventUuid) {
            $eventUuid = trim($eventUuid);
            if ($eventUuid !== "") {
                $uuids[strtolower($eventUuid)] = $eventUuid;
            }
        }
        if ($uuids === []) {
            return [];
        }

        $actions = [];
        foreach (array_chunk(array_values($uuids), 500) as $batch) {
            $placeholders = [];
            $parameters = ["company_id" => $companyId];
            foreach ($batch as $index => $eventUuid) {
                $parameter = "event_uuid_{$index}";
                $placeholders[] = ":{$parameter}";
                $parameters[$parameter] = $eventUuid;
            }

            $statement = $this->connection->prepare(
                "SELECT event_uuid, action FROM logs_auditoria
                 WHERE company_id = :company_id AND event_uuid IN (" . implode(", ", $placeholders) . ")",
            );
            $statement->execute($parameters);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower((string) $row["event_uuid"]);
                if (!array_key_exists($key, $actions)) {
                    $actions[$key] = (string) $row["action"];
                }
            }
        }

        return $actions;
    }

    /** @param array{event_uuid:string,company_id:int,action:string,entity_type:string,entity_id:int,metadata:string} $event */
    public function registrar(array $event, bool $jaRegistrado): void
    {
        if ($jaRegistrado) {
            $statement = $this->connection->prepare(
                "UPDATE logs_auditoria
                 SET action = :action, entity_type = :entity_type, entity_id = :entity_id,
                     metadata = :metadata, delivered_at = NOW(3)
                 WHERE company_id = :company_id AND event_uuid = :event_uuid",
            );
            $statement->execute($event);
            return;
        }

        $statement = $this->connection->prepare(
            "INSERT INTO logs_auditoria
             (event_uuid, company_id, user_id, action, entity_type, entity_id, metadata, delivered_at)
             VALUES (:event_uuid, :company_id, NULL, :action, :entity_type, :entity_id, :metadata, NOW(3))",
        );
        $statement->execute($event);
    }
}
