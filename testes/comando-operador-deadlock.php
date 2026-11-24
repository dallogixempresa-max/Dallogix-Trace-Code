<?php
declare(strict_types=1);

// "Desligar esteira" falhava com 500 quando o MySQL desfazia a transação do
// comando por deadlock com a sincronização do central. O comando do operador
// deve ser repetido do início; outras falhas não são repetidas e ficam em
// logs_erros. Dentro de uma parada automática (transação aninhada) nada muda.

require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoComandoClp.php";

use App\Aplicacao\ExcecaoComandoClp;
use App\Aplicacao\ServicoComandoClp;

$GLOBALS["eventos"] = [];
$GLOBALS["logs"] = [];

function record_operational_event(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
    $GLOBALS["eventos"][] = $action;
}

function registrar_log_erro(Throwable $exception, string $origem = "api"): void
{
    $GLOBALS["logs"][] = $origem;
}

final class FalhaMysqlSimulada extends PDOException
{
    public function __construct(string $sqlState, int $driverCode)
    {
        parent::__construct("SQLSTATE[{$sqlState}]: simulado {$driverCode}");
        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, $driverCode, "simulado"];
    }
}

/** SQLite com o dialeto MySQL do repositório e falhas injetadas no INSERT do comando. */
final class PdoComFalhas extends PDO
{
    /** @var list<array{sqlstate:string,code:int,rollback:bool,depois?:callable}> */
    public array $falhas = [];
    public int $insertsTentados = 0;

    public function __construct()
    {
        parent::__construct("sqlite::memory:", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ComandoStatement::class, [$this]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            [" FOR UPDATE", "TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW())", "NOW(3)"],
            ["", "0", "CURRENT_TIMESTAMP"],
            $query,
        );
        return parent::prepare($query, $options);
    }
}

class ComandoStatement extends PDOStatement
{
    protected function __construct(private readonly PdoComFalhas $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (str_starts_with(ltrim($this->queryString), "INSERT INTO solicitacoes_comandos_clp")) {
            $this->pdo->insertsTentados++;
            $falha = array_shift($this->pdo->falhas);
            if ($falha !== null) {
                // Como o InnoDB: deadlock desfaz a transação inteira; espera de
                // lock esgotada (1205) e FK desfazem só o comando SQL.
                if ($falha["rollback"] && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if (isset($falha["depois"])) {
                    ($falha["depois"])($this->pdo);
                }
                throw new FalhaMysqlSimulada($falha["sqlstate"], $falha["code"]);
            }
        }
        return parent::execute($params);
    }
}

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PdoComFalhas();
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, remote_carregamento_id INTEGER, state TEXT NOT NULL, stop_reason TEXT, operator_alert TEXT)");
$pdo->exec("CREATE TABLE solicitacoes_comandos_clp (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, command TEXT NOT NULL, requested_by INTEGER, status TEXT NOT NULL DEFAULT 'PENDENTE', completed_at TEXT, response_message TEXT)");
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE status_dispositivos (equipment_id INTEGER NOT NULL, device_type TEXT NOT NULL, status TEXT NOT NULL, last_seen_at TEXT)");
$pdo->exec("INSERT INTO equipamentos (id, company_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO status_dispositivos (equipment_id, device_type, status, last_seen_at) VALUES (1, 'CLP', 'ONLINE', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO carregamentos (id, company_id, equipment_id, remote_carregamento_id, state) VALUES (135, 1, 1, 121, 'CARREGANDO')");

$operador = ["id" => 2, "company_id" => 1, "role" => "USUARIO"];
$deadlock = ["sqlstate" => "40001", "code" => 1213, "rollback" => true];
$servico = new ServicoComandoClp($pdo);

$reiniciar = static function (string $state) use ($pdo): void {
    $pdo->exec("DELETE FROM solicitacoes_comandos_clp");
    $pdo->exec("UPDATE carregamentos SET state = '{$state}', stop_reason = NULL, operator_alert = NULL WHERE id = 135");
    $pdo->falhas = [];
    $pdo->insertsTentados = 0;
    $GLOBALS["eventos"] = [];
    $GLOBALS["logs"] = [];
};
$comandos = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM solicitacoes_comandos_clp")->fetchColumn();

// 1) Um deadlock: o desligamento é repetido e registrado uma única vez.
$reiniciar("CARREGANDO");
$pdo->falhas = [$deadlock];
$resultado = $servico->solicitarOperacaoManual($operador, 135, "PAUSAR_CARREGAMENTO");
verificar($resultado["status"] === "PENDENTE", "O desligamento deveria ficar aguardando o gateway após repetir.");
verificar($pdo->insertsTentados === 2, "O comando deveria ser repetido uma vez após o deadlock.");
verificar($comandos() === 1, "O deadlock não pode duplicar o comando.");
verificar($GLOBALS["eventos"] === ["PAUSAR_CARREGAMENTO"], "Só a tentativa confirmada gera auditoria e sincronização.");
verificar($GLOBALS["logs"] === [], "Deadlock resolvido na repetição não é erro.");
verificar(!$pdo->inTransaction(), "A transação do comando deve terminar fechada.");

// 1b) pdo_mysql ainda informa inTransaction() depois do 1213 (o pacote de erro
// não traz o status do servidor): o serviço envia ROLLBACK e repete do mesmo jeito.
$reiniciar("CARREGANDO");
$pdo->falhas = [["sqlstate" => "40001", "code" => 1213, "rollback" => false]];
$resultado = $servico->solicitarOperacaoManual($operador, 135, "PAUSAR_CARREGAMENTO");
verificar($resultado["status"] === "PENDENTE", "Com inTransaction() desatualizado o desligamento também deve ser repetido.");
verificar($pdo->insertsTentados === 2 && $comandos() === 1, "Uma repetição e um único comando.");
verificar(!$pdo->inTransaction(), "A transação deve terminar fechada mesmo com inTransaction() desatualizado.");

// 2) Deadlock persistente: 500 com a causa real em logs_erros, sem comando.
$reiniciar("CARREGANDO");
$pdo->falhas = [$deadlock, $deadlock, $deadlock];
try {
    $servico->solicitarOperacaoManual($operador, 135, "PAUSAR_CARREGAMENTO");
    throw new RuntimeException("Deadlock persistente deveria falhar.");
} catch (ExcecaoComandoClp $exception) {
    verificar($exception->httpStatus === 500, "Deadlock persistente deve responder 500.");
    verificar($exception->getMessage() === "Não foi possível registrar o comando operacional.", "A mensagem ao operador não deveria mudar.");
    verificar($exception->getPrevious() instanceof PDOException, "A causa real deve seguir anexada.");
}
verificar($pdo->insertsTentados === 3, "São no máximo três tentativas.");
verificar($comandos() === 0 && $GLOBALS["eventos"] === [], "Nenhuma tentativa desfeita pode deixar comando ou evento.");
verificar($GLOBALS["logs"] === ["comando_clp.solicitar_operacao"], "A falha final deve ir para logs_erros.");

// 3) Espera de lock esgotada (1205) não é repetida: o navegador já desistiu.
$reiniciar("CARREGANDO");
$pdo->falhas = [["sqlstate" => "HY000", "code" => 1205, "rollback" => false]];
try {
    $servico->solicitarOperacaoManual($operador, 135, "PAUSAR_CARREGAMENTO");
    throw new RuntimeException("1205 deveria falhar sem repetir.");
} catch (ExcecaoComandoClp $exception) {
    verificar($exception->httpStatus === 500, "1205 continua respondendo 500.");
}
verificar($pdo->insertsTentados === 1, "1205 não pode ser repetido.");
verificar($comandos() === 0, "1205 não pode deixar comando gravado.");
verificar($GLOBALS["logs"] === ["comando_clp.solicitar_operacao"], "A causa do 1205 deve ir para logs_erros.");
verificar(!$pdo->inTransaction(), "1205 deve desfazer a transação do comando.");

// 4) Se uma parada automática venceu a corrida, a repetição revalida tudo.
$reiniciar("CARREGANDO");
$pdo->falhas = [$deadlock + ["depois" => static function (PDO $connection): void {
    $connection->exec("UPDATE carregamentos SET state = 'PAUSADO', stop_reason = 'COTA_ROMANEIO_ATINGIDA', operator_alert = 'Meta' WHERE id = 135");
    $connection->exec("INSERT INTO solicitacoes_comandos_clp (company_id, equipment_id, carregamento_id, command, status) VALUES (1, 1, 135, 'PAUSAR_CARREGAMENTO', 'PENDENTE')");
}]];
try {
    $servico->solicitarOperacaoManual($operador, 135, "PAUSAR_CARREGAMENTO");
    throw new RuntimeException("A repetição deveria encontrar a parada automática pendente.");
} catch (ExcecaoComandoClp $exception) {
    verificar($exception->httpStatus === 409, "A parada já pendente deve responder 409, não 500.");
}
verificar($comandos() === 1, "A repetição não pode criar uma segunda parada.");

// 5) Parada automática (transação aninhada): sem repetição e sem log aqui,
// como antes. A exceção exata depende do driver (no MySQL o savepoint some
// junto com a transação); o que importa é que a falha sobe para quem chamou.
foreach ([true, false] as $rollbackNoServidor) {
    $reiniciar("CARREGANDO");
    $pdo->falhas = [["sqlstate" => "40001", "code" => 1213, "rollback" => $rollbackNoServidor]];
    $pdo->beginTransaction();
    $propagou = false;
    try {
        $servico->solicitarOperacao(["id" => null, "company_id" => 1], 135, "PAUSAR_CARREGAMENTO");
    } catch (Throwable) {
        $propagou = true;
    }
    verificar($propagou, "Deadlock aninhado deve chegar a quem abriu a transação.");
    verificar($pdo->insertsTentados === 1, "Chamada aninhada não pode repetir sozinha.");
    verificar($GLOBALS["logs"] === [], "Chamada aninhada não grava log dentro da transação de quem chamou.");
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// 6) Reversão usa a mesma repetição.
$reiniciar("PAUSADO");
$pdo->falhas = [$deadlock];
$reversao = $servico->solicitarReversao($operador, 135, "REVERSAO_ATIVAR");
verificar($reversao["status"] === "PENDENTE", "A reversão deveria ser registrada após repetir.");
verificar($pdo->insertsTentados === 2 && $comandos() === 1, "A reversão deve ser repetida sem duplicar.");

echo "Comando do operador: deadlock repetido, 1205 não repetido e causa real em logs_erros.\n";
