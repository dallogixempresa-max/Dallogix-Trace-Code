<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;

final class RepositorioComandosClp
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** A chamada mantém o bloqueio FOR UPDATE da transação iniciada pelo serviço. */
    public function localizarCarregamento(int $loadingId, int $companyId): array|false
    {
        $statement = $this->connection->prepare(
            "SELECT id, state, equipment_id, remote_carregamento_id, stop_reason, operator_alert
             FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
        );
        $statement->execute(["id" => $loadingId, "company_id" => $companyId]);

        return $statement->fetch();
    }

    /** A chamada mantém o bloqueio FOR UPDATE da transação iniciada pelo serviço. */
    public function comandoPendente(int $loadingId, ?string $command = null): array|false
    {
        $where = "carregamento_id = :carregamento_id AND status IN ('PENDENTE', 'PROCESSANDO')";
        $params = ["carregamento_id" => $loadingId];
        if ($command !== null) {
            $where .= " AND command = :command";
            $params["command"] = $command;
        }

        $statement = $this->connection->prepare(
            "SELECT id, status, command FROM solicitacoes_comandos_clp
             WHERE {$where} ORDER BY id DESC LIMIT 1 FOR UPDATE",
        );
        $statement->execute($params);

        return $statement->fetch();
    }

    public function ultimaReversaoConfirmada(int $equipmentId, int $companyId): ?string
    {
        $statement = $this->connection->prepare(
            "SELECT command FROM solicitacoes_comandos_clp
             WHERE equipment_id = :equipment_id AND company_id = :company_id
               AND command IN ('REVERSAO_ATIVAR', 'REVERSAO_DESATIVAR')
               AND status = 'APLICADO'
             ORDER BY id DESC LIMIT 1",
        );
        $statement->execute([
            "equipment_id" => $equipmentId,
            "company_id" => $companyId,
        ]);
        $command = $statement->fetchColumn();

        return $command === false ? null : (string) $command;
    }

    /**
     * Retorna o comando de segurança mais recente do carregamento.
     * Deve ser chamado dentro da transação do serviço para manter o FOR UPDATE.
     */
    public function ultimaSolicitacaoDeSeguranca(int $loadingId): ?int
    {
        $lockClause = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite"
            ? ""
            : " FOR UPDATE";
        $statement = $this->connection->prepare(
            "SELECT id FROM solicitacoes_comandos_clp
             WHERE carregamento_id = :carregamento_id
               AND command IN ('DESBLOQUEAR_MAQUINA', 'EMERGENCIA')
             ORDER BY id DESC LIMIT 1{$lockClause}",
        );
        $statement->execute(["carregamento_id" => $loadingId]);
        $requestId = $statement->fetchColumn();

        return $requestId === false ? null : (int) $requestId;
    }

    /**
     * @return list<array<string,mixed>>
     * A consulta deve ocorrer dentro da transação do serviço para manter FOR UPDATE.
     */
    public function desbloqueiosPendentes(int $loadingId, int|string $companyId): array
    {
        $statement = $this->connection->prepare(
            "SELECT r.id, r.equipment_id, r.remote_command_id,
                    c.remote_carregamento_id, e.remote_equipment_id, e.equipment_code
             FROM solicitacoes_comandos_clp r
             JOIN carregamentos c ON c.id = r.carregamento_id
             JOIN equipamentos e ON e.id = r.equipment_id
             WHERE r.carregamento_id = :loading_id AND r.company_id = :company_id
               AND r.command = 'DESBLOQUEAR_MAQUINA'
               AND r.status IN ('PENDENTE', 'PROCESSANDO')
             ORDER BY r.id FOR UPDATE",
        );
        $statement->execute([
            "loading_id" => $loadingId,
            "company_id" => $companyId,
        ]);

        return $statement->fetchAll();
    }

    /** Insere a solicitação na transação que o serviço já abriu. */
    public function inserirSolicitacao(
        int|string $companyId,
        int|string $equipmentId,
        int $loadingId,
        string $command,
        int|string $requestedBy,
    ): int {
        $statement = $this->connection->prepare(
            "INSERT INTO solicitacoes_comandos_clp
             (company_id, equipment_id, carregamento_id, command, requested_by)
             VALUES (:company_id, :equipment_id, :carregamento_id, :command, :requested_by)",
        );
        $statement->execute([
            "company_id" => $companyId,
            "equipment_id" => $equipmentId,
            "carregamento_id" => $loadingId,
            "command" => $command,
            "requested_by" => $requestedBy,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** Atualiza somente solicitações ainda não reservadas pelo gateway. */
    public function rejeitarComandoPendente(int|string $requestId, string $message): bool
    {
        $statement = $this->connection->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET status = 'REJEITADO', completed_at = NOW(3),
                 response_message = :message
             WHERE id = :id AND status = 'PENDENTE'",
        );
        $statement->execute(["id" => $requestId, "message" => $message]);

        return $statement->rowCount() === 1;
    }

    /** Revoga desbloqueios pendentes ou em processamento na transação atual. */
    public function rejeitarDesbloqueioPendente(int|string $requestId): bool
    {
        $statement = $this->connection->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET status = 'REJEITADO', completed_at = NOW(3),
                 response_message = 'Nova emergência cancelou o desbloqueio pendente.'
             WHERE id = :id AND status IN ('PENDENTE', 'PROCESSANDO')",
        );
        $statement->execute(["id" => $requestId]);

        return $statement->rowCount() === 1;
    }

    /** A mudança só ocorre se empresa e estado anterior ainda forem os esperados. */
    public function alterarEstadoParaEmergencia(
        int $loadingId,
        int|string $companyId,
        string $previousState,
    ): bool {
        $statement = $this->connection->prepare(
            "UPDATE carregamentos SET state = 'EMERGENCIA'
             WHERE id = :id AND company_id = :company_id AND state = :previous_state",
        );
        $statement->execute([
            "id" => $loadingId,
            "company_id" => $companyId,
            "previous_state" => $previousState,
        ]);

        return $statement->rowCount() === 1;
    }
}
