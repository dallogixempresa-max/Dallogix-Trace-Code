<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioComandosClp;
use App\Persistencia\RepositorioDisponibilidadeClp;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . "/../Persistencia/RepositorioComandosClp.php";
require_once __DIR__ . "/../Persistencia/RepositorioDisponibilidadeClp.php";
require_once __DIR__ . "/ServicoDisponibilidadeClp.php";

final class ExcecaoComandoClp extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}

final class ServicoComandoClp
{
    private const COMANDOS_DE_REVERSAO = ["REVERSAO_ATIVAR", "REVERSAO_DESATIVAR"];
    private const COMANDO_DE_EMERGENCIA = "EMERGENCIA";

    private readonly PDO $connection;
    private readonly RepositorioComandosClp $repositorio;
    private readonly ServicoDisponibilidadeClp $disponibilidadeClp;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
        $this->repositorio = new RepositorioComandosClp($connection);
        $this->disponibilidadeClp = new ServicoDisponibilidadeClp(
            new RepositorioDisponibilidadeClp($connection),
        );
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user */
    public function solicitarOperacao(
        array $user,
        int $loadingId,
        string $command,
    ): array {
        $allowed = ["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"];
        if (!in_array($command, $allowed, true)) {
            throw new ExcecaoComandoClp(
                "Carregamento e comando operacional válido são obrigatórios.",
                422,
            );
        }
        if ($user["company_id"] === null) {
            throw new ExcecaoComandoClp("Usuário sem empresa vinculada.", 403);
        }

        $ownsTransaction = $this->iniciarEscopoTransacao("trace_solicitar_operacao");
        try {
            $loading = $this->repositorio->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$loading) {
                throw new ExcecaoComandoClp(
                    "Carregamento não encontrado para esta empresa.",
                    404,
                );
            }
            $allowedStates = $command === "INICIAR_CARREGAMENTO"
                ? ["PREPARANDO", "PAUSADO"]
                : ["PREPARANDO", "CARREGANDO", "FINALIZANDO"];
            $paradaPorDivergencia = $command === "PAUSAR_CARREGAMENTO"
                && $loading["state"] === "PAUSADO"
                && in_array($loading["stop_reason"], ["EXCESSO", "PRODUTO_INCORRETO", "COTA_PRODUTO_ATINGIDA", "COTA_ROMANEIO_ATINGIDA"], true);
            if ($paradaPorDivergencia) {
                $allowedStates[] = "PAUSADO";
            }
            if ($command === "INICIAR_CARREGAMENTO" && !empty($loading["stop_reason"])) {
                throw new ExcecaoComandoClp(
                    "Confirme a remoção da unidade e a parada física antes de retomar o carregamento.",
                    409,
                );
            }
            // Uma parada pode ser enfileirada atrás de um início PENDENTE ou
            // já reservado pelo gateway. O início não é apagado depois de
            // reservado: o gateway conclui a sequência na ordem registrada,
            // mantendo o comando físico e o retorno auditável.
            $pending = $this->repositorio->comandoPendente($loadingId);
            if (
                $command === "INICIAR_CARREGAMENTO"
                && $pending
                && $pending["command"] === "PAUSAR_CARREGAMENTO"
                && in_array($pending["status"], ["PENDENTE", "PROCESSANDO"], true)
            ) {
                throw new ExcecaoComandoClp(
                    "Aguarde o gateway confirmar ou cancelar a parada antes de retomar o carregamento.",
                    409,
                );
            }
            $paradaAposInicio = $command === "PAUSAR_CARREGAMENTO"
                && $pending
                && in_array($pending["status"], ["PENDENTE", "PROCESSANDO"], true)
                && $pending["command"] === "INICIAR_CARREGAMENTO";
            if ($paradaAposInicio) {
                $allowedStates[] = "PAUSADO";
            }
            if (!in_array($loading["state"], $allowedStates, true)) {
                throw new ExcecaoComandoClp(
                    $command === "INICIAR_CARREGAMENTO"
                        ? "Para iniciar, o carregamento precisa estar em preparação ou pausado."
                        : "Para parar, o carregamento precisa estar em preparação ou carregando.",
                    409,
                );
            }
            $this->disponibilidadeClp->validarComando(
                (int) $user["company_id"],
                (int) $loading["equipment_id"],
            );
            if ($pending) {
                if ($pending["command"] === $command) {
                    throw new ExcecaoComandoClp(
                        "Já existe este comando aguardando o gateway industrial.",
                        409,
                    );
                }
                $podeEnfileirarParada = $paradaAposInicio
                    && $pending["status"] === "PROCESSANDO";
                if (!$podeEnfileirarParada && (
                    $pending["status"] !== "PENDENTE"
                    || !in_array($pending["command"], $allowed, true)
                )) {
                    throw new ExcecaoComandoClp(
                        "O comando anterior já foi reservado pelo gateway industrial. Aguarde a confirmação antes de enviar outro.",
                        409,
                    );
                }
                if ($pending["status"] === "PENDENTE") {
                    $this->cancelarComandoPendente(
                        $user,
                        $loading,
                        $loadingId,
                        $pending,
                        $command,
                    );
                }
            }
            $requestId = $this->registrarSolicitacaoComando(
                $user,
                $loading,
                $loadingId,
                $command,
            );
            $this->concluirEscopoTransacao($ownsTransaction, "trace_solicitar_operacao");
        } catch (Throwable $exception) {
            $this->reverterEscopoTransacao($ownsTransaction, "trace_solicitar_operacao");
            if ($exception instanceof ExcecaoComandoClp || $exception instanceof ExcecaoDisponibilidadeClp) {
                throw $exception;
            }
            error_log(
                "PLC operational command request could not be created: " .
                    $exception->getMessage(),
            );
            throw new ExcecaoComandoClp(
                "Não foi possível registrar o comando operacional.",
                500,
            );
        }

        return [
            "command_request_id" => $requestId,
            "carregamento_id" => $loadingId,
            "equipment_id" => (int) $loading["equipment_id"],
            "command" => $command,
            "status" => "PENDENTE",
            "state" => $loading["state"],
            "message" => "Comando registrado e aguardando escrita física confirmada pelo gateway industrial.",
        ];
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user
     * @return array{command_request_id:?int,physical_stop_request_status:string,operator_alert:string,stop_reason:?string}
     */
    public function solicitarParadaPorExcesso(array $user, int $loadingId): array
    {
        return $this->solicitarParadaPorDivergencia($user, $loadingId, "EXCESSO");
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user */
    public function solicitarParadaPorProdutoIncorreto(array $user, int $loadingId): array
    {
        return $this->solicitarParadaPorDivergencia($user, $loadingId, "PRODUTO_INCORRETO");
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user
     * @return array{command_request_id:?int,physical_stop_request_status:string,operator_alert:string,stop_reason:?string}
     */
    public function solicitarParadaAoAtingirMetaProduto(array $user, int $loadingId): array
    {
        return $this->solicitarParadaPorDivergencia($user, $loadingId, "COTA_PRODUTO_ATINGIDA");
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user */
    public function solicitarParadaAoAtingirMetaRomaneio(array $user, int $loadingId): array
    {
        return $this->solicitarParadaPorDivergencia($user, $loadingId, "COTA_ROMANEIO_ATINGIDA");
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user
     * @return array{command_request_id:?int,physical_stop_request_status:string,operator_alert:string,stop_reason:?string}
     */
    private function solicitarParadaPorDivergencia(array $user, int $loadingId, string $reason): array
    {
        if ($user["company_id"] === null) {
            throw new ExcecaoComandoClp("Usuário sem empresa vinculada.", 403);
        }

        $savepoint = match ($reason) {
            "EXCESSO" => "trace_parada_excesso",
            "COTA_PRODUTO_ATINGIDA" => "trace_parada_meta_produto",
            "COTA_ROMANEIO_ATINGIDA" => "trace_parada_meta_romaneio",
            default => "trace_parada_produto",
        };
        $label = match ($reason) {
            "EXCESSO" => "Quantidade excedida",
            "COTA_PRODUTO_ATINGIDA" => "Meta do produto atingida",
            "COTA_ROMANEIO_ATINGIDA" => "Quantidade do romaneio atingida",
            default => "Produto fora do romaneio",
        };
        $ownsTransaction = $this->iniciarEscopoTransacao($savepoint);
        try {
            $loading = $this->repositorio->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$loading) {
                throw new ExcecaoComandoClp("Carregamento não encontrado para esta empresa.", 404);
            }
            if (!in_array($loading["state"], ["CARREGANDO", "FINALIZANDO", "PAUSADO"], true)) {
                throw new ExcecaoComandoClp("O carregamento não está em um estado que permita registrar a parada por divergência.", 409);
            }
            if (!empty($loading["stop_reason"]) && $loading["stop_reason"] !== $reason) {
                $existingReason = (string) $loading["stop_reason"];
                $operatorAlert = (string) ($loading["operator_alert"] ?? "Já existe uma parada aguardando confirmação.");
                $requestId = null;
                $requestStatus = "BLOQUEIO_EXISTENTE";
                if ($reason === "EXCESSO") {
                    $existingLabel = match ($existingReason) {
                        "COTA_PRODUTO_ATINGIDA" => "meta do produto atingida",
                        "COTA_ROMANEIO_ATINGIDA" => "quantidade do romaneio atingida",
                        "PRODUTO_INCORRETO" => "produto fora do romaneio",
                        default => "parada de segurança",
                    };
                    $operatorAlert = "Passagem acima da quantidade prevista detectada. A parada por $existingLabel permanece ativa.";
                    if (in_array($existingReason, [
                        "EXCESSO",
                        "PRODUTO_INCORRETO",
                        "COTA_PRODUTO_ATINGIDA",
                        "COTA_ROMANEIO_ATINGIDA",
                    ], true)) {
                        $pendingPause = $this->repositorio->comandoPendente($loadingId, "PAUSAR_CARREGAMENTO");
                        if ($pendingPause) {
                            $requestId = (int) $pendingPause["id"];
                            $requestStatus = (string) $pendingPause["status"];
                            $operatorAlert .= " Já existe uma pausa aguardando confirmação.";
                        } else {
                            try {
                                $request = $this->solicitarOperacao($user, $loadingId, "PAUSAR_CARREGAMENTO");
                                $requestId = (int) $request["command_request_id"];
                                $requestStatus = (string) $request["status"];
                                $operatorAlert .= " Um novo comando de pausa foi enviado ao gateway.";
                            } catch (ExcecaoComandoClp|ExcecaoDisponibilidadeClp $exception) {
                                if ($exception->httpStatus >= 500) {
                                    throw $exception;
                                }
                                $requestStatus = "NAO_SOLICITADA";
                                $operatorAlert .= " O reenvio não foi aceito pelo gateway: "
                                    . $exception->getMessage()
                                    . " Mantenha a esteira parada e verifique a máquina.";
                            }
                        }
                    }
                    $saveExistingAlert = $this->connection->prepare(
                        "UPDATE carregamentos SET operator_alert = :operator_alert
                         WHERE id = :id AND company_id = :company_id AND stop_reason = :stop_reason",
                    );
                    $saveExistingAlert->execute([
                        "operator_alert" => $operatorAlert,
                        "id" => $loadingId,
                        "company_id" => (int) $user["company_id"],
                        "stop_reason" => $existingReason,
                    ]);
                }
                $result = [
                    "command_request_id" => $requestId,
                    "physical_stop_request_status" => $requestStatus,
                    "operator_alert" => $operatorAlert,
                    "stop_reason" => $existingReason,
                ];
                $this->concluirEscopoTransacao($ownsTransaction, $savepoint);
                return $result;
            }

            $operatorAlert = $reason === "COTA_ROMANEIO_ATINGIDA"
                ? "$label. A parada da esteira foi solicitada ao gateway. Confirme a parada física e confira as leituras e fotos antes de finalizar."
                : ($reason === "COTA_PRODUTO_ATINGIDA"
                ? "$label. A parada da esteira foi solicitada ao gateway para evitar excedente. Confirme a parada física antes de continuar com outro produto."
                : "$label. A solicitação de pausa física foi enviada ao gateway do CLP; remova a unidade e confirme somente quando a esteira estiver fisicamente parada.");
            $pauseLoading = $this->connection->prepare(
                "UPDATE carregamentos
                 SET state = 'PAUSADO', stop_reason = :stop_reason, operator_alert = :operator_alert
                 WHERE id = :id AND company_id = :company_id
                   AND state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
                   AND (stop_reason IS NULL OR stop_reason = :existing_reason)",
            );
            $pauseLoading->execute([
                "id" => $loadingId,
                "company_id" => (int) $user["company_id"],
                "operator_alert" => $operatorAlert,
                "stop_reason" => $reason,
                "existing_reason" => $reason,
            ]);

            $alreadyPausedForReason = $loading["state"] === "PAUSADO"
                && $loading["stop_reason"] === $reason;
            $pendingPause = $this->repositorio->comandoPendente($loadingId, "PAUSAR_CARREGAMENTO");
            $requestId = null;
            $requestStatus = "PENDENTE";
            if ($pendingPause) {
                $requestId = (int) $pendingPause["id"];
                $requestStatus = (string) $pendingPause["status"];
            } elseif ($alreadyPausedForReason && $reason !== "EXCESSO") {
                $lastPause = $this->repositorio->ultimaSolicitacaoComando($loadingId, "PAUSAR_CARREGAMENTO");
                if ($lastPause && $lastPause["status"] === "APLICADO") {
                    $requestId = (int) $lastPause["id"];
                    $requestStatus = "APLICADO";
                    $operatorAlert = $reason === "COTA_ROMANEIO_ATINGIDA"
                        ? "$label. O gateway confirmou a parada. Confira a parada física, as leituras e as fotos antes de finalizar."
                        : ($reason === "COTA_PRODUTO_ATINGIDA"
                        ? "$label. O gateway confirmou a parada física. Confirme a parada antes de continuar com outro produto."
                        : "$label. O gateway confirmou a parada física. Remova a unidade e confirme somente quando a esteira estiver fisicamente parada.");
                }
            }
            if (!$pendingPause && $requestStatus !== "APLICADO") {
                try {
                    $request = $this->solicitarOperacao($user, $loadingId, "PAUSAR_CARREGAMENTO");
                    $requestId = (int) $request["command_request_id"];
                } catch (ExcecaoComandoClp|ExcecaoDisponibilidadeClp $exception) {
                    if ($exception->httpStatus >= 500) {
                        throw $exception;
                    }
                    $requestStatus = "NAO_SOLICITADA";
                    $operatorAlert = $reason === "COTA_ROMANEIO_ATINGIDA"
                        ? "$label. A parada automática pelo CLP não foi confirmada: "
                            . $exception->getMessage()
                            . " Pare a esteira pelo procedimento operacional, confirme a parada física e confira as leituras e fotos antes de finalizar."
                        : ($reason === "COTA_PRODUTO_ATINGIDA"
                        ? "$label. A parada automática pelo CLP não foi confirmada: "
                            . $exception->getMessage()
                            . " Pare a esteira pelo procedimento operacional e confirme a parada física antes de continuar."
                        : "$label. A parada automática pelo CLP não foi confirmada: "
                            . $exception->getMessage()
                            . " Pare a esteira pelo procedimento operacional e confirme somente após a parada física.");
                }
            }

            $saveAlert = $this->connection->prepare(
                "UPDATE carregamentos SET operator_alert = :operator_alert
                 WHERE id = :id AND company_id = :company_id AND stop_reason = :stop_reason",
            );
            $saveAlert->execute([
                "operator_alert" => $operatorAlert,
                "id" => $loadingId,
                "company_id" => (int) $user["company_id"],
                "stop_reason" => $reason,
            ]);

            $this->concluirEscopoTransacao($ownsTransaction, $savepoint);
            return [
                "command_request_id" => $requestId,
                "physical_stop_request_status" => $requestStatus,
                "operator_alert" => $operatorAlert,
                "stop_reason" => $reason,
            ];
        } catch (Throwable $exception) {
            $this->reverterEscopoTransacao($ownsTransaction, $savepoint);
            throw $exception;
        }
    }

    /** Reavalia carregamentos ativos que já têm uma leitura excedente histórica. */
    public function reconciliarParadasPorExcesso(): int
    {
        $statement = $this->connection->prepare(
            "SELECT c.id, c.company_id FROM carregamentos c
             WHERE c.state IN ('CARREGANDO', 'FINALIZANDO', 'PAUSADO')
               AND c.stop_reason IS NULL
               AND EXISTS (
                   SELECT 1 FROM leituras l
                   WHERE l.company_id = c.company_id AND l.carregamento_id = c.id
                     AND l.result = 'EXCESSO' AND l.conta_na_meta = 1
               )
             ORDER BY c.id",
        );
        $statement->execute();
        $loadings = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($loadings as $loading) {
            try {
                $this->solicitarParadaPorExcesso([
                    "id" => null,
                    "company_id" => (int) $loading["company_id"],
                ], (int) $loading["id"]);
            } catch (ExcecaoComandoClp $exception) {
                if (!in_array($exception->httpStatus, [404, 409], true)) {
                    throw $exception;
                }
                error_log(
                    "Excesso ativo mudou antes da reconciliação do carregamento "
                    . (int) $loading["id"] . ": " . $exception->getMessage(),
                );
            }
        }

        return count($loadings);
    }

    /** @param array{id:int|string|null, company_id:int|string|null} $user */
    public function solicitarReversao(
        array $user,
        int $loadingId,
        string $command,
    ): array {
        if (!in_array($command, self::COMANDOS_DE_REVERSAO, true)) {
            throw new ExcecaoComandoClp(
                "Carregamento e comando de reversão válido são obrigatórios.",
                422,
            );
        }
        if ($user["company_id"] === null) {
            throw new ExcecaoComandoClp(
                "Usuário sem empresa vinculada.",
                403,
            );
        }

        $this->connection->beginTransaction();
        try {
            $loading = $this->repositorio->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$loading) {
                throw new ExcecaoComandoClp(
                    "Carregamento não encontrado para esta empresa.",
                    404,
                );
            }
            if (!empty($loading["stop_reason"])) {
                throw new ExcecaoComandoClp(
                    "Confirme a remoção da unidade e a parada física antes de alterar a reversão.",
                    409,
                );
            }
            if (!in_array($loading["state"], ["PREPARANDO", "PAUSADO"], true)) {
                throw new ExcecaoComandoClp(
                    "Só é possível ligar ou desligar a reversão com a esteira parada.",
                    409,
                );
            }
            if ($command === "REVERSAO_ATIVAR") {
                $reversalCommand = $this->repositorio->ultimaReversaoConfirmada(
                    (int) $loading["equipment_id"],
                    (int) $user["company_id"],
                );
                if ($reversalCommand === "REVERSAO_ATIVAR") {
                    throw new ExcecaoComandoClp(
                        "A reversão já está confirmada como ligada.",
                        409,
                    );
                }
            }
            $this->disponibilidadeClp->validarComando(
                (int) $user["company_id"],
                (int) $loading["equipment_id"],
            );
            $pending = $this->repositorio->comandoPendente($loadingId);
            if ($pending) {
                if ($pending["status"] !== "PENDENTE") {
                    throw new ExcecaoComandoClp(
                        "O comando já foi reservado pelo gateway industrial. Aguarde a confirmação antes de alterar a reversão.",
                        409,
                    );
                }
                $substituiveis = [
                    "INICIAR_CARREGAMENTO",
                    "PAUSAR_CARREGAMENTO",
                    ...self::COMANDOS_DE_REVERSAO,
                ];
                if (!in_array($pending["command"], $substituiveis, true)) {
                    throw new ExcecaoComandoClp(
                        "Existe uma solicitação de segurança aguardando o gateway industrial.",
                        409,
                    );
                }
                $message = "Comando substituído por {$command} antes da escrita física.";
                $this->cancelarComandoPendente(
                    $user,
                    $loading,
                    $loadingId,
                    $pending,
                    $command,
                    $message,
                );
            }
            $requestId = $this->registrarSolicitacaoComando(
                $user,
                $loading,
                $loadingId,
                $command,
            );
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof ExcecaoComandoClp || $exception instanceof ExcecaoDisponibilidadeClp) {
                throw $exception;
            }
            error_log(
                "PLC command request could not be created: " .
                    $exception->getMessage(),
            );
            throw new ExcecaoComandoClp(
                "Não foi possível registrar a solicitação de reversão.",
                500,
            );
        }

        return [
            "command_request_id" => $requestId,
            "carregamento_id" => $loadingId,
            "equipment_id" => (int) $loading["equipment_id"],
            "command" => $command,
            "status" => "PENDENTE",
            "state" => $loading["state"],
            "message" =>
                ($command === "REVERSAO_ATIVAR"
                    ? "Ativação da reversão registrada."
                    : "Desativação da reversão registrada.") .
                " O gateway industrial deverá validar os intertravamentos antes de enviar o comando ao CLP.",
        ];
    }

    /** @param array{id:int|string, company_id:int|string|null} $user */
    public function solicitarEmergencia(array $user, int $loadingId): array
    {
        if ($user["company_id"] === null) {
            throw new ExcecaoComandoClp("Usuário sem empresa vinculada.", 403);
        }

        $this->connection->beginTransaction();
        try {
            $loading = $this->repositorio->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$loading) {
                throw new ExcecaoComandoClp(
                    "Carregamento não encontrado para esta empresa.",
                    404,
                );
            }
            if ($loading["state"] === "FINALIZADO") {
                throw new ExcecaoComandoClp(
                    "Não é possível acionar emergência em um carregamento finalizado.",
                    409,
                );
            }

            // O desbloqueio é revogado mesmo se esta emergência já estiver
            // pendente: um novo pedido do operador invalida qualquer ACK
            // de desbloqueio ainda em voo.
            $this->cancelarDesbloqueiosPendentes($user, $loadingId);

            $pending = $this->repositorio->comandoPendente($loadingId, self::COMANDO_DE_EMERGENCIA);
            if ($pending) {
                if ($loading["state"] !== "EMERGENCIA") {
                    $this->definirEstadoEmergencia($user, $loadingId, $loading);
                }
                $this->connection->commit();
                return [
                    "command_request_id" => (int) $pending["id"],
                    "carregamento_id" => $loadingId,
                    "equipment_id" => (int) $loading["equipment_id"],
                    "command" => self::COMANDO_DE_EMERGENCIA,
                    "status" => (string) $pending["status"],
                    "state" => "EMERGENCIA",
                    "message" => "A emergência já está registrada e aguarda confirmação do gateway industrial.",
                ];
            }

            if ($loading["state"] !== "EMERGENCIA") {
                $this->definirEstadoEmergencia($user, $loadingId, $loading);
            }

            $requestId = $this->registrarSolicitacaoComando(
                $user,
                $loading,
                $loadingId,
                self::COMANDO_DE_EMERGENCIA,
                ["safety_priority" => true],
            );
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception instanceof ExcecaoComandoClp) {
                throw $exception;
            }
            error_log("PLC emergency request could not be created: " . $exception->getMessage());
            throw new ExcecaoComandoClp(
                "Não foi possível registrar a emergência.",
                500,
            );
        }

        return [
            "command_request_id" => $requestId,
            "carregamento_id" => $loadingId,
            "equipment_id" => (int) $loading["equipment_id"],
            "command" => self::COMANDO_DE_EMERGENCIA,
            "status" => "PENDENTE",
            "state" => "EMERGENCIA",
            "message" => "Emergência registrada. A parada física aguarda confirmação do gateway/CLP; use também o botão físico de emergência se necessário.",
        ];
    }

    /** @param array{id:int|string, company_id:int|string|null} $user */
    private function cancelarDesbloqueiosPendentes(array $user, int $loadingId): void
    {
        $pending = $this->repositorio->desbloqueiosPendentes(
            $loadingId,
            $user["company_id"],
        );
        foreach ($pending as $request) {
            if (!$this->repositorio->rejeitarDesbloqueioPendente($request["id"])) {
                continue;
            }
            \record_operational_event(
                $this->connection,
                $user,
                "COMANDO_CLP_CONCLUIDO",
                "solicitacao_comando_clp",
                (int) $request["id"],
                [
                    "equipment_id" => (int) $request["equipment_id"],
                    "carregamento_id" => $loadingId,
                    "command" => "DESBLOQUEAR_MAQUINA",
                    "status" => "REJEITADO",
                    "message" => "Nova emergência cancelou o desbloqueio pendente.",
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
    }

    /**
     * @param array{id:int|string,company_id:int|string} $user
     * @param array<string,mixed> $loading
     */
    private function definirEstadoEmergencia(array $user, int $loadingId, array $loading): void
    {
        if (!$this->repositorio->alterarEstadoParaEmergencia(
            $loadingId,
            $user["company_id"],
            (string) $loading["state"],
        )) {
            throw new ExcecaoComandoClp(
                "O estado do carregamento mudou. Atualize a operação antes de acionar a emergência.",
                409,
            );
        }
        \record_operational_event(
            $this->connection,
            $user,
            "ESTADO_CARREGAMENTO_ALTERADO",
            "carregamento",
            $loadingId,
            [
                "previous_state" => $loading["state"],
                "state" => "EMERGENCIA",
                "remote_carregamento_id" => $loading["remote_carregamento_id"] === null
                    ? null : (int) $loading["remote_carregamento_id"],
                "safety_priority" => true,
            ],
        );
    }

    /**
     * Persiste uma solicitação física e sua trilha de auditoria na transação atual.
     * Regras de autorização, intertravamento e estado permanecem nos fluxos chamadores.
     *
     * @param array{id:int|string|null, company_id:int|string|null} $user
     * @param array<string,mixed> $loading
     * @param array<string,mixed> $metadataAdicional
     */
    private function registrarSolicitacaoComando(
        array $user,
        array $loading,
        int $loadingId,
        string $command,
        array $metadataAdicional = [],
    ): int {
        $requestId = $this->repositorio->inserirSolicitacao(
            $user["company_id"],
            $loading["equipment_id"],
            $loadingId,
            $command,
            $user["id"],
        );
        $metadata = [
            "command" => $command,
            "carregamento_id" => $loadingId,
            "equipment_id" => (int) $loading["equipment_id"],
            "remote_carregamento_id" => $loading["remote_carregamento_id"] === null
                ? null : (int) $loading["remote_carregamento_id"],
            "requires_clp_adapter" => true,
        ] + $metadataAdicional;

        \record_operational_event(
            $this->connection,
            $user,
            $command,
            "plc_command_request",
            $requestId,
            $metadata,
        );

        return $requestId;
    }

    /** @param array<string,mixed> $loading @param array{id:int|string,status:string,command:string} $pending */
    private function cancelarComandoPendente(
        array $user,
        array $loading,
        int $loadingId,
        array $pending,
        string $command,
        ?string $message = null,
    ): void {
        $message ??= "Comando substituído por {$command} antes da escrita física.";
        if (!$this->repositorio->rejeitarComandoPendente($pending["id"], $message)) {
            return;
        }

        \record_operational_event(
            $this->connection,
            $user,
            "COMANDO_CLP_CONCLUIDO",
            "solicitacao_comando_clp",
            (int) $pending["id"],
            [
                "equipment_id" => (int) $loading["equipment_id"],
                "carregamento_id" => $loadingId,
                "command" => $pending["command"],
                "status" => "REJEITADO",
                "message" => $message,
                "replaced_by" => $command,
            ],
        );
    }

    private function iniciarEscopoTransacao(string $savepoint): bool
    {
        if (!$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            return true;
        }

        $this->connection->exec("SAVEPOINT {$savepoint}");
        return false;
    }

    private function concluirEscopoTransacao(bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            $this->connection->commit();
            return;
        }

        $this->connection->exec("RELEASE SAVEPOINT {$savepoint}");
    }

    private function reverterEscopoTransacao(bool $ownsTransaction, string $savepoint): void
    {
        if (!$this->connection->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $this->connection->rollBack();
            return;
        }

        $this->connection->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        $this->connection->exec("RELEASE SAVEPOINT {$savepoint}");
    }
}
