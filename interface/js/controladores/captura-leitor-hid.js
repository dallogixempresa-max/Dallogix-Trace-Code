const MAX_BARCODE_LENGTH = 128;
const MIN_BARCODE_LENGTH = 3;

export function isScannerNoReadMarker(value) {
  return /^ERROR(?:[\s:_-]?\d{8,14})?$/i.test(String(value || "").trim());
}

function isEditableTarget(target) {
  return Boolean(target?.closest?.(
    'input:not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled]), select:not([disabled]), [contenteditable="true"], [role="textbox"]',
  ));
}

/** Captura sequências rápidas do AutoID HID sem tornar o visor editável. */
export function createHidScannerCapture({
  isEnabled = () => false,
  onBarcode = () => {},
  onBufferChange = () => {},
  onError = (error) => console.error("Falha ao processar evento HID do leitor.", error),
  idleMs = 180,
  maxInterKeyMs = 160,
  minBarcodeLength = MIN_BARCODE_LENGTH,
  maxBarcodeLength = MAX_BARCODE_LENGTH,
  now = () => Date.now(),
  setTimer = (callback, delay) => globalThis.setTimeout(callback, delay),
  clearTimer = (timer) => globalThis.clearTimeout(timer),
} = {}) {
  let buffer = "";
  let startedAt = 0;
  let lastKeyAt = 0;
  let timer = null;
  let suppressTrailingEnterUntil = 0;
  let disposed = false;

  function clearTimerIfSet() {
    if (timer !== null) clearTimer(timer);
    timer = null;
  }

  function publishBuffer(value, capturedAtEpochMs = 0) {
    try {
      onBufferChange(value, capturedAtEpochMs);
    } catch (error) {
      onError(error);
    }
  }

  function reset({ clearDisplay = true } = {}) {
    const previousBuffer = buffer;
    const previousStartedAt = startedAt;
    clearTimerIfSet();
    buffer = "";
    startedAt = 0;
    lastKeyAt = 0;
    if (clearDisplay && previousBuffer) publishBuffer("", previousStartedAt);
  }

  function scannerCaptureEnabled() {
    try {
      return isEnabled() === true;
    } catch (error) {
      onError(error);
      return false;
    }
  }

  function finish({ terminatedByEnter = false } = {}) {
    clearTimerIfSet();
    const barcode = buffer.trim();
    const capturedAtEpochMs = startedAt || now();
    buffer = "";
    startedAt = 0;
    lastKeyAt = 0;
    if (!terminatedByEnter) suppressTrailingEnterUntil = now() + 500;

    if (barcode.length < minBarcodeLength || barcode.length > maxBarcodeLength
        || /[\x00-\x1F\x7F]/.test(barcode)) {
      publishBuffer("", capturedAtEpochMs);
      return false;
    }

    if (!scannerCaptureEnabled()) {
      publishBuffer("", capturedAtEpochMs);
      return false;
    }

    publishBuffer("", capturedAtEpochMs);
    try {
      Promise.resolve(onBarcode(barcode, capturedAtEpochMs)).catch(onError);
    } catch (error) {
      onError(error);
    }
    return true;
  }

  function handleKeyDown(event) {
    if (disposed) return false;

    if (!scannerCaptureEnabled()) {
      reset();
      return false;
    }
    if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || isEditableTarget(event.target)) {
      return false;
    }

    const timestamp = now();
    if (event.key === "Enter") {
      if (buffer) {
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
        finish({ terminatedByEnter: true });
        return true;
      }
      if (timestamp <= suppressTrailingEnterUntil) {
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
        return true;
      }
      return false;
    }

    if (typeof event.key !== "string" || event.key.length !== 1 || !/^[\x20-\x7E]$/.test(event.key)) {
      return false;
    }
    if (lastKeyAt && timestamp - lastKeyAt > maxInterKeyMs) reset();
    if (!buffer) startedAt = timestamp;
    buffer += event.key;
    lastKeyAt = timestamp;
    event.preventDefault?.();
    event.stopImmediatePropagation?.();

    if (buffer.length > maxBarcodeLength) {
      reset();
      return true;
    }

    publishBuffer(buffer, startedAt);
    clearTimerIfSet();
    timer = setTimer(() => finish(), idleMs);
    return true;
  }

  return {
    handleKeyDown,
    reset,
    dispose() {
      disposed = true;
      reset();
    },
  };
}
