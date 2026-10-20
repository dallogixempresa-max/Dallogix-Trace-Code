<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ServicoMonitoramento.php";

use App\Aplicacao\ServicoMonitoramento;

exigir_metodo_http(["GET"]);
$usuario = exigir_sessao_usuario();
if ($usuario["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}

$limiteSemSinal = limite_sinal_clp_segundos();
session_write_close();
$maquinas = (new ServicoMonitoramento(obter_conexao_banco()))->obterMaquinas(
    (int) $usuario["company_id"],
    $limiteSemSinal,
);

$statusDasDalas = array_map(
    static function (array $machine): array {
        $status = (string) $machine["clp_status"];
        $segundos = $machine["segundos_sem_sinal"] === null
            ? null
            : max(0, (int) $machine["segundos_sem_sinal"]);
        $state = (string) ($machine["carregamento_state"] ?? "");
        if ($status !== "ONLINE") {
            $mensagem = $segundos === null
                ? "Dala sem comunicação; estado físico desconhecido."
                : "Dala sem comunicação há {$segundos} segundos; estado físico desconhecido.";
            $rotuloStatus = $mensagem;
            $observacaoConfiguracao = null;
        } else {
            $mensagem = match ($state) {
                "EMERGENCIA" => "Emergência registrada; parada física aguardando confirmação do CLP.",
                "PAUSADO" => "Parada solicitada; aguardando confirmação do CLP.",
                "CARREGANDO", "FINALIZANDO" => "Operação ativa; retorno físico da esteira não informado.",
                "PREPARANDO" => "Dala em preparação.",
                default => "Dala ociosa; sem retorno de movimento configurado.",
            };
            $rotuloStatus = match ($state) {
                "CARREGANDO", "FINALIZANDO" => "Operação ativa.",
                "" => "Dala ociosa.",
                default => $mensagem,
            };
            $observacaoConfiguracao = match ($state) {
                "CARREGANDO", "FINALIZANDO" => "Retorno físico da esteira não informado.",
                "" => "Sem retorno de movimento configurado.",
                default => null,
            };
        }
        return array_merge($machine, [
            "id" => (int) $machine["id"],
            "equipment_id" => (int) $machine["id"],
            "status" => $status,
            "carregamento_state" => $state !== "" ? $state : null,
            "message" => $mensagem,
            "status_label" => $rotuloStatus,
            "configuration_note" => $observacaoConfiguracao,
            "last_seen_at" => $machine["last_seen_at"],
            "segundos_sem_sinal" => $segundos,
        ]);
    },
    $maquinas,
);

responder_json([
    "data" => $statusDasDalas,
    "limite_sinal_clp_segundos" => $limiteSemSinal,
]);
