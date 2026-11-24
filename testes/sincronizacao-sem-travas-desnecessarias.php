<?php
declare(strict_types=1);

// Carregamentos 137 e 138 (24/11/2026): o instantâneo do central, a cada ~1 s,
// travava todos os produtos (UPDATE com NOT IN), regravava o equipamento e os
// dispositivos (com bcrypt novo) e varria todos os carregamentos, e só então
// travava o carregamento ativo. O registro de passagem e de leitura trava o
// carregamento e confere produto/equipamento pela chave estrangeira na ordem
// inversa: deadlock 1213, HTTP 500 no sensor_eventos e passagem gravada ~1,4 s
// depois. Este teste garante que um instantâneo sem mudanças não grava
// produtos nem carregamentos e que as mudanças reais continuam sendo
// aplicadas. Equipamento e dispositivos: sincronizacao-dala-sem-escrita.php.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioProdutosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php";

use App\Persistencia\RepositorioCarregamentosRemotos;
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

// 2) Equipamento e dispositivos: testes/sincronizacao-dala-sem-escrita.php.

// 3) Carregamentos ausentes: nada a encerrar não grava; ausente é encerrado pela chave.
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

echo "Instantâneo: sem mudança não grava produtos nem carregamentos; mudanças reais continuam aplicadas.\n";
