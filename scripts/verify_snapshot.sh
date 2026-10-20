#!/usr/bin/env bash
set -euo pipefail

snapshot="${1:-}"
if [[ -z "$snapshot" || ! -d "$snapshot" || -L "$snapshot" ]]; then
  printf 'Uso: %s /caminho/trace_snapshot_<timestamp>\n' "$0" >&2
  exit 2
fi

python3 - "$snapshot" <<'PY'
import hashlib
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
expected_names = {
    "database.sql",
    "database.sql.sha256",
    "storage.tar.gz",
    "node-red-data.tar.gz",
    "metadata.txt",
}
manifest = root / "SHA256SUMS"
if not manifest.is_file() or manifest.is_symlink():
    raise SystemExit("Manifesto SHA256SUMS ausente ou inválido.")

checks = {}
for line in manifest.read_text(encoding="utf-8").splitlines():
    match = re.fullmatch(r"([0-9a-fA-F]{64})  ([A-Za-z0-9._-]+)", line)
    if not match:
        raise SystemExit("Linha inválida no manifesto SHA256SUMS.")
    digest, name = match.groups()
    if name in checks or name not in expected_names:
        raise SystemExit(f"Arquivo repetido ou inesperado no manifesto: {name}")
    file_path = root / name
    if not file_path.is_file() or file_path.is_symlink():
        raise SystemExit(f"Arquivo ausente ou inválido no snapshot: {name}")
    digest_state = hashlib.sha256()
    with file_path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest_state.update(chunk)
    actual = digest_state.hexdigest()
    if actual.lower() != digest.lower():
        raise SystemExit(f"Checksum divergente: {name}")
    checks[name] = digest

if set(checks) != expected_names:
    raise SystemExit("O manifesto não cobre todos os componentes do snapshot.")
PY

bash "$(cd "$(dirname "$0")" && pwd)/verify_backup.sh" "$snapshot/database.sql"
tar -tzf "$snapshot/storage.tar.gz" >/dev/null
tar -tzf "$snapshot/node-red-data.tar.gz" >/dev/null
grep -q '^format=1$' "$snapshot/metadata.txt"
printf 'Snapshot íntegro; SQL e arquivos compactados podem ser lidos: %s\n' "$snapshot"
