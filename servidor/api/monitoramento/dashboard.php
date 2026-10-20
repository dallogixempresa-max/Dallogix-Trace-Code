<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioDashboard.php";

use App\Persistencia\RepositorioDashboard;

// Métricas do dashboard no padrão da referência TracePlatform:
// total de romaneios, operações finalizadas, ocorrências, taxa de divergência e tempo médio de operação.
$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}

session_write_close();
$pdo = db();
$companyId = $user["company_id"];

$count = static function (string $sql, array $params) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) ($statement->fetchColumn() ?: 0);
};

$totalRomaneios = $count(
    "SELECT COUNT(*) FROM romaneios WHERE company_id = :company_id",
    ["company_id" => $companyId],
);
$operacoesFinalizadas = $count(
    "SELECT COUNT(*) FROM romaneios WHERE company_id = :company_id AND status = 'FINALIZADO'",
    ["company_id" => $companyId],
);
$totalOcorrencias = $count(
    "SELECT COUNT(*) FROM ocorrencias WHERE company_id = :company_id",
    ["company_id" => $companyId],
);

$divergentes = (new RepositorioDashboard($pdo))->contarRomaneiosDivergentes((int) $companyId);
$taxaDivergencia =
    $operacoesFinalizadas > 0
        ? round(($divergentes / $operacoesFinalizadas) * 100, 1)
        : 0.0;

$mediaStatement = $pdo->prepare(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, started_at, finished_at)), 0) FROM carregamentos WHERE company_id = :company_id AND state = \'FINALIZADO\' AND started_at IS NOT NULL AND finished_at IS NOT NULL',
);
$mediaStatement->execute(["company_id" => $companyId]);
$tempoMedio = (int) round((float) ($mediaStatement->fetchColumn() ?: 0));

json_response([
    "data" => [
        "total_romaneios" => $totalRomaneios,
        "operacoes_finalizadas" => $operacoesFinalizadas,
        "total_ocorrencias" => $totalOcorrencias,
        "taxa_divergencia" => $taxaDivergencia,
        "tempo_medio_operacao" => $tempoMedio,
    ],
]);
