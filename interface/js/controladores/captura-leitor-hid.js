const MAX_BARCODE_LENGTH = 128;
const MIN_BARCODE_LENGTH = 3;

/**
 * O Keyence pode anexar o tempo de leitura (ms do disparo até a leitura) ao
 * resultado: "7898250782479,0420" ou "ERROR,1000". Ele identifica o disparo e
 * vai ao servidor junto com o texto; aqui só separa o código para exibição.
 */
export function separarTempoDeLeitura(value) {
  const texto = String(value || "").trim();
  const partes = /^(.+?)[,;](\d{1,5})$/.exec(texto);
  if (!partes) return { codigo: texto, tempoMs: null };
  const tempoMs = Number(partes[2]);
  return tempoMs <= 10000 ? { codigo: partes[1].trim(), tempoMs } : { codigo: texto, tempoMs: null };
}

function gtinValido(digitos) {
  if (![8, 12, 13, 14].includes(digitos.length)) return false;
  let soma = 0;
  for (let i = digitos.length - 2, peso = 3; i >= 0; i -= 1, peso = peso === 3 ? 1 : 3) {
    soma += Number(digitos[i]) * peso;
  }
  return (10 - (soma % 10)) % 10 === Number(digitos[digitos.length - 1]);
}

/**
 * Quando o Wedge não manda Enter entre dois resultados próximos, eles chegam
 * grudados ("7898250782479ERROR", "ERROR7898250782943", dois códigos
 * seguidos). Separa o texto só se ele for inteiro uma sequência de resultados
 * completos: GTIN com dígito verificador válido ou ERROR, cada um com o tempo
 * de leitura opcional (",0420"). Devolve [{ texto, inicio }] na ordem; texto
 * que não se encaixa volta como um resultado só.
 */
export function separarResultadosDoLeitor(value) {
  const texto = String(value || "").trim();
  const resultados = [];
  const sufixo = (pos) => {
    const opcoes = [];
    const m = /^[,;](\d{1,5})/.exec(texto.slice(pos));
    if (m) for (let n = m[1].length; n >= 1; n -= 1) opcoes.push(pos + 1 + n);
    opcoes.push(pos);
    return opcoes;
  };
  const analisar = (pos) => {
    if (pos === texto.length) return true;
    if (/^ERROR/i.test(texto.slice(pos))) {
      for (const fim of sufixo(pos + 5)) {
        resultados.push({ texto: texto.slice(pos, fim), inicio: pos });
        if (analisar(fim)) return true;
        resultados.pop();
      }
      return false;
    }
    for (const tamanho of [13, 14, 12, 8]) {
      const digitos = texto.slice(pos, pos + tamanho);
      if (!/^\d+$/.test(digitos) || digitos.length !== tamanho || !gtinValido(digitos)) continue;
      for (const fim of sufixo(pos + tamanho)) {
        resultados.push({ texto: texto.slice(pos, fim), inicio: pos });
        if (analisar(fim)) return true;
        resultados.pop();
      }
    }
    return false;
  };
  if (texto && analisar(0) && resultados.length > 1) return resultados;
  return [{ texto, inicio: 0 }];
}

export function isScannerNoReadMarker(value) {
  return /^ERROR(?:[\s:_-]?\d{8,14})?$/i.test(separarTempoDeLeitura(value).codigo);
}

function isEditableTarget(target) {
  return Boolean(target?.closest?.(
    'input:not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled]), select:not([disabled]), [contenteditable="true"], [role="textbox"]',
  ));
}

/** Captura sequências rápidas do AutoID HID sem tornar o visor editável. */
export function createHidScannerCapture({
  isEnabled = () => false,
  getLoadingId = () => null,
  onBarcode = () => {},
  onBufferChange = () => {},
  onDiscard = () => {},
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
  let keyTimes = [];
  let startedAt = 0;
  let loadingIdAtStart = null;
  let lastKeyAt = 0;
  let timer = null;
  let suppressTrailingEnterUntil = 0;
  let disposed = false;
  let awaitingUnicodeKeypress = false;

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

  function reportDiscard(texto, capturedAtEpochMs, motivo) {
    if (!texto || !motivo) return;
    try {
      onDiscard(texto, capturedAtEpochMs, motivo);
    } catch (error) {
      onError(error);
    }
  }

  function reset({ clearDisplay = true, motivo = "" } = {}) {
    const previousBuffer = buffer;
    const previousStartedAt = startedAt;
    reportDiscard(previousBuffer.trim(), previousStartedAt, motivo);
    clearTimerIfSet();
    buffer = "";
    keyTimes = [];
    startedAt = 0;
    lastKeyAt = 0;
    awaitingUnicodeKeypress = false;
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
    const leadingSpaces = buffer.length - buffer.trimStart().length;
    const timesOfKeys = keyTimes;
    buffer = "";
    keyTimes = [];
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
      reportDiscard(barcode, capturedAtEpochMs, "tela fora da operação");
      return false;
    }

    publishBuffer("", capturedAtEpochMs);
    // Resultados grudados viram envios separados, na ordem, cada um com a hora
    // da sua primeira tecla.
    for (const parte of separarResultadosDoLeitor(barcode)) {
      const horaDaParte = timesOfKeys[leadingSpaces + parte.inicio] || capturedAtEpochMs;
      try {
        Promise.resolve(onBarcode(parte.texto, horaDaParte, loadingIdAtStart)).catch(onError);
      } catch (error) {
        onError(error);
      }
    }
    return true;
  }

  function handleKeyDown(event) {
    if (disposed) return false;

    if (!scannerCaptureEnabled()) {
      reset({ motivo: "tela fora da operação" });
      return false;
    }
    if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || isEditableTarget(event.target)) {
      awaitingUnicodeKeypress = false;
      return false;
    }

    // Injeção Unicode do Windows pode entregar Unidentified no keydown e
    // somente informar o caractere no keypress. O fallback vale só nesse caso.
    awaitingUnicodeKeypress = event.key === "Unidentified" || event.key === "Process";

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
    if (!buffer) {
      startedAt = timestamp;
      loadingIdAtStart = getLoadingId();
    }
    buffer += event.key;
    keyTimes.push(timestamp);
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
    handleKeyPress(event) {
      if (!awaitingUnicodeKeypress) return false;
      awaitingUnicodeKeypress = false;
      const key = typeof event.key === "string" && event.key.length === 1
        ? event.key
        : event.charCode > 0 ? String.fromCharCode(event.charCode) : "";
      if (!key) return false;
      return handleKeyDown({
        key,
        target: event.target,
        defaultPrevented: event.defaultPrevented,
        ctrlKey: event.ctrlKey,
        altKey: event.altKey,
        metaKey: event.metaKey,
        preventDefault: () => event.preventDefault?.(),
        stopImmediatePropagation: () => event.stopImmediatePropagation?.(),
      });
    },
    reset,
    dispose() {
      disposed = true;
      reset();
    },
  };
}
