import { createHash, randomUUID } from "node:crypto";
import { link, mkdir, readFile, readdir, rename, stat, unlink, writeFile } from "node:fs/promises";
import { join, relative, sep } from "node:path";

const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const MAX_LEDGER_ENTRIES = 5000;

function sha256(value) {
  return createHash("sha256").update(value).digest("hex");
}

async function listJpegFiles(root, current = root) {
  const files = [];
  const entries = await readdir(current, { withFileTypes: true });
  for (const entry of entries) {
    const path = join(current, entry.name);
    if (entry.isDirectory()) {
      files.push(...await listJpegFiles(root, path));
    } else if (entry.isFile() && /\.jpe?g$/i.test(entry.name)) {
      files.push({ path, relativePath: relative(root, path).split(sep).join("/") });
    }
  }
  return files;
}

async function readLedger(path) {
  try {
    const parsed = JSON.parse(await readFile(path, "utf8"));
    if (parsed?.version !== 1 || typeof parsed.entries !== "object" || parsed.entries === null || Array.isArray(parsed.entries)) {
      throw new Error("Formato do registro de imagens Keyence inválido.");
    }
    return parsed.entries;
  } catch (error) {
    if (error.code === "ENOENT") return {};
    throw error;
  }
}

async function writeLedger(path, entries) {
  const ordered = Object.entries(entries)
    .sort((left, right) => Number(left[1]?.processedAt) - Number(right[1]?.processedAt))
    .slice(-MAX_LEDGER_ENTRIES);
  const temporary = `${path}.${randomUUID()}.tmp`;
  await writeFile(temporary, JSON.stringify({ version: 1, entries: Object.fromEntries(ordered) }), {
    flag: "wx",
    mode: 0o600,
  });
  try {
    await rename(temporary, path);
  } finally {
    await unlink(temporary).catch((error) => { if (error.code !== "ENOENT") throw error; });
  }
}

function isJpeg(bytes) {
  return bytes.length >= 100
    && bytes.length <= MAX_IMAGE_BYTES
    && bytes[0] === 0xff && bytes[1] === 0xd8
    && bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9;
}

/**
 * Imports recent Keyence error images from a read-only host-folder mount into
 * the durable Trace inbox. Source files are never changed or removed.
 */
export async function importArchivedImages({
  sourceDirectory,
  inboxDirectory,
  now = Date.now(),
  settleMs = 800,
  maxAgeMs = 300000,
  log = () => {},
}) {
  if (!sourceDirectory) return { available: false, imported: 0, skipped: 0 };

  let files;
  try {
    files = await listJpegFiles(sourceDirectory);
  } catch (error) {
    if (error.code === "ENOENT") return { available: false, imported: 0, skipped: 0 };
    throw error;
  }

  await mkdir(inboxDirectory, { recursive: true, mode: 0o770 });
  const ledgerPath = join(inboxDirectory, ".keyence-archive-ledger.json");
  const ledger = await readLedger(ledgerPath);
  let imported = 0;
  let skipped = 0;

  for (const source of files) {
    const before = await stat(source.path);
    const modifiedAt = Math.round(before.mtimeMs);
    const ageMs = now - modifiedAt;
    if (ageMs < settleMs || ageMs > maxAgeMs || before.size > MAX_IMAGE_BYTES) continue;

    const bytes = await readFile(source.path);
    const after = await stat(source.path);
    if (before.size !== after.size || before.mtimeMs !== after.mtimeMs || bytes.length !== after.size) continue;

    const imageHash = sha256(bytes);
    const entryKey = sha256(`${source.relativePath}\0${after.size}\0${modifiedAt}\0${imageHash}`);
    if (ledger[entryKey]) continue;

    if (!isJpeg(bytes)) {
      ledger[entryKey] = { processedAt: now, outcome: "invalid" };
      skipped += 1;
      log("warn", "Arquivo do diretório de falhas Keyence não é um JPEG válido.", {
        source_hash: entryKey.slice(0, 16),
        bytes: bytes.length,
      });
      await writeLedger(ledgerPath, ledger);
      continue;
    }

    const timestamp = String(modifiedAt).padStart(13, "0");
    const sourceBasename = source.relativePath.split("/").at(-1);
    const safeSourceBasename = sourceBasename.replace(/[^A-Za-z0-9._-]/g, "_").slice(0, 80) || "imagem.jpg";
    const targetName = `${timestamp}--${imageHash}-${entryKey.slice(0, 8)}-archive-${safeSourceBasename}`;
    const target = join(inboxDirectory, targetName);
    const temporary = `${target}.${randomUUID()}.tmp`;
    try {
      await writeFile(temporary, bytes, { flag: "wx", mode: 0o600 });
      try {
        await link(temporary, target);
      } catch (error) {
        if (error.code !== "EEXIST") throw error;
        const existingBytes = await readFile(target);
        if (sha256(existingBytes) !== imageHash) throw new Error("Destino da imagem Keyence já existe com outro conteúdo.");
      }
    } finally {
      await unlink(temporary).catch((error) => { if (error.code !== "ENOENT") throw error; });
    }

    ledger[entryKey] = { processedAt: now, outcome: "queued" };
    await writeLedger(ledgerPath, ledger);
    imported += 1;
    log("info", "Imagem de falha importada da pasta Keyence para a fila do Trace.", {
      source_file_name: safeSourceBasename,
      source_hash: entryKey.slice(0, 16),
      image_sha256: imageHash,
      captured_at_epoch_ms: modifiedAt,
      bytes: bytes.length,
    });
  }

  return { available: true, imported, skipped };
}
