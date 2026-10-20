<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

/** Atualiza o último estado de cada tipo de dispositivo vinculado à Dala. */
final class RepositorioStatusDispositivosEventos
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function registrar(int $equipmentId, string $deviceType, string $status, string $details): void
    {
        $driver = (string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query = $driver === "sqlite"
            ? "INSERT INTO status_dispositivos (equipment_id, device_type, status, last_seen_at, details)
               VALUES (:equipment_id, :device_type, :status, NOW(3), :details)
               ON CONFLICT(equipment_id, device_type) DO UPDATE SET
                   status = excluded.status, last_seen_at = excluded.last_seen_at, details = excluded.details"
            : "INSERT INTO status_dispositivos (equipment_id, device_type, status, last_seen_at, details)
               VALUES (:equipment_id, :device_type, :status, NOW(3), :details)
               ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen_at = VALUES(last_seen_at), details = VALUES(details)";

        $this->connection->prepare($query)->execute([
            "equipment_id" => $equipmentId,
            "device_type" => $deviceType,
            "status" => $status,
            "details" => $details,
        ]);
    }
}
