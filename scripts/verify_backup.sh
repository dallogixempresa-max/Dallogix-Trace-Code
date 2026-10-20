#!/usr/bin/env bash
set -euo pipefail

backup="${1:-}"
if [[ -z "$backup" || ! -f "$backup" ]]; then
  echo "Uso: $0 /caminho/backup.sql" >&2
  exit 2
fi

if [[ ! -f "$backup.sha256" ]]; then
  echo "Backup sem checksum: integridade não pode ser confirmada." >&2
  exit 5
fi
# Usa somente o hash salvo: o caminho antigo no checksum nunca escolhe o arquivo.
read -r expected_hash checksum_path < "$backup.sha256"
if [[ ! "$expected_hash" =~ ^[[:xdigit:]]{64}$ ]] || [[ "$(awk 'END {print NR}' "$backup.sha256")" != "1" ]]; then
  echo "Checksum inválido." >&2
  exit 5
fi
if command -v sha256sum >/dev/null 2>&1; then
  actual_hash="$(sha256sum < "$backup")"
else
  actual_hash="$(shasum -a 256 < "$backup")"
fi
actual_hash="${actual_hash%% *}"
expected_hash="$(printf '%s' "$expected_hash" | tr 'A-F' 'a-f')"
if [[ "$actual_hash" != "$expected_hash" ]]; then
  echo "Backup corrompido: checksum divergente." >&2
  exit 5
fi

grep -q '^-- MySQL dump' "$backup" || {
  echo "Backup inválido: cabeçalho mysqldump não encontrado." >&2
  exit 3
}
grep -q 'CREATE TABLE' "$backup" || {
  echo "Backup inválido: nenhuma tabela encontrada." >&2
  exit 4
}
tail -n 1 "$backup" | grep -q '^-- Dump completed on ' || {
  echo "Backup incompleto: marcador final do mysqldump não encontrado." >&2
  exit 6
}
printf 'Checksum e marcador de conclusão conferidos: %s\n' "$backup"
