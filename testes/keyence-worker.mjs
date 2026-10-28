import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createConnection, createServer } from "node:net";
import test from "node:test";
import { createFtpReceiver } from "../integracoes/keyence-worker/ftp-receiver.mjs";
import { matchPendingImage, nextUnreservedSensorEventId, parseBarcodeFrame } from "../integracoes/keyence-worker/keyence-core.mjs";

const worker = await readFile(new URL("../integracoes/keyence-worker/keyence-worker.mjs", import.meta.url), "utf8");
const imageEndpoint = await readFile(new URL("../servidor/api/keyence_worker.php", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
const compose = await readFile(new URL("../docker-compose.yml", import.meta.url), "utf8");

function freePort() {
  return new Promise((resolve, reject) => {
    const server = createServer();
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      const { port } = server.address();
      server.close((error) => error ? reject(error) : resolve(port));
    });
  });
}

function nextFtpLine(socket) {
  return new Promise((resolve, reject) => {
    let buffer = "";
    const timer = setTimeout(() => finish(new Error("Tempo esgotado aguardando FTP.")), 3000);
    const onData = (chunk) => {
      buffer += chunk.toString("utf8");
      const end = buffer.indexOf("\r\n");
      if (end >= 0) finish(null, buffer.slice(0, end));
    };
    const onError = (error) => finish(error);
    function finish(error, line = "") {
      clearTimeout(timer);
      socket.off("data", onData);
      socket.off("error", onError);
      if (error) reject(error);
      else resolve(line);
    }
    socket.on("data", onData);
    socket.once("error", onError);
  });
}

async function sendFtpCommand(socket, command) {
  socket.write(command + "\r\n");
  return nextFtpLine(socket);
}

test("valida códigos TCP e só associa imagem quando há solicitação inequívoca", () => {
  assert.equal(parseBarcodeFrame("  0123456789\r\n"), "0123456789");
  assert.equal(parseBarcodeFrame("01012345\x1D17250301"), "01012345\x1D17250301");
  assert.equal(parseBarcodeFrame(""), null);
  assert.equal(parseBarcodeFrame("0123\x00"), null);
  assert.equal(parseBarcodeFrame("x".repeat(129)), null);

  const requests = [
    { id: 1, requested_at_epoch_ms: 10000 },
    { id: 2, requested_at_epoch_ms: 20000 },
  ];
  assert.equal(matchPendingImage(10500, requests.slice(0, 1), 8000).request.id, 1);
  assert.equal(matchPendingImage(15000, requests, 8000).reason, "solicitacoes_ambiguas");
  assert.equal(matchPendingImage(40000, requests, 8000).reason, "sem_solicitacao_compativel");
});

test("associa códigos às passagens em ordem e reserva um evento por quadro", () => {
  const reserved = new Set([101]);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), 102);
  reserved.add(102);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), 103);
  reserved.add(103);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), null);
  assert.match(imageEndpoint, /pending_sensor_event_ids/);
  assert.match(imageEndpoint, /ORDER BY s\.detected_at ASC, s\.id ASC/);
  assert.match(readingsEndpoint, /ORDER BY s\.detected_at ASC, s\.id ASC LIMIT 1 FOR UPDATE/);
  assert.match(worker, /reservedSensorEventIds\.add\(sensorEventId\)/);
  assert.match(worker, /enqueueBarcodeFrame\(frame\)/);
});

test("servidor FTP aceita JPEG somente após autenticação e grava em inbox local", async (context) => {
  const inboxDirectory = await mkdtemp(join(tmpdir(), "trace-keyence-ftp-"));
  const controlPort = await freePort();
  const passivePort = await freePort();
  let resolveImage;
  const receivedImage = new Promise((resolve) => { resolveImage = resolve; });
  const receiver = createFtpReceiver({
    bindAddress: "127.0.0.1",
    port: controlPort,
    passiveStart: passivePort,
    passiveEnd: passivePort,
    advertisedAddress: "127.0.0.1",
    username: "trace-test",
    password: "local-test-password",
    inboxDirectory,
    onImage: resolveImage,
    log: () => {},
  });
  let control;
  try {
    await receiver.listen();
    control = createConnection({ host: "127.0.0.1", port: controlPort });
    await new Promise((resolve, reject) => { control.once("connect", resolve); control.once("error", reject); });
    assert.match(await nextFtpLine(control), /^220 /);
    assert.match(await sendFtpCommand(control, "USER trace-test"), /^331 /);
    assert.match(await sendFtpCommand(control, "PASS local-test-password"), /^230 /);
    assert.match(await sendFtpCommand(control, "TYPE I"), /^200 /);
    const passive = await sendFtpCommand(control, "PASV");
    const match = passive.match(/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/);
    assert.ok(match, passive);
    const host = match.slice(1, 5).join(".");
    const port = Number(match[5]) * 256 + Number(match[6]);
    const data = createConnection({ host, port });
    await new Promise((resolve, reject) => { data.once("connect", resolve); data.once("error", reject); });
    assert.match(await sendFtpCommand(control, "STOR no-read-1.jpg"), /^150 /);
    data.end(Buffer.from([0xff, 0xd8, 0xff, 0xd9]));
    assert.match(await nextFtpLine(control), /^226 /);
    const savedPath = await receivedImage;
    assert.deepEqual(await readFile(savedPath), Buffer.from([0xff, 0xd8, 0xff, 0xd9]));
    assert.match(await sendFtpCommand(control, "QUIT"), /^221 /);
  } finally {
    control?.destroy();
    await receiver.close();
    await rm(inboxDirectory, { recursive: true, force: true });
  }
});

test("integra SCANNER, fila SEM_LEITURA, imagem do Keyence e limpeza depois do PDF final", () => {
  assert.match(worker, /\/api\/keyence_worker\.php/);
  assert.match(worker, /\/api\/leituras\.php/);
  assert.match(worker, /X-Trace-Offline-Id/);
  assert.match(imageEndpoint, /require_device_token\(\["SCANNER"\]\)/);
  assert.match(readingsEndpoint, /require_device_token\(\["SCANNER"\]\)/);
  assert.match(readingsEndpoint, /evento de passagem/);
  assert.match(readingsEndpoint, /"evidence_status" => \$evidenceStatus/);
  assert.doesNotMatch(readingsEndpoint, /CAMERA_CAPTURA|camera_status/);
  assert.match(worker, /KEYENCE_TCP_ALLOWED_ADDRESS/);
  assert.match(imageEndpoint, /r\.reason = 'SEM_LEITURA'/);
  assert.match(imageEndpoint, /KEYENCE_IMAGEM_EVIDENCIA_RECEBIDA/);
  assert.match(reportEndpoint, /FROM imagens WHERE carregamento_id IN \(\{\$placeholders\}\) AND reason <> 'NORMAL'/);
  assert.match(reportEndpoint, /RelatorioAuditoriaPdf/);
  assert.match(reportEndpoint, /inserirDadosDaImagemDaOcorrencia/);
  assert.match(reportEndpoint, /trace_cleanup_report_evidence/);
  assert.match(reportEndpoint, /solicitacoes_imagens_evidencia/);
  assert.match(reportEndpoint, /\(\?:capture\|keyence\)-\[0-9\]/);
  assert.doesNotMatch(reportEndpoint, /camera-pc\/request-/);
  assert.doesNotMatch(worker, /câmera Trace|contingência RTSP/i);
  assert.match(compose, /keyence-worker:[\s\S]*?profiles: \["industrial"\]/);
  assert.match(compose, /KEYENCE_FTP_PASSIVE_START/);
  assert.match(compose, /health\?\.running === true && \(health\?\.enabled === false \|\| \(health\?\.tcp_output_listening === true && health\?\.ftp_image_receiver_listening === true\)\)/);
});
