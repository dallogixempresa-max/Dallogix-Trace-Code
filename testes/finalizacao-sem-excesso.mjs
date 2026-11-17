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

test("servidor registra divergências para o relatório e só exige atingir a meta física", () => {
  const exactQuotaGuard = finishEndpoint.indexOf("if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity < $loadingPlannedQuantity) {");
  const finishUpdate = finishEndpoint.indexOf("UPDATE carregamentos SET state = 'FINALIZADO'");
  assert.ok(exactQuotaGuard >= 0 && exactQuotaGuard < finishUpdate);
  assert.match(finishEndpoint, /\$loadingDetectedQuantity < \$loadingPlannedQuantity/);
  assert.match(finishEndpoint, /produto incorreto, excesso e evidência ficam auditados no relatório/);
  assert.doesNotMatch(finishEndpoint, /\$hasExcess|\$overfill|EXCESSO_FINALIZADO_COM_JUSTIFICATIVA/);
  assert.doesNotMatch(manifestEndpoint, /\$manifestExcess|\$quantityMismatch/);
});

test("nenhum perfil finaliza abaixo da quantidade prevista", () => {
  const exactQuotaGuard = finishEndpoint.indexOf("if ($loadingPlannedQuantity < 1 || $loadingDetectedQuantity < $loadingPlannedQuantity) {");
  const finishUpdate = finishEndpoint.indexOf("UPDATE carregamentos SET state = 'FINALIZADO'");
  assert.ok(exactQuotaGuard >= 0 && exactQuotaGuard < finishUpdate);
  assert.doesNotMatch(finishEndpoint, /Somente supervisores/);
});

test("o clique em Finalizar conclui pela meta física sem exigir fotos ou revisão de supervisor", () => {
  assert.match(operationController, /podeFinalizarCarregamento\(store.state\)/);
  assert.match(operationController, /store.finishLoading\(""\)/);
  assert.doesNotMatch(operationController, /requiresSupervisorReview/);
});

test("leituras e evidências pendentes ficam explícitas sem bloquear relatório ou conclusão operacional", async () => {
  const report = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
  assert.match(report, /\$unresolvedPassages = \$pdo->prepare/);
  assert.match(report, /\$unavailableCaptures = \$pdo->prepare/);
  assert.match(report, /relatorio_pendencia_evidencia/);
  assert.doesNotMatch(report, /O relatório aguarda|Aguarde o envio das imagens/);
  assert.match(report, /PRODUTO_INCORRETO/);
  assert.doesNotMatch(finishEndpoint, /contarLeiturasSemImagemValida/);
  assert.doesNotMatch(finishEndpoint, /imagens de falha.*antes do encerramento/);
  const finishManifest = manifestEndpoint.slice(manifestEndpoint.indexOf('if ($action === "finish")'), manifestEndpoint.indexOf('if ($action === "cancel")'));
  assert.doesNotMatch(finishManifest, /contarLeiturasSemImagemValida/);
  assert.doesNotMatch(finishManifest, /\$quantityMismatch|\$manifestExcess/);
});

test("passagem excedente reenvia a pausa sem substituir o motivo original", () => {
  assert.match(commandService, /if \(\$reason === "EXCESSO"\) \{[\s\S]*?\$pendingPause = \$this->repositorio->comandoPendente\(\$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /\$request = \$this->solicitarOperacao\(\$user, \$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /"stop_reason" => \$existingReason/);
  assert.match(commandService, /\$alreadyPausedForReason && \$reason !== "EXCESSO"/);
  assert.match(sensorEvents, /SELECT id FROM logs_auditoria[\s\S]*?QUANTIDADE_PLANEJADA_ATINGIDA/);
  assert.match(sensorEvents, /UPDATE logs_auditoria[\s\S]*?JSON_SET\([\s\S]*?\$\.command_request_id/);
});
