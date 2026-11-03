<?php
declare(strict_types=1);

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioProdutosRemotos.php";

use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioProdutosRemotos;

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, remote_product_id INTEGER, code TEXT NOT NULL, name TEXT NOT NULL, category TEXT, active INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE codigos_produtos (company_id INTEGER NOT NULL, product_id INTEGER NOT NULL, barcode TEXT NOT NULL, UNIQUE(company_id, barcode))");
$pdo->exec("CREATE TABLE fila_sincronizacao (company_id INTEGER NOT NULL, aggregate_type TEXT NOT NULL, aggregate_id INTEGER NOT NULL, status TEXT NOT NULL)");
$pdo->exec("CREATE TABLE writes (kind TEXT NOT NULL)");
$pdo->exec("CREATE TRIGGER track_product_update AFTER UPDATE ON produtos BEGIN INSERT INTO writes VALUES ('product_update'); END");
$pdo->exec("CREATE TRIGGER track_barcode_insert AFTER INSERT ON codigos_produtos BEGIN INSERT INTO writes VALUES ('barcode_insert'); END");
$pdo->exec("CREATE TRIGGER track_barcode_delete AFTER DELETE ON codigos_produtos BEGIN INSERT INTO writes VALUES ('barcode_delete'); END");

$repository = new RepositorioProdutosRemotos($pdo, new RepositorioFilaSincronizacao($pdo));
$product = ["id" => 42, "code" => "PROD-1", "name" => "Produto 1", "category" => null, "active" => true, "barcodes" => ["789001", "789002"]];
$repository->sincronizar(7, [$product]);
$productId = (int) $pdo->query("SELECT id FROM produtos WHERE company_id = 7 AND remote_product_id = 42")->fetchColumn();
verificar($productId > 0, "Produto não foi importado.");
$pdo->exec("DELETE FROM writes");

$repository->sincronizar(7, [[...$product, "barcodes" => ["789002", "789001"]]]);
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes")->fetchColumn() === 0, "Snapshot idêntico regravou produto ou códigos.");

$product["name"] = "Produto corrigido";
$repository->sincronizar(7, [$product]);
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes WHERE kind = 'product_update'")->fetchColumn() === 1, "Mudança de nome não atualizou produto uma vez.");
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes WHERE kind LIKE 'barcode_%'")->fetchColumn() === 0, "Mudança de nome regravou códigos idênticos.");
$pdo->exec("DELETE FROM writes");

$product["barcodes"] = ["789003"];
$repository->sincronizar(7, [$product]);
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes WHERE kind = 'product_update'")->fetchColumn() === 0, "Mudança de código regravou produto idêntico.");
verificar($pdo->query("SELECT barcode FROM codigos_produtos WHERE product_id = {$productId}")->fetchColumn() === "789003", "Novo código não foi aplicado.");
$pdo->exec("DELETE FROM writes");

$pdo->exec("INSERT INTO fila_sincronizacao VALUES (7, 'produto', {$productId}, 'PENDENTE')");
$repository->sincronizar(7, [[...$product, "name" => "Nome remoto conflitante", "barcodes" => ["789004"]]]);
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes")->fetchColumn() === 0, "Snapshot sobrescreveu edição local pendente.");
verificar($pdo->query("SELECT name FROM produtos WHERE id = {$productId}")->fetchColumn() === "Produto corrigido", "Nome local pendente foi perdido.");
$pdo->exec("DELETE FROM fila_sincronizacao");

$repository->sincronizar(7, []);
verificar((int) $pdo->query("SELECT active FROM produtos WHERE id = {$productId}")->fetchColumn() === 0, "Produto ausente do snapshot não foi desativado.");
$pdo->exec("DELETE FROM writes");
$repository->sincronizar(7, []);
verificar((int) $pdo->query("SELECT COUNT(*) FROM writes")->fetchColumn() === 0, "Produto já inativo foi regravado no próximo snapshot.");
echo "ok\n";
