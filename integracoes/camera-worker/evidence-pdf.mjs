const MAX_JPEG_BYTES = 4 * 1024 * 1024;
const MAX_PDF_BYTES = 10 * 1024 * 1024;

const START_OF_FRAME_MARKERS = new Set([
  0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7,
  0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf,
]);

function dimensionsFromJpeg(jpeg) {
  if (!Buffer.isBuffer(jpeg) || jpeg.length < 4 || jpeg.length > MAX_JPEG_BYTES
    || jpeg[0] !== 0xff || jpeg[1] !== 0xd8
    || jpeg[jpeg.length - 2] !== 0xff || jpeg[jpeg.length - 1] !== 0xd9) {
    throw new Error("A captura não é um JPEG completo dentro do limite permitido.");
  }

  let offset = 2;
  while (offset < jpeg.length - 2) {
    if (jpeg[offset] !== 0xff) {
      const markerOffset = jpeg.indexOf(0xff, offset);
      if (markerOffset < 0 || markerOffset - offset > 16) {
        throw new Error("Estrutura JPEG inválida antes do quadro.");
      }
      offset = markerOffset;
    }
    while (jpeg[offset] === 0xff) offset++;
    const marker = jpeg[offset++];
    if (marker === 0xd9 || marker === 0xda) break;
    if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue;
    if (offset + 2 > jpeg.length) throw new Error("Segmento JPEG incompleto.");

    const segmentLength = jpeg.readUInt16BE(offset);
    if (segmentLength < 2 || offset + segmentLength > jpeg.length) {
      throw new Error("Comprimento de segmento JPEG inválido.");
    }
    if (START_OF_FRAME_MARKERS.has(marker)) {
      if (segmentLength < 8) throw new Error("Cabeçalho do quadro JPEG incompleto.");
      const height = jpeg.readUInt16BE(offset + 3);
      const width = jpeg.readUInt16BE(offset + 5);
      const components = jpeg[offset + 7];
      if (width < 1 || height < 1 || width > 10000 || height > 10000
        || width * height > 40_000_000 || ![1, 3, 4].includes(components)) {
        throw new Error("Dimensões ou espaço de cor JPEG não suportados.");
      }
      return { width, height, components };
    }
    offset += segmentLength;
  }

  throw new Error("O JPEG não contém dimensões de imagem válidas.");
}

function pdfText(value) {
  return String(value ?? "")
    .normalize("NFKD")
    .replace(/[^\x20-\x7e]/g, "?")
    .replace(/[\\()]/g, "\\$&");
}

function makeStream(dictionary, bytes) {
  return Buffer.concat([
    Buffer.from(`${dictionary}\nstream\n`, "ascii"),
    bytes,
    Buffer.from("\nendstream", "ascii"),
  ]);
}

/** Builds a one-page PDF while retaining the original JPEG for report regeneration. */
export function createCameraEvidencePdf(jpeg, { requestId, reason, capturedAt = new Date() } = {}) {
  const { width, height, components } = dimensionsFromJpeg(jpeg);
  const safeRequestId = Number(requestId);
  if (!Number.isSafeInteger(safeRequestId) || safeRequestId < 1) {
    throw new Error("ID de solicitação de captura inválido.");
  }

  const pageWidth = width >= height ? 842 : 595;
  const pageHeight = width >= height ? 595 : 842;
  const imageMaxWidth = pageWidth - 56;
  const imageMaxHeight = pageHeight - 100;
  const scale = Math.min(imageMaxWidth / width, imageMaxHeight / height);
  const imageWidth = width * scale;
  const imageHeight = height * scale;
  const imageX = (pageWidth - imageWidth) / 2;
  const imageY = 28 + (imageMaxHeight - imageHeight) / 2;
  const colorSpace = components === 1 ? "/DeviceGray" : components === 4 ? "/DeviceCMYK" : "/DeviceRGB";
  const timestamp = capturedAt instanceof Date ? capturedAt.toISOString() : String(capturedAt);
  const caption = pdfText(`Evidencia da camera | ${reason || "INCIDENTE"} | Captura ${safeRequestId} | ${timestamp}`);
  const content = [
    `BT /F1 10 Tf 28 ${pageHeight - 34} Td (${caption}) Tj ET`,
    `q ${imageWidth.toFixed(2)} 0 0 ${imageHeight.toFixed(2)} ${imageX.toFixed(2)} ${imageY.toFixed(2)} cm /Im0 Do Q`,
  ].join("\n");

  const objects = [
    Buffer.from("<< /Type /Catalog /Pages 2 0 R >>", "ascii"),
    Buffer.from("<< /Type /Pages /Kids [7 0 R] /Count 1 >>", "ascii"),
    makeStream(
      `<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace ${colorSpace} /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>`,
      jpeg,
    ),
    makeStream(`<< /Type /TraceSourceImage /Subtype /JPEG /Length ${jpeg.length} >>`, jpeg),
    makeStream(`<< /Length ${Buffer.byteLength(content, "ascii")} >>`, Buffer.from(content, "ascii")),
    Buffer.from("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>", "ascii"),
    Buffer.from(
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 6 0 R >> /XObject << /Im0 3 0 R >> >> /Contents 5 0 R >>`,
      "ascii",
    ),
  ];

  const chunks = [Buffer.from("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n", "binary")];
  const offsets = [0];
  let byteOffset = chunks[0].length;
  for (const [index, object] of objects.entries()) {
    const header = Buffer.from(`${index + 1} 0 obj\n`, "ascii");
    const footer = Buffer.from("\nendobj\n", "ascii");
    offsets.push(byteOffset);
    chunks.push(header, object, footer);
    byteOffset += header.length + object.length + footer.length;
  }

  const xrefOffset = byteOffset;
  const xref = [
    `xref\n0 ${objects.length + 1}\n`,
    "0000000000 65535 f \n",
    ...offsets.slice(1).map((offset) => `${String(offset).padStart(10, "0")} 00000 n \n`),
    `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`,
  ].join("");
  chunks.push(Buffer.from(xref, "ascii"));

  const pdf = Buffer.concat(chunks);
  if (pdf.length > MAX_PDF_BYTES) throw new Error("O PDF de evidência ultrapassa 10 MiB.");
  return pdf;
}

export function isCameraEvidencePdf(pdf) {
  return Buffer.isBuffer(pdf)
    && pdf.length >= 100
    && pdf.length <= MAX_PDF_BYTES
    && pdf.subarray(0, 8).toString("ascii") === "%PDF-1.4"
    && pdf.subarray(-5).toString("ascii") === "%%EOF"
    && pdf.includes(Buffer.from("/Type /TraceSourceImage /Subtype /JPEG /Length ", "ascii"));
}

export const CAMERA_EVIDENCE_PDF_LIMIT_BYTES = MAX_PDF_BYTES;
