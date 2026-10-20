<?php
declare(strict_types=1);

namespace App\Aplicacao;

use PDO;

final class InicializadorAcoesDala
{
    public static function garantir(PDO $connection, int $companyId, int $equipmentId): void
    {
        $existing = $connection->prepare(
            "SELECT id FROM acoes_dala WHERE equipment_id = :equipment_id LIMIT 1 FOR UPDATE",
        );
        $existing->execute(["equipment_id" => $equipmentId]);
        if ($existing->fetch()) {
            return;
        }

        $defaults = [
            ["INICIAR_CARREGAMENTO", "LIGAR", "VERDE", "INCREMENTAL"],
            ["PAUSAR_CARREGAMENTO", "PARAR", "VERMELHO", "INCREMENTAL"],
            ["REVERSAO_ATIVAR", "REVERSO", "CINZA", "DECREMENTAL"],
            ["REVERSAO_DESATIVAR", "PARAR REVERSO", "AMBAR", "DIRETO"],
        ];
        $insert = $connection->prepare(
            "INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, modo, ordem)
             VALUES (:company_id, :equipment_id, :comando, :rotulo, :cor, :modo, :ordem)",
        );
        foreach ($defaults as $index => [$command, $label, $color, $mode]) {
            $insert->execute([
                "company_id" => $companyId,
                "equipment_id" => $equipmentId,
                "comando" => $command,
                "rotulo" => $label,
                "cor" => $color,
                "modo" => $mode,
                "ordem" => $index + 1,
            ]);
        }

        $stop = $connection->prepare(
            "SELECT id FROM acoes_dala
             WHERE equipment_id = :equipment_id AND comando = 'PAUSAR_CARREGAMENTO' LIMIT 1",
        );
        $stop->execute(["equipment_id" => $equipmentId]);
        $trigger = $connection->prepare(
            "INSERT INTO gatilhos_dala (company_id, equipment_id, evento, acao_id)
             VALUES (:company_id, :equipment_id, 'QUANTIDADE_PLANEJADA_ATINGIDA', :acao_id)",
        );
        $trigger->execute([
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
            "acao_id" => $stop->fetchColumn() ?: null,
        ]);
    }
}
