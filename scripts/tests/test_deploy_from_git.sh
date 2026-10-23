#!/usr/bin/env bash
set -euo pipefail

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d /tmp/trace-deploy-test.XXXXXX)"
app_root="$test_root/app"
bin_root="$test_root/bin"
target_commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
current_commit=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
release_version=v1.0.178

mkdir -p "$app_root/scripts" "$app_root/armazenamento/backups" "$bin_root"
cp "$source_root/scripts/deploy_from_git.sh" "$app_root/scripts/deploy_from_git.sh"
printf 'WEB_PORT=8080\n' > "$app_root/.env"
printf '%s\n' "$current_commit" > "$test_root/head"

cat > "$app_root/scripts/backup_db.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "${TRACE_COMPOSE_ENV_FILE:-}" == "$TRACE_GIT_DEPLOY_ROOT/.env" ]]
[[ "${TRACE_COMPOSE_PRODUCTION:-}" == 1 ]]
touch "$TRACE_GIT_DEPLOY_ROOT/backup.called"
EOF

cat > "$app_root/scripts/deploy_production.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == "$TRACE_GIT_DEPLOY_ROOT/.env" ]] || {
  echo "deploy_production.sh must receive the production .env as its first argument; got '${1:-}'" >&2
  exit 97
}
[[ "${2:-}" == up ]]
[[ "${TRACE_DEPLOY_COMMIT:-}" == "$TRACE_EXPECTED_COMMIT" ]]
[[ "${TRACE_DEPLOY_VERSION:-}" == "$TRACE_TEST_VERSION" ]]
touch "$TRACE_GIT_DEPLOY_ROOT/compose.called"
EOF

cat > "$app_root/scripts/migrate.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
touch "$TRACE_GIT_DEPLOY_ROOT/migrate.called"
EOF

cat > "$bin_root/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  diff|fetch|merge-base) exit 0 ;;
  rev-parse)
    case "$2" in
      FETCH_HEAD) printf '%s\n' "$TRACE_EXPECTED_COMMIT" ;;
      HEAD) cat "$TRACE_TEST_HEAD_FILE" ;;
      *) echo "unexpected git rev-parse argument: $2" >&2; exit 98 ;;
    esac
    ;;
  checkout)
    [[ "$2" == --detach ]]
    printf '%s\n' "$3" > "$TRACE_TEST_HEAD_FILE"
    ;;
  describe)
    printf '%s\n' "$TRACE_TEST_VERSION"
    ;;
  *) echo "unexpected git command: $*" >&2; exit 99 ;;
esac
EOF

cat > "$bin_root/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '0\n'
EOF

cat > "$bin_root/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '{"status":"ok","commit":"%s"}\n' "$TRACE_EXPECTED_COMMIT"
EOF

cat > "$bin_root/flock" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == -n ]]
exit 0
EOF

chmod +x "$app_root/scripts/backup_db.sh" "$app_root/scripts/deploy_production.sh" \
  "$app_root/scripts/migrate.sh" "$bin_root/git" "$bin_root/docker" "$bin_root/curl" "$bin_root/flock"

export TRACE_GIT_DEPLOY_ROOT="$app_root"
export TRACE_GIT_DEPLOY_BRANCH=production
export TRACE_GIT_DEPLOY_LOCK="$test_root/deploy.lock"
export TRACE_TEST_HEAD_FILE="$test_root/head"
export TRACE_EXPECTED_COMMIT="$target_commit"
export TRACE_TEST_VERSION="$release_version"
export PATH="$bin_root:$PATH"

bash "$app_root/scripts/deploy_from_git.sh"
[[ -f "$app_root/backup.called" ]]
[[ -f "$app_root/compose.called" ]]
[[ -f "$app_root/migrate.called" ]]
[[ ! -e "$app_root/armazenamento/.maintenance" ]]
[[ "$(cat "$test_root/head")" == "$target_commit" ]]
printf 'PASS: guarded deploy passes .env, release identity, backup, migrations, and health validation.\n'
