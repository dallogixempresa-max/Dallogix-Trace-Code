#!/usr/bin/env bash
set -euo pipefail

umask 077
root_dir="$(cd "$(dirname "$0")/.." && pwd)"
output_dir="${1:-${TRACE_BACKUP_DIR:-$root_dir/armazenamento/backups}}"
mkdir -p -m 700 "$output_dir"
chmod 700 "$output_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
staging_dir="$(mktemp -d "$output_dir/.backup-${timestamp}.XXXXXX")"
trap 'rm -rf "$staging_dir"' EXIT
filename="trace_local_${timestamp}_${staging_dir##*.}.sql"
staging_file="$staging_dir/$filename"
output_file="$output_dir/$filename"

compose_args=(--project-directory "$root_dir")
if [[ -n "${TRACE_COMPOSE_ENV_FILE:-}" ]]; then
  [[ -f "$TRACE_COMPOSE_ENV_FILE" ]] || {
    printf 'Arquivo de ambiente do Compose não encontrado: %s\n' "$TRACE_COMPOSE_ENV_FILE" >&2
    exit 2
  }
  compose_args+=(--env-file "$TRACE_COMPOSE_ENV_FILE")
fi
case "${TRACE_COMPOSE_PRODUCTION:-0}" in
  0) ;;
  1)
    installation_mode="$(printf '%s' "${TRACE_INSTALLATION_MODE:-}" | tr '[:upper:]' '[:lower:]')"
    if [[ "$installation_mode" == "local" || "$installation_mode" == "industrial" ]]; then
      compose_args+=(--profile industrial)
    fi
    compose_args+=(-f "$root_dir/docker-compose.yml" -f "$root_dir/docker-compose.production.yml")
    ;;
  *)
    printf 'TRACE_COMPOSE_PRODUCTION deve ser 0 ou 1.\n' >&2
    exit 2
    ;;
esac

# As credenciais pertencem ao contêiner MySQL. Executar a expansão lá evita
# depender de variáveis existentes no host do servidor e não expõe a senha.
bash "$root_dir/scripts/docker_compose.sh" "${compose_args[@]}" exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --routines --events --triggers --no-tablespaces -u"$MYSQL_USER" "$MYSQL_DATABASE"' > "$staging_file"

test -s "$staging_file"
if command -v sha256sum >/dev/null 2>&1; then
  (cd "$staging_dir" && sha256sum "$filename") > "$staging_file.sha256"
else
  (cd "$staging_dir" && shasum -a 256 "$filename") > "$staging_file.sha256"
fi
bash "$root_dir/scripts/verify_backup.sh" "$staging_file"
mv "$staging_file.sha256" "$output_file.sha256"
mv "$staging_file" "$output_file"
chmod 600 "$output_file" "$output_file.sha256"

retention_days="${TRACE_BACKUP_RETENTION_DAYS:-30}"
if [[ "$retention_days" =~ ^[0-9]+$ ]]; then
  find "$output_dir" -maxdepth 1 -type f -name 'trace_local_*.sql' -mtime "+$retention_days" -delete
  find "$output_dir" -maxdepth 1 -type f -name 'trace_local_*.sql.sha256' -mtime "+$retention_days" -delete
fi
printf 'Backup criado: %s\n' "$output_file"
