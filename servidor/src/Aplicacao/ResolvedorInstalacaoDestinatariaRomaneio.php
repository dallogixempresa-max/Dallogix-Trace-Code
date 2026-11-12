<?php

declare(strict_types=1);

namespace App\Aplicacao;

use DomainException;
use PDO;

final class ResolvedorInstalacaoDestinatariaRomaneio
{
    public static function resolver(
        PDO $pdo,
        int $companyId,
        int $accountUserId,
        mixed $requestedInstallationId = null,
    ): ?int {
        $installationId = filter_var($requestedInstallationId, FILTER_VALIDATE_INT);
        if ($installationId !== false && $installationId !== null && $installationId > 0) {
            $installationCheck = $pdo->prepare(
                "SELECT id FROM instalacoes_industriais
                 WHERE id = :id AND company_id = :company_id
                   AND archived_at IS NULL AND access_blocked_at IS NULL
                 LIMIT 1",
            );
            $installationCheck->execute([
                "id" => $installationId,
                "company_id" => $companyId,
            ]);
            if (!$installationCheck->fetchColumn()) {
                throw new DomainException("Instalação industrial não encontrada ou inacessível.");
            }
            return (int) $installationId;
        }

        $installationsStatement = $pdo->prepare(
            "SELECT id, created_by FROM instalacoes_industriais
             WHERE company_id = :company_id
               AND archived_at IS NULL AND access_blocked_at IS NULL
             ORDER BY id",
        );
        $installationsStatement->execute(["company_id" => $companyId]);
        $installations = $installationsStatement->fetchAll(PDO::FETCH_ASSOC);

        $accountInstallations = [];
        foreach ($installations as $installation) {
            if ((int) ($installation["created_by"] ?? 0) === $accountUserId) {
                $accountInstallations[] = $installation;
            }
        }

        if (count($accountInstallations) === 1) {
            return (int) $accountInstallations[0]["id"];
        }
        if (count($installations) === 1) {
            return (int) $installations[0]["id"];
        }
        if (count($installations) > 1) {
            throw new DomainException(
                "A conta logada não identifica um único PC industrial para receber este romaneio.",
            );
        }

        return null;
    }
}
