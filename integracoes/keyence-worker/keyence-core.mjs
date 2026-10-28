import { createHash, randomUUID } from "node:crypto";

export function parseBarcodeFrame(value, maxLength = 128) {
  const barcode = String(value ?? "").replace(/[\r\n]+$/g, "").trim();
  if (!barcode || Buffer.byteLength(barcode, "utf8") > maxLength) return null;
  // GS is a valid separator in GS1 data; other control bytes usually mean the
  // reader's output format was not configured as a plain decoded result.
  if (/[\x00-\x08\x0B-\x1F\x7F]/.test(barcode.replaceAll("\x1D", ""))) return null;
  return barcode;
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

export function matchPendingImage(receivedAt, requests, windowMs) {
  const candidates = (Array.isArray(requests) ? requests : [])
    .map((request) => ({
      request,
      distance: Math.abs(Number(receivedAt) - Number(request.requested_at_epoch_ms)),
    }))
    .filter((candidate) => Number.isFinite(candidate.distance) && candidate.distance <= windowMs)
    .sort((left, right) => left.distance - right.distance);

  if (candidates.length === 0) return { request: null, reason: "sem_solicitacao_compativel" };
  if (candidates.length > 1) {
    return { request: null, reason: "solicitacoes_ambiguas" };
  }
  return { request: candidates[0].request, reason: null };
}

export function scanEventId() {
  return randomUUID();
}

export function sha256(value) {
  return createHash("sha256").update(value).digest("hex");
}
