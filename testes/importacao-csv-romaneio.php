<?php
declare(strict_types=1);

require_once __DIR__ . "/../servidor/src/Aplicacao/AgrupadorItensImportacaoRomaneio.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ImportadorCsvRomaneio.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioItensRomaneio.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioProdutosRomaneio.php";

use App\Aplicacao\ImportadorCsvRomaneio;

// Mesma regra de servidor/configuracao/ambiente.php (cadastro manual).
if (!function_exists("placa_caminhao_valida")) {
    function placa_caminhao_valida(string $placa): bool
    {
        return $placa !== "" && mb_strlen($placa) <= 20 && preg_match('/^[A-Z0-9À-ÿ _.,\/-]+$/u', $placa) === 1;
    }
}

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function novoBanco(): PDO
{
    $pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL, active INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE codigos_produtos (company_id INTEGER NOT NULL, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE romaneios (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, assigned_installation_id INTEGER, assigned_installation_company_id INTEGER, number TEXT NOT NULL COLLATE NOCASE, scheduled_date TEXT NOT NULL, expedidor TEXT, status TEXT NOT NULL, UNIQUE(company_id, number))");
    $pdo->exec("CREATE TABLE romaneio_caminhoes (id INTEGER PRIMARY KEY AUTOINCREMENT, romaneio_id INTEGER NOT NULL, plate TEXT NOT NULL, driver_name TEXT, UNIQUE(romaneio_id, plate))");
    $pdo->exec("CREATE TABLE romaneio_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, romaneio_id INTEGER NOT NULL, product_id INTEGER NOT NULL, truck_id INTEGER, planned_quantity INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE auditoria (romaneio_id INTEGER NOT NULL, payload TEXT NOT NULL)");
    $pdo->exec("INSERT INTO produtos (company_id, code, name, active) VALUES (7, 'PROD-001', 'Produto 1', 1), (7, 'PROD-002', 'Produto 2', 1)");
    $pdo->exec("INSERT INTO codigos_produtos VALUES (7, 2, '7891234567895')");

    return $pdo;
}

/** @return array{status:int,body:array} */
function importar(PDO $pdo, string $csv): array
{
    $linhas = [];
    foreach (preg_split('/\R/', trim($csv)) as $posicao => $texto) {
        if (trim($texto) !== "") {
            $linhas[$posicao + 1] = array_map("trim", str_getcsv($texto, ";", '"', ""));
        }
    }
    $auditar = static function (PDO $connection, int $romaneioId, array $payload): void {
        $connection->prepare("INSERT INTO auditoria VALUES (?, ?)")->execute([$romaneioId, json_encode($payload)]);
    };

    return (new ImportadorCsvRomaneio($pdo, 7, null, $auditar))->importar($linhas);
}

function contar(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

$cabecalho = "romaneio;data;placa;motorista;expedidor;produto;quantidade";

// Motorista vazio ou "-" importa com pendência; fórmula é limpa.
$pdo = novoBanco();
$resultado = importar($pdo, "{$cabecalho}
R-1;31/12/2026;ABC1D23;;Expedidor;PROD-001;10
R-1;31/12/2026;ABC1D23;;Expedidor;PROD-001;5
R-2;2026-12-31;XYZ9K88;-;  ;7891234567895;20
R-3;2026-12-31;QWE1R23;=João;@Exp;PROD-002;1");
verificar($resultado["status"] === 201, "Importação com campos opcionais vazios deveria devolver 201.");
$importados = array_column($resultado["body"]["data"]["importados"], null, "romaneio");
verificar(count($importados) === 3, "Os três romaneios deveriam ser importados.");
verificar($importados["R-1"]["itens"] === 1, "Linhas repetidas do mesmo produto deveriam ser somadas.");
verificar(in_array("motorista não informado (placa ABC1D23)", $importados["R-1"]["pendencias"], true), "Motorista vazio deveria virar pendência.");
verificar(in_array("motorista não informado (placa XYZ9K88)", $importados["R-2"]["pendencias"], true), "Motorista '-' deveria virar pendência.");
verificar(in_array("expedidor não informado", $importados["R-2"]["pendencias"], true), "Expedidor só com espaços deveria virar pendência.");
verificar(count($importados["R-3"]["pendencias"]) === 2, "Prefixo de fórmula deveria gerar pendência em motorista e expedidor.");
verificar($pdo->query("SELECT driver_name FROM romaneio_caminhoes WHERE plate = 'XYZ9K88'")->fetchColumn() === null, "Motorista '-' deveria ser gravado como NULL.");
verificar($pdo->query("SELECT expedidor FROM romaneios WHERE number = 'R-2'")->fetchColumn() === null, "Expedidor vazio deveria ser gravado como NULL.");
verificar($pdo->query("SELECT driver_name FROM romaneio_caminhoes WHERE plate = 'QWE1R23'")->fetchColumn() === "João", "Motorista com fórmula deveria ser gravado limpo.");
verificar($pdo->query("SELECT expedidor FROM romaneios WHERE number = 'R-3'")->fetchColumn() === "Exp", "Expedidor com fórmula deveria ser gravado limpo.");
verificar(contar($pdo, "SELECT planned_quantity FROM romaneio_itens i JOIN romaneios r ON r.id = i.romaneio_id WHERE r.number = 'R-1'") === 15, "Meta do R-1 deveria somar 15.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneio_itens i JOIN romaneios r ON r.id = i.romaneio_id WHERE r.number = 'R-2'") === 1, "Produto por código de barras deveria ser aceito.");
verificar(contar($pdo, "SELECT COUNT(*) FROM auditoria") === 3, "Cada romaneio importado deveria ter uma auditoria.");

// Coluna motorista ausente no cabeçalho também não rejeita.
$pdo = novoBanco();
$resultado = importar($pdo, "romaneio;data;placa;produto;quantidade\nR-9;31/12/2026;ABC1D23;PROD-001;3");
verificar($resultado["status"] === 201, "Arquivo sem colunas opcionais deveria importar.");

// Produto inexistente rejeita só aquele romaneio; dois romaneios, um válido e um inválido.
$pdo = novoBanco();
$resultado = importar($pdo, "{$cabecalho}
R-10;31/12/2026;ABC1D23;Ana;Exp;PROD-001;10
R-11;31/12/2026;ABC1D23;Ana;Exp;PROD-001;10
R-11;31/12/2026;ABC1D23;Ana;Exp;NAO-EXISTE;10");
verificar($resultado["status"] === 201, "Um romaneio válido deveria garantir 201.");
$data = $resultado["body"]["data"];
verificar(array_column($data["importados"], "romaneio") === ["R-10"], "Somente R-10 deveria ser importado.");
verificar(count($data["rejeitados"]) === 1 && $data["rejeitados"][0]["romaneio"] === "R-11", "R-11 deveria ser rejeitado.");
verificar($data["rejeitados"][0]["linha"] === 4, "Rejeição deveria apontar a linha 4.");
verificar(str_contains($data["rejeitados"][0]["motivo"], "NAO-EXISTE"), "Motivo deveria citar o produto inexistente.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneios WHERE number = 'R-11'") === 0, "R-11 não poderia ser gravado parcialmente.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneio_itens") === 1, "Só o item de R-10 deveria existir.");

// Romaneio já existente vira ignorado; o novo entra.
$pdo->exec("DELETE FROM auditoria");
$resultado = importar($pdo, "{$cabecalho}
r-10;31/12/2026;ABC1D23;Ana;Exp;PROD-001;10
R-12;31/12/2026;ABC1D23;Ana;Exp;PROD-001;10");
verificar($resultado["status"] === 201, "Arquivo com romaneio novo deveria devolver 201.");
verificar($resultado["body"]["data"]["ignorados"] === [["romaneio" => "r-10", "motivo" => "já cadastrado"]], "R-10 já cadastrado deveria ser ignorado.");
verificar(array_column($resultado["body"]["data"]["importados"], "romaneio") === ["R-12"], "R-12 deveria ser importado.");
verificar(contar($pdo, "SELECT COUNT(*) FROM auditoria") === 1, "Ignorado não deveria gerar auditoria.");

// Unicidade violada no INSERT (corrida) também vira ignorado.
$pdo = novoBanco();
$pdo->exec("CREATE TRIGGER corrida BEFORE INSERT ON romaneios WHEN NEW.number = 'R-20' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: romaneios.number'); END");
$resultado = importar($pdo, "{$cabecalho}\nR-20;31/12/2026;ABC1D23;Ana;Exp;PROD-001;1\nR-21;31/12/2026;ABC1D23;Ana;Exp;PROD-001;1");
verificar(($resultado["body"]["data"]["ignorados"][0]["romaneio"] ?? null) === "R-20", "Unicidade violada no INSERT deveria virar ignorado.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneio_caminhoes") === 1, "SAVEPOINT do R-20 deveria desfazer só ele.");
verificar(array_column($resultado["body"]["data"]["importados"], "romaneio") === ["R-21"], "R-21 deveria entrar mesmo com falha no R-20.");

// Placa inválida rejeita o romaneio; nada importado devolve 422 com o resumo.
$pdo = novoBanco();
$resultado = importar($pdo, "{$cabecalho}
R-30;31/12/2026;ABC#123;Ana;Exp;PROD-001;10
R-31;;ABC1D23;Ana;Exp;PROD-001;0
;31/12/2026;ABC1D23;Ana;Exp;PROD-001;1");
verificar($resultado["status"] === 422, "Nenhum romaneio importado deveria devolver 422.");
$rejeitados = $resultado["body"]["data"]["rejeitados"];
verificar(count($rejeitados) === 3, "Três linhas deveriam ser rejeitadas.");
verificar(str_contains($rejeitados[1]["romaneio"] . $rejeitados[1]["motivo"], "R-30placa ABC#123 inválida"), "Placa inválida deveria ser o motivo do R-30.");
verificar(str_contains($rejeitados[0]["motivo"], "número do romaneio"), "Linha sem romaneio deveria ser rejeitada.");
$r31 = array_values(array_filter($rejeitados, static fn (array $r): bool => $r["romaneio"] === "R-31"))[0];
verificar(str_contains($r31["motivo"], "data não informada") && str_contains($r31["motivo"], "quantidade inválida"), "R-31 deveria listar data e quantidade.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneios") === 0, "Nada deveria ser gravado.");

// Placa longa aceita pelo cadastro manual (até 20 caracteres) também entra no CSV.
$pdo = novoBanco();
$resultado = importar($pdo, "{$cabecalho}\nR-40;31/12/2026;CARRETA 01-AB;Ana;Exp;PROD-001;1");
verificar($resultado["status"] === 201, "Placa aceita pelo cadastro manual deveria ser aceita no CSV.");

// Cabeçalho sem coluna obrigatória devolve 422.
$pdo = novoBanco();
$resultado = importar($pdo, "romaneio;data;placa;produto\nR-50;31/12/2026;ABC1D23;PROD-001");
verificar($resultado["status"] === 422, "Cabeçalho incompleto deveria devolver 422.");
verificar($resultado["body"]["error"] === "Coluna obrigatória ausente: quantidade.", "Mensagem deveria citar a coluna ausente.");
verificar(contar($pdo, "SELECT COUNT(*) FROM romaneios") === 0, "Cabeçalho incompleto não poderia gravar.");

echo "ok\n";
