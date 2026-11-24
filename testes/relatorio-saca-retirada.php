<?php
declare(strict_types=1);

// Carregamento 137 (24/11/2026, romaneio sdawdsc): a saca retirada durante a
// parada passou pelo sensor sem código e entrava em "Falhas sem leitura" e
// "Sacas não identificadas" (6 em vez de 5). A 41ª saca de um item previsto
// aparecia como "Produto fora do romaneio". E um produto do catálogo fora do
// romaneio, gravado sem product_id, aparecia como "Código válido não
// cadastrado" no relatório e no visor. Este teste executa no SQLite as
// expressões SQL reais do relatório e do visor.

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$report = file_get_contents(__DIR__ . "/../servidor/api/relatorios/relatorio_auditoria.php");
$monitoring = file_get_contents(__DIR__ . "/../servidor/src/Persistencia/RepositorioMonitoramento.php");

$pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, active INTEGER)");
$pdo->exec("CREATE TABLE codigos_produtos (id INTEGER PRIMARY KEY, company_id INTEGER, product_id INTEGER, barcode TEXT)");
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER, romaneio_id INTEGER, truck_id INTEGER)");
$pdo->exec("CREATE TABLE romaneio_itens (id INTEGER PRIMARY KEY, romaneio_id INTEGER, product_id INTEGER, planned_quantity INTEGER, truck_id INTEGER)");
$pdo->exec("CREATE TABLE eventos_sensor (id INTEGER PRIMARY KEY, carregamento_id INTEGER, conta_na_meta INTEGER)");
$pdo->exec("CREATE TABLE leituras (id INTEGER PRIMARY KEY, company_id INTEGER, carregamento_id INTEGER, sensor_event_id INTEGER, result TEXT, barcode TEXT, product_id INTEGER, conta_na_meta INTEGER)");

$pdo->exec("INSERT INTO produtos VALUES (26, 1, 'BILLY DOG RACAS PEQUENAS', 1), (55, 1, 'QUARTZ HARD TRAINING', 1), (77, 1, 'PRODUTO DE OUTRO ROMANEIO', 1), (88, 1, 'PRODUTO INATIVO', 0)");
$pdo->exec("INSERT INTO codigos_produtos (company_id, product_id, barcode) VALUES (1, 26, '7898250782141'), (1, 55, '7898250782943'), (1, 77, '7890000000017'), (1, 88, '7890000000024')");
$pdo->exec("INSERT INTO carregamentos VALUES (137, 1, 142, 9)");
// O produto 77 só está previsto para outro caminhão (10) do mesmo romaneio.
$pdo->exec("INSERT INTO romaneio_itens (romaneio_id, product_id, planned_quantity, truck_id) VALUES (142, 26, 35, NULL), (142, 55, 40, 9), (142, 77, 5, 10)");
// 2207: 41ª Hard Training (item previsto, acima da quantidade). 2208: saca retirada
// passando com a esteira parada. 3001: código não cadastrado. 3002: produto
// previsto só para outro caminhão. 3003: produto inativo.
$pdo->exec("INSERT INTO eventos_sensor VALUES (2207, 137, 0), (2208, 137, 0), (3001, 137, 0), (3002, 137, 0), (3003, 137, 0), (2157, 137, 1)");
$pdo->exec("INSERT INTO leituras (company_id, carregamento_id, sensor_event_id, result, barcode, product_id, conta_na_meta) VALUES
    (1, 137, 2207, 'PRODUTO_INCORRETO', '7898250782943', 55, 0),
    (1, 137, 2208, 'SEM_LEITURA', NULL, NULL, 0),
    (1, 137, 3001, 'PRODUTO_INCORRETO', '7898538751456', NULL, 0),
    (1, 137, 3002, 'PRODUTO_INCORRETO', '7890000000017', 77, 0),
    (1, 137, 3003, 'PRODUTO_INCORRETO', '7890000000024', NULL, 0),
    (1, 137, 2157, 'SEM_LEITURA', NULL, NULL, 1)");

// 1) Rótulo de cada passagem não contabilizada (expressões reais da consulta de passagens).
$flags = [];
foreach (["codigo_nao_cadastrado", "acima_quantidade_item", "produto_incorreto"] as $alias) {
    $pattern = '/(EXISTS \(SELECT 1 FROM leituras l(?:(?!\) AS \w+,).)*?\)) AS ' . $alias . ',/s';
    verificar(preg_match($pattern, $report, $match) === 1, "Expressão {$alias} não encontrada no relatório.");
    $flags[$alias] = $match[1];
}
$rows = $pdo->query(
    "SELECT id, " . implode(", ", array_map(static fn($alias, $sql) => "{$sql} AS {$alias}", array_keys($flags), $flags))
    . " FROM eventos_sensor WHERE conta_na_meta = 0 ORDER BY id",
)->fetchAll();
$rotulo = static function (array $row): string {
    return (int) $row["codigo_nao_cadastrado"] === 1 ? "Código válido não cadastrado"
        : ((int) $row["acima_quantidade_item"] === 1 ? "Acima da quantidade prevista"
        : ((int) $row["produto_incorreto"] === 1 ? "Produto fora do romaneio" : "sem produto"));
};
$rotulos = array_combine(array_map(static fn($row) => (int) $row["id"], $rows), array_map($rotulo, $rows));
verificar($rotulos[2207] === "Acima da quantidade prevista", "A 41ª saca de um item previsto não é produto fora do romaneio.");
verificar($rotulos[2208] === "sem produto", "A saca retirada sem código não tem indicador de produto.");
verificar($rotulos[3001] === "Código válido não cadastrado", "Código sem cadastro continua identificado como tal.");
verificar($rotulos[3002] === "Produto fora do romaneio", "Produto previsto só para outro caminhão é fora do romaneio desta carga.");
verificar($rotulos[3003] === "Código válido não cadastrado", "Produto inativo segue a regra da leitura: código não identificado.");
// Produto do catálogo fora do romaneio gravado sem product_id (como no PC).
$pdo->exec("UPDATE leituras SET product_id = NULL WHERE sensor_event_id = 3002");
$semProduto = $pdo->query("SELECT {$flags["codigo_nao_cadastrado"]} AS codigo_nao_cadastrado FROM eventos_sensor WHERE id = 3002")->fetchColumn();
verificar((int) $semProduto === 0, "Produto do catálogo fora do romaneio não pode virar código não cadastrado.");
verificar(str_contains($report, '((int) $passage["acima_quantidade_item"] === 1'), "O rótulo do relatório deve usar o indicador de item acima da quantidade.");
// Sem indicador de produto: com a esteira parada é passagem durante a pausa; fora
// da pausa é saco excedente (passou da meta) e continua nos totais de falhas.
verificar(str_contains($report, '((int) $passage["produto_excedente"] === 1 || !$duringPause'), "Saco excedente fora da pausa deve ter rótulo próprio.");
verificar(str_contains($report, "AND paused_at_epoch_ms <= :event_at_open") || str_contains($report, '(int) $pause["paused_at_epoch_ms"] <= $eventAt'), "A janela de pausa deve seguir ServicoPausasCarregamento.");
verificar(str_contains($report, '($pause["resumed_at_epoch_ms"] === null || (int) $pause["resumed_at_epoch_ms"] > $eventAt)'), "Fim da pausa exclusivo, como em ServicoPausasCarregamento.");

// 2) Nome do produto na tabela de divergências (expressão real do relatório).
verificar(preg_match('/(COALESCE\(p\.name, \(SELECT cpp\.name FROM codigos_produtos cp.*?LIMIT 1\)\)) AS product_name/s', $report, $match) === 1, "Nome do produto pelo catálogo não encontrado.");
$names = $pdo->query("SELECT l.sensor_event_id, {$match[1]} AS product_name FROM leituras l LEFT JOIN produtos p ON p.id = l.product_id WHERE l.result = 'PRODUTO_INCORRETO' ORDER BY l.sensor_event_id")->fetchAll(PDO::FETCH_KEY_PAIR);
verificar($names[2207] === "QUARTZ HARD TRAINING" && $names[3002] === "PRODUTO DE OUTRO ROMANEIO", "O produto reconhecido aparece pelo nome.");
verificar($names[3001] === null && $names[3003] === null, "Código não cadastrado ou de produto inativo fica sem nome (vira 'Código válido não cadastrado').");

// 3) Totais de falhas sem leitura: fora só a passagem com a esteira parada.
verificar(preg_match('/\$countedNoReadRows = array_filter\(\s*\$noReadRows,\s*(static fn\(array \$r\): bool => .*?),\s*\);/s', $report, $match) === 1, "Filtro das falhas contabilizadas não encontrado.");
verificar(str_contains($report, '$operationSummary["no_reads"] = count($countedNoReadRows);'), "Falhas sem leitura deve contar só as contabilizadas.");
verificar(str_contains($report, 'count(array_filter($countedNoReadRows, static fn(array $r): bool => $r["product_id"] === null))'), "Sacas não identificadas deve contar só as contabilizadas.");
// Executa o filtro real do relatório (a arrow function captura $passagesDuringPause).
$passagesDuringPause = [2208 => true, 2300 => false];
$filtroDoRelatorio = eval("return {$match[1]};");
$noReadRows = [
    ["sensor_event_id" => 2157, "conta_na_meta" => 1, "product_id" => null], // contada na meta
    ["sensor_event_id" => 2208, "conta_na_meta" => 0, "product_id" => null], // saca retirada, esteira parada
    ["sensor_event_id" => 2300, "conta_na_meta" => 0, "product_id" => null], // saco excedente depois da meta
];
$contadas = array_column(array_values(array_filter($noReadRows, $filtroDoRelatorio)), "sensor_event_id");
verificar($contadas === [2157, 2300], "Só a passagem com a esteira parada sai do total; o saco excedente continua.");
// O manifesto de evidências continua contando todas as falhas (cada uma tem foto).
verificar(str_contains($report, '"SELECT COUNT(*) FROM leituras WHERE carregamento_id IN (" . implode(",", array_fill(0, count($loadIds), "?")) . ") AND result = \'SEM_LEITURA\'"'), "O manifesto de evidências não deve mudar.");

// 4) Visor: produto do catálogo fora do romaneio não é "código não cadastrado".
verificar(preg_match('/(CASE WHEN lr\.result = \'PRODUTO_INCORRETO\'.*?END) AS last_scanner_catalog_product/s', $monitoring, $match) === 1, "Indicador do visor não encontrado.");
$visor = $pdo->query(
    "SELECT lr.sensor_event_id, {$match[1]} AS catalogo FROM leituras lr JOIN carregamentos ca ON ca.id = lr.carregamento_id WHERE lr.result = 'PRODUTO_INCORRETO' ORDER BY lr.sensor_event_id",
)->fetchAll(PDO::FETCH_KEY_PAIR);
verificar((int) $visor[3002] === 1, "Produto do catálogo fora do romaneio deve ser marcado para o visor.");
verificar((int) $visor[3001] === 0 && (int) $visor[3003] === 0, "Código não cadastrado ou inativo continua 'não cadastrado' no visor.");
verificar((int) $visor[2207] === 0, "Item previsto tem product_id e não precisa do indicador.");

// 5) Textos do visor: na hora da leitura e depois da atualização da tela.
$application = file_get_contents(__DIR__ . "/../interface/js/aplicacao.js");
$activeLoading = file_get_contents(__DIR__ . "/../interface/js/servicos/ServicoCarregamentoAtivo.js");
$readings = file_get_contents(__DIR__ . "/../servidor/api/operacoes/leituras.php");
verificar(str_contains($readings, '"product_id" => $productId !== null ? (int) $productId : null,'), "A resposta da leitura informa o produto previsto.");
verificar(str_contains($application, ': reading.product_id != null'), "Na hora da leitura, produto previsto acima da quantidade tem texto próprio.");
verificar(str_contains($activeLoading, ': loading.last_scanner_product_id != null'), "Depois da atualização, o texto continua o mesmo.");
foreach ([$application, $activeLoading] as $source) {
    verificar(str_contains($source, "Acima da quantidade prevista: "), "Texto 'Acima da quantidade prevista' no visor.");
}

echo "Relatório: saca retirada fora dos totais, rótulos de produto incorreto corretos e visor sem 'não cadastrado' para produto do catálogo.\n";
