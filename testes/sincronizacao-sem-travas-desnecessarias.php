<?php
declare(strict_types=1);

// Carregamentos 137 e 138 (24/11/2026): o instantâneo do central, a cada ~1 s,
// travava todos os produtos (UPDATE com NOT IN), regravava o equipamento e os
// dispositivos (com bcrypt novo) e varria todos os carregamentos, e só então
// travava o carregamento ativo. O registro de passagem e de leitura trava o
// carregamento e confere produto/equipamento pela chave estrangeira na ordem
// inversa: deadlock 1213, HTTP 500 no sensor_eventos e passagem gravada ~1,4 s
// depois. Este teste garante que um instantâneo sem mudanças não grava essas
// linhas e que as mudanças reais continuam sendo aplicadas.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioProdutosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioEquipamentosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ProvisionadorDispositivosLocais.php";

use App\Aplicacao\ProvisionadorDispositivosLocais;
use App\Persistencia\RepositorioCarregamentosRemotos;
use App\Persistencia\RepositorioEquipamentosRemotos;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioProdutosRemotos;
use App\Persistencia\RepositorioRomaneiosRemotos;

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Registra cada comando de escrita executado. */
final class ComandoRegistrado extends PDOStatement
{
    /** @var list<string> */
    public static array $escritas = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $this->queryString) === 1) {
            self::$escritas[] = preg_replace('/\s+/', " ", trim($this->queryString));
        }
        return parent::execute($params);
    }
}

$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_STATEMENT_CLASS => [ComandoRegistrado::class],
]);
$pdo->exec("CREATE TABLE fila_sincronizacao (id INTEGER PRIMARY KEY, company_id INTEGER, aggregate_type TEXT, aggregate_id INTEGER, status TEXT)");
$pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY, company_id INTEGER, remote_product_id INTEGER, code TEXT, name TEXT, category TEXT, active INTEGER)");
$pdo->exec("CREATE TABLE codigos_produtos (id INTEGER PRIMARY KEY, company_id INTEGER, product_id INTEGER, barcode TEXT)");
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY, company_id INTEGER, remote_equipment_id INTEGER, equipment_code TEXT, name TEXT, plc_ip TEXT, plc_port INTEGER, external_port INTEGER, plc_protocol TEXT)");
$pdo->exec("CREATE TABLE dispositivos (id INTEGER PRIMARY KEY, company_id INTEGER, equipment_id INTEGER, device_code TEXT UNIQUE, device_type TEXT, token_hash TEXT, token_lookup_hash TEXT, token_id TEXT, token_created_at TEXT, token_last_used_at TEXT, token_expires_at TEXT, token_revoked_at TEXT, active INTEGER)");
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER, remote_carregamento_id INTEGER, state TEXT, finished_at TEXT)");

$fila = new RepositorioFilaSincronizacao($pdo);
$escritasDe = static function (callable $acao): array {
    ComandoRegistrado::$escritas = [];
    $acao();
    return ComandoRegistrado::$escritas;
};

// 1) Produtos: catálogo igual ao local não grava nada; produto que saiu do
// central é desativado sozinho, pela chave.
$pdo->exec("INSERT INTO produtos VALUES (26, 1, 2026, '208', 'BILLY DOG', NULL, 1), (54, 1, 2054, '228', 'QUARTZ ESSENCIAL', NULL, 1), (99, 1, 2099, '999', 'FORA DO CENTRAL', NULL, 1)");
$pdo->exec("INSERT INTO codigos_produtos (company_id, product_id, barcode) VALUES (1, 26, '7898250782141'), (1, 54, '7898250782479'), (1, 99, '7890000000999')");
$produtos = new RepositorioProdutosRemotos($pdo, $fila);
$catalogo = [
    ["id" => 2026, "code" => "208", "name" => "BILLY DOG", "active" => true, "barcodes" => ["7898250782141"]],
    ["id" => 2054, "code" => "228", "name" => "QUARTZ ESSENCIAL", "active" => true, "barcodes" => ["7898250782479"]],
];
$escritas = $escritasDe(static fn () => $produtos->sincronizar(1, $catalogo));
verificar(count($escritas) === 1 && str_contains($escritas[0], "UPDATE produtos SET active = 0 WHERE id = :id"), "Só o produto que saiu do central pode ser gravado, pela chave.");
verificar((int) $pdo->query("SELECT active FROM produtos WHERE id = 99")->fetchColumn() === 0, "Produto fora do central fica inativo.");
verificar((int) $pdo->query("SELECT active FROM produtos WHERE id = 26")->fetchColumn() === 1, "Produto do central continua ativo.");
$escritas = $escritasDe(static fn () => $produtos->sincronizar(1, $catalogo));
verificar($escritas === [], "Instantâneo igual não pode gravar nem travar produtos.");
foreach (["produtos", "carregamentos"] as $arquivo) {
    $fonte = file_get_contents(__DIR__ . "/../servidor/src/Persistencia/Repositorio" . ucfirst($arquivo) . "Remotos.php");
    verificar(!str_contains($fonte, "UPDATE {$arquivo} SET active = 0\n                     WHERE company_id"), "UPDATE varrendo a tabela inteira voltou em {$arquivo}.");
}

// 2) Equipamento: igual ao central não grava; diferente grava.
$pdo->exec("INSERT INTO equipamentos VALUES (1, 1, 1, 'NutriDani_1', 'NutriDani', '192.168.1.10', 502, NULL, 'MODBUS_TCP')");
$equipamentos = new RepositorioEquipamentosRemotos($pdo, $fila);
$remoto = ["id" => 1, "equipment_code" => "NutriDani_1", "name" => "NutriDani", "plc_ip" => "192.168.1.10", "plc_port" => 502, "external_port" => null, "plc_protocol" => "MODBUS_TCP"];
$escritas = $escritasDe(static fn () => $equipamentos->sincronizarEquipamento(1, $remoto));
verificar($escritas === [], "Equipamento igual ao central não pode ser regravado a cada instantâneo.");
$escritas = $escritasDe(static fn () => $equipamentos->sincronizarEquipamento(1, [...$remoto, "plc_ip" => "192.168.1.11"]));
verificar(count($escritas) === 1 && str_starts_with($escritas[0], "UPDATE equipamentos SET remote_equipment_id"), "Mudança real no equipamento continua sendo aplicada.");
verificar($pdo->query("SELECT plc_ip FROM equipamentos WHERE id = 1")->fetchColumn() === "192.168.1.11", "IP novo gravado.");

// 3) Dispositivos: já provisionados com o mesmo token não são regravados (sem bcrypt).
putenv("TRACE_INSTALLATION_MODE=local");
putenv("KEYENCE_ENABLED=0");
$token = str_repeat("ab", 32);
putenv("TRACE_DEVICE_TOKEN={$token}");
$pdo->exec("INSERT INTO dispositivos (company_id, equipment_id, device_code, device_type, token_hash, token_lookup_hash, token_id, active)
            VALUES (1, 1, 'PLC-NutriDani_1', 'CLP', 'x', '" . hash("sha256", $token) . "', 't1', 1)");
$inicio = microtime(true);
$escritas = $escritasDe(static fn () => ProvisionadorDispositivosLocais::garantir($pdo, 1, 1, "NutriDani_1"));
verificar($escritas === [], "Dispositivo igual não pode ser regravado a cada instantâneo.");
verificar(microtime(true) - $inicio < 0.05, "Sem bcrypt quando nada mudou.");
$pdo->exec("UPDATE dispositivos SET token_lookup_hash = 'outro' WHERE device_code = 'PLC-NutriDani_1'");
$tentouGravar = false;
try {
    $escritas = $escritasDe(static fn () => ProvisionadorDispositivosLocais::garantir($pdo, 1, 1, "NutriDani_1"));
    $tentouGravar = $escritas !== [];
} catch (PDOException $exception) {
    // O SQLite não entende ON DUPLICATE KEY: a falha ao preparar mostra que a
    // gravação do dispositivo foi tentada.
    $tentouGravar = str_contains($exception->getMessage(), "DUPLICATE");
}
verificar($tentouGravar, "Token diferente continua sendo regravado.");

// 4) Carregamentos ausentes: nada a encerrar não grava; ausente é encerrado pela chave.
$pdo->exec("INSERT INTO carregamentos VALUES (137, 1, 123, 'FINALIZADO', '2026-11-24 13:59:35'), (138, 1, 124, 'PAUSADO', NULL), (139, 1, 125, 'CARREGANDO', NULL)");
$carregamentos = new RepositorioCarregamentosRemotos($pdo, $fila, new RepositorioRomaneiosRemotos($pdo, $fila));
$escritas = $escritasDe(static fn () => $carregamentos->finalizarAusentes(1, [124, 125]));
verificar($escritas === [], "Sem carregamento ausente, nada é gravado nem travado.");
$escritas = $escritasDe(static fn () => $carregamentos->finalizarAusentes(1, [124]));
verificar(count($escritas) === 1 && str_contains($escritas[0], "WHERE id = :id"), "Só o carregamento ausente é encerrado, pela chave.");
verificar($pdo->query("SELECT state FROM carregamentos WHERE id = 139")->fetchColumn() === "FINALIZADO", "Carregamento ausente do central é encerrado.");
verificar($pdo->query("SELECT state FROM carregamentos WHERE id = 138")->fetchColumn() === "PAUSADO", "Carregamento presente no central não muda.");
$pdo->exec("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status) VALUES (1, 'carregamento', 138, 'PENDENTE')");
$escritasDe(static fn () => $carregamentos->finalizarAusentes(1, []));
verificar($pdo->query("SELECT state FROM carregamentos WHERE id = 138")->fetchColumn() === "PAUSADO", "Carregamento com evento local pendente não é encerrado.");

echo "Instantâneo: sem mudança não grava produtos, equipamento, dispositivos nem carregamentos; mudanças reais continuam aplicadas.\n";
