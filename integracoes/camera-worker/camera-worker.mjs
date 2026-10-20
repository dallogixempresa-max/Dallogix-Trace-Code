import { spawn } from "node:child_process";
import { createServer } from "node:http";
import { createHash } from "node:crypto";
import { createConnection, isIP } from "node:net";
import { boundedInteger } from "./config.mjs";
import { createCameraEvidencePdf } from "./evidence-pdf.mjs";
import { localEvidenceFilename, persistLocalEvidencePdf, readLocalEvidencePdf } from "./evidence-store.mjs";
import { createFfmpegArguments, createFfmpegInputList } from "./ffmpeg-arguments.mjs";
import { JpegFrameCollector } from "./jpeg-frame-collector.mjs";

const SERVICE_NAME = "camera-worker";
const API_BASE = (process.env.TRACE_API_URL || "http://nginx").replace(/\/+$/, "");
const DEVICE_TOKEN = process.env.CAMERA_DEVICE_TOKEN || "";
const CAMERA_HOST = process.env.CAMERA_RTSP_HOST || "";
const CAMERA_PORT = Number.parseInt(process.env.CAMERA_RTSP_PORT || "554", 10);
const CAMERA_CHANNEL = process.env.CAMERA_RTSP_CHANNEL || "101";
const CAMERA_USERNAME = process.env.CAMERA_RTSP_USERNAME || "";
const CAMERA_PASSWORD = process.env.CAMERA_RTSP_PASSWORD || "";
const JOB_POLL_MS = boundedInteger(process.env.CAMERA_JOB_POLL_MS, 200, 100, 5000);
const CAMERA_CAPTURE_TIMEOUT_MS = boundedInteger(process.env.CAMERA_CAPTURE_TIMEOUT_MS, 10000, 2000, 20000);
const HEARTBEAT_MS = boundedInteger(process.env.CAMERA_HEARTBEAT_INTERVAL_MS, 5000, 1000, 60000);
const CAMERA_EVIDENCE_DIR = process.env.CAMERA_EVIDENCE_DIR || "/var/lib/trace/camera-evidence";
const MAX_FRAME_BYTES = 4 * 1024 * 1024;
const API_RETRY_MAX_MS = 2000;
const API_FAILURE_LOG_INTERVAL_MS = 30000;
const SHUTDOWN_GRACE_MS = 5000;

let shuttingDown = false;
let activeFfmpegChild = null;
let cameraTcpReachable = false;
let lastCaptureAt = null;
let lastCaptureError = "";
const apiFailureLogState = new Map();

function log(level, message, details = {}) {
  const entry = {
    timestamp: new Date().toISOString(),
    service: SERVICE_NAME,
    message,
    ...details,
  };
  const output = JSON.stringify(entry);
  if (level === "error") {
    console.error(output);
  } else if (level === "warn") {
    console.warn(output);
  } else {
    console.info(output);
  }
}

function isPrivateCameraAddress(value) {
  if (isIP(value) !== 4) return false;
  const octets = value.split(".").map(Number);
  return octets[0] === 10
    || (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31)
    || (octets[0] === 192 && octets[1] === 168);
}

function cameraConfigurationIsComplete() {
  return isPrivateCameraAddress(CAMERA_HOST)
    && Number.isInteger(CAMERA_PORT) && CAMERA_PORT >= 1 && CAMERA_PORT <= 65535
    && /^\d{3,4}$/.test(CAMERA_CHANNEL)
    && CAMERA_USERNAME.length > 0 && CAMERA_PASSWORD.length > 0;
}

function streamUrl() {
  const url = new URL("rtsp://" + CAMERA_HOST + ":" + CAMERA_PORT + "/Streaming/channels/" + CAMERA_CHANNEL);
  url.username = CAMERA_USERNAME;
  url.password = CAMERA_PASSWORD;
  return url.toString();
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function reportApiFailure(operation, message, error) {
  const now = Date.now();
  const reason = (error instanceof Error ? error.message : String(error || "erro inesperado"))
    .slice(0, 300);
  const previous = apiFailureLogState.get(operation);
  if (
    previous?.reason === reason &&
    now - previous.loggedAt < API_FAILURE_LOG_INTERVAL_MS
  ) return;

  apiFailureLogState.set(operation, { reason, loggedAt: now });
  log("warn", message, { operation, reason });
}

function apiRetryDelay(failureCount) {
  const multiplier = 2 ** Math.min(failureCount, 8);
  return Math.max(
    JOB_POLL_MS,
    Math.min(API_RETRY_MAX_MS, JOB_POLL_MS * multiplier),
  );
}

async function apiRequest(path, { jsonBody = null, formBody = null } = {}) {
  const headers = { "X-Device-Token": DEVICE_TOKEN };
  let body;
  if (formBody) {
    body = formBody;
  } else {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(jsonBody ?? {});
  }

  const response = await fetch(new URL(path, API_BASE + "/"), {
    method: "POST",
    headers,
    body,
    signal: AbortSignal.timeout(15000),
  });
  if (response.status === 204) return null;
  if (!response.ok) {
    await response.arrayBuffer();
    throw new Error("Trace API respondeu HTTP " + response.status + " em " + path);
  }
  const text = await response.text();
  return text ? JSON.parse(text) : null;
}

function probeCameraTcp(timeoutMs = 1500) {
  if (!cameraConfigurationIsComplete()) return Promise.resolve(false);
  return new Promise((resolve) => {
    let settled = false;
    const socket = createConnection({ host: CAMERA_HOST, port: CAMERA_PORT });
    const finish = (reachable) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      socket.destroy();
      resolve(reachable);
    };
    const timer = setTimeout(() => finish(false), timeoutMs);
    socket.once("connect", () => finish(true));
    socket.once("error", () => finish(false));
    socket.setTimeout(timeoutMs, () => finish(false));
  });
}

async function publishHeartbeat() {
  cameraTcpReachable = await probeCameraTcp();
  const online = cameraTcpReachable;
  const details = {
    protocol: "RTSP",
    capture_mode: "ON_DEMAND",
    rtsp_tcp_reachable: online,
    credential_validation: "ON_CAPTURE",
  };
  if (lastCaptureAt !== null) details.last_capture_at = new Date(lastCaptureAt).toISOString();
  if (lastCaptureError) details.capture_error = lastCaptureError.slice(0, 240);
  let status = online ? "ONLINE" : "OFFLINE";
  if (!cameraConfigurationIsComplete()) {
    details.reason_code = "camera_config_missing";
  } else if (!online) {
    details.reason_code = "rtsp_tcp_unreachable";
  } else if (lastCaptureError) {
    details.reason_code = "capture_failed";
    status = "ERRO";
  }
  await apiRequest("/api/device_heartbeat.php", {
    jsonBody: { status, details },
  });
}

function captureSingleFrame() {
  if (!cameraConfigurationIsComplete()) {
    return Promise.reject(new Error("A configuração RTSP da câmera está incompleta."));
  }

  return new Promise((resolve, reject) => {
    const child = spawn("ffmpeg", createFfmpegArguments(), { stdio: ["pipe", "pipe", "ignore"] });
    const collector = new JpegFrameCollector(MAX_FRAME_BYTES);
    let capturedFrame = null;
    let timedOut = false;
    let settled = false;
    let forceKillTimer = null;
    let finishTimeoutTimer = null;
    activeFfmpegChild = child;

    const finish = (error, frame = null) => {
      if (settled) return;
      settled = true;
      clearTimeout(timeoutTimer);
      if (forceKillTimer) clearTimeout(forceKillTimer);
      if (finishTimeoutTimer) clearTimeout(finishTimeoutTimer);
      if (error) reject(error);
      else resolve(frame);
    };
    const stopChild = () => {
      if (child.exitCode !== null || child.signalCode !== null) return;
      child.kill("SIGTERM");
      forceKillTimer = setTimeout(() => {
        if (child.exitCode === null && child.signalCode === null) child.kill("SIGKILL");
      }, SHUTDOWN_GRACE_MS);
      forceKillTimer.unref();
    };
    const timeoutTimer = setTimeout(() => {
      timedOut = true;
      stopChild();
      finishTimeoutTimer = setTimeout(
        () => finish(new Error("Tempo limite ao solicitar um quadro RTSP da câmera.")),
        SHUTDOWN_GRACE_MS + 500,
      );
      finishTimeoutTimer.unref();
    }, CAMERA_CAPTURE_TIMEOUT_MS);

    child.stdout.on("data", (chunk) => {
      if (capturedFrame) return;
      capturedFrame = collector.pushLatest(chunk);
      if (capturedFrame) stopChild();
    });
    child.stdin.once("error", (error) => {
      log("warn", "Não foi possível enviar o endereço RTSP ao FFmpeg.", {
        code: error.code || "ERRO_ENTRADA_FFMPEG",
      });
    });
    child.stdin.end(createFfmpegInputList(streamUrl()), "utf8");
    child.once("error", (error) => {
      if (activeFfmpegChild === child) activeFfmpegChild = null;
      finish(new Error("Não foi possível iniciar a captura RTSP: " + error.message));
    });
    child.once("close", (code) => {
      if (activeFfmpegChild === child) activeFfmpegChild = null;
      if (capturedFrame) finish(null, capturedFrame);
      else if (timedOut) finish(new Error("Tempo limite ao solicitar um quadro RTSP da câmera."));
      else finish(new Error("FFmpeg terminou sem fornecer um quadro JPEG (código " + code + ")."));
    });
    child.once("stop-camera-worker", () => {
      stopChild();
      if (!capturedFrame) finish(new Error("Captura interrompida durante o encerramento do worker."));
    });
    if (shuttingDown) child.emit("stop-camera-worker");
  });
}

async function completeRequest(request, evidencePdfPath) {
  await apiRequest("/api/camera_worker.php", {
    jsonBody: {
      action: "COMPLETE",
      request_id: request.id,
      evidence_pdf_path: evidencePdfPath,
    },
  });
}

async function downloadStoredPdf(request) {
  const response = await fetch(new URL("/api/camera_worker.php", API_BASE + "/"), {
    method: "POST",
    headers: { "X-Device-Token": DEVICE_TOKEN, "Content-Type": "application/json" },
    body: JSON.stringify({ action: "DOWNLOAD", request_id: request.id }),
    signal: AbortSignal.timeout(15000),
  });
  if (!response.ok) {
    await response.arrayBuffer();
    throw new Error("A API não disponibilizou o PDF já enviado (HTTP " + response.status + ").");
  }
  if (!(response.headers.get("content-type") || "").toLowerCase().startsWith("application/pdf")) {
    await response.arrayBuffer();
    throw new Error("A API devolveu um formato diferente do PDF esperado.");
  }
  return Buffer.from(await response.arrayBuffer());
}

async function processRequest(request) {
  if (!request || !Number.isInteger(Number(request.id))) {
    throw new Error("A API devolveu uma solicitação de captura inválida.");
  }

  let evidencePdfPath = typeof request.evidence_pdf_path === "string"
    ? request.evidence_pdf_path
    : "";
  if (!evidencePdfPath) {
    let pdf = await readLocalEvidencePdf(CAMERA_EVIDENCE_DIR, request);
    if (!pdf) {
      let jpeg;
      try {
        jpeg = await captureSingleFrame();
        lastCaptureError = "";
      } catch (error) {
        lastCaptureError = error instanceof Error ? error.message : "Falha ao solicitar quadro RTSP.";
        try {
          await publishHeartbeat();
        } catch (heartbeatError) {
          reportApiFailure("capture-error-heartbeat", "Não foi possível publicar a falha da captura da câmera.", heartbeatError);
        }
        throw error;
      }
      lastCaptureAt = Date.now();
      pdf = createCameraEvidencePdf(jpeg, {
        requestId: request.id,
        reason: request.reason,
        capturedAt: new Date(lastCaptureAt),
      });
      await persistLocalEvidencePdf(CAMERA_EVIDENCE_DIR, request, pdf);
      try {
        await publishHeartbeat();
      } catch (error) {
        reportApiFailure("capture-success-heartbeat", "Não foi possível publicar o sucesso da captura da câmera.", error);
      }
    }

    const form = new FormData();
    form.append("request_id", String(request.id));
    form.append("file", new Blob([pdf], { type: "application/pdf" }), localEvidenceFilename(request));
    const result = await apiRequest("/api/camera_upload.php", { formBody: form });
    evidencePdfPath = result?.data?.evidence_pdf_path || "";
    if (!evidencePdfPath) throw new Error("A API não confirmou o PDF da evidência.");
    const expectedHash = createHash("sha256").update(pdf).digest("hex");
    if (result?.data?.pdf_sha256 !== expectedHash) {
      throw new Error("A API não confirmou o mesmo PDF que foi armazenado no PC industrial.");
    }
  } else {
    let localPdf = await readLocalEvidencePdf(CAMERA_EVIDENCE_DIR, request);
    if (!localPdf) {
      localPdf = await downloadStoredPdf(request);
      await persistLocalEvidencePdf(CAMERA_EVIDENCE_DIR, request, localPdf);
    }
  }

  await completeRequest(request, evidencePdfPath);
  log("info", "Foto de evidência capturada sob demanda e confirmada.", { request_id: Number(request.id) });
}

async function pollRequests() {
  let lastHeartbeatAt = 0;
  let claimFailureCount = 0;
  while (!shuttingDown) {
    const now = Date.now();
    if (now - lastHeartbeatAt >= HEARTBEAT_MS) {
      lastHeartbeatAt = now;
      try {
        await publishHeartbeat();
        apiFailureLogState.delete("heartbeat");
      } catch (error) {
        reportApiFailure("heartbeat", "Não foi possível atualizar o heartbeat da câmera.", error);
      }
    }

    if (cameraTcpReachable && cameraConfigurationIsComplete() && DEVICE_TOKEN && !activeFfmpegChild) {
      try {
        const result = await apiRequest("/api/camera_worker.php", {
          jsonBody: { action: "CLAIM" },
        });
        claimFailureCount = 0;
        apiFailureLogState.delete("claim");
        const request = result?.data || null;
        if (request) {
          try {
            await processRequest(request);
          } catch (error) {
            log("warn", "Captura não concluída; a reserva será recuperada automaticamente.", {
              request_id: Number(request.id) || null,
              reason: error instanceof Error ? error.message : "erro inesperado",
            });
          }
        }
      } catch (error) {
        claimFailureCount = Math.min(claimFailureCount + 1, 8);
        reportApiFailure("claim", "Não foi possível consultar a fila de captura do Trace.", error);
        await sleep(apiRetryDelay(claimFailureCount));
        continue;
      }
    }
    await sleep(JOB_POLL_MS);
  }
}

const healthServer = createServer((request, response) => {
  if (request.url !== "/health") {
    response.writeHead(404);
    response.end();
    return;
  }
  response.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
  response.end(JSON.stringify({
    service: SERVICE_NAME,
    running: true,
    camera_configured: cameraConfigurationIsComplete(),
    capture_mode: "ON_DEMAND",
    rtsp_tcp_reachable: cameraTcpReachable,
    capture_in_progress: Boolean(activeFfmpegChild),
    last_capture_at: lastCaptureAt === null ? null : new Date(lastCaptureAt).toISOString(),
  }));
});
healthServer.listen(8090, "0.0.0.0");

function requestShutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log("info", "Encerrando worker da câmera.", { signal });
  healthServer.close();
  if (activeFfmpegChild) activeFfmpegChild.emit("stop-camera-worker");
}

process.on("SIGTERM", () => requestShutdown("SIGTERM"));
process.on("SIGINT", () => requestShutdown("SIGINT"));

if (!DEVICE_TOKEN) {
  log("error", "CAMERA_DEVICE_TOKEN não foi configurado.");
  process.exitCode = 1;
} else {
  log("info", "Configuração do worker da câmera carregada.", {
    queue_poll_ms: JOB_POLL_MS,
    capture_timeout_ms: CAMERA_CAPTURE_TIMEOUT_MS,
    heartbeat_interval_ms: HEARTBEAT_MS,
    capture_mode: "ON_DEMAND",
  });
  if (!cameraConfigurationIsComplete()) {
    log("warn", "Configure IP privado, usuário e senha RTSP para habilitar a câmera.");
  }
  await pollRequests();
}
