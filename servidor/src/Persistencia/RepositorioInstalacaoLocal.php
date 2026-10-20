<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

final class RepositorioInstalacaoLocal
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array{company_id:?int,sync_token:?string}|null */
    public function buscarConfiguracaoSincronizacao(): ?array
    {
        $row = $this->connection->query(
            "SELECT company_id, sync_token FROM instalacoes_locais WHERE id = 1 LIMIT 1",
        )->fetch();
        if (!$row) {
            return null;
        }

        return [
            "company_id" => $row["company_id"] === null ? null : (int) $row["company_id"],
            "sync_token" => $row["sync_token"] === null ? null : (string) $row["sync_token"],
        ];
    }
}
