<?php
declare(strict_types=1);

// Com o instantâneo protegendo o estado do PC, uma pausa pedida pelo central
// pode chegar a um carregamento que o PC já pausou (parada automática). O ACK
// não muda linha e não gerava ESTADO; o central ficava em CARREGANDO e não
// conseguia encerrar. O ACK passa a sincronizar o PAUSADO só nesse caso.

require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoGatewayClp.php";

use App\Aplicacao\ServicoGatewayClp;

$GLOBALS["auditados"] = [];
$GLOBALS["sincronizados"] = [];

function record_operational_event(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
    $GLOBALS["auditados"][] = $action;
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

/** SQLite com o dialeto MySQL do gateway; a reclassificação das passagens não é o alvo aqui. */
final class PdoGateway extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^\s*UPDATE (eventos_sensor s|leituras l|carregamentos c SET leituras_validas)/', $query) === 1) {
            $query = "SELECT :loading_id";
        }
        $query = str_replace(
            [" FOR UPDATE", "NOW(3)", "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"],
            ["", "CURRENT_TIMESTAMP", ""],
            $query,
        );
        // MySQL sem FOUND_ROWS conta só linhas alteradas: PAUSADO→PAUSADO dá 0.
        // O SQLite conta linhas encontradas; a condição extra reproduz o MySQL.
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
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_carregamento_id INTEGER, state TEXT NOT NULL, stop_reason TEXT)");
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_equipment_id INTEGER, equipment_code TEXT)");
$pdo->exec("CREATE TABLE solicitacoes_comandos_clp (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, command TEXT NOT NULL, status TEXT NOT NULL, claimed_by_device_id INTEGER, expires_at TEXT, completed_at TEXT, response_message TEXT, remote_command_id INTEGER)");
$pdo->exec("CREATE TABLE pausas_carregamento (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, paused_at_epoch_ms INTEGER NOT NULL, resumed_at_epoch_ms INTEGER)");
$pdo->exec("INSERT INTO equipamentos (id, company_id, remote_equipment_id, equipment_code) VALUES (1, 1, 11, 'DALA-01')");

$gateway = new ServicoGatewayClp($pdo);
$requestId = 500;

/** Prepara um PAUSAR reservado pelo dispositivo 7 e confirma com o status informado. */
$confirmar = static function (string $localState, ?int $remoteCommandId, string $status = "APLICADO", string $stopReason = "PRODUTO_INCORRETO") use ($pdo, $gateway, &$requestId): array {
    $requestId++;
    $GLOBALS["auditados"] = [];
    $GLOBALS["sincronizados"] = [];
    $pdo->exec("DELETE FROM carregamentos");
    $pdo->exec("DELETE FROM pausas_carregamento");
    $pdo->prepare("INSERT INTO carregamentos (id, company_id, remote_carregamento_id, state, stop_reason) VALUES (135, 1, 121, ?, ?)")
        ->execute([$localState, $localState === "PAUSADO" ? $stopReason : null]);
    if ($localState === "PAUSADO") {
        // A parada automática já abriu a janela física da pausa.
        $pdo->exec("INSERT INTO pausas_carregamento (company_id, carregamento_id, paused_at_epoch_ms) VALUES (1, 135, 1795465400455)");
    }
    $pdo->prepare(
        "INSERT INTO solicitacoes_comandos_clp (id, company_id, equipment_id, carregamento_id, command, status, claimed_by_device_id, remote_command_id)
         VALUES (?, 1, 1, 135, 'PAUSAR_CARREGAMENTO', 'PROCESSANDO', 7, ?)",
    )->execute([$requestId, $remoteCommandId]);

    return $gateway->concluirComando($requestId, 7, $status, "");
};
$estado = static fn (): string => (string) $pdo->query("SELECT state FROM carregamentos WHERE id = 135")->fetchColumn();

// 1) Pausa do central sobre um PC já pausado: o central recebe PAUSADO.
$result = $confirmar("PAUSADO", 45);
verificar($result["status"] === "APLICADO" && $result["state_changed"] === false, "O ACK continua sem mudança local.");
verificar($estado() === "PAUSADO", "O estado local continua PAUSADO.");
verificar(count($GLOBALS["sincronizados"]) === 1, "A pausa remota sem mudança local deve gerar um evento de sincronização.");
$event = $GLOBALS["sincronizados"][0];
verificar($event["action"] === "ESTADO_CARREGAMENTO_ALTERADO" && $event["entity_type"] === "carregamento" && $event["entity_id"] === 135, "O evento deve ser ESTADO do carregamento 135.");
verificar($event["data"]["state"] === "PAUSADO" && $event["data"]["remote_carregamento_id"] === 121, "O evento leva PAUSADO e o id do central.");
verificar($event["data"]["local_state_unchanged"] === true, "O evento deve indicar que localmente nada mudou.");
verificar($GLOBALS["auditados"] === ["COMANDO_CLP_CONCLUIDO"], "Sem transição local, nenhuma auditoria de ESTADO é gravada.");

// 2) Pausa automática local já aplicada: comportamento anterior (o central usa o evento da divergência).
$confirmar("PAUSADO", null);
verificar($GLOBALS["sincronizados"] === [], "Pausa local não gera ESTADO extra.");
verificar($GLOBALS["auditados"] === ["COMANDO_CLP_CONCLUIDO"], "Pausa local sem mudança continua só com o retorno do comando.");

// 3) Pausa do central sobre CARREGANDO: transição normal, como antes.
$result = $confirmar("CARREGANDO", 46);
verificar($result["state_changed"] === true && $estado() === "PAUSADO", "CARREGANDO deve virar PAUSADO no ACK.");
verificar($GLOBALS["auditados"] === ["COMANDO_CLP_CONCLUIDO", "ESTADO_CARREGAMENTO_ALTERADO"], "A transição real continua auditada.");
verificar($GLOBALS["sincronizados"] === [], "Transição real não duplica o ESTADO.");

// 4) Pausa do central que falhou ou foi recusada no CLP não sincroniza PAUSADO.
$confirmar("PAUSADO", 47, "ERRO");
verificar($GLOBALS["sincronizados"] === [], "Comando com erro não sincroniza estado.");
$confirmar("PAUSADO", 48, "REJEITADO");
verificar($GLOBALS["sincronizados"] === [], "Comando recusado não sincroniza estado.");

// 5) Carga já encerrada no PC: o ACK não manda PAUSADO (não pode reabrir nada).
$confirmar("FINALIZADO", 49);
verificar($estado() === "FINALIZADO", "Carga encerrada continua encerrada.");
verificar($GLOBALS["sincronizados"] === [], "Pausa remota sobre carga encerrada não sincroniza estado.");

echo "Gateway: pausa do central sobre PC já pausado sincroniza PAUSADO; demais ACKs inalterados.\n";
