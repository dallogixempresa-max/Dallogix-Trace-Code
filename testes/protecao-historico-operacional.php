<?php

declare(strict_types=1);

use App\Persistencia\RepositorioDalasEventos;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioProdutosEventos;
use App\Persistencia\RepositorioRomaneiosRemotos;

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioDalasEventos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioProdutosEventos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";

function garantir(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO("sqlite::memory:");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE carregamentos (
    id INTEGER PRIMARY KEY,
    company_id INTEGER NOT NULL,
    equipment_id INTEGER,
    romaneio_id INTEGER NOT NULL,
    state TEXT NOT NULL,
    started_at TEXT
)");

$dalaRepository = new RepositorioDalasEventos($pdo);
$activeStates = ["PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA"];
foreach ($activeStates as $index => $state) {
    $equipmentId = $index + 1;
    $insert = $pdo->prepare(
        "INSERT INTO carregamentos (company_id, equipment_id, romaneio_id, state, started_at)
         VALUES (7, :equipment_id, 100, :state, '2026-11-15 10:00:00')",
    );
    $insert->execute(["equipment_id" => $equipmentId, "state" => $state]);
    try {
        $dalaRepository->excluirDalaVinculada(7, 3, $equipmentId);
        throw new RuntimeException("Exclusão de Dala foi aceita no estado {$state}.");
    } catch (RuntimeException $exception) {
        garantir(
            str_contains($exception->getMessage(), "carregamento ativo"),
            "A exclusão em {$state} falhou por motivo inesperado: " . $exception->getMessage(),
        );
    }
    $loading = $pdo->query("SELECT equipment_id, state, started_at FROM carregamentos WHERE equipment_id = {$equipmentId}")
        ->fetch(PDO::FETCH_ASSOC);
    garantir($loading === [
        "equipment_id" => $equipmentId,
        "state" => $state,
        "started_at" => "2026-11-15 10:00:00",
    ], "A tentativa recusada alterou o carregamento {$state}.");
}

$pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, code TEXT NOT NULL, remote_product_id INTEGER)");
$pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, number TEXT NOT NULL, status TEXT NOT NULL, remote_romaneio_id INTEGER)");
$pdo->exec("CREATE TABLE romaneio_itens (id INTEGER PRIMARY KEY, romaneio_id INTEGER NOT NULL, product_id INTEGER NOT NULL)");
$pdo->exec("INSERT INTO produtos (id, company_id, code, remote_product_id) VALUES (20, 7, 'PROD-20', 120)");
$pdo->exec("INSERT INTO romaneios (id, company_id, number, status) VALUES (30, 7, 'R-30', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO romaneio_itens (id, romaneio_id, product_id) VALUES (40, 30, 20)");

$productRepository = new RepositorioProdutosEventos($pdo);
try {
    $productRepository->excluir(7, ["code" => "PROD-20"], 120);
    throw new RuntimeException("Exclusão de produto usado por romaneio ativo foi aceita.");
} catch (RuntimeException $exception) {
    garantir(
        str_contains($exception->getMessage(), "romaneio ativo"),
        "Exclusão protegida do produto falhou por motivo inesperado: " . $exception->getMessage(),
    );
}
garantir((int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE id = 20")->fetchColumn() === 1, "O produto ativo foi apagado.");
garantir((int) $pdo->query("SELECT COUNT(*) FROM romaneio_itens WHERE id = 40")->fetchColumn() === 1, "O item ativo do romaneio foi apagado.");

$pdo->exec("INSERT INTO romaneios (id, company_id, number, status) VALUES (50, 7, 'R-FINAL', 'FINALIZADO')");
$pdo->exec("INSERT INTO romaneios (id, company_id, number, status) VALUES (51, 7, 'R-OPEN', 'AGUARDANDO')");
$romaneioRepository = new RepositorioRomaneiosRemotos($pdo, new RepositorioFilaSincronizacao($pdo));
$romaneioRepository->sincronizarRomaneiosCancelados(7, [
    ["id" => 500, "number" => "R-FINAL"],
    ["id" => 501, "number" => "R-OPEN"],
]);
garantir(
    $pdo->query("SELECT status FROM romaneios WHERE id = 50")->fetchColumn() === "FINALIZADO",
    "Cancelamento remoto rebaixou o romaneio finalizado.",
);
garantir(
    (int) $pdo->query("SELECT remote_romaneio_id FROM romaneios WHERE id = 50")->fetchColumn() === 500,
    "O vínculo remoto do romaneio finalizado não foi registrado.",
);
garantir(
    $pdo->query("SELECT status FROM romaneios WHERE id = 51")->fetchColumn() === "CANCELADO",
    "Cancelamento remoto deixou de cancelar um romaneio aberto.",
);

echo "ok\n";
