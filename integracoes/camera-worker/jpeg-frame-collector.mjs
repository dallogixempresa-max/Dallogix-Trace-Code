const JPEG_START = Buffer.from([0xff, 0xd8]);
const JPEG_END = Buffer.from([0xff, 0xd9]);
const EMPTY_BUFFER = Buffer.alloc(0);

/**
 * Remonta quadros JPEG recebidos em fragmentos sem copiar o quadro inteiro a
 * cada fragmento. Mantém apenas partes do quadro e um byte de sobreposição
 * para reconhecer marcadores que cruzam a fronteira entre dois fragmentos.
 */
export class JpegFrameCollector {
  constructor(maxFrameBytes) {
    if (!Number.isSafeInteger(maxFrameBytes) || maxFrameBytes < 4) {
      throw new RangeError("O limite do quadro JPEG precisa ser um inteiro positivo.");
    }
    this.maxFrameBytes = maxFrameBytes;
    this.pendingByte = EMPTY_BUFFER;
    this.frameParts = [];
    this.frameLength = 0;
    this.collectingFrame = false;
  }

  push(chunk) {
    return this.consume(chunk, true);
  }

  /** Retorna somente o último JPEG do bloco sem criar buffers para quadros descartados. */
  pushLatest(chunk) {
    return this.consume(chunk, false);
  }

  consume(chunk, includeAllFrames) {
    const input = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    const data = this.pendingByte.length > 0
      ? Buffer.concat([this.pendingByte, input])
      : input;
    this.pendingByte = EMPTY_BUFFER;

    const frames = [];
    let latestFrameParts = null;
    let latestFrameLength = 0;
    let offset = 0;
    while (offset < data.length) {
      if (!this.collectingFrame) {
        const start = data.indexOf(JPEG_START, offset);
        if (start < 0) {
          this.pendingByte = data.subarray(data.length - 1);
          break;
        }
        this.beginFrame();
        offset = start;
      }

      const end = data.indexOf(JPEG_END, offset);
      if (end >= 0) {
        this.append(data.subarray(offset, end + JPEG_END.length));
        if (this.frameLength <= this.maxFrameBytes) {
          if (includeAllFrames) {
            frames.push(Buffer.concat(this.frameParts, this.frameLength));
          } else {
            latestFrameParts = this.frameParts;
            latestFrameLength = this.frameLength;
          }
        }
        this.resetFrame();
        offset = end + JPEG_END.length;
        continue;
      }

      // O último byte fica pendente para detectar FFD9 caso chegue no próximo chunk.
      const safeEnd = Math.max(offset, data.length - 1);
      this.append(data.subarray(offset, safeEnd));
      this.pendingByte = data.subarray(data.length - 1);
      if (this.frameLength > this.maxFrameBytes) {
        // Como o quadro ainda não terminou, descarta o restante deste chunk,
        // limitando memória e mantendo a recuperação no próximo fragmento.
        this.resetFrame();
        this.pendingByte = EMPTY_BUFFER;
      }
      break;
    }

    return includeAllFrames
      ? frames
      : latestFrameParts
        ? Buffer.concat(latestFrameParts, latestFrameLength)
        : null;
  }

  reset() {
    this.pendingByte = EMPTY_BUFFER;
    this.resetFrame();
  }

  beginFrame() {
    this.collectingFrame = true;
    this.frameParts = [];
    this.frameLength = 0;
  }

  append(part) {
    if (part.length === 0) return;
    this.frameParts.push(part);
    this.frameLength += part.length;
  }

  resetFrame() {
    this.collectingFrame = false;
    this.frameParts = [];
    this.frameLength = 0;
  }
}
