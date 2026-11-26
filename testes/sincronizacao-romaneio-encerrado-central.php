<?php
declare(strict_types=1);

// Romaneio "teste novo ms" (25/11/2026): o carregamento foi encerrado no central,
// saiu do instantâneo e o PC o finalizou por ausência. O romaneio local ficou
// EM_ANDAMENTO, o PC não podia gerar o relatório com as fotos do Keyence e o
// central entregou o relatório sem fotos. Só o central sabe se o romaneio
// inteiro acabou (pode haver caminhões em outras Dalas), então ele avisa em
// "romaneios_finalizados" e o PC aplica.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ValidadorInstantaneoRemoto.php";

use App\Aplicacao\ValidadorInstantaneoRemoto;
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
$pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_romaneio_id INTEGER, number TEXT NOT NULL, scheduled_date TEXT, expedidor TEXT, status TEXT NOT NULL)");
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_carregamento_id INTEGER, romaneio_id INTEGER, state TEXT NOT NULL, finished_at TEXT)");
$pdo->exec("CREATE TABLE fila_sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, aggregate_type TEXT NOT NULL, aggregate_id INTEGER NOT NULL, status TEXT NOT NULL, payload TEXT NOT NULL DEFAULT '{}')");
$pdo->exec("CREATE TABLE logs_auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, event_uuid TEXT NOT NULL, company_id INTEGER, user_id INTEGER, action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, metadata TEXT)");

$fila = new RepositorioFilaSincronizacao($pdo);
$romaneios = new RepositorioRomaneiosRemotos($pdo, $fila);
$carregamentos = new RepositorioCarregamentosRemotos($pdo, $fila, $romaneios);
$status = static fn (int $id): string => (string) $pdo->query("SELECT status FROM romaneios WHERE id = {$id}")->fetchColumn();
$aviso = static fn (int $remoteId, string $number): array => ["id" => $remoteId, "number" => $number, "status" => "FINALIZADO"];

// 1) O incidente: carregamento encerrado no central sai do instantâneo, é
// finalizado por ausência e o central avisa que encerrou o romaneio.
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (149, 1, 152, 'teste novo ms', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos VALUES (147, 1, 133, 149, 'PAUSADO', NULL)");
$carregamentos->finalizarAusentes(1, []);
verificar($status(149) === "EM_ANDAMENTO", "finalizarAusentes continua só finalizando carregamentos.");
$closed = $romaneios->sincronizarRomaneiosFinalizados(1, [$aviso(152, "teste novo ms")]);
verificar($closed === [149], "O romaneio encerrado no central deve ser encerrado no PC.");
verificar($status(149) === "FINALIZADO", "Romaneio local fica FINALIZADO e o relatório com fotos pode ser gerado no PC.");
$audit = $pdo->query("SELECT action, entity_id, user_id, metadata FROM logs_auditoria")->fetchAll();
verificar(count($audit) === 1 && $audit[0]["action"] === "ROMANEIO_FINALIZADO" && (int) $audit[0]["entity_id"] === 149 && $audit[0]["user_id"] === null, "O encerramento fica na auditoria local.");
verificar(json_decode((string) $audit[0]["metadata"], true)["origin"] === "CENTRAL", "A auditoria indica a origem central.");
verificar((int) $pdo->query("SELECT COUNT(*) FROM fila_sincronizacao")->fetchColumn() === 0, "Nada é reenviado ao central, que é quem encerrou.");

// 2) Aviso repetido nos próximos instantâneos não grava de novo.
verificar($romaneios->sincronizarRomaneiosFinalizados(1, [$aviso(152, "teste novo ms")]) === [], "Aviso repetido não encerra de novo.");
verificar((int) $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn() === 1, "Sem nova auditoria em aviso repetido.");

// 3) Divisão de carga: o caminhão desta Dala terminou, mas o romaneio segue
// aberto no central (outra Dala). Sem aviso, o romaneio local continua aberto e
// um novo caminhão para esta Dala ainda pode ser importado.
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (150, 1, 153, 'divisao de carga', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos VALUES (150, 1, 140, 150, 'FINALIZADO', '2026-11-25 21:00:00')");
verificar($romaneios->sincronizarRomaneiosFinalizados(1, []) === [] && $status(150) === "EM_ANDAMENTO", "Sem aviso do central o romaneio não é encerrado.");
verificar($romaneios->salvarOuAtualizarRomaneio(1, 153, ["romaneio_number" => "divisao de carga", "romaneio_status" => "EM_ANDAMENTO"]) === 150, "Romaneio aberto continua recebendo caminhões novos desta Dala.");

// 4) Aviso chegou, mas um carregamento local ainda não foi finalizado (evento local pendente): espera.
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (151, 1, 154, 'carregamento local aberto', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos VALUES (151, 1, 141, 151, 'PAUSADO', NULL)");
verificar($romaneios->sincronizarRomaneiosFinalizados(1, [$aviso(154, "carregamento local aberto")]) === [] && $status(151) === "EM_ANDAMENTO", "Com carregamento local aberto o romaneio espera.");

// 5) Romaneio com evento local ainda não enviado: espera.
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (152, 1, 155, 'evento pendente', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos VALUES (152, 1, 142, 152, 'FINALIZADO', '2026-11-25 21:00:00')");
$pdo->exec("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status) VALUES (1, 'romaneio', 152, 'PENDENTE')");
verificar($romaneios->sincronizarRomaneiosFinalizados(1, [$aviso(155, "evento pendente")]) === [] && $status(152) === "EM_ANDAMENTO", "Romaneio com evento local pendente espera.");

// 6) Romaneio cancelado continua cancelado; desconhecido e de outra empresa são ignorados.
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (153, 1, 156, 'cancelado', 'CANCELADO')");
$pdo->exec("INSERT INTO romaneios (id, company_id, remote_romaneio_id, number, status) VALUES (154, 2, 157, 'outra empresa', 'EM_ANDAMENTO')");
$pdo->exec("INSERT INTO carregamentos VALUES (154, 2, 144, 154, 'FINALIZADO', '2026-11-25 21:00:00')");
verificar($romaneios->sincronizarRomaneiosFinalizados(1, [$aviso(156, "cancelado"), $aviso(999, "desconhecido"), $aviso(157, "outra empresa")]) === [], "Cancelado, desconhecido e outra empresa não são tocados.");
verificar($status(153) === "CANCELADO" && $status(154) === "EM_ANDAMENTO", "Estados preservados.");

// 7) O validador do instantâneo confere a lista nova.
$installation = ["remote_company_id" => 1];
$snapshot = [
    "empresa" => ["id" => 1, "license_status" => "ATIVA"],
    "instalacao" => ["id" => 5, "equipment_id" => 9],
    "equipamentos" => [["id" => 9, "equipment_code" => "DALA-1", "plc_port" => 502]],
    "produtos" => [],
    "carregamentos_ativos" => [],
    "comandos" => [],
    "romaneios_finalizados" => [$aviso(152, "teste novo ms")],
];
ValidadorInstantaneoRemoto::validar($installation, $snapshot);
unset($snapshot["romaneios_finalizados"]);
ValidadorInstantaneoRemoto::validar($installation, $snapshot); // central antigo, sem a lista
foreach ([["id" => 0, "number" => "x", "status" => "FINALIZADO"], ["id" => 1, "number" => "", "status" => "FINALIZADO"], ["id" => 1, "number" => "x", "status" => "CANCELADO"]] as $invalid) {
    $snapshot["romaneios_finalizados"] = [$invalid];
    try {
        ValidadorInstantaneoRemoto::validar($installation, $snapshot);
        throw new LogicException("Aviso de encerramento inválido foi aceito.");
    } catch (RuntimeException $expected) {
    }
}

echo "Romaneio encerrado no central é encerrado no PC pelo aviso do central; divisão de carga, pendências e cancelados preservados.\n";
