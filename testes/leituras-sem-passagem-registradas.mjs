import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHidScannerCapture } from "../interface/js/controladores/captura-leitor-hid.js";
import { ServicoLeiturasOperacionais } from "../interface/js/servicos/ServicoLeiturasOperacionais.js";

const ler = (caminho) => readFile(new URL(`../${caminho}`, import.meta.url), "utf8");

test("captura descartada pela tela é informada com o motivo, em vez de sumir", () => {
  let agora = 1000;
  const descartes = [];
  const captura = createHidScannerCapture({
    isEnabled: () => true,
    onDiscard: (texto, hora, motivo) => descartes.push([texto, hora, motivo]),
    now: () => agora,
    setTimer: () => 1,
    clearTimer: () => {},
  });
  for (const tecla of "7898250782141") { captura.handleKeyDown({ key: tecla, target: null }); agora += 5; }
  captura.reset({ motivo: "perda de foco" });
  assert.deepEqual(descartes, [["7898250782141", 1000, "perda de foco"]]);
  captura.reset({ motivo: "perda de foco" });
  assert.equal(descartes.length, 1, "Campo vazio não gera descarte.");
});

test("captura com a tela fora da operação também é informada", () => {
  const descartes = [];
  let habilitada = true;
  const captura = createHidScannerCapture({
    isEnabled: () => habilitada,
    onDiscard: (texto, hora, motivo) => descartes.push(motivo),
    setTimer: () => 1,
    clearTimer: () => {},
  });
  for (const tecla of "7898250782141") captura.handleKeyDown({ key: tecla, target: null });
  habilitada = false;
  captura.handleKeyDown({ key: "Enter", target: null });
  assert.deepEqual(descartes, ["tela fora da operação"]);
});

test("a tela envia o resultado sem passagem ao servidor com motivo e hora da captura", async () => {
  const chamadas = [];
  const servico = new ServicoLeiturasOperacionais({
    api: { fetch: async (url, opcoes) => { chamadas.push([url, JSON.parse(opcoes.body), opcoes.headers]); return new Response(JSON.stringify({ data: { id: 1 } }), { status: 201, headers: { "Content-Type": "application/json" } }); } },
    state: { loadingId: 150 },
    jsonHeaders: () => ({ "Content-Type": "application/json" }),
    jsonResponse: async (r) => r.json(),
    createEventId: () => "0123456789abcdef0123",
  });
  await servico.registrarLeituraSemPassagem(150, "7898250782141", 1795719268585, "SEM_PASSAGEM");
  assert.equal(chamadas[0][0], "/api/leituras_sem_passagem.php");
  assert.deepEqual(chamadas[0][1], { carregamento_id: 150, barcode: "7898250782141", no_read: false, motivo: "SEM_PASSAGEM", scanner_received_at_epoch_ms: 1795719268585 });
  assert.equal(chamadas[0][2]["X-Trace-Offline-ID"], "0123456789abcdef0123");
});

test("a operação grava sem passagem e esteira parada; o servidor guarda e o PDF lista", async () => {
  const app = await ler("interface/js/aplicacao.js");
  assert.match(app, /registrarSemPassagem\(loadingId, barcode, capturedAtEpochMs, esteiraParada \? "ESTEIRA_PARADA" : "SEM_PASSAGEM", noRead\)/);
  assert.match(app, /"DESCARTADA_TELA"/);
  assert.match(app, /capture\.reset\(\{ motivo: "perda de foco" \}\)/);
  const endpoint = await ler("servidor/api/operacoes/leituras_sem_passagem.php");
  assert.match(endpoint, /exigir_csrf\(\)/);
  assert.match(endpoint, /INSERT INTO leituras_sem_passagem/);
  assert.match(endpoint, /1062/);
  const migracao = await ler("banco-de-dados/migrations/085_leituras_sem_passagem.sql");
  assert.match(migracao, /CREATE TABLE IF NOT EXISTS leituras_sem_passagem/);
  const relatorio = await ler("servidor/api/relatorios/relatorio_auditoria.php");
  assert.match(relatorio, /Leituras do leitor sem passagem/);
  assert.match(relatorio, /FROM leituras_sem_passagem u/);
  assert.match(relatorio, /O leitor não registrou falha nesta saca/);
  const sensor = await ler("servidor/api/operacoes/sensor_eventos.php");
  assert.match(sensor, /'sensor\.passagem\.antiduplicacao'/);
});
