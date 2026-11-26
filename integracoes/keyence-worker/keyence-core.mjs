import { createHash, randomUUID } from "node:crypto";
import { mkdir, readFile, readdir, rename, unlink, writeFile } from "node:fs/promises";
import { createServer } from "node:net";
import { join } from "node:path";

/** Carrega todas as páginas antes de decidir se um horário tem vínculo único. */
export async function loadPendingImagePages(fetchPage) {
  let afterId = 0;
  const requests = new Map();
  for (;;) {
    const result = await fetchPage(afterId);
    if (!Array.isArray(result?.data)) throw new Error("Página de evidências inválida.");
    for (const request of result.data) {
      const id = Number(request.id);
      if (!Number.isSafeInteger(id) || id <= afterId) throw new Error("Identificação de evidência inválida na página.");
      requests.set(id, request);
    }
    const next = result.meta?.next_after_id;
    if (next === null || next === undefined) return [...requests.values()];
    if (!Number.isSafeInteger(next) || next <= afterId || !requests.has(next)) {
      throw new Error("Cursor de evidências sem avanço; associação interrompida.");
    }
    afterId = next;
  }
}

export const KEYENCE_MIN_BARCODE_LENGTH = 3;
export const KEYENCE_MAX_BARCODE_LENGTH = 128;

/**
 * Classifica um quadro do leitor com as mesmas regras da rota HID
 * (captura-leitor-hid.js descarta menos de 3 caracteres) e do servidor
 * (SolicitacaoLeitura.php recusa bytes de controle com 422): só ASCII
 * imprimível segue para associação. GS e TAB passavam aqui e eram recusados
 * pelo Trace depois de reservar a passagem.
 */
export function classifyBarcodeFrame(value, {
  minLength = KEYENCE_MIN_BARCODE_LENGTH,
  maxLength = KEYENCE_MAX_BARCODE_LENGTH,
} = {}) {
  const barcode = String(value ?? "").replace(/^[ \t\n\v\f\r]+|[ \t\n\v\f\r]+$/g, "");
  if (!barcode) return { valid: false, cause: "quadro vazio" };
  if (/[\x00-\x1F\x7F]/.test(barcode)) return { valid: false, cause: "caractere de controle no quadro" };
  if (/[^\x20-\x7E]/.test(barcode)) return { valid: false, cause: "caractere fora do ASCII imprimível" };
  if (barcode.length > maxLength) return { valid: false, cause: `código acima de ${maxLength} caracteres` };
  if (barcode.length < minLength) return { valid: false, cause: `código com menos de ${minLength} caracteres` };
  return { valid: true, barcode, noRead: isKeyenceNoReadFrame(barcode) };
}

export function parseBarcodeFrame(value, maxLength = KEYENCE_MAX_BARCODE_LENGTH) {
  const result = classifyBarcodeFrame(value, { maxLength });
  return result.valid ? result.barcode : null;
}

export function isKeyenceNoReadFrame(value) {
  const frame = String(value ?? "").trim();
  // Alguns perfis do leitor deixam o marcador ERROR colado ao conteúdo numérico.
  return /^ERROR(?:[0-9]{8,14})?$/i.test(frame);
}

export function nextUnreservedSensorEventId(pendingEventIds, reservedEventIds = new Set()) {
  for (const candidate of Array.isArray(pendingEventIds) ? pendingEventIds : []) {
    const eventId = Number(candidate);
    if (Number.isSafeInteger(eventId) && eventId > 0 && !reservedEventIds.has(eventId)) {
      return eventId;
    }
  }
  return null;
}

export function isAllowedSourceAddress(remoteAddress, allowedAddresses) {
  const normalize = (address) => String(address ?? "").replace(/^::ffff:/, "");
  const remote = normalize(remoteAddress);
  if (!remote) return false;
  const allowed = Array.isArray(allowedAddresses) ? allowedAddresses : [allowedAddresses];
  return allowed.some((address) => normalize(address) === remote);
}

export function parseLinuxDefaultGateway(routeTable) {
  for (const line of String(routeTable ?? "").split(/\r?\n/).slice(1)) {
    const [interfaceName, destination, gateway, flags] = line.trim().split(/\s+/);
    if (!interfaceName || destination !== "00000000" || !/^[a-f0-9]{8}$/i.test(gateway || "")) continue;
    const parsedFlags = Number.parseInt(flags || "0", 16);
    if (!Number.isInteger(parsedFlags) || (parsedFlags & 1) === 0 || gateway === "00000000") continue;
    const octets = gateway.match(/../g).reverse().map((octet) => Number.parseInt(octet, 16));
    if (octets.length === 4 && octets.every((octet) => octet >= 0 && octet <= 255)
      && octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) return octets.join(".");
  }
  return null;
}

export function matchPendingImage(receivedAt, requests, windowMs, timestampField = "requested_at_epoch_ms", ambiguityMarginMs = 250) {
  const candidates = (Array.isArray(requests) ? requests : [])
    .map((request) => ({
      request,
      distance: Math.abs(Number(receivedAt) - Number(request[timestampField])),
    }))
    .filter((candidate) => Number.isFinite(candidate.distance) && candidate.distance <= windowMs)
    .sort((left, right) => left.distance - right.distance);

  if (candidates.length === 0) return { request: null, reason: "sem_solicitacao_compativel" };
  if (candidates.length > 1 && candidates[1].distance - candidates[0].distance <= ambiguityMarginMs) {
    return { request: null, reason: "solicitacoes_ambiguas" };
  }
  return { request: candidates[0].request, reason: null };
}

/**
 * Match an image only when exactly one pending evidence request fits its time
 * window. FTP filenames do not carry a Trace passage ID, so choosing the
 * nearest of multiple candidates can attach the photo to the wrong sack.
 */
export function matchUniquePendingImage(receivedAt, requests, windowMs, timestampField) {
  const candidates = (Array.isArray(requests) ? requests : [])
    .map((request) => ({
      request,
      distance: Math.abs(Number(receivedAt) - Number(request[timestampField])),
    }))
    .filter(({ request, distance }) => {
      if (!Number.isFinite(distance) || distance > windowMs) return false;
      if (timestampField !== "image_expected_at_epoch_ms") return true;
      const from = request.image_valid_from_epoch_ms;
      const until = request.image_valid_until_epoch_ms;
      return (from == null || receivedAt >= Number(from))
        && (until == null || receivedAt < Number(until));
    });

  if (candidates.length === 0) return { request: null, reason: "sem_solicitacao_compativel", candidateCount: 0 };
  if (candidates.length !== 1) return { request: null, reason: "solicitacoes_ambiguas", candidateCount: candidates.length };
  // Uma solicitação capturada continua elegível quando a imagem nova tem uma
  // única passagem compatível; o servidor deduplica o mesmo arquivo e guarda
  // cada foto diferente sob a identidade da mesma passagem.
  return { request: candidates[0].request, reason: null, candidateCount: 1 };
}

export function groupImageFrames(frames, burstWindowMs) {
  const ordered = (Array.isArray(frames) ? frames : [])
    .filter((frame) => Number.isFinite(Number(frame.receivedAt)) && Number(frame.receivedAt) >= 0)
    .slice()
    .sort((left, right) => Number(left.receivedAt) - Number(right.receivedAt));
  const groups = [];

  for (const frame of ordered) {
    const group = groups.at(-1);
    if (group && Number(frame.receivedAt) - group.lastReceivedAt <= burstWindowMs) {
      group.frames.push(frame);
      group.lastReceivedAt = Number(frame.receivedAt);
      group.representative = frame;
      continue;
    }
    groups.push({
      firstReceivedAt: Number(frame.receivedAt),
      lastReceivedAt: Number(frame.receivedAt),
      frames: [frame],
      representative: frame,
    });
  }

  return groups;
}

export function scanEventId() {
  return randomUUID();
}

export function sha256(value) {
  return createHash("sha256").update(value).digest("hex");
}

function isoOrNull(epochMs) {
  return Number.isFinite(epochMs) ? new Date(epochMs).toISOString() : null;
}

/** Texto seguro para o heartbeat: só ASCII imprimível, com limite de tamanho. */
export function printableAscii(value, maxLength) {
  return String(value ?? "").replace(/[^\x20-\x7E]/g, "?").slice(0, maxLength);
}

const FRAME_START = 0x02;
const FRAME_TERMINATORS = new Set([0x0d, 0x0a, 0x03]);
const EMPTY_BUFFER = Buffer.alloc(0);
const OVERSIZE_TAIL_CAUSE = "continuação de quadro acima do limite";
// Um resto sem terminador no fechamento pode ser um código cortado no meio da
// transmissão: enfileirado, seria classificado como produto incorreto e
// pausaria a esteira. Fica em failed-scans como quadro recusado.
export const CLOSE_REMAINDER_CAUSE = "quadro sem terminador no fechamento da conexão";
export const REPLACED_CONNECTION_REASON = "substituída por nova conexão do leitor";

function describeSocketError(error) {
  const known = {
    ECONNRESET: "conexão reiniciada pelo leitor",
    ETIMEDOUT: "leitor sem resposta ao keepalive",
    EPIPE: "conexão interrompida",
    ECONNABORTED: "conexão abortada",
  };
  const code = String(error?.code || "");
  return (known[code] || "erro de conexão: " + String(error?.message || code || "desconhecido")).slice(0, 120);
}

/**
 * Recebe os resultados do SR-X100W como cliente de dados TCP. O leitor abre a
 * conexão no primeiro resultado e a mantém aberta; o worker não encerra
 * conexões ociosas. Atrás do proxy de portas do Docker Desktop o keepalive só
 * enxerga o proxy, não o leitor: um leitor desligado deixa a conexão aberta
 * para sempre. Como o SR-X mantém uma única conexão de dados, uma conexão nova
 * aceita substitui as anteriores, que são encerradas depois de processar o que
 * já entregaram. Quadros terminam em CR, LF ou ETX, com STX inicial opcional;
 * o limite de tamanho vale só para o resto sem terminador, para que uma rajada
 * de quadros num único pacote seja processada inteira.
 */
export function createKeyenceTcpReceiver({
  allowedAddresses = [],
  onFrame = () => {},
  onRejectedFrame = () => {},
  log = () => {},
  now = () => Date.now(),
  maxFrameBytes = 2048,
  keepAliveInitialDelayMs = 10000,
} = {}) {
  const connections = new Map();
  let connectionSequence = 0;
  const stats = {
    lastConnectedAtMs: null,
    lastDisconnectedAtMs: null,
    lastDisconnectReason: null,
    lastFrameAtMs: null,
    framesReceived: 0,
    framesRejected: 0,
  };

  function notify(callback, payload, message) {
    try {
      const result = callback(payload);
      if (result && typeof result.catch === "function") {
        result.catch((error) => log("error", message, { reason: String(error?.message || error) }));
      }
    } catch (error) {
      log("error", message, { reason: String(error?.message || error) });
    }
  }

  function deliver(connection, piece, receivedAtMs, forcedCause = null) {
    let start = 0;
    while (start < piece.length && piece[start] === FRAME_START) start += 1;
    const frame = piece.subarray(start);
    if (frame.length === 0) return;
    stats.lastFrameAtMs = receivedAtMs;
    // latin1 preserva cada byte como um caractere: bytes fora do ASCII
    // imprimível são recusados em vez de virar U+FFFD.
    const classification = forcedCause
      ? { valid: false, cause: forcedCause }
      : classifyBarcodeFrame(frame.toString("latin1"));
    if (classification.valid) {
      stats.framesReceived += 1;
      connection.frames += 1;
      notify(onFrame, {
        barcode: classification.barcode,
        noRead: classification.noRead,
        receivedAtMs,
        connectionId: connection.id,
      }, "Falha ao entregar um quadro TCP do Keyence.");
      return;
    }
    stats.framesRejected += 1;
    connection.rejected += 1;
    notify(onRejectedFrame, {
      cause: classification.cause,
      raw: Buffer.from(frame),
      receivedAtMs,
      connectionId: connection.id,
    }, "Falha ao preservar um quadro TCP rejeitado do Keyence.");
  }

  const server = createServer((socket) => {
    const remote = String(socket.remoteAddress || "").replace(/^::ffff:/, "");
    if (!isAllowedSourceAddress(remote, allowedAddresses)) {
      log("warn", "Conexão TCP Keyence rejeitada por endereço de origem.", { remote_address: remote });
      socket.destroy();
      return;
    }
    connectionSequence += 1;
    const connection = {
      id: connectionSequence,
      remote,
      openedAtMs: now(),
      lastChunkAtMs: null,
      pending: EMPTY_BUFFER,
      oversizeTail: false,
      frames: 0,
      rejected: 0,
      closeReason: null,
    };
    connections.set(socket, connection);
    stats.lastConnectedAtMs = connection.openedAtMs;
    socket.setNoDelay(true);
    socket.setKeepAlive(true, keepAliveInitialDelayMs);
    log("info", "Conexão TCP do leitor aberta.", {
      remote_address: remote,
      remote_port: socket.remotePort,
      connection_id: connection.id,
      connections_open: connections.size,
    });
    // Conexões anteriores são de um leitor que reconectou (ex.: reiniciado).
    // A troca só acontece quando a conexão nova entrega dados: o leitor abre a
    // conexão já enviando um resultado, e uma conexão local que só abre e fica
    // calada (sonda, teste) não derruba a do leitor. A destruição fica para
    // setImmediate, depois da fase de leitura do laço, para que os pacotes que
    // as anteriores já entregaram sejam processados antes.
    let replacedPrevious = false;
    const replacePreviousConnections = () => {
      if (replacedPrevious) return;
      replacedPrevious = true;
      for (const [previousSocket, previous] of connections) {
        if (previousSocket === socket) continue;
        previous.closeReason ??= REPLACED_CONNECTION_REASON;
        log("warn", "Conexão TCP anterior do leitor substituída pela nova.", {
          connection_id: previous.id,
          replaced_by: connection.id,
          last_chunk_at: isoOrNull(previous.lastChunkAtMs),
        });
        setImmediate(() => previousSocket.destroy());
      }
    };

    socket.on("data", (chunk) => {
      replacePreviousConnections();
      const receivedAtMs = now();
      connection.lastChunkAtMs = receivedAtMs;
      const data = connection.pending.length ? Buffer.concat([connection.pending, chunk]) : chunk;
      let start = 0;
      for (let index = 0; index < data.length; index += 1) {
        if (!FRAME_TERMINATORS.has(data[index])) continue;
        if (index > start) {
          deliver(connection, data.subarray(start, index), receivedAtMs, connection.oversizeTail ? OVERSIZE_TAIL_CAUSE : null);
        }
        connection.oversizeTail = false;
        start = index + 1;
      }
      connection.pending = start < data.length ? Buffer.from(data.subarray(start)) : EMPTY_BUFFER;
      if (connection.pending.length > maxFrameBytes) {
        // O resto sem terminador acima do limite é preservado como rejeitado;
        // a continuação dele até o próximo terminador também é recusada,
        // para que um fragmento não vire um código aparentemente válido.
        deliver(connection, connection.pending, receivedAtMs, `quadro sem terminador acima de ${maxFrameBytes} bytes`);
        connection.pending = EMPTY_BUFFER;
        connection.oversizeTail = true;
      }
    });
    socket.on("end", () => {
      connection.closeReason ??= "conexão encerrada pelo leitor";
    });
    socket.on("error", (error) => {
      connection.closeReason ??= describeSocketError(error);
      log("warn", "Conexão TCP do Keyence encerrada com erro.", {
        connection_id: connection.id,
        reason: String(error?.message || error),
      });
    });
    socket.on("close", (hadError) => {
      if (connection.pending.length) {
        // O resto sem terminador nunca vira código (ver CLOSE_REMAINDER_CAUSE)
        // e usa o horário do último pacote recebido, não o do fechamento.
        deliver(connection, connection.pending, connection.lastChunkAtMs ?? connection.openedAtMs,
          connection.oversizeTail ? OVERSIZE_TAIL_CAUSE : CLOSE_REMAINDER_CAUSE);
        connection.pending = EMPTY_BUFFER;
      }
      connections.delete(socket);
      const closedAtMs = now();
      stats.lastDisconnectedAtMs = closedAtMs;
      stats.lastDisconnectReason = String(connection.closeReason
        || (hadError ? "conexão encerrada com erro" : "conexão encerrada")).slice(0, 120);
      log("info", "Conexão TCP do leitor encerrada.", {
        remote_address: connection.remote,
        connection_id: connection.id,
        duration_ms: Math.max(0, closedAtMs - connection.openedAtMs),
        frames: connection.frames,
        frames_rejected: connection.rejected,
        reason: stats.lastDisconnectReason,
        connections_open: connections.size,
      });
    });
  });

  return {
    server,
    listen(port, host) {
      return new Promise((resolve, reject) => {
        server.once("error", reject);
        server.listen(port, host, () => {
          server.removeListener("error", reject);
          resolve(server.address());
        });
      });
    },
    close(reason = "worker encerrando") {
      for (const [socket, connection] of connections) {
        connection.closeReason ??= reason;
        socket.destroy();
      }
      return new Promise((resolve) => server.close(() => resolve()));
    },
    state() {
      return {
        connected: connections.size > 0,
        connections_open: connections.size,
        last_connected_at: isoOrNull(stats.lastConnectedAtMs),
        last_disconnected_at: isoOrNull(stats.lastDisconnectedAtMs),
        last_disconnect_reason: stats.lastDisconnectReason,
        last_frame_at: isoOrNull(stats.lastFrameAtMs),
        frames_received: stats.framesReceived,
        frames_rejected: stats.framesRejected,
      };
    },
  };
}

export const SCAN_ERROR_RETENTION_MS = 600000;
// Só com o carregamento neste estado um código sem passagem entra no contador
// da tela de operação; fora dele (pausa, finalização, sem carregamento) é o
// saco sendo manuseado ou um teste do leitor, não uma falha operacional.
export const COUNTED_LOADING_STATE = "CARREGANDO";

/** Estado do carregamento devolvido pelo STATUS (active_loading_state), ou null. */
export function normalizeLoadingState(value) {
  if (typeof value !== "string") return null;
  const state = printableAscii(value.trim(), 40);
  return state || null;
}

/** Motivo curto de uma leitura recusada pelo Trace depois da associação. */
export function traceRejectionReason(error) {
  const status = Number(error?.status);
  // As mensagens do Trace têm acentos: só os bytes de controle são trocados.
  const message = String(error?.message || "").replace(/[\x00-\x1F\x7F]+/g, " ").trim().slice(0, 120);
  const parts = [Number.isInteger(status) && status > 0 ? "HTTP " + status : "erro"];
  // A mensagem genérica de apiRequest só repete o status.
  if (message && !/^Trace respondeu HTTP \d+/.test(message)) parts.push(message);
  return ("recusada pelo Trace: " + parts.join(" - ")).slice(0, 120);
}

/**
 * Contadores da rota TCP publicados no heartbeat SCANNER como "tcp_route".
 * O scan_error não é apagado pelo STATUS: só um registro bem-sucedido
 * posterior ou o prazo de 10 minutos o limpa. Em unassociated_by_loading, o
 * "count" (o que a tela de operação mostra) só cresce para códigos recebidos
 * com o carregamento em CARREGANDO; os demais só atualizam o "last".
 */
export function createTcpRouteStats({
  now = () => Date.now(),
  scanErrorRetentionMs = SCAN_ERROR_RETENTION_MS,
  maxLoadingEntries = 5,
} = {}) {
  let scansRegistered = 0;
  let scansUnassociated = 0;
  let scansRejectedByTrace = 0;
  let scanError = null;
  let scanErrorAtMs = null;
  let unassociatedByLoading = [];

  function expireScanError() {
    if (scanErrorAtMs !== null && now() - scanErrorAtMs >= scanErrorRetentionMs) {
      scanError = null;
      scanErrorAtMs = null;
    }
  }

  function rememberForLoading({ loadingId = null, loadingState = null, barcode = "", noRead = false, reason = "", receivedAtMs = null }) {
    const id = Number(loadingId);
    const normalizedId = Number.isSafeInteger(id) && id > 0 ? id : null;
    const state = normalizeLoadingState(loadingState);
    const existing = unassociatedByLoading.find((entry) => entry.loading_id === normalizedId);
    const entry = {
      loading_id: normalizedId,
      count: (existing?.count || 0) + (state === COUNTED_LOADING_STATE ? 1 : 0),
      last: {
        barcode: printableAscii(barcode, KEYENCE_MAX_BARCODE_LENGTH),
        no_read: noRead === true,
        reason: String(reason || "").slice(0, 120),
        received_at: isoOrNull(Number.isFinite(receivedAtMs) ? receivedAtMs : now()),
        loading_state: state,
      },
    };
    unassociatedByLoading = [entry, ...unassociatedByLoading.filter((item) => item !== existing)]
      .slice(0, maxLoadingEntries);
  }

  return {
    setScanError(message) {
      scanError = String(message || "erro inesperado").slice(0, 240);
      scanErrorAtMs = now();
    },
    recordRegistered() {
      scansRegistered += 1;
      scanError = null;
      scanErrorAtMs = null;
    },
    /**
     * Leitura associada que o Trace recusou (4xx definitivo). Com os detalhes,
     * entra também no carregamento dela, como a recusa aparece na rota HID.
     */
    recordRejectedByTrace(details = null) {
      scansRejectedByTrace += 1;
      if (details && typeof details === "object") rememberForLoading(details);
    },
    recordUnassociated(details = {}) {
      scansUnassociated += 1;
      rememberForLoading(details || {});
    },
    snapshot(connection = {}, failedScansPending = 0) {
      expireScanError();
      const integer = (value) => (Number.isSafeInteger(Number(value)) && Number(value) >= 0 ? Number(value) : 0);
      return {
        connected: connection?.connected === true,
        connections_open: integer(connection?.connections_open),
        last_connected_at: connection?.last_connected_at ?? null,
        last_disconnected_at: connection?.last_disconnected_at ?? null,
        last_disconnect_reason: connection?.last_disconnect_reason ?? null,
        last_frame_at: connection?.last_frame_at ?? null,
        frames_received: integer(connection?.frames_received),
        frames_rejected: integer(connection?.frames_rejected),
        scans_registered: scansRegistered,
        scans_unassociated: scansUnassociated,
        scans_rejected_by_trace: scansRejectedByTrace,
        failed_scans_pending: integer(failedScansPending),
        scan_error: scanError,
        scan_error_at: isoOrNull(scanErrorAtMs),
        unassociated_by_loading: unassociatedByLoading.map((item) => ({ ...item, last: { ...item.last } })),
      };
    },
  };
}

/** Grava um registro JSON novo (nunca sobrescreve) e devolve o caminho. */
export async function writeScanRecord(directory, suffix, record) {
  await mkdir(directory, { recursive: true, mode: 0o770 });
  const offlineId = String(record?.offline_id || scanEventId());
  const file = join(directory, `${Date.now()}-${offlineId}${suffix}`);
  await writeFile(file, JSON.stringify({ ...record, offline_id: offlineId }), { flag: "wx", mode: 0o600 });
  return file;
}

/** Registro de auditoria de um quadro TCP recusado antes da associação. */
export function rejectedFrameRecord({ cause, raw, receivedAtMs, connectionId = null }) {
  const bytes = Buffer.isBuffer(raw) ? raw : Buffer.from(String(raw ?? ""), "latin1");
  return {
    reason: "quadro inválido",
    cause: String(cause || "quadro inválido").slice(0, 120),
    raw_base64: bytes.toString("base64"),
    raw_length: bytes.length,
    raw_preview: printableAscii(bytes.toString("latin1"), KEYENCE_MAX_BARCODE_LENGTH),
    received_at: isoOrNull(receivedAtMs),
    received_at_epoch_ms: Number.isFinite(receivedAtMs) ? receivedAtMs : null,
    connection_id: connectionId,
  };
}

/** Grava o quadro aceito antes da associação, para sobreviver a um reinício. */
export async function writeInFlightScan(directory, { offlineId, barcode, noRead, receivedAtMs, connectionId = null }) {
  await mkdir(directory, { recursive: true, mode: 0o770 });
  const file = join(directory, `${receivedAtMs}-${offlineId}.json`);
  await writeFile(file, JSON.stringify({
    offline_id: offlineId,
    barcode: noRead ? "" : barcode,
    no_read: noRead === true,
    received_at: isoOrNull(receivedAtMs),
    received_at_epoch_ms: receivedAtMs,
    connection_id: connectionId,
  }), { flag: "wx", mode: 0o600 });
  return file;
}

/**
 * Move para failed-scans os quadros que ficaram em trânsito quando o worker
 * parou. Quadros que já chegaram à fila ou a failed-scans (o arquivo em
 * trânsito ainda não tinha sido apagado) só têm a cópia em trânsito removida.
 * Só conta como já gravado um .json confirmado, legível e com o mesmo
 * offline_id: um scans/*.json.tmp órfão (worker parado entre a gravação e o
 * rename) nunca chegou à fila e é removido. Deve rodar antes de a porta TCP
 * abrir, quando nada mais grava na fila.
 */
export async function recoverInFlightScans({ inFlightDirectory, failedDirectory, queueDirectory, reason, log = () => {} }) {
  const listNames = async (directory) => {
    try { return await readdir(directory); } catch (error) { if (error?.code === "ENOENT") return []; throw error; }
  };
  const queueNames = await listNames(queueDirectory);
  for (const name of queueNames.filter((item) => item.endsWith(".tmp"))) {
    try {
      await unlink(join(queueDirectory, name));
      log("warn", "Arquivo temporário órfão removido da fila de leituras Keyence.", { file: name });
    } catch (error) {
      if (error?.code !== "ENOENT") {
        log("warn", "Não foi possível remover o arquivo temporário órfão da fila de leituras Keyence.", {
          file: name,
          reason: String(error?.message || error),
        });
      }
    }
  }
  let names;
  try {
    names = (await readdir(inFlightDirectory)).sort();
  } catch (error) {
    if (error?.code === "ENOENT") return [];
    throw error;
  }
  if (names.length === 0) return [];
  await mkdir(failedDirectory, { recursive: true, mode: 0o770 });
  const committed = (directory, list) => list
    .filter((name) => name.endsWith(".json"))
    .map((name) => ({ name, file: join(directory, name) }));
  const knownFiles = [
    ...committed(queueDirectory, queueNames),
    ...committed(failedDirectory, await listNames(failedDirectory)),
  ];
  // Uma gravação interrompida em failed-scans pode deixar um .json truncado:
  // ele não substitui a cópia em trânsito.
  const alreadyStored = async (offlineId) => {
    for (const known of knownFiles.filter(({ name }) => name.includes(offlineId))) {
      try {
        const stored = JSON.parse(await readFile(known.file, "utf8"));
        if (stored && typeof stored === "object" && String(stored.offline_id || "") === offlineId) return true;
      } catch {
        // Ilegível ou já removido: não conta como gravado.
      }
    }
    return false;
  };
  const recovered = [];
  for (const name of names) {
    const source = join(inFlightDirectory, name);
    let record = null;
    try {
      const parsed = JSON.parse(await readFile(source, "utf8"));
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) record = parsed;
    } catch {
      record = null;
    }
    if (!record) {
      await rename(source, join(failedDirectory, `${name}.interrupted.invalid`));
      recovered.push({ reason, invalid: true });
      continue;
    }
    const offlineId = String(record.offline_id || "");
    if (/^[a-f0-9-]{16,36}$/i.test(offlineId) && await alreadyStored(offlineId)) {
      await unlink(source);
      continue;
    }
    await writeScanRecord(failedDirectory, ".interrupted.json", { ...record, reason });
    await unlink(source);
    recovered.push({ ...record, reason });
  }
  return recovered;
}

const RETRYABLE_TRACE_CLIENT_STATUSES = [401, 408, 423, 429];

/** 401 (token trocado), 408, 423 (emergência), 429, 5xx e falhas de rede são temporários. */
export function isRetryableTraceError(error) {
  const status = Number(error?.status);
  if (!(status >= 400 && status < 500)) return true;
  return RETRYABLE_TRACE_CLIENT_STATUSES.includes(status);
}

export const SCAN_RETRY_FAST_DELAY_MS = 500;
// 10 tentativas a cada 500 ms cobrem os ~5 s em que a passagem ainda pode
// estar aberta no servidor (~4,7 s).
export const SCAN_RETRY_FAST_ATTEMPTS = 10;
export const SCAN_RETRY_MAX_DELAY_MS = 10000;

/**
 * Prazo até a próxima tentativa de um arquivo da fila: 500 ms enquanto a
 * passagem pode estar aberta, depois 1 s, 2 s, 4 s, 8 s e no máximo 10 s, para
 * que uma leitura seja reenviada logo depois de uma emergência ou queda curta
 * sem que leituras presas consumam o limite do nginx.
 */
export function scanRetryDelayMs(attempts) {
  if (attempts <= SCAN_RETRY_FAST_ATTEMPTS) return SCAN_RETRY_FAST_DELAY_MS;
  return Math.min(SCAN_RETRY_MAX_DELAY_MS, 1000 * 2 ** (attempts - SCAN_RETRY_FAST_ATTEMPTS - 1));
}

/**
 * Uma passada pela fila local de leituras. Um erro temporário mantém o
 * arquivo para uma próxima passada e segue com os demais, para que uma
 * leitura presa não atrase as seguintes até as passagens expirarem. Um 429
 * ou falhas temporárias consecutivas encerram a passada.
 */
export async function drainScanQueue({
  queueDirectory,
  failedDirectory,
  register,
  retries = new Map(),
  now = () => Date.now(),
  maxConsecutiveRetryableFailures = 2,
  onRegistered = () => {},
  onRejected = () => {},
  onRetryable = () => {},
  onInvalid = () => {},
}) {
  const names = (await readdir(queueDirectory)).filter((name) => name.endsWith(".json")).sort();
  const present = new Set(names);
  for (const name of [...retries.keys()]) if (!present.has(name)) retries.delete(name);
  const summary = { registered: 0, rejected: 0, retryable: 0, deferred: 0, invalid: 0 };
  let consecutiveRetryableFailures = 0;
  for (const name of names) {
    const retry = retries.get(name);
    if (retry && retry.nextAttemptAt > now()) {
      summary.deferred += 1;
      continue;
    }
    const file = join(queueDirectory, name);
    let event;
    try {
      event = JSON.parse(await readFile(file, "utf8"));
    } catch (error) {
      await rename(file, join(failedDirectory, name + ".invalid"));
      retries.delete(name);
      summary.invalid += 1;
      onInvalid(name, error);
      continue;
    }
    let result = null;
    let failure = null;
    // O prazo conta do início da tentativa: medido depois da resposta, os
    // 500 ms caíam sempre na consulta seguinte do laço e viravam 1 s.
    const attemptStartedAt = now();
    try {
      result = await register(event);
      if (!result?.data?.id) throw new Error("Trace não confirmou o registro da leitura.");
      await unlink(file);
    } catch (error) {
      failure = error;
    }
    if (!failure) {
      retries.delete(name);
      consecutiveRetryableFailures = 0;
      summary.registered += 1;
      onRegistered(event, result, name);
      continue;
    }
    if (!isRetryableTraceError(failure)) {
      await rename(file, join(failedDirectory, name));
      retries.delete(name);
      consecutiveRetryableFailures = 0;
      summary.rejected += 1;
      onRejected(event, failure, name);
      continue;
    }
    const attempts = (retry?.attempts || 0) + 1;
    const retryInMs = scanRetryDelayMs(attempts);
    retries.set(name, { attempts, nextAttemptAt: attemptStartedAt + retryInMs });
    consecutiveRetryableFailures += 1;
    summary.retryable += 1;
    onRetryable(event, failure, { name, attempts, retryInMs });
    // 429 é o limite do nginx: insistir com os próximos arquivos agravaria a
    // limitação, inclusive para o STATUS usado na associação.
    if (Number(failure?.status) === 429 || consecutiveRetryableFailures >= maxConsecutiveRetryableFailures) break;
  }
  return summary;
}
