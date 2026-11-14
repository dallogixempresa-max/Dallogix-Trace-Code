import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(path, import.meta.url), "utf8");
const sensorEvents = await read("../servidor/api/operacoes/sensor_eventos.php");
const scannerTiming = await read("../servidor/api/operacoes/temporizacao_leitor.php");
const readings = await read("../servidor/api/operacoes/leituras.php");
const finish = await read("../servidor/api/operacoes/encerrar_carregamento.php");
const summary = await read("../servidor/api/operacoes/resumo_carregamento.php");
const romaneios = await read("../servidor/api/romaneios/romaneios.php");
const report = await read("../servidor/api/relatorios/relatorio_auditoria.php");
const validator = await read("../servidor/src/Aplicacao/ValidadorEvidenciasOperacionais.php");
const finishRules = await read("../interface/js/funcoes/regras-carregamento.js");

test("passagem durante pausa não é contada, mas sem leitura gera ocorrência e foto", () => {
  const timeoutQuery = sensorEvents.slice(
    sensorEvents.indexOf("$events = $pdo->prepare("),
    sensorEvents.indexOf("$events->execute(", sensorEvents.indexOf("$events = $pdo->prepare(")),
  );
  assert.match(timeoutQuery, /s\.conta_na_meta/);
  assert.doesNotMatch(timeoutQuery, /AND s\.conta_na_meta = 1/);
  assert.match(sensorEvents, /\$countsTowardPlan = \(int\) \$pendingSensorEvent\["conta_na_meta"\] === 1 && !\$physicalExcess/);
  assert.match(sensorEvents, /"conta_na_meta" => \$countsTowardPlan \? 1 : 0/);
  assert.match(sensorEvents, /\$evidenceRequest = \$pdo->prepare/);
  assert.match(sensorEvents, /"reason" => \$evidenceReason/);
});

test("o leitor associa a passagem pausada e registra o resultado sem consumir a meta", () => {
  const matcher = scannerTiming.slice(scannerTiming.indexOf("function trace_pending_scanner_event_match"));
  assert.doesNotMatch(matcher, /AND s\.conta_na_meta = 1/);
  assert.match(readings, /\$countsTowardPlan = in_array\(\$result, \["VALIDO", "SEM_LEITURA"\], true\)[\s\S]*?\(int\) \(\$sensorEvent\["conta_na_meta"\] \?\? 0\) === 1/);
  assert.doesNotMatch(readings, /Esta passagem foi capturada durante a pausa e não pode gerar leitura operacional/);
});

test("a finalização aguarda qualquer passagem sem resultado e toda foto legível", () => {
  const pendingQuery = finish.slice(finish.indexOf("$pendingSensorEvents ="), finish.indexOf("$pendingSensorEvents->execute"));
  assert.doesNotMatch(pendingQuery, /s\.conta_na_meta = 1/);
  assert.match(finish, /contarLeiturasSemImagemValida\([\s\S]*?"SEM_LEITURA"/);
  assert.match(finish, /contarLeiturasSemImagemValida\([\s\S]*?"PRODUTO_INCORRETO"/);
  assert.match(summary, /sem_leitura_evidencias_obrigatorias/);
  assert.match(summary, /sem_leitura_evidencias_com_imagem/);
  assert.match(finishRules, /sem_leitura_evidencias_obrigatorias/);
  assert.match(finishRules, /sem_leitura_evidencias_com_imagem/);
  assert.match(validator, /trace_evidence_image_path/);
  assert.match(validator, /image_sha256/);
});

test("cancelamento e relatório não contornam evidência nem passagem pendente", () => {
  assert.match(romaneios, /contarLeiturasSemImagemValida\([\s\S]*?"SEM_LEITURA"/);
  assert.match(romaneios, /contarLeiturasSemImagemValidaNoRomaneio/);
  assert.match(report, /\$unresolvedPassages = \$pdo->prepare/);
  const noReadRows = report.slice(report.indexOf("$noReadQuery ="), report.indexOf("$noReadQuery->execute"));
  assert.doesNotMatch(noReadRows, /l\.conta_na_meta = 1/);
  assert.match(report, /Cada falha corresponde a uma passagem de saco registrada pelo sensor e exige foto de evidência/);
});
