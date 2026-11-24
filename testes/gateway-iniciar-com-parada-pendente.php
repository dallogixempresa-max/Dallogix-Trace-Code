<?php
declare(strict_types=1);

// O "Ligar" do central chega ao PC como INICIAR_CARREGAMENTO PENDENTE mesmo
// quando o PC ainda tem uma parada por divergência sem confirmação (a linha do
// central pode estar liberada). O gateway não pode entregar esse comando ao
// node-red: ele termina REJEITADO na reserva, com retorno para o central, e não
// fica adiado para disparar sozinho depois da confirmação do operador.

require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoGatewayClp.php";

use App\Aplicacao\ServicoGatewayClp;

$GLOBALS["auditados"] = [];
$GLOBALS["sincronizados"] = [];

function record_operational_event(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
    $GLOBALS["auditados"][] = ["action" => $action, "entity_type" => $entityType, "entity_id" => $entityId, "data" => $payload];
}

function enfileirar_evento_sincronizacao(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
    $GLOBALS["sincronizados"][] = ["action" => $action, "entity_type" => $entityType, "entity_id" => $entityId, "data" => $payload];
}

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** SQLite com o dialeto MySQL do gateway (reserva e ACK). */
final class PdoGateway extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^\s*UPDATE (eventos_sensor s|leituras l|carregamentos c SET leituras_validas)/', $query) === 1) {
            $query = "SELECT :loading_id";
        }
        $query = str_replace(
            [
                " FOR UPDATE SKIP LOCKED",
                " FOR UPDATE",
                "DATE_ADD(NOW(3), INTERVAL 2 MINUTE)",
                "NOW(3)",
                "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            ],
            ["", "", "datetime('now', '+2 minutes')", "CURRENT_TIMESTAMP", ""],
            $query,
        );
        // MySQL sem FOUND_ROWS conta só linhas alteradas: PAUSADO→PAUSADO dá 0.
        $query = str_replace(
            "AND state = :previous_state",
            "AND state = :previous_state AND state <> :state",
            $query,
        );
        return parent::prepare($query, $options);
    }
}

$pdo = new PdoGateway("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_carregamento_id INTEGER, state TEXT NOT NULL, stop_reason TEXT, operator_alert TEXT)");
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_equipment_id INTEGER, equipment_code TEXT)");
$pdo->exec("CREATE TABLE solicitacoes_comandos_clp (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, command TEXT NOT NULL, status TEXT NOT NULL, requested_at TEXT NOT NULL, claimed_at TEXT, claimed_by_device_id INTEGER, expires_at TEXT, completed_at TEXT, response_message TEXT, remote_command_id INTEGER)");
$pdo->exec("CREATE TABLE pausas_carregamento (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, paused_at_epoch_ms INTEGER NOT NULL, resumed_at_epoch_ms INTEGER)");
$pdo->exec("INSERT INTO equipamentos (id, company_id, remote_equipment_id, equipment_code) VALUES (1, 1, 11, 'DALA-01')");

$gateway = new ServicoGatewayClp($pdo);
$segundo = 0;

$preparar = static function (string $state, ?string $stopReason) use ($pdo): void {
    $GLOBALS["auditados"] = [];
    $GLOBALS["sincronizados"] = [];
    $pdo->exec("DELETE FROM carregamentos");
    $pdo->exec("DELETE FROM solicitacoes_comandos_clp");
    $pdo->exec("DELETE FROM pausas_carregamento");
    $pdo->prepare("INSERT INTO carregamentos (id, company_id, remote_carregamento_id, state, stop_reason, operator_alert) VALUES (135, 1, 121, ?, ?, ?)")
        ->execute([$state, $stopReason, $stopReason === null ? null : "Produto incorreto. Remova a unidade e confirme."]);
    if ($state === "PAUSADO") {
        $pdo->exec("INSERT INTO pausas_carregamento (company_id, carregamento_id, paused_at_epoch_ms) VALUES (1, 135, 1795465400455)");
    }
};
$pedir = static function (string $command, ?int $remoteCommandId = null, string $status = "PENDENTE") use ($pdo, &$segundo): int {
    $segundo++;
    $pdo->prepare(
        "INSERT INTO solicitacoes_comandos_clp (company_id, equipment_id, carregamento_id, command, status, requested_at, remote_command_id, claimed_by_device_id, expires_at)
         VALUES (1, 1, 135, ?, ?, ?, ?, ?, ?)",
    )->execute([
        $command,
        $status,
        sprintf("2026-11-24 10:00:%02d.000", $segundo),
        $remoteCommandId,
        $status === "PROCESSANDO" ? 7 : null,
        $status === "PROCESSANDO" ? "2999-01-01 00:00:00" : null,
    ]);
    return (int) $pdo->lastInsertId();
};
$solicitacao = static function (int $id) use ($pdo): array {
    $statement = $pdo->prepare("SELECT * FROM solicitacoes_comandos_clp WHERE id = ?");
    $statement->execute([$id]);
    return $statement->fetch();
};
$carregamento = static fn (): array => $pdo->query("SELECT state, stop_reason FROM carregamentos WHERE id = 135")->fetch();
$pausaAberta = static fn (): bool => (bool) $pdo->query("SELECT COUNT(*) FROM pausas_carregamento WHERE carregamento_id = 135 AND resumed_at_epoch_ms IS NULL")->fetchColumn();
$acoes = static fn (): array => array_column($GLOBALS["auditados"], "action");

// 1) Incidente: "Ligar" do central com produto incorreto ainda na esteira.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$iniciar = $pedir("INICIAR_CARREGAMENTO", 901);
verificar($gateway->reservarComando(1, 7) === null, "O INICIAR bloqueado não pode ser entregue ao node-red.");
$request = $solicitacao($iniciar);
verificar($request["status"] === "REJEITADO", "O INICIAR com parada pendente termina REJEITADO.");
verificar($request["claimed_by_device_id"] === null && $request["completed_at"] !== null, "A recusa conclui sem reservar para o dispositivo.");
verificar(str_contains((string) $request["response_message"], "PRODUTO_INCORRETO"), "A mensagem cita o motivo da parada.");
verificar(str_contains((string) $request["response_message"], "a esteira não foi ligada"), "A mensagem diz que a esteira não foi ligada.");
verificar($acoes() === ["COMANDO_CLP_CONCLUIDO"], "A recusa grava um único retorno do comando.");
$retorno = $GLOBALS["auditados"][0];
verificar($retorno["entity_type"] === "solicitacao_comando_clp" && $retorno["entity_id"] === $iniciar, "O retorno aponta para a solicitação recusada.");
verificar($retorno["data"]["status"] === "REJEITADO" && $retorno["data"]["command"] === "INICIAR_CARREGAMENTO", "O central recebe INICIAR REJEITADO.");
verificar($retorno["data"]["remote_command_id"] === 901 && $retorno["data"]["remote_carregamento_id"] === 121, "O retorno leva os ids do central.");
verificar($retorno["data"]["remote_equipment_id"] === 11 && $retorno["data"]["equipment_code"] === "DALA-01", "O retorno identifica a Dala no central.");
verificar($retorno["data"]["message"] === $request["response_message"], "O central recebe a mesma mensagem gravada no PC.");
verificar($carregamento() === ["state" => "PAUSADO", "stop_reason" => "PRODUTO_INCORRETO"], "A parada e o motivo continuam intactos.");
verificar($pausaAberta(), "A janela da pausa continua aberta.");

// 2) Não é adiado: depois da confirmação do operador nada dispara sozinho.
$GLOBALS["auditados"] = [];
$pdo->exec("UPDATE carregamentos SET stop_reason = NULL, operator_alert = NULL WHERE id = 135");
verificar($gateway->reservarComando(1, 7) === null, "O INICIAR recusado não volta para a fila após a confirmação.");
verificar($solicitacao($iniciar)["status"] === "REJEITADO" && $GLOBALS["auditados"] === [], "Nada mais acontece com o comando recusado.");

// 3) Um novo "Ligar" depois da confirmação segue normalmente até CARREGANDO.
$novoInicio = $pedir("INICIAR_CARREGAMENTO", 902);
$claimed = $gateway->reservarComando(1, 7);
verificar($claimed !== null && (int) $claimed["id"] === $novoInicio, "Sem parada pendente o INICIAR é reservado.");
verificar($claimed["stop_reason"] === null && $claimed["loading_state"] === "PAUSADO", "A reserva expõe estado e motivo ao node-red.");
$result = $gateway->concluirComando($novoInicio, 7, "APLICADO", "motor ligado");
verificar($result["status"] === "APLICADO" && $result["state_changed"] === true, "O ACK do INICIAR liberado continua mudando o estado.");
verificar($carregamento()["state"] === "CARREGANDO" && !$pausaAberta(), "O INICIAR liberado leva a CARREGANDO e fecha a pausa.");

// 4) A recusa não trava a fila: o PAUSAR seguinte sai na mesma reserva.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$iniciar = $pedir("INICIAR_CARREGAMENTO", 903);
$pausar = $pedir("PAUSAR_CARREGAMENTO", 904);
$claimed = $gateway->reservarComando(1, 7);
verificar($claimed !== null && (int) $claimed["id"] === $pausar, "O PAUSAR seguinte é reservado na mesma chamada.");
verificar($claimed["command"] === "PAUSAR_CARREGAMENTO" && $claimed["stop_reason"] === "PRODUTO_INCORRETO", "A parada segue com o motivo exposto.");
verificar($solicitacao($pausar)["status"] === "PROCESSANDO" && (int) $solicitacao($pausar)["claimed_by_device_id"] === 7, "O PAUSAR fica PROCESSANDO no dispositivo.");
verificar($solicitacao($iniciar)["status"] === "REJEITADO", "O INICIAR anterior foi recusado.");
$result = $gateway->concluirComando($pausar, 7, "APLICADO", "motor desligado");
verificar($result["status"] === "APLICADO" && $carregamento()["state"] === "PAUSADO", "O ACK da parada continua igual.");
verificar(count($GLOBALS["sincronizados"]) === 1 && $GLOBALS["sincronizados"][0]["data"]["state"] === "PAUSADO", "A pausa do central sobre PC pausado ainda sincroniza PAUSADO.");

// 5) Fora do escopo: a reversão segue para o node-red como antes, mesmo com a parada pendente.
$preparar("PAUSADO", "EXCESSO");
foreach (["REVERSAO_ATIVAR", "REVERSAO_DESATIVAR"] as $command) {
    $GLOBALS["auditados"] = [];
    $id = $pedir($command, 905);
    $claimed = $gateway->reservarComando(1, 7);
    verificar($claimed !== null && (int) $claimed["id"] === $id && $claimed["command"] === $command, "A reversão continua sendo reservada.");
    verificar($solicitacao($id)["status"] === "PROCESSANDO" && $GLOBALS["auditados"] === [], "A reserva da reversão não grava recusa.");
    $result = $gateway->concluirComando($id, 7, "APLICADO", "reversão escrita");
    verificar($result["status"] === "APLICADO" && $result["state_changed"] === false, "O ACK da reversão continua igual.");
    verificar($carregamento() === ["state" => "PAUSADO", "stop_reason" => "EXCESSO"], "A reversão não mexe no estado nem no motivo.");
}

// 6) Parada prioritária do sensor não é afetada nem arrasta o INICIAR.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$iniciar = $pedir("INICIAR_CARREGAMENTO", 907);
$pausar = $pedir("PAUSAR_CARREGAMENTO");
$claimed = $gateway->reservarComando(1, 7, $pausar);
verificar($claimed !== null && (int) $claimed["id"] === $pausar, "A reserva prioritária continua entregando a parada.");
verificar($solicitacao($iniciar)["status"] === "PENDENTE" && $GLOBALS["auditados"] === [], "A reserva prioritária não avalia outros comandos.");
verificar($gateway->reservarComando(1, 7) === null && $solicitacao($iniciar)["status"] === "PENDENTE", "Com a parada PROCESSANDO nada mais é reservado.");
$gateway->concluirComando($pausar, 7, "APLICADO", "motor desligado");
$GLOBALS["auditados"] = [];
verificar($gateway->reservarComando(1, 7) === null, "Depois da parada, o INICIAR ainda bloqueado não sai.");
verificar($solicitacao($iniciar)["status"] === "REJEITADO" && $acoes() === ["COMANDO_CLP_CONCLUIDO"], "O INICIAR é recusado na reserva seguinte.");

// 7) Parada pendente após finalizar continua sendo executada.
$preparar("FINALIZADO", "COTA_ROMANEIO_ATINGIDA");
$pausar = $pedir("PAUSAR_CARREGAMENTO");
$claimed = $gateway->reservarComando(1, 7, $pausar);
verificar($claimed !== null && (int) $claimed["id"] === $pausar && $claimed["loading_state"] === "FINALIZADO", "A parada pendente após finalizar é reservada.");
$result = $gateway->concluirComando($pausar, 7, "APLICADO", "motor desligado");
verificar($result["status"] === "APLICADO" && $carregamento()["state"] === "FINALIZADO", "O ACK não reabre a carga finalizada.");
$pausar = $pedir("PAUSAR_CARREGAMENTO");
$claimed = $gateway->reservarComando(1, 7);
verificar($claimed !== null && (int) $claimed["id"] === $pausar, "A reserva comum também entrega a parada após finalizar.");

// 8) Defesa no ACK: a divergência entrou enquanto o INICIAR era escrito.
$preparar("PAUSADO", null);
$iniciar = $pedir("INICIAR_CARREGAMENTO", 908);
$claimed = $gateway->reservarComando(1, 7);
verificar($claimed !== null && (int) $claimed["id"] === $iniciar, "Sem parada o INICIAR é reservado.");
$pdo->exec("UPDATE carregamentos SET stop_reason = 'PRODUTO_INCORRETO', operator_alert = 'Remova a unidade.' WHERE id = 135");
$result = $gateway->concluirComando($iniciar, 7, "APLICADO", "motor ligado");
verificar($result["status"] === "ERRO" && $result["state_changed"] === false, "O ACK não aplica CARREGANDO com parada pendente.");
verificar($carregamento() === ["state" => "PAUSADO", "stop_reason" => "PRODUTO_INCORRETO"], "O PC continua PAUSADO com o aviso confirmável.");
verificar($pausaAberta(), "A janela da pausa não é fechada.");
$request = $solicitacao($iniciar);
verificar($request["status"] === "ERRO" && str_contains((string) $request["response_message"], "PRODUTO_INCORRETO"), "A solicitação registra o motivo do erro.");
verificar($acoes() === ["COMANDO_CLP_CONCLUIDO"], "Sem transição, nenhuma auditoria de ESTADO é gravada.");
verificar($GLOBALS["auditados"][0]["data"]["status"] === "ERRO" && $GLOBALS["auditados"][0]["data"]["remote_command_id"] === 908, "O central recebe o ERRO do próprio comando.");

// 9) ACK de INICIAR recusado pelo node-red com parada pendente: sem mudança.
$preparar("PAUSADO", null);
$iniciar = $pedir("INICIAR_CARREGAMENTO");
$gateway->reservarComando(1, 7);
$pdo->exec("UPDATE carregamentos SET stop_reason = 'EXCESSO' WHERE id = 135");
$result = $gateway->concluirComando($iniciar, 7, "REJEITADO", "recusado no node-red");
verificar($result["status"] === "REJEITADO" && $carregamento()["state"] === "PAUSADO", "Recusa do node-red continua sem mudar estado.");

echo "Gateway: INICIAR com parada por divergência pendente termina REJEITADO na reserva; INICIAR/PAUSAR normais, reversão, parada prioritária e parada após finalizar inalterados.\n";
