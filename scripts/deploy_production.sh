#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${1:-$root_dir/.env}"
shift || true

[[ -f "$env_file" ]] || {
  echo "ERRO: arquivo .env de produção não encontrado: $env_file" >&2
  exit 2
}

bash "$root_dir/scripts/check_production_env.sh" "$env_file"

set -a
# shellcheck disable=SC1090
. "$env_file"
set +a

trace_commit="${TRACE_COMMIT:-$(git -C "$root_dir" rev-parse HEAD 2>/dev/null || echo unknown)}"
trace_version="${TRACE_VERSION:-$trace_commit}"
export TRACE_COMMIT="$trace_commit" TRACE_VERSION="$trace_version"

exec bash "$root_dir/scripts/docker_compose.sh" \
  --env-file "$env_file" \
  -f "$root_dir/docker-compose.yml" \
  -f "$root_dir/docker-compose.production.yml" \
  "$@"
