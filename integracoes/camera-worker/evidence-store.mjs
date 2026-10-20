import { randomBytes } from "node:crypto";
import { mkdir, open, readFile, rename, unlink } from "node:fs/promises";
import path from "node:path";
import { isCameraEvidencePdf } from "./evidence-pdf.mjs";

function localEvidenceFilename(request) {
  const requestId = Number(request?.id);
  const requestedAt = String(request?.requested_at ?? "").replace(/\D/g, "");
  if (!Number.isSafeInteger(requestId) || requestId < 1 || !/^\d{14,20}$/.test(requestedAt)) {
    throw new Error("A solicitação não contém identidade estável para armazenar o PDF local.");
  }
  return `request-${requestId}-${requestedAt}.pdf`;
}

async function removeTemporaryAfterFailure(temporary, originalError) {
  try {
    await unlink(temporary);
  } catch (cleanupError) {
    if (cleanupError?.code !== "ENOENT") {
      throw new AggregateError(
        [originalError, cleanupError],
        "Falha ao gravar o PDF e ao limpar o arquivo temporário.",
      );
    }
  }
}

export async function readLocalEvidencePdf(directory, request) {
  const filename = localEvidenceFilename(request);
  try {
    const pdf = await readFile(path.join(directory, filename));
    if (!isCameraEvidencePdf(pdf)) {
      throw new Error(`O PDF local da solicitação ${request.id} está inválido; a evidência foi preservada.`);
    }
    return pdf;
  } catch (error) {
    if (error?.code === "ENOENT") return null;
    throw error;
  }
}

export async function persistLocalEvidencePdf(directory, request, pdf) {
  if (!isCameraEvidencePdf(pdf)) throw new Error("O conteúdo local não é um PDF de evidência válido.");
  await mkdir(directory, { recursive: true, mode: 0o770 });
  const filename = localEvidenceFilename(request);
  const destination = path.join(directory, filename);
  const existing = await readLocalEvidencePdf(directory, request);
  if (existing) return existing;

  const temporary = `${destination}.tmp-${randomBytes(8).toString("hex")}`;
  const file = await open(temporary, "wx", 0o640);
  try {
    await file.writeFile(pdf);
    await file.sync();
  } catch (error) {
    try {
      await file.close();
    } catch (closeError) {
      throw new AggregateError([error, closeError], "Falha ao gravar e fechar o PDF local.");
    }
    await removeTemporaryAfterFailure(temporary, error);
    throw error;
  }
  try {
    await file.close();
  } catch (error) {
    await removeTemporaryAfterFailure(temporary, error);
    throw error;
  }
  try {
    await rename(temporary, destination);
  } catch (error) {
    await removeTemporaryAfterFailure(temporary, error);
    const concurrentCopy = await readLocalEvidencePdf(directory, request);
    if (concurrentCopy) return concurrentCopy;
    throw error;
  }
  return pdf;
}

export { localEvidenceFilename };
