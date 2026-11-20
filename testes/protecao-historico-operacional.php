<?php

declare(strict_types=1);

use App\Persistencia\RepositorioCarregamentosRemotos;
use App\Persistencia\RepositorioDalasEventos;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioProdutosEventos;
use App\Persistencia\RepositorioRomaneiosRemotos;

require_once __DIR__ . "/../servidor/configuracao/ambiente.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php";
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
    started_at TEXT,
    finished_at TEXT,
    truck_id INTEGER,
    remote_carregamento_id INTEGER
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
$pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, number TEXT NOT NULL, status TEXT NOT NULL, remote_romaneio_id INTEGER, expedidor TEXT, scheduled_date TEXT)");
$pdo->exec("CREATE TABLE romaneio_itens (
    id INTEGER PRIMARY KEY, romaneio_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
    company_id INTEGER, truck_id INTEGER, planned_quantity INTEGER
)");
$pdo->exec("CREATE TABLE romaneio_caminhoes (
    id INTEGER PRIMARY KEY, romaneio_id INTEGER NOT NULL, remote_truck_id INTEGER,
    plate TEXT NOT NULL, driver_name TEXT
)");
$pdo->exec("CREATE TABLE fila_sincronizacao (company_id INTEGER, aggregate_type TEXT, aggregate_id INTEGER, status TEXT)");
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
$syncQueue = new RepositorioFilaSincronizacao($pdo);
$romaneioRepository = new RepositorioRomaneiosRemotos($pdo, $syncQueue);
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

$loadingRepository = new RepositorioCarregamentosRemotos($pdo, $syncQueue, $romaneioRepository);
$pdo->exec("INSERT INTO romaneio_caminhoes (id, romaneio_id, remote_truck_id, plate, driver_name)
    VALUES (60, 50, 600, 'ABC1D23', 'Motorista original')");
$pdo->exec("INSERT INTO carregamentos (id, company_id, romaneio_id, equipment_id, truck_id, state, finished_at, remote_carregamento_id)
    VALUES (70, 7, 50, 1, 60, 'FINALIZADO', '2026-11-19 18:49:31', 700)");
$pdo->exec("INSERT INTO fila_sincronizacao VALUES (7, 'romaneio', 50, 'ENVIADO'), (7, 'carregamento', 70, 'ENVIADO')");
$stale = [
    "id" => 700,
    "romaneio_id" => 500,
    "romaneio_number" => "R-FINAL",
    "romaneio_status" => "EM_ANDAMENTO",
    "truck_id" => 600,
    "plate" => "ABC1D23",
    "driver_name" => "Dados antigos do Central",
    "state" => "PAUSADO",
];
$pdo->beginTransaction();
garantir($loadingRepository->salvarOuAtualizar(7, 1, $stale) === null, "O snapshot antigo reativou um carregamento encerrado.");
garantir($loadingRepository->salvarOuAtualizar(7, 1, array_replace($stale, ["id" => 701])) === null, "Foi criado um carregamento em romaneio encerrado.");
garantir($loadingRepository->localizarId(7, 700) === null, "Comandos remotos poderiam ser vinculados ao carregamento encerrado.");
$pdo->commit();
garantir($pdo->query("SELECT status FROM romaneios WHERE id = 50")->fetchColumn() === "FINALIZADO", "O snapshot reabriu o romaneio.");
garantir($pdo->query("SELECT state FROM carregamentos WHERE id = 70")->fetchColumn() === "FINALIZADO", "O snapshot reabriu o carregamento.");
garantir($pdo->query("SELECT driver_name FROM romaneio_caminhoes WHERE id = 60")->fetchColumn() === "Motorista original", "O snapshot alterou o histórico do caminhão.");

$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status)
    VALUES (52, 7, 502, 'R-DUAS-CARGAS', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos (id, company_id, romaneio_id, equipment_id, state, remote_carregamento_id)
    VALUES (72, 7, 52, 1, 'FINALIZADO', 702)");
$activeManifest = array_replace($stale, ["id" => 702, "romaneio_id" => 502, "romaneio_number" => "R-DUAS-CARGAS"]);
garantir($loadingRepository->salvarOuAtualizar(7, 1, $activeManifest) === null, "Uma carga concluída foi reaberta em romaneio ainda ativo.");
$newLoading = array_replace($activeManifest, ["id" => 703, "truck_id" => 603, "state" => "PREPARANDO"]);
$localId = $loadingRepository->salvarOuAtualizar(7, 1, $newLoading);
garantir($localId !== null && $loadingRepository->localizarId(7, 703) === $localId, "Um novo carregamento válido deixou de sincronizar.");

echo "ok\n";
