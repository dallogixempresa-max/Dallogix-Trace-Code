<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioComandosClp;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . "/../Persistencia/RepositorioComandosClp.php";
require_once __DIR__ . "/ServicoPausasCarregamento.php";

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

    /**
     * Pausa automática (produto incorreto, acima da quantidade, excesso, meta)
     * que o servidor já gravou como PAUSADO. É a única que a consulta longa de
     * parada de segurança entrega; ligar a esteira, reversão e parada manual
     * seguem só na consulta normal de 2 s.
     */
    private const FILTRO_PARADA_DE_SEGURANCA = "r.command = 'PAUSAR_CARREGAMENTO'
                           AND c.state IN ('PAUSADO', 'FINALIZADO')
                           AND c.stop_reason IN (
                               'EXCESSO', 'PRODUTO_INCORRETO',
                               'COTA_PRODUTO_ATINGIDA', 'COTA_ROMANEIO_ATINGIDA'
                           )";

    /**
     * Consulta longa do node-red durante a carga: espera até $esperaMs por uma
     * pausa de segurança e a reserva assim que ela existir. A parada que nasce
     * da leitura do Keyence não tem o CLAIM imediato da passagem e esperava a
     * consulta de 2 s; aqui ela sai em ~$intervaloMs. Sem parada pendente, só
     * lê sem trava, sem abrir transação nem disputar a Dala.
     */
    public function aguardarParadaDeSeguranca(
        int $equipmentId,
        int $deviceId,
        int $esperaMs,
        int $intervaloMs = 100,
        ?int $prazoClienteEpochMs = null,
    ): ?array {
        $limite = hrtime(true) + max(0, $esperaMs) * 1_000_000;
        if ($prazoClienteEpochMs !== null) {
            // Prazo do node-red (mesmo relógio do host Docker): depois dele o
            // node-red pode ter desistido ou reiniciado, e uma pausa reservada
            // ficaria PROCESSANDO sem ninguém escrever a bobina.
            $restanteClienteMs = $prazoClienteEpochMs - (int) round(microtime(true) * 1000);
            if ($restanteClienteMs <= 0) {
                return null;
            }
            $limite = min($limite, hrtime(true) + $restanteClienteMs * 1_000_000);
        }
        $intervaloMs = max(10, $intervaloMs);
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql") {
            // A conexão é só desta requisição. Com a Dala travada por outra
            // transação, desiste em 1 s (o node-red tenta de novo em 2 s) em vez
            // de esperar os 50 s padrão e acumular consultas nos 5 processos PHP.
            $this->connection->exec("SET SESSION innodb_lock_wait_timeout = 1");
        }
        while (true) {
            if ($this->existeParadaDeSegurancaReservavel($equipmentId)) {
                // A reserva pode pular a linha travada por outra transação;
                // nesse caso tenta de novo na próxima volta.
                $request = $this->reservarComando($equipmentId, $deviceId, null, true);
                if ($request !== null) {
                    return $request;
                }
            }
            $restanteNs = $limite - hrtime(true);
            if ($restanteNs <= 0) {
                return null;
            }
            usleep((int) min($intervaloMs * 1000, intdiv($restanteNs, 1000)));
        }
    }

    /**
     * Prazo enviado pelo node-red (wait_until_epoch_ms), no mesmo relógio do
     * host Docker. Prazo vencido, por mais antigo que seja (fila do PHP), fica
     * como vencido: a consulta responde 204 sem reservar. Só um prazo mais de
     * 10 s no futuro, ou inválido, é ignorado (vale só a espera de 1,5 s).
     */
    public static function prazoDoCliente(mixed $valor, int $agoraEpochMs): ?int
    {
        $prazo = filter_var($valor, FILTER_VALIDATE_INT);
        if ($prazo === false || $prazo <= 0 || $prazo - $agoraEpochMs > 10000) {
            return null;
        }
        return $prazo;
    }

    /** Mesma condição da reserva rápida, em leitura sem trava (autocommit). */
    private function existeParadaDeSegurancaReservavel(int $equipmentId): bool
    {
        $pendingStop = $this->connection->prepare(
            "SELECT r.id FROM solicitacoes_comandos_clp r
             JOIN carregamentos c ON c.id = r.carregamento_id
             WHERE r.equipment_id = :equipment_id AND r.status = 'PENDENTE'
               AND " . self::FILTRO_PARADA_DE_SEGURANCA . "
               AND NOT EXISTS (
                   SELECT 1 FROM solicitacoes_comandos_clp processing
                   WHERE processing.equipment_id = r.equipment_id
                     AND processing.status = 'PROCESSANDO'
               )
             LIMIT 1",
        );
        $pendingStop->execute(["equipment_id" => $equipmentId]);
        $exists = $pendingStop->fetchColumn() !== false;
        $pendingStop->closeCursor();
        return $exists;
    }

    public function reservarComando(
        int $equipmentId,
        int $deviceId,
        ?int $priorityStopRequestId = null,
        bool $somenteParadaDeSeguranca = false,
    ): ?array
    {
        if (
            $equipmentId <= 0 ||
            $deviceId <= 0 ||
            ($priorityStopRequestId !== null && $priorityStopRequestId <= 0)
        ) {
            throw new ExcecaoGatewayClp("Equipamento e dispositivo são obrigatórios.", 422);
        }

        $reservaRapida = $somenteParadaDeSeguranca && $priorityStopRequestId === null;
        if ($reservaRapida && !$this->existeParadaDeSegurancaReservavel($equipmentId)) {
            return null;
        }

        $this->connection->beginTransaction();
        try {
            // Serializa os CLAIMs da mesma Dala. A emergência pode ultrapassar
            // outro comando em andamento, mas comandos comuns não são enviados
            // em paralelo pela mesma máquina.
            // A reserva rápida não espera a Dala: ocupada por outra reserva ou
            // pela passagem do sensor, devolve null e a consulta longa tenta de
            // novo em 100 ms.
            $equipmentLock = $this->connection->prepare(
                "SELECT id FROM equipamentos WHERE id = :equipment_id LIMIT 1 FOR UPDATE"
                    . ($reservaRapida ? " SKIP LOCKED" : ""),
            );
            $equipmentLock->execute(["equipment_id" => $equipmentId]);
            if (!$equipmentLock->fetchColumn()) {
                $this->connection->commit();
                return null;
            }

            if ($reservaRapida) {
                // Depois de travar a Dala, a reserva rápida não espera nenhuma
                // outra trava: o UPDATE dos aposentados e a varredura dos
                // vencidos (FOR UPDATE sem SKIP) ficam com a consulta de 2 s.
                // Esperar a linha da pausa travada pela leitura da saca seguinte,
                // segurando a Dala, fechava ciclo de deadlock com a FK da evidência.
                return $this->reservarParadaDeSeguranca($deviceId, $equipmentId);
            }

            // Compatibilidade com instalações antigas: comandos removidos não
            // podem reaparecer na fila após uma reconexão. Preserve a solicitação.
            $retired = $this->connection->prepare(
                "UPDATE solicitacoes_comandos_clp
                 SET status = 'REJEITADO', completed_at = NOW(3),
                     response_message = 'Comando removido do Trace; controle exclusivo do painel físico.'
                 WHERE equipment_id = :equipment_id AND status = 'PENDENTE'
                   AND command IN ('EMERGENCIA', 'DESBLOQUEAR_MAQUINA')",
            );
            $retired->execute(["equipment_id" => $equipmentId]);

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

            // Prioriza a pausa gerada pelo sensor sobre outros comandos ainda
            // pendentes, sem concorrer com uma escrita que já está PROCESSANDO.
            // O ID só pode apontar para uma pausa de segurança do carregamento
            // que o servidor já marcou como PAUSADO.
            $priorityStopFilter = $priorityStopRequestId === null
                ? ""
                : " AND (
                       r.command = 'EMERGENCIA'
                       OR (r.id = :priority_stop_request_id
                           AND r.command = 'PAUSAR_CARREGAMENTO'
                           AND c.state IN ('PAUSADO', 'FINALIZADO')
                           AND c.stop_reason IN (
                               'EXCESSO', 'PRODUTO_INCORRETO',
                               'COTA_PRODUTO_ATINGIDA', 'COTA_ROMANEIO_ATINGIDA'
                           ))
                   )";
            $statement = $this->connection->prepare(
                "SELECT r.id, r.company_id, r.equipment_id, r.carregamento_id, r.command, r.requested_at,
                        r.remote_command_id, c.remote_carregamento_id, c.state AS loading_state,
                        c.stop_reason,
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
                   AND r.command IN ('INICIAR_CARREGAMENTO', 'PAUSAR_CARREGAMENTO', 'REVERSAO_ATIVAR', 'REVERSAO_DESATIVAR')
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
                   {$priorityStopFilter}
                 ORDER BY CASE WHEN r.command = 'EMERGENCIA' THEN 0 ELSE 1 END,
                          r.requested_at, r.id
                 LIMIT 1 FOR UPDATE SKIP LOCKED",
            );
            $parameters = ["equipment_id" => $equipmentId];
            if ($priorityStopRequestId !== null) {
                $parameters["priority_stop_request_id"] = $priorityStopRequestId;
            }
            // Ligar a esteira com uma parada por divergência ainda não
            // confirmada no PC (ex.: produto incorreto com a saca na esteira)
            // é recusado aqui, antes de chegar ao node-red. O central
            // pode ter liberado a própria linha sem o PC confirmar; adiar o
            // comando o faria disparar sozinho logo após a confirmação, então
            // ele termina REJEITADO e o próximo da fila segue na mesma reserva.
            while (true) {
                $statement->execute($parameters);
                $request = $statement->fetch();
                $statement->closeCursor();
                if (!$request) {
                    $this->connection->commit();
                    return null;
                }
                if (!self::bloqueadoPorParadaPendente($request)) {
                    break;
                }
                $this->rejeitarPorParadaPendente($request, $deviceId);
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

    /**
     * Parte da reserva rápida que roda com a Dala já travada pela transação de
     * reservarComando. Trava só a solicitação (OF r, SKIP LOCKED): o carregamento
     * é lido sem trava porque a pausa é a direção segura e o ACK (concluirComando)
     * trava o carregamento antes de mudar o estado. Assim a sincronização do
     * central e a leitura, que seguram o carregamento, não fazem a pausa esperar
     * a consulta de 2 s.
     */
    private function reservarParadaDeSeguranca(int $deviceId, int $equipmentId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT r.id, r.company_id, r.equipment_id, r.carregamento_id, r.command, r.requested_at,
                    r.remote_command_id, c.remote_carregamento_id, c.state AS loading_state,
                    c.stop_reason,
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
               AND " . self::FILTRO_PARADA_DE_SEGURANCA . "
               AND NOT EXISTS (
                   SELECT 1 FROM solicitacoes_comandos_clp processing
                   WHERE processing.equipment_id = r.equipment_id
                     AND processing.status = 'PROCESSANDO'
               )
             ORDER BY r.requested_at, r.id
             LIMIT 1 FOR UPDATE OF r SKIP LOCKED",
        );
        $statement->execute(["equipment_id" => $equipmentId]);
        $request = $statement->fetch();
        $statement->closeCursor();
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
            $lockedStopReason = "";
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
                        "SELECT state, stop_reason FROM carregamentos
                         WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
                    );
                    $loadingLock->execute([
                        "id" => $candidateRequest["carregamento_id"],
                        "company_id" => $candidateRequest["company_id"],
                    ]);
                    $lockedLoading = $loadingLock->fetch();
                    if ($lockedLoading) {
                        $lockedLoadingState = $lockedLoading["state"];
                        $lockedStopReason = trim((string) ($lockedLoading["stop_reason"] ?? ""));
                    }
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
                    : ["PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "FINALIZADO"];
                if (!in_array($previousState, $allowedStates, true)) {
                    $status = "ERRO";
                    $message = "O estado do carregamento mudou antes da confirmação física; o comando não foi aplicado ao estado local.";
                } elseif ($request["command"] === "INICIAR_CARREGAMENTO" && $lockedStopReason !== "") {
                    // Uma parada por divergência entrou durante a escrita (ou o
                    // início escapou da reserva). Ir para CARREGANDO deixaria o
                    // aviso sem confirmação possível: o estado local não muda e
                    // a parada já solicitada desliga a esteira.
                    $status = "ERRO";
                    $message = "Parada por divergência ({$lockedStopReason}) registrada antes da confirmação física; o carregamento não passou para CARREGANDO e aguarda a confirmação do operador no PC.";
                } elseif ($previousState !== "FINALIZADO") {
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

            $pauseAppliedAtEpochMs = null;
            $pauseStartedAtEpochMs = null;
            if (
                $status === "APLICADO"
                && in_array($request["command"], ["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"], true)
            ) {
                $pauseAppliedAtEpochMs = (int) round(microtime(true) * 1000);
                $pauseStartedAtEpochMs = ServicoPausasCarregamento::registrarComandoAplicado(
                    $this->connection,
                    (int) $request["company_id"],
                    (int) $request["carregamento_id"],
                    (string) $request["command"],
                    $pauseAppliedAtEpochMs,
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
                    "applied_at_epoch_ms" => $pauseAppliedAtEpochMs,
                    "pause_started_at_epoch_ms" => $pauseStartedAtEpochMs,
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
            if (self::pausaRemotaSemMudancaLocal($status, $request, (string) $lockedLoadingState, $stateChanged)) {
                // O central pediu a pausa de um carregamento que o PC já tinha
                // pausado (parada automática). O UPDATE não muda linha e nenhum
                // ESTADO sairia; como o instantâneo não rebaixa mais o estado do
                // PC, o central ficaria em CARREGANDO sem conseguir encerrar.
                // Só sincroniza: localmente nada mudou e não há o que auditar.
                \enfileirar_evento_sincronizacao(
                    $this->connection,
                    $actor,
                    "ESTADO_CARREGAMENTO_ALTERADO",
                    "carregamento",
                    (int) $request["carregamento_id"],
                    [
                        "previous_state" => "PAUSADO",
                        "state" => "PAUSADO",
                        "local_state_unchanged" => true,
                        "command_request_id" => $requestId,
                        "command" => $request["command"],
                        "device_id" => $deviceId,
                        "remote_carregamento_id" => $request["remote_carregamento_id"] === null
                            ? null : (int) $request["remote_carregamento_id"],
                    ],
                );
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

    /**
     * Só a pausa vinda do central (remote_command_id) e aplicada sobre um
     * carregamento já PAUSADO no PC. Pausas automáticas locais seguem como
     * antes: o central recebe o evento da própria divergência.
     *
     * @param array<string,mixed> $request
     */
    private static function pausaRemotaSemMudancaLocal(
        string $status,
        array $request,
        string $previousState,
        bool $stateChanged,
    ): bool {
        return $status === "APLICADO"
            && $request["command"] === "PAUSAR_CARREGAMENTO"
            && $request["remote_command_id"] !== null
            && $previousState === "PAUSADO"
            && !$stateChanged;
    }

    /**
     * O PC recusa ligar a esteira enquanto houver um stop_reason
     * (ServicoComandoClp). O gateway aplica a mesma regra ao INICIAR que
     * chegou por outra via, como o "Ligar" do central.
     *
     * @param array<string,mixed> $request
     */
    private static function bloqueadoPorParadaPendente(array $request): bool
    {
        return $request["command"] === "INICIAR_CARREGAMENTO"
            && trim((string) ($request["stop_reason"] ?? "")) !== "";
    }

    /** @param array<string,mixed> $request */
    private function rejeitarPorParadaPendente(array $request, int $deviceId): void
    {
        $stopReason = trim((string) $request["stop_reason"]);
        $message = "Comando recusado pelo PC da máquina: o carregamento está parado por divergência ({$stopReason}) "
            . "e a remoção da unidade e a parada física ainda não foram confirmadas no PC; a esteira não foi ligada. "
            . "Confirme no PC e envie o comando novamente.";
        $reject = $this->connection->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET status = 'REJEITADO', completed_at = NOW(3), response_message = :message
             WHERE id = :id AND status = 'PENDENTE'",
        );
        $reject->execute(["message" => $message, "id" => $request["id"]]);
        if ($reject->rowCount() !== 1) {
            throw new ExcecaoGatewayClp("Comando já foi reservado por outro gateway.", 409);
        }
        // Mesmo evento do ACK do node-red: o central marca o próprio comando
        // como REJEITADO e mostra o motivo a quem pediu.
        \record_operational_event(
            $this->connection,
            $this->atorDoDispositivo((int) $request["company_id"]),
            "COMANDO_CLP_CONCLUIDO",
            "solicitacao_comando_clp",
            (int) $request["id"],
            [
                "equipment_id" => (int) $request["equipment_id"],
                "carregamento_id" => (int) $request["carregamento_id"],
                "command" => $request["command"],
                "status" => "REJEITADO",
                "applied_at_epoch_ms" => null,
                "pause_started_at_epoch_ms" => null,
                "message" => $message,
                "device_id" => $deviceId,
                "blocked_by_stop_reason" => $stopReason,
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

    /** @return array{id:null, company_id:int} */
    private function atorDoDispositivo(int $companyId): array
    {
        return ["id" => null, "company_id" => $companyId];
    }
}
