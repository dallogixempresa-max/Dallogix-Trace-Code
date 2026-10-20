<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioDisponibilidadeClp;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . "/../Persistencia/RepositorioDisponibilidadeClp.php";

final class ExcecaoEstadoCarregamento extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}

final class ServicoEstadoCarregamento
{
    private const TRANSICOES_DE_ESTADO = [
        "AGUARDANDO" => ["PREPARANDO", "EMERGENCIA"],
        "PREPARANDO" => ["CARREGANDO", "PAUSADO", "EMERGENCIA"],
        "CARREGANDO" => ["PAUSADO", "FINALIZANDO", "EMERGENCIA"],
        "PAUSADO" => ["CARREGANDO", "EMERGENCIA"],
        "FINALIZANDO" => ["EMERGENCIA"],
        // A saída da emergência só pode ocorrer após ACK do gateway industrial.
        // O endpoint genérico não deve permitir que um usuário contorne esse fluxo.
        "EMERGENCIA" => [],
        "FINALIZADO" => [],
    ];

    public function __construct(private readonly PDO $connection) {}

    /** @param array{id:int|string, company_id:int|string|null, role:string} $user */
    public function alterarEstado(array $user, int $loadingId, string $target): array
    {
        if ($user["company_id"] === null) {
            throw new ExcecaoEstadoCarregamento("Usuário sem empresa vinculada.", 403);
        }
        if (!array_key_exists($target, self::TRANSICOES_DE_ESTADO)) {
            throw new ExcecaoEstadoCarregamento("Carregamento e estado válido são obrigatórios.", 422);
        }
        if ($target === "FINALIZADO") {
            throw new ExcecaoEstadoCarregamento(
                "Use o encerramento do carregamento para validar capturas e divergências.",
                409,
            );
        }

        $this->connection->beginTransaction();
        try {
            $current = $this->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$current) {
                throw new ExcecaoEstadoCarregamento("Carregamento não encontrado para esta empresa.", 404);
            }
            if ($target === "CARREGANDO" && !empty($current["stop_reason"])) {
                throw new ExcecaoEstadoCarregamento(
                    "Confirme a remoção da unidade e a parada física antes de retomar o carregamento.",
                    409,
                );
            }
            if ($target === "CARREGANDO") {
                $pendingStop = $this->connection->prepare(
                    "SELECT 1 FROM solicitacoes_comandos_clp
                     WHERE carregamento_id = :loading_id AND company_id = :company_id
                       AND command = 'PAUSAR_CARREGAMENTO'
                       AND status IN ('PENDENTE', 'PROCESSANDO')
                     LIMIT 1",
                );
                $pendingStop->execute([
                    "loading_id" => $loadingId,
                    "company_id" => $user["company_id"],
                ]);
                if ($pendingStop->fetchColumn()) {
                    throw new ExcecaoEstadoCarregamento(
                        "Aguarde o gateway confirmar ou cancelar a parada antes de retomar o carregamento.",
                        409,
                    );
                }
            }
            if ($target === $current["state"]) {
                $this->connection->commit();
                return [
                    "id" => $loadingId,
                    "previous_state" => $current["state"],
                    "state" => $target,
                    "changed" => false,
                ];
            }
            if (!in_array($target, self::TRANSICOES_DE_ESTADO[$current["state"]], true)) {
                throw new ExcecaoEstadoCarregamento(
                    "Transição inválida: {$current["state"]} para {$target}.",
                    409,
                );
            }
            if (in_array($target, ["CARREGANDO", "PAUSADO"], true)) {
                (new ServicoDisponibilidadeClp(
                    new RepositorioDisponibilidadeClp($this->connection),
                ))->validarComando(
                    (int) $user["company_id"],
                    (int) $current["equipment_id"],
                );
            }

            $update = $this->connection->prepare(
                "UPDATE carregamentos SET state = :state
                 WHERE id = :id AND company_id = :company_id AND state = :previous_state",
            );
            $update->execute([
                "state" => $target,
                "id" => $loadingId,
                "company_id" => $user["company_id"],
                "previous_state" => $current["state"],
            ]);
            if ($update->rowCount() !== 1) {
                throw new ExcecaoEstadoCarregamento(
                    "O estado do carregamento mudou. Atualize a operação antes de tentar novamente.",
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
                    "previous_state" => $current["state"],
                    "state" => $target,
                    "remote_carregamento_id" => $current["remote_carregamento_id"] === null
                        ? null : (int) $current["remote_carregamento_id"],
                ],
            );
            $this->connection->commit();
            return [
                "id" => $loadingId,
                "previous_state" => $current["state"],
                "state" => $target,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array{id:int|string, company_id:int|string|null, role:string} $user */
    public function confirmarRemocao(array $user, int $loadingId): array
    {
        if ($user["company_id"] === null) {
            throw new ExcecaoEstadoCarregamento("Usuário sem empresa vinculada.", 403);
        }
        if ($loadingId <= 0) {
            throw new ExcecaoEstadoCarregamento("Carregamento obrigatório.", 422);
        }

        $this->connection->beginTransaction();
        try {
            $current = $this->localizarCarregamento($loadingId, (int) $user["company_id"]);
            if (!$current) {
                throw new ExcecaoEstadoCarregamento("Carregamento não encontrado para esta empresa.", 404);
            }
            $reason = (string) ($current["stop_reason"] ?? "");
            if (
                $current["state"] !== "PAUSADO"
                || !in_array($reason, ["PRODUTO_INCORRETO", "EXCESSO"], true)
                || trim((string) ($current["operator_alert"] ?? "")) === ""
            ) {
                throw new ExcecaoEstadoCarregamento(
                    "Não há uma parada por divergência aguardando confirmação neste carregamento.",
                    409,
                );
            }

            $update = $this->connection->prepare(
                "UPDATE carregamentos
                 SET stop_reason = NULL, operator_alert = NULL
                 WHERE id = :id AND company_id = :company_id AND state = 'PAUSADO'
                   AND stop_reason = :stop_reason",
            );
            $update->execute([
                "id" => $loadingId,
                "company_id" => $user["company_id"],
                "stop_reason" => $reason,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ExcecaoEstadoCarregamento(
                    "A parada mudou antes da confirmação. Atualize a operação e tente novamente.",
                    409,
                );
            }

            \record_operational_event(
                $this->connection,
                $user,
                "REMOCAO_UNIDADE_E_PARADA_FISICA_CONFIRMADAS",
                "carregamento",
                $loadingId,
                ["stop_reason" => $reason, "physical_stop_confirmed_by_operator" => true],
            );
            $this->connection->commit();
            $cancelledPauseRequests = $this->cancelarParadasAindaNaoReservadas($user, $loadingId);
            return [
                "id" => $loadingId,
                "state" => "PAUSADO",
                "confirmed" => true,
                "cancelled_pending_stop_commands" => $cancelledPauseRequests,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array{id:int|string, company_id:int|string|null, role:string} $user */
    private function cancelarParadasAindaNaoReservadas(array $user, int $loadingId): int
    {
        $this->connection->beginTransaction();
        try {
            $pending = $this->connection->prepare(
                "SELECT id FROM solicitacoes_comandos_clp
                 WHERE carregamento_id = :loading_id AND company_id = :company_id
                   AND command = 'PAUSAR_CARREGAMENTO' AND status = 'PENDENTE'
                 ORDER BY id",
            );
            $pending->execute([
                "loading_id" => $loadingId,
                "company_id" => $user["company_id"],
            ]);
            $requests = $pending->fetchAll(PDO::FETCH_COLUMN);
            $cancel = $this->connection->prepare(
                "UPDATE solicitacoes_comandos_clp
                 SET status = 'REJEITADO', completed_at = NOW(3),
                     response_message = 'Solicitação cancelada após confirmação física do operador, antes de ser reservada pelo gateway.'
                 WHERE id = :id AND company_id = :company_id
                   AND command = 'PAUSAR_CARREGAMENTO' AND status = 'PENDENTE'",
            );
            $cancelled = 0;
            foreach ($requests as $requestId) {
                $cancel->execute([
                    "id" => $requestId,
                    "company_id" => $user["company_id"],
                ]);
                if ($cancel->rowCount() !== 1) {
                    continue;
                }
                $cancelled++;
                \record_operational_event(
                    $this->connection,
                    $user,
                    "COMANDO_CLP_CONCLUIDO",
                    "solicitacao_comando_clp",
                    (int) $requestId,
                    [
                        "carregamento_id" => $loadingId,
                        "command" => "PAUSAR_CARREGAMENTO",
                        "status" => "REJEITADO",
                        "message" => "Solicitação cancelada após confirmação física do operador, antes de ser reservada pelo gateway.",
                        "physical_stop_confirmed_by_operator" => true,
                    ],
                );
            }
            $this->connection->commit();
            return $cancelled;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function localizarCarregamento(int $loadingId, int $companyId): array|false
    {
        $statement = $this->connection->prepare(
            "SELECT id, state, equipment_id, remote_carregamento_id, stop_reason, operator_alert FROM carregamentos
             WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
        );
        $statement->execute(["id" => $loadingId, "company_id" => $companyId]);
        return $statement->fetch();
    }
}
