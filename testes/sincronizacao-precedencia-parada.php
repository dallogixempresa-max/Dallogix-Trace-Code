<?php
declare(strict_types=1);

// Central: o PC dá prioridade a produto incorreto sobre cota e excesso. O
// central aplicava só "motivo nulo ou igual", ficava com a cota antiga e
// recusava a parada seguinte; o instantâneo devolvia CARREGANDO ao PC. Este
// teste executa o SQL real de sincronizacao_eventos.php no SQLite.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioCarregamentosEventos.php";

use App\Persistencia\RepositorioCarregamentosEventos;

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(__DIR__ . "/../servidor/api/sincronizacao/sincronizacao_eventos.php");
$template = static function (string $statementVariable) use ($source): array {
    $pattern = '/\$supersededReasons = \$stopReason === "PRODUTO_INCORRETO"\s*\?\s*"(?<or>[^"]+)"\s*:\s*"";\s*'
        . '\$' . $statementVariable . ' = \$pdo->prepare\(\s*"(?<sql>[^"]+)"/';
    verificar(preg_match($pattern, $source, $match) === 1, "SQL de {$statementVariable} com prioridade de motivo não encontrado.");
    return ["or" => $match["or"], "sql" => $match["sql"]];
};
$pauseTemplate = $template("pauseLoading");
$clearTemplate = $template("clearStop");
$sqlFor = static fn (array $template, string $reason, bool $withPrecedence = true): string => str_replace(
    '{$supersededReasons}',
    $withPrecedence && $reason === "PRODUTO_INCORRETO" ? $template["or"] : "",
    $template["sql"],
);

$pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, state TEXT NOT NULL, stop_reason TEXT, operator_alert TEXT)");
$events = new RepositorioCarregamentosEventos($pdo);

$pause = static function (string $reason, bool $withPrecedence = true) use ($pdo, $pauseTemplate, $sqlFor): void {
    $pdo->prepare($sqlFor($pauseTemplate, $reason, $withPrecedence))->execute([
        "stop_reason_set" => $reason,
        "stop_reason_match" => $reason,
        "operator_alert" => "alerta {$reason}",
        "id" => 121,
        "company_id" => 1,
    ]);
};
$confirm = static function (string $reason, bool $withPrecedence = true) use ($pdo, $clearTemplate, $sqlFor): void {
    $pdo->prepare($sqlFor($clearTemplate, $reason, $withPrecedence))->execute([
        "id" => 121,
        "company_id" => 1,
        "stop_reason" => $reason,
    ]);
};
$reset = static function (string $state, ?string $reason) use ($pdo): void {
    $pdo->exec("DELETE FROM carregamentos");
    $pdo->prepare("INSERT INTO carregamentos (id, company_id, state, stop_reason) VALUES (121, 1, ?, ?)")->execute([$state, $reason]);
};
$row = static fn (): array => $pdo->query("SELECT state, stop_reason FROM carregamentos WHERE id = 121")->fetch(PDO::FETCH_ASSOC);

// 1) Prioridade igual à do PC.
$reset("PAUSADO", "COTA_ROMANEIO_ATINGIDA");
$pause("PRODUTO_INCORRETO");
verificar($row()["stop_reason"] === "PRODUTO_INCORRETO", "Produto incorreto deve substituir a cota do romaneio.");
$reset("PAUSADO", "EXCESSO");
$pause("PRODUTO_INCORRETO");
verificar($row()["stop_reason"] === "PRODUTO_INCORRETO", "Produto incorreto deve substituir o excesso.");
$reset("PAUSADO", "PRODUTO_INCORRETO");
$pause("COTA_ROMANEIO_ATINGIDA");
verificar($row()["stop_reason"] === "PRODUTO_INCORRETO", "Cota não pode substituir produto incorreto.");
$reset("PAUSADO", "EXCESSO");
$pause("COTA_PRODUTO_ATINGIDA");
verificar($row()["stop_reason"] === "EXCESSO", "Entre cota e excesso vale o motivo já registrado, como no PC.");
$reset("CARREGANDO", null);
$pause("COTA_ROMANEIO_ATINGIDA");
verificar($row() === ["state" => "PAUSADO", "stop_reason" => "COTA_ROMANEIO_ATINGIDA"], "Sem motivo anterior, a parada é aplicada.");

// 2) Confirmar produto incorreto encerra também o motivo que ele substituiu.
$reset("PAUSADO", "COTA_ROMANEIO_ATINGIDA");
$confirm("PRODUTO_INCORRETO");
verificar($row()["stop_reason"] === null, "A confirmação de produto incorreto deve limpar a cota substituída.");
$reset("PAUSADO", "PRODUTO_INCORRETO");
$confirm("COTA_ROMANEIO_ATINGIDA");
verificar($row()["stop_reason"] === "PRODUTO_INCORRETO", "Confirmar a cota não limpa produto incorreto.");
$reset("PAUSADO", "COTA_PRODUTO_ATINGIDA");
$confirm("COTA_PRODUTO_ATINGIDA");
verificar($row()["stop_reason"] === null, "A confirmação da própria cota continua limpando.");

// 3) Sequência real do carregamento 121 (fila 7894 → 7909).
$replay = static function (bool $withPrecedence) use ($reset, $pause, $confirm, $events, $row): array {
    $reset("CARREGANDO", null);
    $pause("COTA_ROMANEIO_ATINGIDA", $withPrecedence);   // 7894 QUANTIDADE_PLANEJADA_ATINGIDA
    $pause("PRODUTO_INCORRETO", $withPrecedence);        // 7897 PARADA_PRODUTO_INCORRETO
    $confirm("PRODUTO_INCORRETO", $withPrecedence);      // 7900 REMOCAO_UNIDADE_E_PARADA_FISICA_CONFIRMADAS
    $events->atualizarEstado(1, 121, "CARREGANDO");      // 7903 ESTADO_CARREGAMENTO_ALTERADO
    $pause("PRODUTO_INCORRETO", $withPrecedence);        // 7909 PARADA_PRODUTO_INCORRETO
    return $row();
};
$before = $replay(false);
verificar($before === ["state" => "CARREGANDO", "stop_reason" => "COTA_ROMANEIO_ATINGIDA"], "A regra antiga deveria reproduzir o central em CARREGANDO com a cota antiga.");
$after = $replay(true);
verificar($after === ["state" => "PAUSADO", "stop_reason" => "PRODUTO_INCORRETO"], "Com a prioridade, o central deve acompanhar a segunda parada do PC.");

// 4) ESTADO de pausa remota aplicada num PC já pausado (local_state_unchanged):
// só tira o central de operação; chega às vezes depois do encerramento no
// central e não pode reabrir a carga.
$pausaRemota = static function (string $centralState) use ($reset, $events, $row): array {
    $reset($centralState, null);
    $changed = $events->pausarSeEmOperacao(1, 121);
    return [$changed, $row()["state"]];
};
verificar($pausaRemota("CARREGANDO") === [true, "PAUSADO"], "Central atrasado em CARREGANDO deve ir para PAUSADO.");
verificar($pausaRemota("FINALIZANDO") === [true, "PAUSADO"], "FINALIZANDO também é estado em operação.");
verificar($pausaRemota("FINALIZADO") === [false, "FINALIZADO"], "Carga encerrada no central não pode ser reaberta.");
verificar($pausaRemota("AGUARDANDO") === [false, "AGUARDANDO"], "Carga desvinculada não pode voltar a PAUSADO.");
verificar($pausaRemota("PAUSADO") === [false, "PAUSADO"], "Central já pausado não muda.");
verificar(
    preg_match(
        '/if \(filter_var\(\$data\["local_state_unchanged"\] \?\? false, FILTER_VALIDATE_BOOLEAN\)\) \{[\s\S]*?\$state === "PAUSADO" && \$loadingEventsRepository->pausarSeEmOperacao\(\$companyId, \$loadingId\)[\s\S]*?\} else \{\s*\$loadingEventsRepository->atualizarEstado\(/',
        $source,
    ) === 1,
    "O ESTADO sem mudança local deve passar só pela pausa protegida; os demais seguem atualizarEstado.",
);

echo "Central: produto incorreto prevalece sobre cota/excesso, a sequência do carregamento 121 fica pausada e a pausa remota não reabre carga encerrada.\n";
