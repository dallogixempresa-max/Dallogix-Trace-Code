<?php
declare(strict_types=1);

// Carregamento 135 (23/11/2026): o PC pausou por produto incorreto, o central
// ficou em CARREGANDO e o instantâneo copiou esse estado por cima do PAUSADO.
// A esteira estava parada, a confirmação foi recusada e o operador travou.
// O instantâneo não pode alterar estados que só o PC (via CLP) controla.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php";

use App\Persistencia\RepositorioCarregamentosRemotos;
use App\Persistencia\RepositorioFilaSincronizacao;
use App\Persistencia\RepositorioRomaneiosRemotos;

if (!function_exists("placa_caminhao_valida")) {
    function placa_caminhao_valida(string $placa): bool
    {
        return preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa) === 1;
    }
}

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, remote_romaneio_id INTEGER, number TEXT NOT NULL, scheduled_date TEXT, expedidor TEXT, status TEXT NOT NULL)");
$pdo->exec("CREATE TABLE romaneio_caminhoes (id INTEGER PRIMARY KEY AUTOINCREMENT, romaneio_id INTEGER NOT NULL, remote_truck_id INTEGER, plate TEXT NOT NULL, driver_name TEXT)");
$pdo->exec("CREATE TABLE romaneio_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, romaneio_id INTEGER NOT NULL, product_id INTEGER NOT NULL, truck_id INTEGER, planned_quantity INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, code TEXT NOT NULL)");
$pdo->exec("CREATE TABLE fila_sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, aggregate_type TEXT NOT NULL, aggregate_id INTEGER NOT NULL, status TEXT NOT NULL, payload TEXT NOT NULL DEFAULT '{}')");
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, remote_carregamento_id INTEGER, equipment_id INTEGER, romaneio_id INTEGER, truck_id INTEGER, state TEXT NOT NULL, started_at TEXT, finished_at TEXT, stop_reason TEXT, operator_alert TEXT)");

$fila = new RepositorioFilaSincronizacao($pdo);
$repository = new RepositorioCarregamentosRemotos($pdo, $fila, new RepositorioRomaneiosRemotos($pdo, $fila));
$alert = "Código válido não cadastrado no catálogo. O CLP confirmou a parada física; remova a unidade e confirme antes de retomar.";

/** Cria a linha local e aplica o instantâneo com o estado do central. */
$aplicar = static function (int $remoteId, ?string $localState, string $remoteState, ?string $stopReason = null) use ($pdo, $repository, $alert): array {
    if ($localState !== null) {
        $pdo->prepare(
            "INSERT INTO carregamentos (company_id, remote_carregamento_id, equipment_id, state, stop_reason, operator_alert)
             VALUES (1, ?, 9, ?, ?, ?)",
        )->execute([$remoteId, $localState, $stopReason, $stopReason === null ? null : $alert]);
    }
    $localId = $repository->salvarOuAtualizar(1, 1, [
        "id" => $remoteId,
        "romaneio_id" => 300 + $remoteId,
        "romaneio_number" => "R-{$remoteId}",
        "romaneio_status" => "EM_ANDAMENTO",
        "truck_id" => 400 + $remoteId,
        "plate" => "ABC1D23",
        "state" => $remoteState,
        "started_at" => "2026-11-23 20:17:38",
        "items" => [],
    ]);
    $row = $pdo->prepare("SELECT * FROM carregamentos WHERE id = ?");
    $row->execute([$localId]);
    return $row->fetch();
};

// 1) O incidente: PAUSADO por produto incorreto, central em CARREGANDO.
$row = $aplicar(121, "PAUSADO", "CARREGANDO", "PRODUTO_INCORRETO");
verificar($row["state"] === "PAUSADO", "A pausa por produto incorreto não pode virar CARREGANDO pelo instantâneo.");
verificar($row["stop_reason"] === "PRODUTO_INCORRETO" && $row["operator_alert"] === $alert, "A parada pendente deve continuar aguardando confirmação.");
verificar((int) $row["equipment_id"] === 1 && (int) $row["truck_id"] > 0, "Os demais dados do instantâneo continuam sendo aplicados.");

// 2) Depois da confirmação (sem motivo), o PAUSADO ainda é físico: só INICIAR confirmado retoma.
verificar($aplicar(122, "PAUSADO", "CARREGANDO")["state"] === "PAUSADO", "PAUSADO confirmado pelo CLP não pode virar CARREGANDO pelo central.");

// 3) Central atrasado em PAUSADO não pode parar a carga no PC (desligaria a parada pela meta).
verificar($aplicar(123, "CARREGANDO", "PAUSADO")["state"] === "CARREGANDO", "CARREGANDO local não pode virar PAUSADO pelo central.");

// 4) Emergência local também só sai pelo gateway.
verificar($aplicar(124, "EMERGENCIA", "CARREGANDO")["state"] === "EMERGENCIA", "EMERGENCIA local não pode ser sobrescrita pelo central.");

// 5) Estados de preparação continuam vindo do central.
verificar($aplicar(125, "PREPARANDO", "AGUARDANDO")["state"] === "AGUARDANDO", "AGUARDANDO do central deve ser aplicado.");
verificar($aplicar(126, "AGUARDANDO", "PREPARANDO")["state"] === "PREPARANDO", "PREPARANDO do central deve ser aplicado.");
verificar($aplicar(127, "PREPARANDO", "CARREGANDO")["state"] === "CARREGANDO", "Fora dos estados do PC o comportamento anterior é mantido.");

// 6) Carregamento novo continua sendo criado com o estado do central.
verificar($aplicar(128, null, "PREPARANDO")["state"] === "PREPARANDO", "Carregamento novo deve nascer com o estado do central.");

echo "Instantâneo do central: estados controlados pelo PC preservados, preparação sincronizada.\n";
