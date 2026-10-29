import { createServer as createHttpServer } from "node:http";
import { createConnection as createTcpConnection, createServer as createTcpServer, isIP } from "node:net";
import { mkdir, readFile, readdir, rename, unlink, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { createFtpReceiver } from "./ftp-receiver.mjs";
import { groupImageFrames, isAllowedSourceAddress, isKeyenceNoReadFrame, matchPendingImage, nextUnreservedSensorEventId, parseBarcodeFrame, parseLinuxDefaultGateway, scanEventId, sha256 } from "./keyence-core.mjs";
import { importArchivedImages } from "./image-archive.mjs";

const SERVICE_NAME = "keyence-worker";
const boundedInteger = (rawValue, fallback, minimum, maximum) => {
  const value = Number.parseInt(rawValue || "", 10);
  return Number.isInteger(value) && value >= minimum && value <= maximum ? value : fallback;
};
const ENABLED = process.env.KEYENCE_ENABLED === "1";
const API_BASE = (process.env.TRACE_API_URL || "http://nginx").replace(/\/+$/, "");
const DEVICE_TOKEN = process.env.KEYENCE_DEVICE_TOKEN || "";
const TCP_PORT = boundedInteger(process.env.KEYENCE_TCP_PORT, 19000, 1, 65535);
const READER_PROBE_PORT = boundedInteger(process.env.KEYENCE_READER_PROBE_PORT, 80, 1, 65535);
const READER_PROBE_INTERVAL_MS = 10000;
const FTP_CONTROL_PORT = boundedInteger(process.env.KEYENCE_FTP_PORT, 2121, 1, 65535);
const FTP_PASSIVE_START = boundedInteger(process.env.KEYENCE_FTP_PASSIVE_START, 21100, 1024, 65535);
const FTP_PASSIVE_END = boundedInteger(process.env.KEYENCE_FTP_PASSIVE_END, 21109, FTP_PASSIVE_START, 65535);
const FTP_USER = process.env.KEYENCE_FTP_USER || "trace-keyence";
const FTP_PASSWORD = process.env.KEYENCE_FTP_PASSWORD || "";
const FTP_PASV_ADDRESS = process.env.KEYENCE_FTP_PASV_ADDRESS || "";
const FTP_ALLOWED_ADDRESS = process.env.KEYENCE_FTP_ALLOWED_ADDRESS || "";
const TCP_ALLOWED_ADDRESS = process.env.KEYENCE_TCP_ALLOWED_ADDRESS || "";
const INBOX_DIR = process.env.KEYENCE_INBOX_DIR || "/var/lib/trace/keyence-inbox";
const IMAGE_ARCHIVE_DIR = process.env.KEYENCE_IMAGE_ARCHIVE_DIR || "";
const SCAN_QUEUE_DIR = join(INBOX_DIR, "scans");
const FAILED_SCAN_DIR = join(INBOX_DIR, "failed-scans");
const TCP_BIND_ADDRESS = process.env.KEYENCE_SERVICE_BIND || "0.0.0.0";
const IMAGE_MATCH_WINDOW_MS = boundedInteger(process.env.KEYENCE_IMAGE_MATCH_WINDOW_MS, 3000, 1000, 60000);
const IMAGE_ARCHIVE_MATCH_WINDOW_MS = boundedInteger(process.env.KEYENCE_IMAGE_ARCHIVE_MATCH_WINDOW_MS, 1500, 500, 10000);
const IMAGE_SETTLE_MS = boundedInteger(process.env.KEYENCE_IMAGE_SETTLE_MS, 800, 0, 10000);
const IMAGE_BURST_WINDOW_MS = boundedInteger(process.env.KEYENCE_IMAGE_BURST_WINDOW_MS, 100, 0, 1000);
const IMAGE_ARCHIVE_POLL_MS = boundedInteger(process.env.KEYENCE_IMAGE_ARCHIVE_POLL_MS, 2000, 500, 10000);
const IMAGE_MAX_AGE_MS = boundedInteger(process.env.KEYENCE_IMAGE_MAX_AGE_MS, 300000, 30000, 86400000);
const POLL_MS = boundedInteger(process.env.KEYENCE_POLL_MS, 500, 100, 5000);
const HEARTBEAT_MS = boundedInteger(process.env.KEYENCE_HEARTBEAT_MS, 5000, 1000, 60000);
const SCANNER_NO_READ_TIMEOUT_MS = boundedInteger(process.env.TRACE_SCANNER_NO_READ_TIMEOUT_MS, 1500, 500, 10000);
const SCAN_ASSOCIATION_WAIT_MS = Math.max(100, SCANNER_NO_READ_TIMEOUT_MS - 250);
const MAX_SCAN_BYTES = 2048;

let shuttingDown = false;
let tcpReachable = false;
let ftpReachable = false;
let readerNetworkStatus = "DESCONHECIDO";
let readerProbeCheckedAt = null;
let readerProbeAttemptedAt = 0;
let readerProbeFailures = 0;
let readerLastReadAt = null;
let readerLastReadAtMs = null;
let activeLoading = null;
let activeLoadingCheckedAt = 0;
let lastError = "";
let unmatchedImageCount = 0;
let imageArchiveAvailable = null;
let lastImageArchivePollAt = 0;
const loggedAmbiguousImageGroups = new Set();
let pollTimer = null;
let processingImages = false;
let scanQueuePromise = null;
let scanFrameQueue = Promise.resolve();
const reservedSensorEventIds = new Set();
let ftpReceiver = null;
let tcpServer = null;
let healthServer = null;

function log(level, message, details = {}) {
  const entry = { timestamp: new Date().toISOString(), service: SERVICE_NAME, message, ...details };
  const output = JSON.stringify(entry);
  if (level === "error") console.error(output);
  else if (level === "warn") console.warn(output);
  else console.info(output);
}

function setError(error) {
  lastError = String(error instanceof Error ? error.message : error || "erro inesperado").slice(0, 240);
}

function apiUrl(path) {
  return new URL(path, API_BASE + "/");
}

async function apiRequest(path, { jsonBody = null, formBody = null, timeoutMs = 10000, idempotencyKey = "" } = {}) {
  const headers = { "X-Device-Token": DEVICE_TOKEN };
  if (idempotencyKey) headers["X-Trace-Offline-Id"] = idempotencyKey;
  let body;
  if (formBody) {
    body = formBody;
  } else {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(jsonBody ?? {});
  }
  const response = await fetch(apiUrl(path), {
    method: "POST",
    headers,
    body,
    signal: AbortSignal.timeout(timeoutMs),
  });
  const text = await response.text();
  let data = null;
  if (text) {
    try { data = JSON.parse(text); } catch { throw new Error("Trace devolveu uma resposta inválida em " + path); }
  }
  if (!response.ok) {
    const error = new Error(data?.error || "Trace respondeu HTTP " + response.status + " em " + path);
    error.status = response.status;
    throw error;
  }
  return data;
}

async function refreshActiveLoading() {
  const result = await apiRequest("/api/keyence_worker.php", { jsonBody: { action: "STATUS" } });
  const data = result?.data || {};
  const pendingSensorEventIds = Array.isArray(data.pending_sensor_event_ids)
    ? data.pending_sensor_event_ids.map(Number).filter((id) => Number.isSafeInteger(id) && id > 0)
    : Number(data.pending_sensor_event_id) > 0 ? [Number(data.pending_sensor_event_id)] : [];
  const pendingSensorEventSet = new Set(pendingSensorEventIds);
  for (const eventId of reservedSensorEventIds) {
    if (!pendingSensorEventSet.has(eventId)) reservedSensorEventIds.delete(eventId);
  }
  activeLoading = Number(data.active_loading_id) > 0
    ? {
      id: Number(data.active_loading_id),
      sensorEventIds: pendingSensorEventIds,
    }
    : null;
  activeLoadingCheckedAt = Date.now();
  tcpReachable = true;
  lastError = "";
  return activeLoading;
}

async function queueBarcode(rawFrame) {
  const decodedValue = parseBarcodeFrame(rawFrame);
  if (!decodedValue) {
    log("warn", "Quadro TCP ignorado: código vazio, longo ou com caracteres de controle.");
    return;
  }
  const noRead = isKeyenceNoReadFrame(decodedValue);
  const barcode = noRead ? "" : decodedValue;
  readerLastReadAt = new Date().toISOString();
  readerLastReadAtMs = Date.now();
  let loading = null;
  let sensorEventId = null;
  try {
    const associationDeadline = Date.now() + SCAN_ASSOCIATION_WAIT_MS;
    do {
      loading = await refreshActiveLoading();
      sensorEventId = loading
        ? nextUnreservedSensorEventId(loading.sensorEventIds, reservedSensorEventIds)
        : null;
      if (!loading || sensorEventId || Date.now() >= associationDeadline) break;
      await new Promise((resolve) => setTimeout(resolve, Math.min(100, associationDeadline - Date.now())));
    } while (Date.now() <= associationDeadline);
  } catch (error) {
    setError(error);
    if (Date.now() - activeLoadingCheckedAt > 5000) {
      log("error", "Código recebido sem estado operacional recente; leitura não foi associada.", { reason: lastError });
      return;
    }
    loading = activeLoading;
  }
  if (!loading) {
    log("warn", "Código Keyence recebido sem carregamento ativo; leitura ignorada.");
    return;
  }
  if (!sensorEventId) {
    log("warn", "Código Keyence recebido sem passagem disponível; leitura ignorada.", { loading_id: loading.id });
    return;
  }
  const event = {
    offline_id: scanEventId(),
    barcode,
    no_read: noRead,
    loading_id: loading.id,
    sensor_event_id: sensorEventId,
    received_at: new Date().toISOString(),
  };
  await mkdir(SCAN_QUEUE_DIR, { recursive: true, mode: 0o770 });
  const file = join(SCAN_QUEUE_DIR, `${Date.now()}-${event.offline_id}.json`);
  const temporary = `${file}.tmp`;
  await writeFile(temporary, JSON.stringify(event), { flag: "wx", mode: 0o600 });
  await rename(temporary, file);
  reservedSensorEventIds.add(sensorEventId);
  log("info", "Código recebido do Keyence e enfileirado para o Trace.", {
    loading_id: event.loading_id,
    sensor_event_id: event.sensor_event_id,
    no_read: noRead,
    barcode_length: Buffer.byteLength(barcode, "utf8"),
  });
  void processScanQueue().catch((error) => {
    setError(error);
    log("error", "Falha ao processar a fila local de códigos do Keyence.", { reason: lastError });
  });
}

async function loadScanQueueReservations() {
  const files = (await readdir(SCAN_QUEUE_DIR)).filter((name) => name.endsWith(".json"));
  for (const name of files) {
    try {
      const event = JSON.parse(await readFile(join(SCAN_QUEUE_DIR, name), "utf8"));
      const eventId = Number(event.sensor_event_id);
      if (Number.isSafeInteger(eventId) && eventId > 0) reservedSensorEventIds.add(eventId);
    } catch {
      // processScanQueue registra e move arquivos inválidos para a fila de falhas.
    }
  }
}

function processScanQueue() {
  if (shuttingDown || !DEVICE_TOKEN) return Promise.resolve();
  if (scanQueuePromise) return scanQueuePromise;
  scanQueuePromise = (async () => {
    try {
      await mkdir(SCAN_QUEUE_DIR, { recursive: true, mode: 0o770 });
      await mkdir(FAILED_SCAN_DIR, { recursive: true, mode: 0o770 });
      const files = (await readdir(SCAN_QUEUE_DIR)).filter((name) => name.endsWith(".json")).sort();
      for (const name of files) {
        const file = join(SCAN_QUEUE_DIR, name);
        let event;
        try { event = JSON.parse(await readFile(file, "utf8")); }
        catch (error) {
          setError(error);
          await rename(file, join(FAILED_SCAN_DIR, name + ".invalid"));
          continue;
        }
        try {
          const result = await apiRequest("/api/leituras.php", {
            jsonBody: {
              carregamento_id: Number(event.loading_id),
              sensor_event_id: Number(event.sensor_event_id) || null,
              barcode: String(event.barcode),
              no_read: event.no_read === true,
            },
            timeoutMs: 10000,
            idempotencyKey: String(event.offline_id),
          });
          if (!result?.data?.id) throw new Error("Trace não confirmou o registro da leitura.");
          await unlink(file);
          reservedSensorEventIds.delete(Number(event.sensor_event_id));
          log("info", "Leitura Keyence registrada no Trace.", {
            reading_id: result.data.id,
            loading_id: Number(event.loading_id),
            result: result.data.result,
            duplicate: Boolean(result.data.duplicate),
          });
        } catch (error) {
          if (Number(error.status) >= 400 && Number(error.status) < 500 && error.status !== 429) {
            setError(error);
            await rename(file, join(FAILED_SCAN_DIR, name));
            log("error", "Leitura Keyence rejeitada pelo Trace e movida para a fila de falhas.", {
              loading_id: Number(event.loading_id),
              http_status: error.status,
              reason: lastError,
            });
            continue;
          }
          setError(error);
          break;
        }
      }
    } finally {
      scanQueuePromise = null;
    }
  })();
  return scanQueuePromise;
}

function enqueueBarcodeFrame(rawFrame) {
  scanFrameQueue = scanFrameQueue.then(() => queueBarcode(rawFrame)).catch((error) => {
    setError(error);
    log("error", "Falha ao associar um quadro TCP do Keyence.", { reason: lastError });
  });
  return scanFrameQueue;
}

async function pendingImages() {
  const result = await apiRequest("/api/keyence_worker.php", { jsonBody: { action: "PENDING_IMAGES" } });
  return Array.isArray(result?.data) ? result.data : [];
}

async function uploadImage(file, request, capturedAtEpochMs) {
  const bytes = await readFile(file);
  const form = new FormData();
  form.append("action", "UPLOAD_IMAGE");
  form.append("request_id", String(request.id));
  form.append("captured_at_epoch_ms", String(Math.round(capturedAtEpochMs)));
  const sourceName = file.split(/[\\/]/).at(-1)?.match(/-archive-([A-Za-z0-9._-]{1,80})$/i)?.[1];
  if (sourceName) form.append("source_file_name", sourceName);
  form.append("file", new Blob([bytes], { type: "image/jpeg" }), "keyence-evidence.jpg");
  const result = await apiRequest("/api/keyence_worker.php", { formBody: form, timeoutMs: 20000 });
  if (!result?.data?.request_id) throw new Error("Trace não confirmou a imagem Keyence.");
  await unlink(file);
  log("info", "Imagem Keyence vinculada à falha de leitura.", {
    request_id: Number(request.id),
    sensor_event_id: Number(request.sensor_event_id),
    ...(sourceName ? { source_file_name: sourceName } : {}),
    captured_at_epoch_ms: Math.round(capturedAtEpochMs),
    duplicate: Boolean(result.data.duplicate),
  });
}

async function removeImageGroup(group, keepName = "") {
  for (const frame of group.frames) {
    if (frame.name === keepName) continue;
    try {
      await unlink(join(INBOX_DIR, frame.name));
      unmatchedImageCount = Math.max(0, unmatchedImageCount - 1);
    } catch (error) {
      if (error.code !== "ENOENT") throw error;
    }
  }
}

async function processImageQueue() {
  if (processingImages || shuttingDown || !ftpReachable || !DEVICE_TOKEN) return;
  processingImages = true;
  try {
    if (IMAGE_ARCHIVE_DIR && Date.now() - lastImageArchivePollAt >= IMAGE_ARCHIVE_POLL_MS) {
      lastImageArchivePollAt = Date.now();
      const archiveResult = await importArchivedImages({
        sourceDirectory: IMAGE_ARCHIVE_DIR,
        inboxDirectory: INBOX_DIR,
        now: lastImageArchivePollAt,
        settleMs: IMAGE_SETTLE_MS,
        maxAgeMs: IMAGE_MAX_AGE_MS,
        log,
      });
      if (archiveResult.available !== imageArchiveAvailable) {
        imageArchiveAvailable = archiveResult.available;
        log(imageArchiveAvailable ? "info" : "warn", imageArchiveAvailable
          ? "Pasta local de imagens Keyence está montada no Trace."
          : "Pasta local de imagens Keyence não está acessível ao Trace.");
      }
    }

    let names = (await readdir(INBOX_DIR))
      .filter((name) => /^\d{13}--[a-f0-9]{64}-[a-f0-9]{8}(?:-archive(?:-[A-Za-z0-9._-]{1,80})?)?\.jpg$/i.test(name))
      .sort();
    for (const name of names) {
      const receivedAt = Number(name.slice(0, 13));
      if (Number.isFinite(receivedAt) && Date.now() - receivedAt > IMAGE_MAX_AGE_MS) {
        await unlink(join(INBOX_DIR, name));
        log("warn", "Imagem Keyence sem evento compatível foi removida após o prazo de associação.", {
          sha256: name.slice(15, 79),
        });
      }
    }
    names = names.filter((name) => Date.now() - Number(name.slice(0, 13)) <= IMAGE_MAX_AGE_MS);
    unmatchedImageCount = names.length;
    if (names.length === 0) return;
    const requests = await pendingImages();
    const reserved = new Set();
    const groups = groupImageFrames(names.map((name) => ({
      name,
      receivedAt: Number(name.slice(0, 13)),
      archived: /-archive(?:-|\.jpg$)/i.test(name),
    })), IMAGE_BURST_WINDOW_MS);
    for (const group of groups) {
      const receivedAt = Number(group.representative.receivedAt);
      if (!Number.isFinite(receivedAt)) continue;
      if (group.frames.some((frame) => Date.now() - Number(frame.receivedAt) < IMAGE_SETTLE_MS)) continue;
      const timestampField = group.frames.some((frame) => frame.archived)
        ? "sensor_detected_at_epoch_ms"
        : "requested_at_epoch_ms";
      const matchWindowMs = timestampField === "sensor_detected_at_epoch_ms"
        ? IMAGE_ARCHIVE_MATCH_WINDOW_MS
        : IMAGE_MATCH_WINDOW_MS;
      const match = matchPendingImage(receivedAt, requests, matchWindowMs, timestampField);
      const groupKey = group.frames.map((frame) => frame.name).join("|");
      if (!match.request) {
        if (match.reason === "solicitacoes_ambiguas") {
          if (!loggedAmbiguousImageGroups.has(groupKey)) {
            loggedAmbiguousImageGroups.add(groupKey);
            log("warn", "Imagem Keyence ambígua; preservada na fila sem anexar a uma falha incorreta.", {
              image_count: group.frames.length,
              candidate_requests: requests.filter((request) => {
                const timestamp = Number(request[timestampField]);
                return Number.isFinite(timestamp) && Math.abs(receivedAt - timestamp) <= matchWindowMs;
              }).length,
            });
          }
        } else if (Date.now() - receivedAt > matchWindowMs + IMAGE_SETTLE_MS) {
          await removeImageGroup(group);
          loggedAmbiguousImageGroups.delete(groupKey);
          log("warn", "Imagem Keyence sem solicitação compatível; o arquivo foi descartado.", {
            image_count: group.frames.length,
            sha256: group.representative.name.slice(15, 79),
          });
        }
        continue;
      }
      if (match.request.status === "CAPTURADA") {
        await removeImageGroup(group);
        loggedAmbiguousImageGroups.delete(groupKey);
        log("info", "Imagem Keyence repetida descartada; esta passagem já tem evidência.", {
          sensor_event_id: Number(match.request.sensor_event_id),
          image_count: group.frames.length,
        });
        continue;
      }
      if (reserved.has(Number(match.request.id))) {
        await removeImageGroup(group);
        log("info", "Quadro adicional da mesma passagem descartado após selecionar uma evidência.", {
          sensor_event_id: Number(match.request.sensor_event_id),
          image_count: group.frames.length,
        });
        continue;
      }
      reserved.add(Number(match.request.id));
      try {
        await uploadImage(join(INBOX_DIR, group.representative.name), match.request, receivedAt);
        unmatchedImageCount = Math.max(0, unmatchedImageCount - 1);
        await removeImageGroup(group, group.representative.name);
        loggedAmbiguousImageGroups.delete(groupKey);
      } catch (error) {
        if (error.status === 404 || error.status === 409) {
          // Outro processo pode ter reservado a mesma solicitação durante o envio.
          // Deixe o grupo na fila até o próximo ciclo, quando o estado ficará claro.
          continue;
        }
        setError(error);
        if (Number(error.status) >= 400 && Number(error.status) < 500 && error.status !== 429) {
          await removeImageGroup(group);
          log("error", "Imagem Keyence rejeitada pela API e descartada.", {
            request_id: Number(match.request.id),
            http_status: error.status,
            reason: lastError,
          });
        }
        break;
      }
    }
  } catch (error) {
    setError(error);
  } finally {
    processingImages = false;
  }
}

async function publishHeartbeat() {
  const archiveUnavailable = Boolean(IMAGE_ARCHIVE_DIR) && imageArchiveAvailable === false;
  const status = lastError || archiveUnavailable ? "ERRO" : "ONLINE";
  await apiRequest("/api/device_heartbeat.php", {
    jsonBody: {
      device_type: "SCANNER",
      status,
      details: {
        source: "keyence-worker",
        model: "SR-X100W",
        tcp_output_listening: tcpReachable,
        ftp_image_receiver_listening: ftpReachable,
        reader_network_status: readerNetworkStatus,
        reader_probe_port: READER_PROBE_PORT,
        reader_probe_checked_at: readerProbeCheckedAt,
        reader_last_read_at: readerLastReadAt,
        reader_last_read_age_ms: readerLastReadAtMs === null ? null : Math.max(0, Date.now() - readerLastReadAtMs),
        active_loading: Boolean(activeLoading),
        unmatched_image_count: unmatchedImageCount,
        image_archive_directory_available: IMAGE_ARCHIVE_DIR ? imageArchiveAvailable : null,
        ...(archiveUnavailable ? { reason_code: "keyence_image_archive_unavailable" } : {}),
        ...(lastError ? { reason_code: "keyence_worker_error", error: lastError } : {}),
      },
    },
  });
}

function probeReaderNetwork() {
  return new Promise((resolve) => {
    let settled = false;
    let socket = null;
    const finish = (reachable) => {
      if (settled) return;
      settled = true;
      readerProbeCheckedAt = new Date().toISOString();
      if (reachable) {
        readerNetworkStatus = "ONLINE";
        readerProbeFailures = 0;
      } else {
        readerProbeFailures += 1;
        if (readerProbeFailures >= 2) readerNetworkStatus = "OFFLINE";
      }
      socket?.destroy();
      resolve(readerNetworkStatus);
    };

    try {
      socket = createTcpConnection({ host: TCP_ALLOWED_ADDRESS, port: READER_PROBE_PORT });
      socket.setTimeout(1500);
      socket.once("connect", () => finish(true));
      socket.once("timeout", () => finish(false));
      socket.once("error", () => finish(false));
      socket.once("close", () => { if (!settled) finish(false); });
    } catch {
      finish(false);
    }
  });
}

async function refreshReaderNetworkStatus() {
  if (Date.now() - readerProbeAttemptedAt < READER_PROBE_INTERVAL_MS) return;
  readerProbeAttemptedAt = Date.now();
  await probeReaderNetwork();
}

function startTcpReceiver(allowedAddresses) {
  tcpServer = createTcpServer((socket) => {
    const remote = String(socket.remoteAddress || "").replace(/^::ffff:/, "");
    if (!isAllowedSourceAddress(remote, allowedAddresses)) {
      log("warn", "Conexão TCP Keyence rejeitada por endereço de origem.", { remote_address: remote });
      socket.destroy();
      return;
    }
    socket.setEncoding("utf8");
    socket.setTimeout(30000, () => socket.destroy());
    let buffer = "";
    socket.on("data", (chunk) => {
      buffer += chunk;
      if (Buffer.byteLength(buffer, "utf8") > MAX_SCAN_BYTES) {
        log("warn", "Quadro TCP Keyence acima do limite; conexão encerrada.");
        socket.destroy();
        return;
      }
      const frames = buffer.split(/[\r\n]+/);
      buffer = frames.pop() || "";
      for (const frame of frames) {
        if (!frame) continue;
        void enqueueBarcodeFrame(frame);
      }
    });
    socket.on("close", () => {
      const finalFrame = buffer;
      buffer = "";
      if (finalFrame) void enqueueBarcodeFrame(finalFrame);
    });
    socket.on("error", (error) => log("warn", "Conexão TCP do Keyence encerrada com erro.", { reason: error.message }));
  });
  return new Promise((resolve, reject) => {
    tcpServer.once("error", reject);
    tcpServer.listen(TCP_PORT, TCP_BIND_ADDRESS, () => {
      tcpServer.removeListener("error", reject);
      tcpReachable = true;
      resolve();
    });
  });
}

async function start() {
  healthServer = createHttpServer((request, response) => {
    if (request.url !== "/health") {
      response.writeHead(404);
      response.end();
      return;
    }
    response.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
    response.end(JSON.stringify({
      service: SERVICE_NAME,
      running: true,
      enabled: ENABLED,
      tcp_output_listening: tcpReachable,
      ftp_image_receiver_listening: ftpReachable,
      reader_network_status: readerNetworkStatus,
      reader_probe_port: READER_PROBE_PORT,
      reader_probe_checked_at: readerProbeCheckedAt,
      reader_last_read_at: readerLastReadAt,
      reader_last_read_age_ms: readerLastReadAtMs === null ? null : Math.max(0, Date.now() - readerLastReadAtMs),
      unmatched_image_count: unmatchedImageCount,
      image_archive_directory_available: IMAGE_ARCHIVE_DIR ? imageArchiveAvailable : null,
      scan_queue_directory: SCAN_QUEUE_DIR,
    }));
  });
  healthServer.listen(8091, "0.0.0.0");

  if (!ENABLED) {
    log("info", "Worker Keyence iniciado em modo inativo. Ative KEYENCE_ENABLED para abrir as portas do leitor.");
    return;
  }
  if (!DEVICE_TOKEN || !FTP_PASSWORD || isIP(FTP_PASV_ADDRESS) !== 4
    || isIP(FTP_ALLOWED_ADDRESS) !== 4 || isIP(TCP_ALLOWED_ADDRESS) !== 4) {
    throw new Error("Configure o token SCANNER, a senha FTP, o IP passivo e os IPs fixos do leitor para ativar o SR-X100W.");
  }
  if (FTP_PASSIVE_START > FTP_PASSIVE_END) {
    throw new Error("A faixa passiva FTP do Keyence está invertida.");
  }
  await mkdir(INBOX_DIR, { recursive: true, mode: 0o770 });
  await mkdir(SCAN_QUEUE_DIR, { recursive: true, mode: 0o770 });
  await mkdir(FAILED_SCAN_DIR, { recursive: true, mode: 0o770 });
  await loadScanQueueReservations();
  let dockerGateway = null;
  try {
    dockerGateway = parseLinuxDefaultGateway(await readFile("/proc/net/route", "utf8"));
  } catch (error) {
    log("warn", "Não foi possível identificar o gateway do Docker; somente o IP direto do leitor será aceito.", {
      reason: String(error?.message || error).slice(0, 180),
    });
  }
  // No Docker Desktop do Windows, a publicação de portas troca o IP do leitor
  // pelo gateway do bridge. O Firewall do Windows limita a origem externa ao
  // IP fixo do leitor antes de encaminhar a conexão para este container.
  const tcpAllowedAddresses = [...new Set([TCP_ALLOWED_ADDRESS, dockerGateway].filter(Boolean))];
  const ftpAllowedAddresses = [...new Set([FTP_ALLOWED_ADDRESS, dockerGateway].filter(Boolean))];
  await startTcpReceiver(tcpAllowedAddresses);
  ftpReceiver = createFtpReceiver({
    bindAddress: TCP_BIND_ADDRESS,
    port: FTP_CONTROL_PORT,
    passiveStart: FTP_PASSIVE_START,
    passiveEnd: FTP_PASSIVE_END,
    advertisedAddress: FTP_PASV_ADDRESS,
    username: FTP_USER,
    password: FTP_PASSWORD,
    allowedAddresses: ftpAllowedAddresses,
    inboxDirectory: INBOX_DIR,
    onImage: () => void processImageQueue(),
    log,
  });
  await ftpReceiver.listen();
  ftpReachable = true;
  log("info", "Entrada TCP e recepção FTP do Keyence ativas.", {
    tcp_port: TCP_PORT,
    reader_probe_port: READER_PROBE_PORT,
    ftp_port: FTP_CONTROL_PORT,
    passive_ports: [FTP_PASSIVE_START, FTP_PASSIVE_END],
    ftp_source_allowlist: FTP_ALLOWED_ADDRESS || "não configurada",
    tcp_source_allowlist: TCP_ALLOWED_ADDRESS,
    docker_gateway_ingress: dockerGateway,
  });

  let lastHeartbeatAt = 0;
  await refreshReaderNetworkStatus();
  pollTimer = setInterval(async () => {
    if (shuttingDown) return;
    try {
      await refreshReaderNetworkStatus();
      await refreshActiveLoading();
      await processScanQueue();
      await processImageQueue();
    } catch (error) {
      setError(error);
    }
    if (Date.now() - lastHeartbeatAt >= HEARTBEAT_MS) {
      lastHeartbeatAt = Date.now();
      try { await publishHeartbeat(); }
      catch (error) { setError(error); log("warn", "Heartbeat do Keyence não foi aceito pelo Trace.", { reason: lastError }); }
    }
  }, POLL_MS);
  pollTimer.unref();
  await refreshActiveLoading().catch((error) => setError(error));
  await processScanQueue();
  await processImageQueue();
}

try {
  await start();
} catch (error) {
  log("error", "Worker Keyence não iniciou.", { reason: error instanceof Error ? error.message : String(error) });
  tcpServer?.close();
  ftpReceiver?.close();
  healthServer?.close();
  process.exitCode = 1;
}

function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log("info", "Encerrando worker Keyence.", { signal });
  if (pollTimer) clearInterval(pollTimer);
  if (tcpServer) tcpServer.close();
  ftpReceiver?.close();
  healthServer?.close();
}

process.on("SIGTERM", () => shutdown("SIGTERM"));
process.on("SIGINT", () => shutdown("SIGINT"));
