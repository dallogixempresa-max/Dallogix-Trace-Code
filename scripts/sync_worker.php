<?php

declare(strict_types=1);

require_once __DIR__ . "/../servidor/configuracao/bootstrap.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ProvisionadorDispositivosLocais.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoSincronizacaoRemota.php";

use App\Aplicacao\ServicoSincronizacao;
use App\Aplicacao\ServicoSincronizacaoRemota;

if ((string) (getenv("TRACE_LOCAL_SIMULATION") ?: "0") === "1") {
    exit(0);
}

$batchSize = max(1, min(500, (int) (getenv("SYNC_BATCH_SIZE") ?: 50)));
$summary = (new ServicoSincronizacao(db()))->processarLote($batchSize);
$remoteSummary = (new ServicoSincronizacaoRemota(db()))->sincronizarConfiguracaoDaInstalacao();
if ($summary["reserved"] > 0 || $summary["failed"] > 0) {
    fwrite(STDOUT, sprintf(
        "sync-worker: reservados=%d enviados=%d falhas=%d ignorados=%d\n",
        $summary["reserved"],
        $summary["sent"],
        $summary["failed"],
        $summary["skipped"],
    ));
}
if (($remoteSummary["enabled"] ?? false) && !($remoteSummary["synced"] ?? false)) {
    fwrite(STDERR, sprintf(
        "sync-worker: sincronização remota falhou: %s\n",
        (string) ($remoteSummary["error"] ?? "erro desconhecido"),
    ));
}
if (($remoteSummary["enabled"] ?? false) && ($remoteSummary["synced"] ?? false)) {
    $updated = (int) ($remoteSummary["updated"] ?? 0);
    if ($updated > 0) {
        fwrite(STDOUT, sprintf(
            "sync-worker: configuração remota aplicada; registros_atualizados=%d\n",
            $updated,
        ));
    }
}
if ($summary["failed"] > 0 || (($remoteSummary["enabled"] ?? false) && !($remoteSummary["synced"] ?? false))) {
    exit(1);
}
