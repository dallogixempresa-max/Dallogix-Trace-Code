<?php
declare(strict_types=1);

// Snapshot do central: um romaneio ruim não pode travar o canal, e eventos
// locais de leitura/comando protegem o estado do carregamento (pausa local).

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioRomaneiosRemotos.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ValidadorInstantaneoRemoto.php";

use App\Aplicacao\ValidadorInstantaneoRemoto;
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

// 1) Romaneio disponível inválido sai do snapshot com aviso; os válidos ficam.
$valid = [
    "id" => 10, "number" => "100", "status" => "AGUARDANDO", "scheduled_date" => "2026-11-23",
    "trucks" => [["id" => 1, "plate" => "ABC1D23", "items" => [["code" => "P1", "planned_quantity" => 5]]]],
];
$zeroQuantity = $valid;
$zeroQuantity["id"] = 11;
$zeroQuantity["number"] = "101";
$zeroQuantity["trucks"][0]["items"][0]["planned_quantity"] = 0;
$badPlate = $valid;
$badPlate["id"] = 12;
$badPlate["number"] = "102";
$badPlate["trucks"][0]["plate"] = "???";
$snapshot = ["romaneios_disponiveis" => [$valid, $zeroQuantity, $badPlate]];
$warnings = ValidadorInstantaneoRemoto::separarRomaneiosDisponiveisInvalidos($snapshot);
verificar(count($snapshot["romaneios_disponiveis"]) === 1, "Só o romaneio válido deveria permanecer no snapshot.");
verificar($snapshot["romaneios_disponiveis"][0]["id"] === 10, "O romaneio válido foi descartado.");
verificar(count($warnings) === 2, "Cada romaneio inválido deve gerar um aviso.");
verificar(str_contains($warnings[0], "101") && str_contains($warnings[1], "102"), "O aviso deve citar o número do romaneio.");
verificar(ValidadorInstantaneoRemoto::motivoRomaneioDisponivelInvalido($valid) === null, "Romaneio válido foi recusado.");

// 2) Número repetido com romaneio local antigo: pula só ele e segue com os demais.
$pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, remote_romaneio_id INTEGER, number TEXT NOT NULL, scheduled_date TEXT, expedidor TEXT, status TEXT NOT NULL)");
$pdo->exec("CREATE TABLE fila_sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, aggregate_type TEXT NOT NULL, aggregate_id INTEGER NOT NULL, status TEXT NOT NULL, payload TEXT NOT NULL DEFAULT '{}')");
$pdo->exec("INSERT INTO romaneios (company_id, remote_romaneio_id, number, scheduled_date, status) VALUES (7, NULL, '123', '2026-10-01', 'FINALIZADO')");
$fila = new RepositorioFilaSincronizacao($pdo);
$repository = new RepositorioRomaneiosRemotos($pdo, $fila);
$avisos = [];
$updated = $repository->sincronizarRomaneiosDisponiveis(7, [
    ["id" => 500, "number" => "123", "status" => "AGUARDANDO", "scheduled_date" => "2026-11-23", "trucks" => []],
    ["id" => 501, "number" => "124", "status" => "AGUARDANDO", "scheduled_date" => "2026-11-23", "trucks" => []],
], $avisos);
verificar($updated === 1, "O romaneio sem conflito deveria ser importado.");
verificar((int) $pdo->query("SELECT COUNT(*) FROM romaneios WHERE remote_romaneio_id = 501")->fetchColumn() === 1, "Romaneio 124 não foi importado.");
verificar((int) $pdo->query("SELECT COUNT(*) FROM romaneios WHERE remote_romaneio_id = 500")->fetchColumn() === 0, "Romaneio em conflito não pode ser vinculado automaticamente.");
verificar(count($avisos) === 1 && str_contains($avisos[0], "123"), "O conflito deve virar aviso.");

// 3) Evento pendente de leitura/comando com o carregamento no payload protege o estado.
$payload = static fn(int $loadingId): string => json_encode(["action" => "PARADA_PRODUTO_INCORRETO", "data" => ["carregamento_id" => $loadingId]]);
$pdo->prepare("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status, payload) VALUES (7, 'plc_command_request', 900, 'PENDENTE', ?)")->execute([$payload(55)]);
$pdo->prepare("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status, payload) VALUES (7, 'leitura', 901, 'ENVIADO', ?)")->execute([$payload(56)]);
$pdo->prepare("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status, payload) VALUES (8, 'leitura', 902, 'PENDENTE', ?)")->execute([$payload(57)]);
verificar($fila->possuiEventoPendenteDoCarregamento(7, 55), "Comando pendente do carregamento 55 deveria proteger o estado.");
verificar(!$fila->possuiEventoPendenteDoCarregamento(7, 56), "Evento já ENVIADO não deve proteger o estado.");
verificar(!$fila->possuiEventoPendenteDoCarregamento(7, 57), "Evento de outra empresa não deve proteger o estado.");
$pdo->exec("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status) VALUES (7, 'carregamento', 58, 'ERRO')");
verificar($fila->possuiEventoPendenteDoCarregamento(7, 58), "Evento do próprio carregamento continua protegendo o estado.");

$source = file_get_contents(__DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosRemotos.php");
verificar(
    preg_match('/possuiEventoPendenteDoCarregamento\(\$companyId, \$localId\)[\s\S]*?if \(!\$hasPendingStateChange\)/', $source) === 1,
    "O snapshot de carregamentos deve checar eventos pendentes de leitura e comando antes de gravar o estado.",
);

echo "Snapshot central resiliente: romaneio inválido isolado e pausa local preservada.\n";
