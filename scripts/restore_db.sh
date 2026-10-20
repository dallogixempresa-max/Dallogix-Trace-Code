#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! -f "$1" ]]; then
  echo "Uso: $0 /caminho/backup.sql" >&2
  exit 2
fi

root_dir="$(cd "$(dirname "$0")/.." && pwd)"
env_file="${TRACE_ENV_FILE:-$root_dir/.env}"
if [[ -f "$env_file" ]]; then
  set -a
  # shellcheck disable=SC1091
  . "$env_file"
  set +a
fi

if [[ "${APP_ENV:-local}" == "production" && "${TRACE_ALLOW_RESTORE:-0}" != "1" ]]; then
  echo "Restauração bloqueada em produção. Defina TRACE_ALLOW_RESTORE=1 conscientemente." >&2
  exit 3
fi

bash "$root_dir/scripts/verify_backup.sh" "$1"

docker compose --project-directory "$root_dir" exec -T mysql sh -lc \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' < "$1"
printf 'Backup restaurado: %s\n' "$1"
