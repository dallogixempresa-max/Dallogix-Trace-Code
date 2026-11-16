<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;

/** Reconcilia a confirmação do gateway com eventos locais e sincronizados. */
final class ValidadorParadaCarregamento
{
    public static function confirmada(
        PDO $pdo,
        int $companyId,
        int $loadingId,
        int $equipmentId,
        string $stopReason,
    ): bool {
        $action = match ($stopReason) {
            "COTA_ROMANEIO_ATINGIDA" => "QUANTIDADE_PLANEJADA_ATINGIDA",
            "COTA_PRODUTO_ATINGIDA" => "COTA_PRODUTO_ATINGIDA",
            "EXCESSO" => "EXCESSO_DETECTADO",
            "PRODUTO_INCORRETO" => "PARADA_PRODUTO_INCORRETO",
            default => null,
        };
        if ($action === null) {
            return false;
        }

        $statement = $pdo->prepare(
            "SELECT EXISTS (
               SELECT 1 FROM logs_auditoria a
               WHERE a.company_id = :company_id AND a.action = :action
                 AND COALESCE(
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.carregamento_id')) AS UNSIGNED),
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.remote_carregamento_id')) AS UNSIGNED),
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.carregamento_id')) AS UNSIGNED),
                   CASE WHEN a.entity_type = 'carregamento' THEN a.entity_id END
                 ) = :loading_id
                 AND COALESCE(
                   JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.stop_reason')),
                   JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.stop_reason'))
                 ) = :stop_reason
                 AND (
                   EXISTS (
                     SELECT 1 FROM solicitacoes_comandos_clp q
                     WHERE q.id = CAST(COALESCE(
                       JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')),
                       JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.command_request_id'))
                     ) AS UNSIGNED)
                       AND q.carregamento_id = :command_loading_id
                       AND q.company_id = :command_company_id
                       AND q.command = 'PAUSAR_CARREGAMENTO' AND q.status = 'APLICADO'
                   )
                   OR EXISTS (
                     SELECT 1 FROM logs_auditoria ack
                     WHERE ack.company_id = a.company_id
                       AND ack.action = 'COMANDO_CLP_CONCLUIDO'
                       AND ack.entity_type = 'solicitacao_comando_clp'
                       AND (
                         ack.entity_id = CAST(COALESCE(
                           JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')),
                           JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.command_request_id'))
                         ) AS UNSIGNED)
                         OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.aggregate_id')) AS UNSIGNED)
                           = CAST(COALESCE(
                             JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')),
                             JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.payload.data.command_request_id'))
                           ) AS UNSIGNED)
                       )
                       AND COALESCE(
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.command')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.command'))
                       ) = 'PAUSAR_CARREGAMENTO'
                       AND COALESCE(
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.status')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.status'))
                       ) = 'APLICADO'
                       AND CAST(COALESCE(
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_carregamento_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.carregamento_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.remote_carregamento_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.carregamento_id'))
                       ) AS UNSIGNED) = :ack_loading_id
                       AND CAST(COALESCE(
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_equipment_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.equipment_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.remote_equipment_id')),
                         JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.equipment_id'))
                       ) AS UNSIGNED) = :ack_equipment_id
                   )
                 )
             )",
        );
        $statement->execute([
            "company_id" => $companyId,
            "action" => $action,
            "loading_id" => $loadingId,
            "stop_reason" => $stopReason,
            "command_loading_id" => $loadingId,
            "command_company_id" => $companyId,
            "ack_loading_id" => $loadingId,
            "ack_equipment_id" => $equipmentId,
        ]);

        return (bool) $statement->fetchColumn();
    }
}
