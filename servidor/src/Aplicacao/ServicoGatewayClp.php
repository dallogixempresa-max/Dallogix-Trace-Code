<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioComandosClp;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . "/../Persistencia/RepositorioComandosClp.php";

final class ExcecaoGatewayClp extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}

final class ServicoGatewayClp
{
    private const ESTADOS_FINAIS = ["APLICADO", "REJEITADO", "ERRO"];

    private readonly RepositorioComandosClp $repositorio;

    public function __construct(private readonly PDO $connection)
    {
        $this->repositorio = new RepositorioComandosClp($connection);
    }

    public function reservarComando(int $equipmentId, int $deviceId): ?array
    {
        if ($equipmentId <= 0 || $deviceId <= 0) {
            throw new ExcecaoGatewayClp("Equipamento e dispositivo são obrigatórios.", 422);
        }

        $this->connection->beginTransaction();
        try {
            // Serializa os CLAIMs da mesma Dala. A emergência pode ultrapassar
            // outro comando em andamento, mas comandos comuns não são enviados
            // em paralelo pela mesma máquina.
            $equipmentLock = $this->connection->prepare(
                "SELECT id FROM equipamentos WHERE id = :equipment_id LIMIT 1 FOR UPDATE",
            );
            $equipmentLock->execute(["equipment_id" => $equipmentId]);
            if (!$equipmentLock->fetchColumn()) {
                $this->connection->commit();
                return null;
            }

            $expired = $this->connection->prepare(
                "SELECT r.id, r.company_id, r.equipment_id, r.carregamento_id, r.command,
                        r.remote_command_id, c.remote_carregamento_id,
                        e.remote_equipment_id, e.equipment_code
                 FROM solicitacoes_comandos_clp r
                 JOIN carregamentos c ON c.id = r.carregamento_id
                 JOIN equipamentos e ON e.id = r.equipment_id
                 WHERE r.equipment_id = :equipment_id AND r.status = 'PROCESSANDO'
                   AND expires_at < NOW(3)
                 ORDER BY r.id FOR UPDATE",
            );
            $expired->execute(["equipment_id" => $equipmentId]);
            $expire = $this->connection->prepare(
                "UPDATE solicitacoes_comandos_clp
                 SET status = 'ERRO', completed_at = NOW(3),
                     response_message = 'Tempo de confirmação do gateway expirado.'
                 WHERE id = :id AND status = 'PROCESSANDO' AND expires_at < NOW(3)",
            );
            foreach ($expired->fetchAll() as $request) {
                $expire->execute(["id" => $request["id"]]);
                \record_operational_event(
                    $this->connection,
                    $this->atorDoDispositivo((int) $request["company_id"]),
                    "COMANDO_CLP_EXPIRADO",
                    "solicitacao_comando_clp",
                    (int) $request["id"],
                    [
                        "equipment_id" => (int) $request["equipment_id"],
                        "carregamento_id" => (int) $request["carregamento_id"],
                        "command" => $request["command"],
                        "device_id" => $deviceId,
                        "remote_command_id" => $request["remote_command_id"] === null
                            ? null : (int) $request["remote_command_id"],
                        "remote_carregamento_id" => $request["remote_carregamento_id"] === null
                            ? null : (int) $request["remote_carregamento_id"],
                        "remote_equipment_id" => $request["remote_equipment_id"] === null
                            ? null : (int) $request["remote_equipment_id"],
                        "equipment_code" => $request["equipment_code"],
                    ],
                );
            }

            $statement = $this->connection->prepare(
                "SELECT r.id, r.company_id, r.equipment_id, r.carregamento_id, r.command, r.requested_at,
                        r.remote_command_id, c.remote_carregamento_id, c.state AS loading_state,
                        (SELECT direction.command FROM solicitacoes_comandos_clp direction
                         WHERE direction.equipment_id = r.equipment_id
                           AND direction.company_id = r.company_id
                           AND direction.command IN ('REVERSAO_ATIVAR', 'REVERSAO_DESATIVAR')
                           AND direction.status = 'APLICADO'
                         ORDER BY direction.id DESC LIMIT 1) AS reversal_command,
                        e.remote_equipment_id, e.equipment_code
                 FROM solicitacoes_comandos_clp r
                 JOIN carregamentos c ON c.id = r.carregamento_id
                 JOIN equipamentos e ON e.id = r.equipment_id
                 WHERE r.equipment_id = :equipment_id AND r.status = 'PENDENTE'
                   AND (
                       (r.command = 'EMERGENCIA' AND NOT EXISTS (
                           SELECT 1 FROM solicitacoes_comandos_clp processing
                           WHERE processing.equipment_id = r.equipment_id
                             AND processing.command = 'EMERGENCIA'
                             AND processing.status = 'PROCESSANDO'
                       ))
                       OR (r.command <> 'EMERGENCIA' AND NOT EXISTS (
                           SELECT 1 FROM solicitacoes_comandos_clp processing
                           WHERE processing.equipment_id = r.equipment_id
                             AND processing.status = 'PROCESSANDO'
                       ))
                   )
                 ORDER BY CASE WHEN r.command = 'EMERGENCIA' THEN 0 ELSE 1 END,
                          r.requested_at, r.id
                 LIMIT 1 FOR UPDATE SKIP LOCKED",
            );
            $statement->execute(["equipment_id" => $equipmentId]);
            $request = $statement->fetch();
            if (!$request) {
                $this->connection->commit();
                return null;
            }
            $update = $this->connection->prepare(
                "UPDATE solicitacoes_comandos_clp
                 SET status = 'PROCESSANDO', claimed_at = NOW(3),
                     expires_at = DATE_ADD(NOW(3), INTERVAL 2 MINUTE),
                     claimed_by_device_id = :device_id
                 WHERE id = :id AND status = 'PENDENTE'",
            );
            $update->execute(["id" => $request["id"], "device_id" => $deviceId]);
            if ($update->rowCount() !== 1) {
                throw new ExcecaoGatewayClp("Comando já foi reservado por outro gateway.", 409);
            }
            $this->connection->commit();
            $request["claimed_by_device_id"] = $deviceId;
            return $request;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof ExcecaoGatewayClp) {
                throw $exception;
            }
            error_log("PLC gateway could not claim command: " . $exception->getMessage());
            throw new ExcecaoGatewayClp("Não foi possível reservar o comando industrial.", 500);
        }
    }

    public function concluirComando(
        int $requestId,
        int $deviceId,
        string $status,
        string $message,
    ): array {
        if ($requestId <= 0 || $deviceId <= 0 || !in_array($status, self::ESTADOS_FINAIS, true)) {
            throw new ExcecaoGatewayClp(
                "request_id, dispositivo e status final válido são obrigatórios.",
                422,
            );
        }
        if (mb_strlen($message) > 1000) {
            throw new ExcecaoGatewayClp("message excede 1000 caracteres.", 422);
        }

        $this->connection->beginTransaction();
        try {
            // A API de comando bloqueia primeiro o carregamento e depois a
            // solicitação. Preserve a mesma ordem no ACK: inverter esses locks
            // permitia um deadlock quando o operador enviava outro comando.
            $lockedLoadingState = null;
            if ($status === "APLICADO") {
                $candidate = $this->connection->prepare(
                    "SELECT command, carregamento_id, company_id
                     FROM solicitacoes_comandos_clp
                     WHERE id = :id AND claimed_by_device_id = :device_id
                       AND status = 'PROCESSANDO'
                       AND (expires_at IS NULL OR expires_at >= NOW(3))
                     LIMIT 1",
                );
                $candidate->execute(["id" => $requestId, "device_id" => $deviceId]);
                $candidateRequest = $candidate->fetch();
                $commandsThatChangeLoading = [
                    "INICIAR_CARREGAMENTO",
                    "PAUSAR_CARREGAMENTO",
                    "DESBLOQUEAR_MAQUINA",
                ];
                if ($candidateRequest && in_array($candidateRequest["command"], $commandsThatChangeLoading, true)) {
                    $loadingLock = $this->connection->prepare(
                        "SELECT state FROM carregamentos
                         WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
                    );
                    $loadingLock->execute([
                        "id" => $candidateRequest["carregamento_id"],
                        "company_id" => $candidateRequest["company_id"],
                    ]);
                    $lockedLoadingState = $loadingLock->fetchColumn();
                }
            }

            $statement = $this->connection->prepare(
                "SELECT r.id, r.company_id, r.equipment_id, r.command, r.carregamento_id,
                        r.remote_command_id, c.remote_carregamento_id,
                        e.remote_equipment_id, e.equipment_code
                 FROM solicitacoes_comandos_clp r
                 JOIN carregamentos c ON c.id = r.carregamento_id
                 JOIN equipamentos e ON e.id = r.equipment_id
                 WHERE r.id = :id AND r.claimed_by_device_id = :device_id
                   AND r.status = 'PROCESSANDO'
                   AND (r.expires_at IS NULL OR r.expires_at >= NOW(3))
                 LIMIT 1 FOR UPDATE",
            );
            $statement->execute(["id" => $requestId, "device_id" => $deviceId]);
            $request = $statement->fetch();
            if (!$request) {
                throw new ExcecaoGatewayClp(
                    "Comando não está reservado por este dispositivo.",
                    404,
                );
            }

            $staleUnlock = false;
            $stateChanged = false;
            $stateTransition = null;
            if ($status === "APLICADO" && $request["command"] === "DESBLOQUEAR_MAQUINA") {
                $staleUnlock = $this->repositorio->ultimaSolicitacaoDeSeguranca(
                    (int) $request["carregamento_id"],
                ) !== $requestId;
                if ($staleUnlock) {
                    $status = "REJEITADO";
                    $message = "ACK de desbloqueio obsoleto após outra solicitação de segurança.";
                }
            }

            if ($status === "APLICADO" && in_array($request["command"], ["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"], true)) {
                $previousState = (string) $lockedLoadingState;
                $targetState = $request["command"] === "INICIAR_CARREGAMENTO"
                    ? "CARREGANDO"
                    : "PAUSADO";
                $allowedStates = $request["command"] === "INICIAR_CARREGAMENTO"
                    ? ["PREPARANDO", "PAUSADO"]
                    : ["PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO"];
                if (!in_array($previousState, $allowedStates, true)) {
                    $status = "ERRO";
                    $message = "O estado do carregamento mudou antes da confirmação física; o comando não foi aplicado ao estado local.";
                } else {
                    $stateUpdate = $this->connection->prepare(
                        "UPDATE carregamentos SET state = :state
                         WHERE id = :id AND company_id = :company_id AND state = :previous_state",
                    );
                    $stateUpdate->execute([
                        "state" => $targetState,
                        "id" => $request["carregamento_id"],
                        "company_id" => $request["company_id"],
                        "previous_state" => $previousState,
                    ]);
                    $stateChanged = $stateUpdate->rowCount() === 1;
                    if ($stateChanged) {
                        $stateTransition = [
                            "previous_state" => $previousState,
                            "state" => $targetState,
                            "command" => $request["command"],
                        ];
                    }
                }
            }

            $update = $this->connection->prepare(
                "UPDATE solicitacoes_comandos_clp
                 SET status = :status, completed_at = NOW(3), response_message = :message
                 WHERE id = :id AND claimed_by_device_id = :device_id
                   AND status = 'PROCESSANDO'
                   AND (expires_at IS NULL OR expires_at >= NOW(3))",
            );
            $update->execute([
                "status" => $status,
                "message" => $message === "" ? null : $message,
                "id" => $requestId,
                "device_id" => $deviceId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ExcecaoGatewayClp(
                    "Comando já concluído ou com prazo de confirmação expirado.",
                    409,
                );
            }

            $actor = $this->atorDoDispositivo((int) $request["company_id"]);
            \record_operational_event(
                $this->connection,
                $actor,
                "COMANDO_CLP_CONCLUIDO",
                "solicitacao_comando_clp",
                $requestId,
                [
                    "equipment_id" => (int) $request["equipment_id"],
                    "carregamento_id" => (int) $request["carregamento_id"],
                    "command" => $request["command"],
                    "status" => $status,
                    "message" => $message,
                    "device_id" => $deviceId,
                    "remote_command_id" => $request["remote_command_id"] === null
                        ? null : (int) $request["remote_command_id"],
                    "remote_carregamento_id" => $request["remote_carregamento_id"] === null
                        ? null : (int) $request["remote_carregamento_id"],
                    "remote_equipment_id" => $request["remote_equipment_id"] === null
                        ? null : (int) $request["remote_equipment_id"],
                    "equipment_code" => $request["equipment_code"],
                ],
            );

            if ($status === "APLICADO" && $request["command"] === "DESBLOQUEAR_MAQUINA") {
                $loading = $this->connection->prepare(
                    "UPDATE carregamentos SET state = 'PREPARANDO'
                     WHERE id = :id AND company_id = :company_id AND state = 'EMERGENCIA'",
                );
                $loading->execute([
                    "id" => $request["carregamento_id"],
                    "company_id" => $request["company_id"],
                ]);
                $stateChanged = $loading->rowCount() === 1;
                if ($stateChanged) {
                    \record_operational_event(
                        $this->connection,
                        $actor,
                        "ESTADO_CARREGAMENTO_ALTERADO",
                        "carregamento",
                        (int) $request["carregamento_id"],
                        [
                            "previous_state" => "EMERGENCIA",
                            "state" => "PREPARANDO",
                            "command_request_id" => $requestId,
                            "command" => $request["command"],
                            "device_id" => $deviceId,
                            "remote_carregamento_id" => $request["remote_carregamento_id"] === null
                                ? null : (int) $request["remote_carregamento_id"],
                        ],
                    );
                }
            }
            if ($stateChanged && $stateTransition !== null) {
                \record_operational_event(
                    $this->connection,
                    $actor,
                    "ESTADO_CARREGAMENTO_ALTERADO",
                    "carregamento",
                    (int) $request["carregamento_id"],
                    [
                        "previous_state" => $stateTransition["previous_state"],
                        "state" => $stateTransition["state"],
                        "command_request_id" => $requestId,
                        "command" => $stateTransition["command"],
                        "device_id" => $deviceId,
                        "remote_carregamento_id" => $request["remote_carregamento_id"] === null
                            ? null : (int) $request["remote_carregamento_id"],
                    ],
                );
            }
            $this->connection->commit();
            return [
                "request_id" => $requestId,
                "command" => $request["command"],
                "status" => $status,
                "state_changed" => $stateChanged,
                "stale" => $staleUnlock,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof ExcecaoGatewayClp) {
                throw $exception;
            }
            error_log("PLC gateway could not complete command: " . $exception->getMessage());
            throw new ExcecaoGatewayClp("Não foi possível concluir o comando industrial.", 500);
        }
    }

    /** @return array{id:null, company_id:int} */
    private function atorDoDispositivo(int $companyId): array
    {
        return ["id" => null, "company_id" => $companyId];
    }
}
