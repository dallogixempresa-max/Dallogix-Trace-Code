import assert from "node:assert/strict";
import { mkdtemp, mkdir, readFile, readdir, rm, stat, utimes, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createConnection, createServer } from "node:net";
import test from "node:test";
import { createFtpReceiver } from "../integracoes/keyence-worker/ftp-receiver.mjs";
import { groupImageFrames, isAllowedSourceAddress, matchPendingImage, nextUnreservedSensorEventId, parseBarcodeFrame, parseLinuxDefaultGateway } from "../integracoes/keyence-worker/keyence-core.mjs";
import { importArchivedImages } from "../integracoes/keyence-worker/image-archive.mjs";

const worker = await readFile(new URL("../integracoes/keyence-worker/keyence-worker.mjs", import.meta.url), "utf8");
const imageEndpoint = await readFile(new URL("../servidor/api/keyence_worker.php", import.meta.url), "utf8");
const readingsEndpoint = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
const sensorEventsEndpoint = await readFile(new URL("../servidor/api/operacoes/sensor_eventos.php", import.meta.url), "utf8");
const scannerTiming = await readFile(new URL("../servidor/api/operacoes/temporizacao_leitor.php", import.meta.url), "utf8");
const manifestEndpoint = await readFile(new URL("../servidor/api/romaneios/romaneios.php", import.meta.url), "utf8");
const reportEndpoint = await readFile(new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url), "utf8");
const pdfGenerator = await readFile(new URL("../servidor/src/Aplicacao/RelatorioAuditoriaPdf.php", import.meta.url), "utf8");
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
  const closeRequests = [
    { id: 3, sensor_detected_at_epoch_ms: 10000 },
    { id: 4, sensor_detected_at_epoch_ms: 12000 },
  ];
  assert.equal(matchPendingImage(10500, closeRequests, 3000, "sensor_detected_at_epoch_ms").request.id, 3);
  assert.equal(matchPendingImage(11000, closeRequests, 3000, "sensor_detected_at_epoch_ms").reason, "solicitacoes_ambiguas");
  const groups = groupImageFrames([
    { name: "190_E_01.JPG", receivedAt: 10000 },
    { name: "190_E_02.JPG", receivedAt: 10020 },
    { name: "191_E_01.JPG", receivedAt: 10400 },
  ], 250);
  assert.equal(groups.length, 2);
  assert.equal(groups[0].representative.name, "190_E_02.JPG");
});

test("importa cópias recentes da pasta Keyence sem alterar os originais nem repetir arquivos", async () => {
  const root = await mkdtemp(join(tmpdir(), "trace-keyence-archive-"));
  const sourceDirectory = join(root, "falhas");
  const nestedSource = join(sourceDirectory, "2026", "10");
  const inboxDirectory = join(root, "inbox");
  await mkdir(nestedSource, { recursive: true });
  const sourceFile = join(nestedSource, "190_E_02.JPG");
  const jpeg = Buffer.alloc(256, 0x11);
  jpeg[0] = 0xff;
  jpeg[1] = 0xd8;
  jpeg[jpeg.length - 2] = 0xff;
  jpeg[jpeg.length - 1] = 0xd9;
  await writeFile(sourceFile, jpeg);
  const capturedAt = Date.now() - 2000;
  await utimes(sourceFile, capturedAt / 1000, capturedAt / 1000);
  const staleFile = join(nestedSource, "180_E_01.JPG");
  await writeFile(staleFile, jpeg);
  await utimes(staleFile, (capturedAt - 600000) / 1000, (capturedAt - 600000) / 1000);

  try {
    const first = await importArchivedImages({
      sourceDirectory,
      inboxDirectory,
      now: Date.now(),
      settleMs: 500,
      maxAgeMs: 300000,
    });
    assert.deepEqual(first, { available: true, imported: 1, skipped: 0 });
    const names = await readdir(inboxDirectory);
    const importedName = names.find((name) => name.includes("-archive-190_E_02.JPG"));
    assert.ok(importedName);
    assert.match(importedName, /^\d{13}--[a-f0-9]{64}-[a-f0-9]{8}-archive-190_E_02\.JPG$/);
    assert.equal(Number(importedName.slice(0, 13)), Math.round((await stat(sourceFile)).mtimeMs));
    assert.deepEqual(await readFile(sourceFile), jpeg);
    assert.equal((await importArchivedImages({ sourceDirectory, inboxDirectory, settleMs: 500, maxAgeMs: 300000 })).imported, 0);

    await rm(join(inboxDirectory, importedName));
    assert.equal((await importArchivedImages({ sourceDirectory, inboxDirectory, settleMs: 500, maxAgeMs: 300000 })).imported, 0);
    assert.deepEqual(await readFile(sourceFile), jpeg);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("associa códigos às passagens em ordem e reserva um evento por quadro", () => {
  const reserved = new Set([101]);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), 102);
  reserved.add(102);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), 103);
  reserved.add(103);
  assert.equal(nextUnreservedSensorEventId([101, 102, 103], reserved), null);
  assert.match(imageEndpoint, /pending_sensor_event_ids/);
  assert.match(imageEndpoint, /trace_pending_scanner_event_match\(/);
  assert.match(readingsEndpoint, /trace_pending_scanner_event_match\([\s\S]*?\$scannerObservedAtMs,\s*true,/);
  assert.match(scannerTiming, /\$expectedAtMs\s*=\s*\$detectedAtMs\s*\+\s*\$timing\["travel_ms"\]/);
  assert.match(scannerTiming, /ORDER BY s\.id ASC/);
  assert.match(scannerTiming, /FOR UPDATE/);
  assert.match(scannerTiming, /ambiguity_margin_ms/);
  assert.match(worker, /reservedSensorEventIds\.add\(sensorEventId\)/);
  assert.match(worker, /enqueueBarcodeFrame\(frame\)/);
});

test("reconhece apenas o leitor direto e o gateway de publicação do Docker", () => {
  const routes = "Iface Destination Gateway Flags RefCnt Use Metric Mask\net0 00000000 010012AC 0003 0 0 0 00000000\net0 000012AC 00000000 0001 0 0 0 0000FFFF";
  assert.equal(parseLinuxDefaultGateway(routes), "172.18.0.1");
  assert.equal(parseLinuxDefaultGateway("Iface Destination Gateway Flags\net0 00000000 00000000 0001"), null);
  assert.equal(parseLinuxDefaultGateway("Iface Destination Gateway Flags\net0 00000000 0101A8C0 0003"), null);
  assert.equal(isAllowedSourceAddress("::ffff:172.18.0.1", ["192.168.1.30", "172.18.0.1"]), true);
  assert.equal(isAllowedSourceAddress("192.168.1.30", ["192.168.1.30", "172.18.0.1"]), true);
  assert.equal(isAllowedSourceAddress("192.168.1.31", ["192.168.1.30", "172.18.0.1"]), false);
  assert.match(worker, /parseLinuxDefaultGateway\(await readFile\("\/proc\/net\/route", "utf8"\)\)/);
  assert.match(worker, /tcpAllowedAddresses = \[\.\.\.new Set\(\[TCP_ALLOWED_ADDRESS, dockerGateway\]/);
  assert.match(compose, /TRACE_SCANNER_NO_READ_TIMEOUT_MS: \$\{TRACE_SCANNER_NO_READ_TIMEOUT_MS:-1500\}/);
  assert.match(worker, /SCAN_ASSOCIATION_WAIT_MS = Math\.max\(100, SCANNER_NO_READ_TIMEOUT_MS - 250\)/);
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
  assert.match(sensorEventsEndpoint, /COUNT\(\*\) FROM eventos_sensor/);
  assert.match(sensorEventsEndpoint, /detectedBags >= \$plannedQuantity/);
  assert.match(sensorEventsEndpoint, /SET state = 'FINALIZANDO'/);
  assert.match(readingsEndpoint, /!in_array\(\$loading\["state"\], \["CARREGANDO", "FINALIZANDO"\], true\)/);
  assert.match(readingsEndpoint, /"Leitura aguardando o evento de passagem do sensor M2052\."/);
  assert.match(readingsEndpoint, /if \(\$result === "PRODUTO_INCORRETO"\)[\s\S]*?PAUSAR_CARREGAMENTO/);
  assert.match(manifestEndpoint, /\$action === "finish"/);
  assert.match(manifestEndpoint, /UPDATE romaneios SET status = 'FINALIZADO'/);
  assert.match(manifestEndpoint, /status IN \('PENDENTE', 'CAPTURANDO'\)/);
  assert.doesNotMatch(readingsEndpoint, /CAMERA_CAPTURA|camera_status/);
  assert.match(worker, /KEYENCE_TCP_ALLOWED_ADDRESS/);
  assert.match(imageEndpoint, /r\.reason = 'SEM_LEITURA'/);
  assert.match(imageEndpoint, /KEYENCE_IMAGEM_EVIDENCIA_RECEBIDA/);
  assert.match(imageEndpoint, /captured_at_epoch_ms/);
  assert.match(imageEndpoint, /sensor_detected_at_epoch_ms/);
  assert.match(imageEndpoint, /source_file_name/);
  assert.match(reportEndpoint, /FROM imagens i[\s\S]*?WHERE i\.carregamento_id IN \(\{\$placeholders\}\) AND i\.reason <> 'NORMAL'/);
  assert.match(reportEndpoint, /Sacas detectadas pelo sensor M2052/);
  assert.match(reportEndpoint, /RelatorioAuditoriaPdf/);
  assert.match(reportEndpoint, /COALESCE\(l\.sensor_event_id, r\.sensor_event_id\) AS sensor_event_id/);
  assert.match(reportEndpoint, /Falha de leitura · \{\$sackReference\} · \{\$capturedAt\}/);
  assert.match(pdfGenerator, /\$channels === 1 \? 1 : \(\$channels === 4 \? 4 : 3\)/);
  assert.match(pdfGenerator, /\$colors === 1 \? "\/DeviceGray"/);
  assert.match(pdfGenerator, /\$maxWidth = 360\.0/);
  assert.match(pdfGenerator, /\$maxHeight = 270\.0/);
  assert.match(reportEndpoint, /inserirDadosDaImagemDaOcorrencia/);
  assert.match(reportEndpoint, /trace_cleanup_report_evidence/);
  assert.match(reportEndpoint, /solicitacoes_imagens_evidencia/);
  assert.match(reportEndpoint, /\(\?:capture\|keyence\)-\[0-9\]/);
  assert.doesNotMatch(reportEndpoint, /camera-pc\/request-/);
  assert.doesNotMatch(worker, /câmera Trace|contingência RTSP/i);
  assert.match(compose, /keyence-worker:[\s\S]*?profiles: \["industrial"\]/);
  assert.match(compose, /KEYENCE_FTP_PASSIVE_START/);
  assert.match(compose, /KEYENCE_IMAGE_ARCHIVE_HOST_DIR:-C:\/Keyence\/Falhas/);
  assert.match(compose, /target: \/var\/lib\/trace\/keyence-falhas\s+read_only: true/);
  assert.match(compose, /health\?\.running === true && \(health\?\.enabled === false \|\| \(health\?\.tcp_output_listening === true && health\?\.ftp_image_receiver_listening === true && health\?\.image_archive_directory_available === true\)\)/);
});
