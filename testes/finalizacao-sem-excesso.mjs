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
  const finishUpdate = finishEndpoint.indexOf("UPDATE carregamentos SET state = 'FINALIZADO'");
  assert.ok(excessGuard >= 0 && excessGuard < finishUpdate);
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

test("interface não oferece exceção de supervisor para finalizar excesso", () => {
  assert.match(operationController, /const requiresSupervisorReview = hasItemDivergence && !evidenceCoversDivergence/);
  assert.doesNotMatch(operationController, /hasExcess/);
  assert.doesNotMatch(operationController, /autorizar o encerramento com justificativa/);
});

test("o botão exige uma foto capturada para cada passagem sem leitura", () => {
  assert.match(loadingSummary, /\$data\["sem_leitura_com_foto"\] = \$count/);
  assert.match(loadingSummary, /\$data\["sem_leitura_evidencias_com_imagem"\] = max\([\s\S]*?contarLeiturasSemImagemValida/);
  assert.match(loadingSummary, /ValidadorEvidenciasOperacionais/);
  assert.match(loadingSummary, /e\.sensor_event_id = l\.sensor_event_id/);
  assert.match(loadingSummary, /e\.reason = 'SEM_LEITURA' AND e\.status = 'CAPTURADA'/);
  assert.match(loadingRules, /Aguarde a foto Keyence de cada saca sem leitura/);
  assert.match(loadingRules, /Number\(summary\.sem_leitura_com_foto\) >= noRead/);
  assert.match(manifestEndpoint, /contarLeiturasSemImagemValidaNoRomaneio/);
  assert.match(finishEndpoint, /contarLeiturasSemImagemValida\([\s\S]*?"SEM_LEITURA"/);
  assert.match(finishEndpoint, /contarLeiturasSemImagemValida\([\s\S]*?"PRODUTO_INCORRETO"/);
  assert.match(manifestEndpoint, /contarLeiturasSemImagemValidaNoRomaneio\([\s\S]*?"PRODUTO_INCORRETO"/);
  assert.match(loadingSummary, /\$data\["produtos_incorretos_com_foto"\] = max\([\s\S]*?contarLeiturasSemImagemValida/);
  assert.match(loadingRules, /produtos_incorretos_com_foto/);
  assert.match(loadingRules, /Aguarde a foto Keyence de cada produto fora do romaneio/);
  assert.match(manifestEndpoint, /\$failedEvidence = \$pdo->prepare/);
});

test("as APIs também bloqueiam foto com falha e leituras incorretas", () => {
  assert.match(finishEndpoint, /\$failedEvidenceStatement = \$pdo->prepare/);
  assert.match(finishEndpoint, /\$failedEvidenceForLoading = \$pdo->prepare/);
  assert.match(finishEndpoint, /\$failedEvidenceCountForManifestLoading > 0/);
  assert.match(finishEndpoint, /status = 'ERRO'/);
  assert.match(manifestEndpoint, /\$unsupportedReadings = \$pdo->prepare/);
  assert.match(manifestEndpoint, /l\.result NOT IN \('VALIDO', 'SEM_LEITURA'\)/);
});

test("passagem excedente reenvia a pausa sem substituir o motivo original", () => {
  assert.match(commandService, /if \(\$reason === "EXCESSO"\) \{[\s\S]*?\$pendingPause = \$this->repositorio->comandoPendente\(\$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /\$request = \$this->solicitarOperacao\(\$user, \$loadingId, "PAUSAR_CARREGAMENTO"\)/);
  assert.match(commandService, /"stop_reason" => \$existingReason/);
  assert.match(commandService, /\$alreadyPausedForReason && \$reason !== "EXCESSO"/);
  assert.match(sensorEvents, /SELECT id FROM logs_auditoria[\s\S]*?QUANTIDADE_PLANEJADA_ATINGIDA/);
  assert.match(sensorEvents, /UPDATE logs_auditoria[\s\S]*?JSON_SET\([\s\S]*?\$\.command_request_id/);
});
