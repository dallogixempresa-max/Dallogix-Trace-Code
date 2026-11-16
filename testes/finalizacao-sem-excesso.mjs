import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const finishEndpoint = await readFile(
  new URL("../servidor/api/operacoes/encerrar_carregamento.php", import.meta.url),
  "utf8",
);
const manifestEndpoint = await readFile(
  new URL("../servidor/api/romaneios/romaneios.php", import.meta.url),
  "utf8",
);
const loadingSummary = await readFile(
  new URL("../servidor/api/operacoes/resumo_carregamento.php", import.meta.url),
  "utf8",
);
const operationController = await readFile(
  new URL("../interface/js/controladores/acoes-operacionais.js", import.meta.url),
  "utf8",
);
const loadingRules = await readFile(
  new URL("../interface/js/funcoes/regras-carregamento.js", import.meta.url),
  "utf8",
);
const commandService = await readFile(
  new URL("../servidor/src/Aplicacao/ServicoComandoClp.php", import.meta.url),
  "utf8",
);
const sensorEvents = await readFile(
  new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url),
  "utf8",
);

test("servidor bloqueia excesso antes de gravar FINALIZADO", () => {
  const excessGuard = finishEndpoint.indexOf("if ($hasExcess) {");
  const exactQuotaGuard = finishEndpoint.indexOf("if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity !== $loadingPlannedQuantity) {");
  const finishUpdate = finishEndpoint.indexOf("UPDATE carregamentos SET state = 'FINALIZADO'");
  assert.ok(excessGuard >= 0 && excessGuard < finishUpdate);
  assert.ok(exactQuotaGuard > excessGuard && exactQuotaGuard < finishUpdate);
  assert.match(finishEndpoint, /\$loadingDetectedQuantity !== \$loadingPlannedQuantity/);
  assert.match(finishEndpoint, /quantidade prevista de sacas válidas ou sem leitura/);
  assert.match(finishEndpoint, /\$loadingDetectedQuantity > \$loadingPlannedQuantity/);
  assert.match(finishEndpoint, /\$stopReason === "EXCESSO"/);
  assert.match(finishEndpoint, /\|\| \$overfill > 0/);
  assert.match(finishEndpoint, /"error" => "A quantidade detectada excedeu o previsto[^"]*"\],\s*409/);
  assert.doesNotMatch(finishEndpoint, /EXCESSO_FINALIZADO_COM_JUSTIFICATIVA/);
  assert.match(manifestEndpoint, /\$manifestExcess = \$pdo->prepare/);
  assert.match(manifestEndpoint, /\$manifestExcess->fetchColumn\(\) > 0/);
  assert.match(manifestEndpoint, /quantidade de produto acima do previsto e não pode ser finalizado/);
  assert.match(manifestEndpoint, /\$quantityMismatch = \$pdo->prepare/);
  assert.match(manifestEndpoint, /quantidade detectada não corresponde ao previsto/);
  assert.doesNotMatch(manifestEndpoint, /EXCESSO_FINALIZADO_COM_JUSTIFICATIVA/);
});

test("nenhum perfil finaliza abaixo da quantidade prevista", () => {
  const exactQuotaGuard = finishEndpoint.indexOf("if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity !== $loadingPlannedQuantity) {");
  const finishUpdate = finishEndpoint.indexOf("UPDATE carregamentos SET state = 'FINALIZADO'");
  assert.ok(exactQuotaGuard >= 0 && exactQuotaGuard < finishUpdate);
  assert.doesNotMatch(finishEndpoint, /Somente supervisores/);
});

test("o clique em Finalizar conclui pela meta física sem exigir fotos ou revisão de supervisor", () => {
  assert.match(operationController, /podeFinalizarCarregamento\(store.state\)/);
  assert.match(operationController, /store.finishLoading\(""\)/);
  assert.doesNotMatch(operationController, /requiresSupervisorReview/);
});

test("leituras e evidências bloqueiam o relatório posterior, não a conclusão operacional", async () => {
  const report = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
  assert.match(report, /\$unresolvedPassages = \$pdo->prepare/);
  assert.match(report, /\$pendingCaptures = \$pdo->prepare/);
  assert.match(report, /missing_evidence_count/);
  assert.match(report, /PRODUTO_INCORRETO/);
  assert.doesNotMatch(finishEndpoint, /contarLeiturasSemImagemValida/);
  assert.doesNotMatch(finishEndpoint, /imagens de falha.*antes do encerramento/);
  const finishManifest = manifestEndpoint.slice(manifestEndpoint.indexOf('if ($action === "finish")'), manifestEndpoint.indexOf('if ($action === "cancel")'));
  assert.doesNotMatch(finishManifest, /contarLeiturasSemImagemValida/);
  assert.match(finishManifest, /\$quantityMismatch/);
  assert.match(finishManifest, /\$manifestExcess/);
});

test("passagem excedente reenvia a pausa sem substituir o motivo original", () => {
  assert.match(commandService, /if \(\$reason === "EXCESSO"\) \{[\s\S]*?\$pendingPause = \$this->repositorio->comandoPendente\(\$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /\$request = \$this->solicitarOperacao\(\$user, \$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /"stop_reason" => \$existingReason/);
  assert.match(commandService, /\$alreadyPausedForReason && \$reason !== "EXCESSO"/);
  assert.match(sensorEvents, /SELECT id FROM logs_auditoria[\s\S]*?QUANTIDADE_PLANEJADA_ATINGIDA/);
  assert.match(sensorEvents, /UPDATE logs_auditoria[\s\S]*?JSON_SET\([\s\S]*?\$\.command_request_id/);
});
