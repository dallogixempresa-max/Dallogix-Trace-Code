<?php
declare(strict_types=1);
namespace App\Persistencia;

use PDO;

/** Consulta a mesma confirmação recente na exibição e no encerramento. */
final class RepositorioFilaSensor
{
    public function __construct(private readonly PDO $connection) {}

    public function consultar(int $equipmentId, bool $bloquear = false): array
    {
        $statement = $this->connection->prepare(
            "SELECT CASE
               WHEN status = 'ONLINE' AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND)
               THEN JSON_UNQUOTE(JSON_EXTRACT(details, '$.sensor_event_outbox_pending'))
               ELSE NULL
             END AS pending_events,
             CASE
               WHEN status = 'ONLINE' AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND)
               THEN JSON_EXTRACT(details, '$.sensor_event_outbox_pending_by_loading')
               ELSE NULL
             END AS pending_events_by_loading
             FROM status_dispositivos
             WHERE equipment_id = :equipment_id AND device_type = 'SENSOR'
             LIMIT 1" . ($bloquear ? " FOR UPDATE" : ""),
        );
        $statement->execute(["equipment_id" => $equipmentId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
