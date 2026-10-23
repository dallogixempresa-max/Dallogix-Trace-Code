#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root_dir="$(cd "${TRACE_GIT_DEPLOY_ROOT:-$script_dir/..}" && pwd)"
branch="${TRACE_GIT_DEPLOY_BRANCH:-production}"
lock_file="${TRACE_GIT_DEPLOY_LOCK:-/run/lock/dallogix-trace-git-deploy.lock}"

[[ "$branch" =~ ^[A-Za-z0-9._/-]+$ ]] || {
  echo "Nome de branch de deploy inválido." >&2
  exit 2
}
[[ -f "$root_dir/.env" ]] || {
  echo "Arquivo .env ausente; deploy pelo Git cancelado." >&2
  exit 2
}
[[ -s "$root_dir/secrets/cloudflare_tunnel_token" ]] || {
  echo "Token do Cloudflare Tunnel ausente; deploy pelo Git cancelado." >&2
  exit 2
}

mkdir -p "$(dirname "$lock_file")"
exec 9>"$lock_file"
if ! flock -n 9; then
  echo "Outro deploy pelo Git já está em andamento; esta verificação foi adiada."
  exit 0
fi

cd "$root_dir"
git diff --quiet && git diff --cached --quiet || {
  echo "Há alterações locais rastreadas; deploy cancelado para preservá-las." >&2
  exit 2
}

git fetch --quiet --no-tags origin "$branch"
target_commit="$(git rev-parse FETCH_HEAD)"
current_commit="$(git rev-parse HEAD)"
failed_file="$root_dir/armazenamento/.git-deploy-failed"
if [[ -f "$failed_file" ]] && grep -Fqx "commit=$target_commit" "$failed_file"; then
  echo "Este commit já falhou no deploy; intervenção manual é necessária antes de repetir." >&2
  exit 20
fi
if [[ "$target_commit" == "$current_commit" ]]; then
  exit 0
fi
git merge-base --is-ancestor "$current_commit" "$target_commit" || {
  echo "A branch de produção não avança por fast-forward; deploy cancelado." >&2
  exit 2
}

maintenance_file="$root_dir/armazenamento/.maintenance"
mkdir -p "$root_dir/armazenamento"
if [[ -e "$maintenance_file" ]] || ! (set -o noclobber; : > "$maintenance_file") 2>/dev/null; then
  echo "O sistema já está em manutenção; deploy pelo Git adiado."
  exit 0
fi
maintenance_owned=1
cleanup() {
  local result="$?"
  trap - EXIT
  if [[ "${maintenance_owned:-0}" == "1" ]]; then
    rm -f -- "$maintenance_file"
  fi
  exit "$result"
}
trap cleanup EXIT

compose_args=(
  --project-directory "$root_dir"
  --env-file "$root_dir/.env"
  -f "$root_dir/docker-compose.yml"
  -f "$root_dir/docker-compose.production.yml"
)
compose() {
  docker compose "${compose_args[@]}" "$@"
}

active_loads="$(compose exec -T mysql sh -lc \
  'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql --connect-timeout=5 --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
  trace-git-deploy \
  "SELECT COUNT(*) FROM carregamentos WHERE state IN ('PREPARANDO', 'CARREGANDO', 'PAUSADO', 'FINALIZANDO', 'EMERGENCIA');" \
  | tr -d '[:space:]')"
[[ "$active_loads" =~ ^[0-9]+$ ]] || {
  echo "Não foi possível confirmar que não há carregamento ativo; deploy cancelado." >&2
  exit 3
}
if [[ "$active_loads" != "0" ]]; then
  echo "Deploy pelo Git adiado: há carregamento ativo ou em estado de intervenção."
  exit 0
fi

TRACE_COMPOSE_ENV_FILE="$root_dir/.env" TRACE_COMPOSE_PRODUCTION=1 \
  bash "$root_dir/scripts/backup_db.sh" "$root_dir/armazenamento/backups/git-deploy"

git checkout --detach "$target_commit"
release_version="$(git describe --tags --exact-match HEAD 2>/dev/null || true)"
if [[ ! "$release_version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  release_version="$target_commit"
fi
if ! TRACE_DEPLOY_COMMIT="$target_commit" TRACE_DEPLOY_VERSION="$release_version" \
  bash "$root_dir/scripts/deploy_production.sh" up -d --build --remove-orphans; then
  printf 'commit=%s\nfailed_at=%s\nphase=compose\n' "$target_commit" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$failed_file"
  chmod 600 "$failed_file"
  echo "Compose falhou; o commit ficou registrado para revisão manual." >&2
  exit 10
fi

if ! bash "$root_dir/scripts/migrate.sh"; then
  printf 'commit=%s\nfailed_at=%s\nphase=migrations\n' "$target_commit" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$failed_file"
  chmod 600 "$failed_file"
  echo "Migrations falharam; o banco não será restaurado automaticamente." >&2
  exit 11
fi

expected_commit="$target_commit"
web_port="$(awk -F= '$1 == "WEB_PORT" {gsub(/[[:space:]\047\042]/, "", $2); print $2; exit}' "$root_dir/.env")"
web_port="${web_port:-8080}"
for attempt in {1..30}; do
  response="$(curl --fail --silent --show-error --connect-timeout 2 --max-time 5 \
    "http://127.0.0.1:${web_port}/api/health.php" 2>/dev/null || true)"
  if [[ -n "$response" ]] && python3 - "$response" "$expected_commit" <<'PY'
import json, sys
try:
    data = json.loads(sys.argv[1])
except (ValueError, TypeError):
    raise SystemExit(1)
raise SystemExit(0 if data.get("status") == "ok" and data.get("commit") == sys.argv[2] else 1)
PY
  then
    rm -f -- "$failed_file"
    echo "Deploy Git concluído e validado: $expected_commit"
    exit 0
  fi
  sleep 2
done

printf 'commit=%s\nfailed_at=%s\nphase=healthcheck\n' "$target_commit" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$failed_file"
chmod 600 "$failed_file"
echo "Healthcheck não confirmou o commit novo; deploy registrado para revisão manual." >&2
exit 12
