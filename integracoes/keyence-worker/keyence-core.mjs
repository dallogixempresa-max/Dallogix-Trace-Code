import { createHash, randomUUID } from "node:crypto";

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

export function parseBarcodeFrame(value, maxLength = 128) {
  const barcode = String(value ?? "").replace(/[\r\n]+$/g, "").trim();
  if (!barcode || Buffer.byteLength(barcode, "utf8") > maxLength) return null;
  // GS is a valid separator in GS1 data; other control bytes usually mean the
  // reader's output format was not configured as a plain decoded result.
  if (/[\x00-\x08\x0B-\x1F\x7F]/.test(barcode.replaceAll("\x1D", ""))) return null;
  return barcode;
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
