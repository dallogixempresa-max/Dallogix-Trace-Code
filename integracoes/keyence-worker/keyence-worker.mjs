import { randomUUID } from "node:crypto";
import { createServer as createHttpServer } from "node:http";
import { execFile as execFileCallback } from "node:child_process";
import { createServer as createTcpServer, isIP } from "node:net";
import { link, mkdir, readFile, readdir, rename, unlink, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { promisify } from "node:util";
import { createFtpReceiver } from "./ftp-receiver.mjs";
import { isAllowedSourceAddress, isKeyenceNoReadFrame, matchUniquePendingImage, nextUnreservedSensorEventId, parseBarcodeFrame, parseLinuxDefaultGateway, scanEventId, sha256 } from "./keyence-core.mjs";
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
const READER_PROBE_INTERVAL_MS = 5000;
const FTP_CONTROL_PORT = boundedInteger(process.env.KEYENCE_FTP_PORT, 2121, 1, 65535);
const FTP_PASSIVE_START = boundedInteger(process.env.KEYENCE_FTP_PASSIVE_START, 21100, 1024, 65535);
const FTP_PASSIVE_END = boundedInteger(process.env.KEYENCE_FTP_PASSIVE_END, 21109, FTP_PASSIVE_START, 65535);
const FTP_MINIMUM_FREE_BYTES = boundedInteger(process.env.KEYENCE_FTP_MINIMUM_FREE_BYTES, 268435456, 0, 2147483648);
const FTP_USER = process.env.KEYENCE_FTP_USER || "trace-keyence";
const FTP_PASSWORD = process.env.KEYENCE_FTP_PASSWORD || "";
const FTP_PASV_ADDRESS = process.env.KEYENCE_FTP_PASV_ADDRESS || "";
const FTP_ALLOWED_ADDRESS = process.env.KEYENCE_FTP_ALLOWED_ADDRESS || "";
const TCP_ALLOWED_ADDRESS = process.env.KEYENCE_TCP_ALLOWED_ADDRESS || "";
const INBOX_DIR = process.env.KEYENCE_INBOX_DIR || "/var/lib/trace/keyence-inbox";
const IMAGE_ARCHIVE_DIR = process.env.KEYENCE_IMAGE_ARCHIVE_DIR || "";
const SCAN_QUEUE_DIR = join(INBOX_DIR, "scans");
const FAILED_SCAN_DIR = join(INBOX_DIR, "failed-scans");
const QUARANTINE_DIR = join(INBOX_DIR, "quarantine");
const TCP_BIND_ADDRESS = process.env.KEYENCE_SERVICE_BIND || "0.0.0.0";
const IMAGE_MATCH_WINDOW_MS = boundedInteger(process.env.KEYENCE_IMAGE_MATCH_WINDOW_MS, 3000, 1000, 60000);
const IMAGE_UNMATCHED_GRACE_MS = boundedInteger(process.env.KEYENCE_IMAGE_UNMATCHED_GRACE_MS, 300000, 5000, 86400000);
const IMAGE_ARCHIVE_MATCH_WINDOW_MS = boundedInteger(process.env.KEYENCE_IMAGE_ARCHIVE_MATCH_WINDOW_MS, 1500, 500, 10000);
const IMAGE_SETTLE_MS = boundedInteger(process.env.KEYENCE_IMAGE_SETTLE_MS, 800, 0, 10000);
const IMAGE_PROCESS_POLL_MS = 1000;
const IMAGE_ARCHIVE_POLL_MS = boundedInteger(process.env.KEYENCE_IMAGE_ARCHIVE_POLL_MS, 2000, 500, 10000);
const IMAGE_MAX_AGE_MS = boundedInteger(process.env.KEYENCE_IMAGE_MAX_AGE_MS, 300000, 30000, 86400000);
const POLL_MS = boundedInteger(process.env.KEYENCE_POLL_MS, 500, 100, 5000);
const HEARTBEAT_MS = boundedInteger(process.env.KEYENCE_HEARTBEAT_MS, 5000, 1000, 60000);
const SCANNER_NO_READ_TIMEOUT_MS = boundedInteger(process.env.TRACE_SCANNER_NO_READ_TIMEOUT_MS, 1500, 500, 10000);
const SCAN_ASSOCIATION_WAIT_MS = Math.max(100, SCANNER_NO_READ_TIMEOUT_MS - 250);
const MAX_SCAN_BYTES = 2048;
const execFile = promisify(execFileCallback);

let shuttingDown = false;
let tcpReachable = false;
let ftpReachable = false;
let readerNetworkStatus = "DESCONHECIDO";
let readerProbeError = "";
let readerProbeCheckedAt = null;
let readerProbeAttemptedAt = 0;
let readerProbeFailures = 0;
let readerLastReadAt = null;
let readerLastReadAtMs = null;
let activeLoading = null;
let activeLoadingCheckedAt = 0;
let lastError = "";
let unmatchedImageCount = 0;
let oldestUnmatchedImageAgeMs = 0;
let ftpImageReceivedCount = 0;
let ftpLastImageReceivedAt = null;
let ftpTransferErrorCode = null;
let imageArchiveAvailable = null;
let lastImageArchivePollAt = 0;
let lastImageProcessAttemptAt = 0;
const loggedUnmatchedImages = new Set();
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

async function refreshActiveLoading(scannerObservedAtEpochMs = null) {
  const result = await apiRequest("/api/keyence_worker.php", {
    jsonBody: {
      action: "STATUS",
      ...(Number.isSafeInteger(scannerObservedAtEpochMs) ? { scanner_received_at_epoch_ms: scannerObservedAtEpochMs } : {}),
    },
  });
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
      loading = await refreshActiveLoading(readerLastReadAtMs);
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
    received_at_epoch_ms: readerLastReadAtMs,
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
              scanner_received_at_epoch_ms: Number(event.received_at_epoch_ms) || null,
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
  const basename = file.split(/[\\/]/).at(-1) || "";
  const sourceName = basename.match(/-archive-([A-Za-z0-9._-]{1,120})$/i)?.[1]
    || basename.match(/-ftp-([A-Za-z0-9._-]{1,120})\.jpg$/i)?.[1];
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

async function removeImageFile(name) {
  try {
    await unlink(join(INBOX_DIR, name));
    unmatchedImageCount = Math.max(0, unmatchedImageCount - 1);
  } catch (error) {
    if (error.code !== "ENOENT") throw error;
  }
}

async function quarantineImageGroup(group, reason, details = {}) {
  await mkdir(QUARANTINE_DIR, { recursive: true, mode: 0o770 });
  const quarantined = [];
  for (const frame of group.frames) {
    const source = join(INBOX_DIR, frame.name);
    const destination = join(QUARANTINE_DIR, frame.name);
    try {
      await rename(source, destination);
      quarantined.push(frame.name);
      unmatchedImageCount = Math.max(0, unmatchedImageCount - 1);
    } catch (error) {
      if (error.code !== "ENOENT") throw error;
    }
  }
  if (quarantined.length > 0) {
    await writeFile(
      join(QUARANTINE_DIR, `${quarantined[0]}.json`),
      JSON.stringify({ quarantined_at: new Date().toISOString(), reason, files: quarantined, ...details }, null, 2),
      { mode: 0o600 },
    );
    log("warn", "Imagem Keyence preservada na quarentena para revisão.", {
      reason,
      image_count: quarantined.length,
      files: quarantined,
      ...details,
    });
  }
}

async function restoreRetryableQuarantine() {
  const retryableReasons = new Set([
    "prazo_de_associacao_expirado",
    "sem_solicitacao_compativel",
    // Older releases overwrote this manifest reason with the HTTP error text.
    "Horário de captura fora da janela aceita.",
  ]);
  let manifests = [];
  try {
    manifests = (await readdir(QUARANTINE_DIR)).filter((name) => name.endsWith(".json"));
  } catch (error) {
    if (error.code === "ENOENT") return;
    throw error;
  }

  for (const manifestName of manifests) {
    const manifestPath = join(QUARANTINE_DIR, manifestName);
    let manifest;
    try {
      manifest = JSON.parse(await readFile(manifestPath, "utf8"));
    } catch (error) {
      log("error", "Manifesto de quarentena Keyence inválido; preservado sem alteração.", {
        manifest: manifestName,
        reason: String(error?.message || error).slice(0, 180),
      });
      continue;
    }
    if (!retryableReasons.has(manifest?.reason) || manifest?.requeued_at || !Array.isArray(manifest?.files)) continue;

    const restored = [];
    for (const name of manifest.files) {
      if (typeof name !== "string"
        || !/^\d{13}--[a-f0-9]{64}-[a-f0-9]{8}(?:-archive(?:-[A-Za-z0-9._-]{1,120})?|-ftp-[A-Za-z0-9._-]{1,120}\.jpg)?\.jpg$/i.test(name)) continue;
      const source = join(QUARANTINE_DIR, name);
      const destination = join(INBOX_DIR, name);
      try {
        await link(source, destination);
        restored.push(name);
        await unlink(source).catch((error) => {
          if (error.code !== "ENOENT") {
            log("warn", "Imagem Keyence recuperada, mas a cópia de quarentena foi mantida.", { image: name });
          }
        });
      } catch (error) {
        if (error.code === "ENOENT") continue;
        if (error.code === "EEXIST") {
          const [sourceBytes, destinationBytes] = await Promise.all([readFile(source), readFile(destination)]);
          if (sha256(sourceBytes) !== sha256(destinationBytes)) {
            log("error", "Conflito ao recuperar imagem Keyence; ambos os arquivos foram preservados.", { image: name });
            continue;
          }
          await unlink(source);
          restored.push(name);
          continue;
        }
        throw error;
      }
    }

    const updatedManifest = {
      ...manifest,
      requeued_at: new Date().toISOString(),
      requeued_files: restored,
    };
    const temporary = `${manifestPath}.${randomUUID()}.tmp`;
    await writeFile(temporary, JSON.stringify(updatedManifest, null, 2), { flag: "wx", mode: 0o600 });
    await rename(temporary, manifestPath);
    if (restored.length > 0) {
      log("warn", "Imagens Keyence sem associação foram recuperadas para nova tentativa.", {
        image_count: restored.length,
        original_reason: manifest.reason,
      });
    }
  }
}

async function processImageQueue() {
  if (processingImages || shuttingDown || !ftpReachable || !DEVICE_TOKEN) return;
  if (Date.now() - lastImageProcessAttemptAt < IMAGE_PROCESS_POLL_MS) return;
  lastImageProcessAttemptAt = Date.now();
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

    const names = (await readdir(INBOX_DIR))
      .filter((name) => /^\d{13}--[a-f0-9]{64}-[a-f0-9]{8}(?:-archive(?:-[A-Za-z0-9._-]{1,120})?|-ftp-[A-Za-z0-9._-]{1,120}\.jpg)?\.jpg$/i.test(name))
      .sort();
    unmatchedImageCount = names.length;
    const oldestReceivedAt = names.reduce((oldest, name) => Math.min(oldest, Number(name.slice(0, 13))), Date.now());
    oldestUnmatchedImageAgeMs = names.length > 0 ? Math.max(0, Date.now() - oldestReceivedAt) : 0;
    if (names.length === 0) return;
    const requests = await pendingImages();
    const reserved = new Set();
    const pendingRequests = requests.filter((request) => ["PENDENTE", "ERRO"].includes(request.status));
    for (const name of names) {
      const receivedAt = Number(name.slice(0, 13));
      if (!Number.isFinite(receivedAt)) continue;
      if (Date.now() - receivedAt < IMAGE_SETTLE_MS) continue;
      const archived = /-archive-/i.test(name);
      const timestampField = archived
        ? "image_expected_at_epoch_ms"
        : "requested_at_epoch_ms";
      const matchWindowMs = timestampField === "image_expected_at_epoch_ms"
        ? IMAGE_ARCHIVE_MATCH_WINDOW_MS
        : IMAGE_MATCH_WINDOW_MS;
      const match = matchUniquePendingImage(receivedAt, pendingRequests, matchWindowMs, timestampField);
      const imageHash = name.slice(15, 79).toLowerCase();
      const groupKey = name;
      if (!match.request) {
        const capturedDuplicate = match.reason === "sem_solicitacao_compativel"
          ? requests.find((request) => request.status === "CAPTURADA"
          && String(request.image_sha256 || "").toLowerCase() === imageHash
          && Number.isFinite(Number(request[timestampField]))
          && Math.abs(receivedAt - Number(request[timestampField])) <= matchWindowMs)
          : null;
        if (capturedDuplicate) {
          await removeImageFile(name);
          loggedUnmatchedImages.delete(groupKey);
          log("info", "Reenvio FTP idêntico confirmado pela impressão digital; cópia redundante removida.", {
            sensor_event_id: Number(capturedDuplicate.sensor_event_id),
          });
          continue;
        }
        const ageMs = Math.max(0, Date.now() - receivedAt);
        if (ageMs >= IMAGE_UNMATCHED_GRACE_MS && !loggedUnmatchedImages.has(groupKey)) {
          loggedUnmatchedImages.add(groupKey);
          const candidateCount = requests.filter((request) => {
            const timestamp = Number(request[timestampField]);
            return Number.isFinite(timestamp) && Math.abs(receivedAt - timestamp) <= matchWindowMs;
          }).length;
          log("warn", "Imagem Keyence mantida na fila: não há correspondência única e segura.", {
            age_ms: ageMs,
            reason: match.reason,
            candidate_requests: candidateCount,
            image_sha256: imageHash,
          });
        }
        continue;
      }
      if (reserved.has(Number(match.request.id))) {
        if (!loggedUnmatchedImages.has(groupKey)) {
          loggedUnmatchedImages.add(groupKey);
          log("warn", "Imagem Keyence mantida na fila até atualizar o estado da solicitação já reservada.", {
            sensor_event_id: Number(match.request.sensor_event_id),
          });
        }
        continue;
      }
      reserved.add(Number(match.request.id));
      try {
        await uploadImage(join(INBOX_DIR, name), match.request, receivedAt);
        unmatchedImageCount = Math.max(0, unmatchedImageCount - 1);
        loggedUnmatchedImages.delete(groupKey);
      } catch (error) {
        if (error.status === 404 || error.status === 409) {
          // Uma solicitação concorrente pode ter concluído o vínculo. Reavalie
          // no próximo ciclo; não descarte a imagem com base apenas no horário.
          continue;
        }
        setError(error);
        if (error.status === 413 || error.status === 422) {
          await quarantineImageGroup({ frames: [{ name, receivedAt }] }, "api_rejeitou_imagem", {
            request_id: Number(match.request.id),
            http_status: error.status,
            error_message: lastError,
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
  const imageQueueStale = unmatchedImageCount > 0 && oldestUnmatchedImageAgeMs >= IMAGE_UNMATCHED_GRACE_MS;
  const status = lastError || readerProbeError || archiveUnavailable || imageQueueStale || ftpTransferErrorCode ? "ERRO" : "ONLINE";
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
        reader_probe_method: "ICMP_PING",
        reader_ip: TCP_ALLOWED_ADDRESS,
        reader_probe_checked_at: readerProbeCheckedAt,
        reader_last_read_at: readerLastReadAt,
        reader_last_read_age_ms: readerLastReadAtMs === null ? null : Math.max(0, Date.now() - readerLastReadAtMs),
        active_loading: Boolean(activeLoading),
        unmatched_image_count: unmatchedImageCount,
        oldest_unmatched_image_age_ms: oldestUnmatchedImageAgeMs,
        image_queue_status: unmatchedImageCount === 0
          ? "OK"
          : imageQueueStale ? "ERRO" : "AGUARDANDO_CORRELACAO",
        ftp_images_received: ftpImageReceivedCount,
        ftp_last_image_received_at: ftpLastImageReceivedAt,
        ftp_transfer_error_code: ftpTransferErrorCode,
        image_archive_directory_available: IMAGE_ARCHIVE_DIR ? imageArchiveAvailable : null,
        ...(archiveUnavailable ? { reason_code: "keyence_image_archive_unavailable" } : {}),
        ...(imageQueueStale ? { reason_code: "keyence_images_unmatched" } : {}),
        ...(readerProbeError ? { reason_code: "keyence_reader_ping_failed" } : {}),
        ...(lastError ? { reason_code: "keyence_worker_error", error: lastError } : {}),
      },
    },
  });
}

async function probeReaderNetwork() {
  let reachable = false;
  try {
    await execFile("ping", ["-n", "-c", "1", "-W", "1", TCP_ALLOWED_ADDRESS], {
      timeout: 2000,
      maxBuffer: 1024,
    });
    reachable = true;
    readerProbeError = "";
  } catch (error) {
    if (error?.code !== 1) {
      readerProbeError = String(error?.message || error).slice(0, 180);
      throw new Error("A verificação ICMP do Keyence falhou ao executar o ping.", { cause: error });
    }
  }

  readerProbeCheckedAt = new Date().toISOString();
  if (reachable) {
    readerNetworkStatus = "ONLINE";
    readerProbeFailures = 0;
  } else {
    readerProbeFailures += 1;
    readerNetworkStatus = readerProbeFailures >= 2 ? "OFFLINE" : "DESCONHECIDO";
  }
  return readerNetworkStatus;
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
      reader_probe_method: "ICMP_PING",
      reader_ip: TCP_ALLOWED_ADDRESS,
      reader_probe_checked_at: readerProbeCheckedAt,
      reader_last_read_at: readerLastReadAt,
      reader_last_read_age_ms: readerLastReadAtMs === null ? null : Math.max(0, Date.now() - readerLastReadAtMs),
      unmatched_image_count: unmatchedImageCount,
      oldest_unmatched_image_age_ms: oldestUnmatchedImageAgeMs,
      image_queue_status: unmatchedImageCount === 0
        ? "OK"
        : oldestUnmatchedImageAgeMs >= IMAGE_UNMATCHED_GRACE_MS ? "ERRO" : "AGUARDANDO_CORRELACAO",
      ftp_images_received: ftpImageReceivedCount,
      ftp_last_image_received_at: ftpLastImageReceivedAt,
      ftp_transfer_error_code: ftpTransferErrorCode,
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
  await mkdir(QUARANTINE_DIR, { recursive: true, mode: 0o770 });
  await restoreRetryableQuarantine();
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
    minimumFreeBytes: FTP_MINIMUM_FREE_BYTES,
    advertisedAddress: FTP_PASV_ADDRESS,
    username: FTP_USER,
    password: FTP_PASSWORD,
    allowedAddresses: ftpAllowedAddresses,
    inboxDirectory: INBOX_DIR,
    onImage: () => {
      ftpImageReceivedCount += 1;
      ftpLastImageReceivedAt = new Date().toISOString();
      ftpTransferErrorCode = null;
      void processImageQueue();
    },
    onTransferError: (code) => {
      ftpTransferErrorCode = String(code || "falha_ftp_desconhecida").slice(0, 80);
    },
    log,
  });
  await ftpReceiver.listen();
  ftpReachable = true;
  log("info", "Entrada TCP e recepção FTP do Keyence ativas.", {
    tcp_port: TCP_PORT,
    reader_probe_method: "ICMP_PING",
    reader_ip: TCP_ALLOWED_ADDRESS,
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
