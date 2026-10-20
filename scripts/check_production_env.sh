#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "$0")/.." && pwd)"
env_file="${1:-$root_dir/.env}"
if [[ -f "$env_file" ]]; then
  set -a
  # shellcheck disable=SC1091
  . "$env_file"
  set +a
fi

failures=0
required=(MYSQL_PASSWORD MYSQL_ROOT_PASSWORD TRACE_DEVICE_TOKEN CAMERA_DEVICE_TOKEN APP_URL)
for key in "${required[@]}"; do
  value="${!key:-}"
  if [[ -z "$value" || "$value" == change-me-* || "$value" == "password" || "$value" == "password1234" || "$value" == "trace-device-local-token-2026-v1" || "$value" == "trace-camera-local-token-2026-v1" ]]; then
    echo "ERRO: $key precisa ser definido com valor próprio e não padrão." >&2
    failures=$((failures + 1))
  fi
done

if [[ ! -f "$env_file" ]]; then
  echo "ERRO: arquivo .env ausente para produção." >&2
  failures=$((failures + 1))
fi

if [[ "${APP_ENV:-}" != "production" ]]; then
  echo "ERRO: APP_ENV deve ser production para esta verificação." >&2
  failures=$((failures + 1))
fi
case "${TRACE_INSTALLATION_MODE:-}" in
  local|industrial|central|remoto|server) ;;
  *)
    echo "ERRO: TRACE_INSTALLATION_MODE deve identificar explicitamente local ou central em produção." >&2
    failures=$((failures + 1))
    ;;
esac
if [[ "${SESSION_SECURE:-}" != "true" ]]; then
  echo "ERRO: SESSION_SECURE deve ser true em produção." >&2
  failures=$((failures + 1))
fi
if [[ "${APP_URL:-}" != https://* ]]; then
  echo "ERRO: APP_URL deve usar HTTPS em produção." >&2
  failures=$((failures + 1))
fi
if [[ "${WEB_BIND_ADDRESS:-}" == "0.0.0.0" || "${WEB_BIND_ADDRESS:-}" == "::" ]]; then
  echo "ERRO: WEB_BIND_ADDRESS não pode expor a interface pública em produção." >&2
  failures=$((failures + 1))
fi
bind_address="${BIND_ADDRESS:-127.0.0.1}"
if [[ "$bind_address" != "127.0.0.1" && "$bind_address" != "::1" ]]; then
  echo "ERRO: BIND_ADDRESS deve ser loopback (127.0.0.1 ou ::1) em produção." >&2
  failures=$((failures + 1))
fi
if [[ ",${COMPOSE_PROFILES//[[:space:]]/}," == *,industrial,* ]]; then
  node_red_user="${NODE_RED_ADMIN_USER:-}"
  node_red_hash="${NODE_RED_ADMIN_PASSWORD_HASH:-}"
  if [[ ! "$node_red_user" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$ ]]; then
    echo "ERRO: NODE_RED_ADMIN_USER precisa ter de 3 a 64 caracteres seguros." >&2
    failures=$((failures + 1))
  fi
  if [[ ! "$node_red_hash" =~ ^\$2[ab]\$[0-9]{2}\$[./A-Za-z0-9]{53}$ ]]; then
    echo "ERRO: NODE_RED_ADMIN_PASSWORD_HASH precisa ser um hash bcrypt; não use senha em texto puro." >&2
    failures=$((failures + 1))
  fi
fi
if [[ -n "${SYNC_REMOTE_URL:-}${SYNC_REMOTE_BATCH_URL:-}" && -z "${SYNC_REMOTE_TOKEN:-}" ]]; then
  echo "ERRO: SYNC_REMOTE_TOKEN é obrigatório quando a sincronização remota está configurada." >&2
  failures=$((failures + 1))
fi
for key in SYNC_REMOTE_URL SYNC_REMOTE_BATCH_URL TRACE_CENTRAL_URL; do
  value="${!key:-}"
  if [[ -n "$value" && "$value" != https://* ]]; then
    echo "ERRO: $key deve usar HTTPS em produção." >&2
    failures=$((failures + 1))
  fi
done
if [[ -n "${APP_URL:-}" && "${APP_URL:-}" == http://* ]]; then
  echo "ERRO: APP_URL não pode usar HTTP em produção." >&2
  failures=$((failures + 1))
fi
if [[ "${TRACE_TESTING_DISABLE_CSRF:-0}" == "1" || "${TRACE_TESTING_DISABLE_LOGIN_RATE_LIMIT:-0}" == "1" ]]; then
  echo "ERRO: proteções de teste não podem ser ativadas em produção." >&2
  failures=$((failures + 1))
fi
if [[ "${TRACE_LOCAL_SIMULATION:-0}" == "1" || "${TRACE_SIMULATOR_ONLY_COMMANDS:-0}" == "1" ]]; then
  echo "ERRO: a simulação local e os comandos fictícios não podem ser ativados em produção." >&2
  failures=$((failures + 1))
fi

if [[ "${TRACE_ENV_CHECK_SKIP_DATABASE:-0}" != "1" ]]; then
  known_hashes=(
    # nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- Hashes conhecidos do demo local servem como lista de bloqueio, não são credenciais de produção.
    '$2y$10$ebOT1MqNyajFths8pCaJu.qE7MOSNMWkXYLan9LVzGSXFHIdgxK8C'
    # nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- Hashes conhecidos do demo local servem como lista de bloqueio, não são credenciais de produção.
    '$2y$12$rNW5syuIUQXWmnkzFzzCUOq7APYSVr.0iN9JIkPvCvoT0mfa/Bwm.'
  )
  for hash in "${known_hashes[@]}"; do
    if ! count="$(bash "$root_dir/scripts/docker_compose.sh" \
      --project-directory "$root_dir" --env-file "$env_file" \
      -f "$root_dir/docker-compose.yml" -f "$root_dir/docker-compose.production.yml" \
      exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
      trace-env-check "SELECT COUNT(*) FROM usuarios WHERE password_hash = '$hash'")"; then
      echo "ERRO: não foi possível consultar as senhas de demonstração no banco; a produção não foi validada." >&2
      failures=$((failures + 1))
      break
    fi
    count="${count%$'\r'}"
    if [[ ! "$count" =~ ^[0-9]+$ ]]; then
      echo "ERRO: o banco retornou uma contagem inválida; a produção não foi validada." >&2
      failures=$((failures + 1))
      break
    fi
    if [[ "$count" =~ [1-9] ]]; then
      echo "ERRO: existe usuário com hash de senha de demonstração conhecido." >&2
      failures=$((failures + 1))
      break
    fi
  done
fi
if [[ -n "${MYSQL_ROOT_PASSWORD:-}" && "${MYSQL_ROOT_PASSWORD:-}" == "change-me-root" ]]; then
  echo "ERRO: MYSQL_ROOT_PASSWORD não pode permanecer em valor padrão." >&2
  failures=$((failures + 1))
fi

if [[ "$failures" -gt 0 ]]; then
  exit 1
fi
if [[ "${TRACE_ENV_CHECK_SKIP_DATABASE:-0}" == "1" ]]; then
  echo "OK: configuração mínima validada; banco não verificado por opção explícita de preparação."
else
  echo "OK: configuração mínima de produção e senhas de demonstração no banco verificadas sem exibir segredos."
fi
