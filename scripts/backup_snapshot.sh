#!/usr/bin/env bash
set -euo pipefail

umask 077
root_dir="$(cd "$(dirname "$0")/.." && pwd)"
storage_dir="$root_dir/armazenamento"
output_dir="${1:-${TRACE_SNAPSHOT_DIR:-$storage_dir/backups/snapshots}}"
retention_days="${TRACE_BACKUP_RETENTION_DAYS:-30}"

[[ -d "$storage_dir" ]] || {
  printf 'Diretório persistente não encontrado: %s\n' "$storage_dir" >&2
  exit 2
}
[[ "$retention_days" =~ ^[0-9]+$ ]] || {
  printf 'TRACE_BACKUP_RETENTION_DAYS deve ser um inteiro não negativo.\n' >&2
  exit 2
}

mkdir -p -m 700 "$output_dir"
chmod 700 "$output_dir"
output_dir="$(cd "$output_dir" && pwd -P)"
storage_dir="$(cd "$storage_dir" && pwd -P)"
if [[ "$output_dir" == "$storage_dir"/* && "$output_dir" != "$storage_dir/backups" && "$output_dir" != "$storage_dir/backups"/* ]]; then
  printf 'O destino dentro de armazenamento precisa ficar sob armazenamento/backups para não ser arquivado recursivamente.\n' >&2
  exit 2
fi

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
    compose_args+=(
      -f "$root_dir/docker-compose.yml"
      -f "$root_dir/docker-compose.production.yml"
    )
    ;;
  *)
    printf 'TRACE_COMPOSE_PRODUCTION deve ser 0 ou 1.\n' >&2
    exit 2
    ;;
esac
compose_args+=(--profile industrial)
docker_compose() {
  bash "$root_dir/scripts/docker_compose.sh" "${compose_args[@]}" "$@"
}

docker_volume() {
  local docker_bin="${TRACE_DOCKER_BIN:-$(command -v docker || true)}"
  [[ -n "$docker_bin" ]] || {
    printf 'Docker não encontrado para ler o volume persistente do Node-RED.\n' >&2
    return 1
  }
  if command -v sudo >/dev/null 2>&1 && sudo -n "$docker_bin" info >/dev/null 2>&1; then
    sudo -n "$docker_bin" "$@"
  else
    "$docker_bin" "$@"
  fi
}

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
staging_dir="$(mktemp -d "$output_dir/.snapshot-${timestamp}.XXXXXX")"
trap 'rm -rf -- "$staging_dir"' EXIT
chmod 700 "$staging_dir"

database_dir="$staging_dir/database"
mkdir -m 700 "$database_dir"
bash "$root_dir/scripts/backup_db.sh" "$database_dir" >/dev/null
database_files=("$database_dir"/trace_local_*.sql)
[[ -f "${database_files[0]}" && -f "${database_files[0]}.sha256" ]] || {
  printf 'O backup SQL não foi criado com checksum.\n' >&2
  exit 1
}
mv -- "${database_files[0]}" "$staging_dir/database.sql"
mv -- "${database_files[0]}.sha256" "$staging_dir/database.sql.sha256"
rmdir "$database_dir"
(
  cd "$staging_dir"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum database.sql
  else
    shasum -a 256 database.sql
  fi
) > "$staging_dir/database.sql.sha256"

tar -czf "$staging_dir/storage.tar.gz" \
  --exclude=./backups \
  --exclude=./updates \
  --exclude=./producao-teste \
  -C "$storage_dir" .

node_red_container="$(docker_compose ps -q node-red | tr -d '[:space:]')"
if [[ -n "$node_red_container" ]]; then
  docker_compose exec -T node-red tar -czf - -C /data . > "$staging_dir/node-red-data.tar.gz"
else
  # O Central não sobe o perfil industrial, mas o volume pode conter fluxos
  # persistentes. Leia-o diretamente para que o snapshot não dependa de um
  # contêiner Node-RED em execução.
  node_red_volume="$(docker_compose config --format json | python3 -c 'import json,sys; data=json.load(sys.stdin); print(data.get("volumes", {}).get("node_red_data", {}).get("name", ""))')"
  [[ "$node_red_volume" =~ ^[A-Za-z0-9_.-]+$ ]] || {
    printf 'Volume persistente do Node-RED não foi identificado.\n' >&2
    exit 1
  }
  node_red_mount="$(docker_volume volume inspect "$node_red_volume" --format '{{.Mountpoint}}')"
  [[ -d "$node_red_mount" ]] || {
    printf 'Ponto de montagem do volume Node-RED não encontrado: %s\n' "$node_red_mount" >&2
    exit 1
  }
  tar -czf "$staging_dir/node-red-data.tar.gz" -C "$node_red_mount" .
fi

commit="unknown"
if git -C "$root_dir" rev-parse --verify HEAD >/dev/null 2>&1; then
  commit="$(git -C "$root_dir" rev-parse --short HEAD)"
fi
cat > "$staging_dir/metadata.txt" <<EOF
format=1
created_at_utc=$timestamp
trace_commit=$commit
node_red_volume=node_red_data
contents=database.sql,storage.tar.gz,node-red-data.tar.gz
EOF

chmod 600 \
  "$staging_dir/database.sql" \
  "$staging_dir/database.sql.sha256" \
  "$staging_dir/storage.tar.gz" \
  "$staging_dir/node-red-data.tar.gz" \
  "$staging_dir/metadata.txt"
(
  cd "$staging_dir"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum database.sql database.sql.sha256 storage.tar.gz node-red-data.tar.gz metadata.txt
  else
    shasum -a 256 database.sql database.sql.sha256 storage.tar.gz node-red-data.tar.gz metadata.txt
  fi
) > "$staging_dir/SHA256SUMS"
chmod 600 "$staging_dir/SHA256SUMS"
bash "$root_dir/scripts/verify_snapshot.sh" "$staging_dir"

snapshot_name="trace_snapshot_${timestamp}_${staging_dir##*.}"
snapshot_path="$output_dir/$snapshot_name"
mv -- "$staging_dir" "$snapshot_path"
trap - EXIT
chmod 700 "$snapshot_path"

find "$output_dir" -mindepth 1 -maxdepth 1 -type d -name 'trace_snapshot_*' -mtime "+$retention_days" -exec rm -rf -- {} +
printf 'Snapshot criado e verificado: %s\n' "$snapshot_path"
