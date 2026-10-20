#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
migrations_dir="$root_dir/banco-de-dados/migrations"

mysql_query() {
  local sql="$1"
  local owner=""
  if [[ "${lock_held:-0}" == "1" ]]; then
    owner="$(mysql_query_raw "SELECT IS_USED_LOCK('${migration_lock}')" | tr -d '[:space:]')" || owner=""
    if [[ "$owner" != "$lock_connection_id" ]]; then
      echo "ERRO: a trava de migrations foi perdida antes da próxima operação." >&2
      return 1
    fi
  fi
  mysql_query_raw "$sql"
}

mysql_query_raw() {
  local sql="$1"
  bash "$root_dir/scripts/docker_compose.sh" --project-directory "$root_dir" exec -T mysql sh -lc \
    'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql --connect-timeout=5 --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
    trace-migrate "$sql"
}

mysql_file() {
  local owner=""
  if [[ "${lock_held:-0}" == "1" ]]; then
    owner="$(mysql_query_raw "SELECT IS_USED_LOCK('${migration_lock}')" | tr -d '[:space:]')" || owner=""
    if [[ "$owner" != "$lock_connection_id" ]]; then
      echo "ERRO: a trava de migrations foi perdida antes de aplicar o arquivo $1." >&2
      return 1
    fi
  fi
  bash "$root_dir/scripts/docker_compose.sh" --project-directory "$root_dir" exec -T mysql sh -lc \
    'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql --connect-timeout=5 -u"$MYSQL_USER" "$MYSQL_DATABASE"' < "$1"
  if [[ "${lock_held:-0}" == "1" ]]; then
    owner="$(mysql_query_raw "SELECT IS_USED_LOCK('${migration_lock}')" | tr -d '[:space:]')" || owner=""
    if [[ "$owner" != "$lock_connection_id" ]]; then
      echo "ERRO: a trava de migrations foi perdida durante a aplicação do arquivo $1." >&2
      return 1
    fi
  fi
}

migration_lock="dallogix-trace-migrations"
lock_directory="$(mktemp -d)"
lock_output="$lock_directory/mysql-output"
lock_error="$lock_directory/mysql-error"
lock_pid=""
lock_connection_id=""
lock_held=0

cleanup_migration_lock() {
  local exit_code="$?"
  local release_result=""
  local session_result=""
  local kill_output=""
  local kill_error=""
  local process_result=0
  local kill_sent=0
  trap - EXIT

  if [[ -n "$lock_pid" ]]; then
    if [[ "$lock_connection_id" =~ ^[0-9]+$ ]]; then
      # O processo docker exec local pode encerrar sem fechar o processo MySQL
      # dentro do contêiner; KILL CONNECTION garante a liberação no servidor.
      if ! kill_output="$(mysql_query_raw "KILL CONNECTION ${lock_connection_id}" 2>&1)"; then
        kill_error="A tentativa KILL CONNECTION falhou: ${kill_output}"
      fi
    fi
    if kill -0 "$lock_pid" 2>/dev/null; then
      if kill "$lock_pid" 2>/dev/null; then
        kill_sent=1
      elif [[ "$exit_code" == "0" ]]; then
        process_result=1
      fi
    fi
    if wait "$lock_pid"; then
      release_result="0"
    else
      local wait_status="$?"
      if [[ "$kill_sent" != "1" ]]; then
        process_result="$wait_status"
      fi
    fi
    if [[ "$exit_code" == "0" && "$lock_held" == "1" ]]; then
      release_result="$(mysql_query_raw "SELECT COALESCE(IS_USED_LOCK('${migration_lock}') <> ${lock_connection_id}, 1)" | tr -d '[:space:]')" || release_result=""
      if [[ "$release_result" != "1" ]]; then
        echo "ERRO: a conexão que mantinha a trava das migrations não confirmou a liberação." >&2
        exit_code=1
      fi
    elif [[ "$exit_code" == "0" && "$process_result" != "0" ]]; then
      echo "ERRO: a sessão que mantinha a trava das migrations terminou com erro." >&2
      exit_code=1
    fi
    if [[ "$lock_connection_id" =~ ^[0-9]+$ ]]; then
      session_result="$(mysql_query_raw "SELECT COUNT(*) FROM information_schema.processlist WHERE id = ${lock_connection_id}" | tr -d '[:space:]')" || session_result=""
      if [[ "$session_result" != "0" ]]; then
        echo "ERRO: não foi possível confirmar o encerramento da sessão MySQL da trava." >&2
        [[ -z "$kill_error" ]] || echo "$kill_error" >&2
        exit_code=1
      fi
    fi
  fi

  rm -rf "$lock_directory"
  exit "$exit_code"
}
trap cleanup_migration_lock EXIT

# Mantém GET_LOCK na mesma sessão MySQL durante as migrations. O trap encerra
# explicitamente essa sessão no servidor em toda saída normal do script.
bash "$root_dir/scripts/docker_compose.sh" --project-directory "$root_dir" exec -T mysql sh -lc \
  'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysql --connect-timeout=5 --batch --skip-column-names --unbuffered -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
  trace-migrate "SELECT GET_LOCK('${migration_lock}', 30), CONNECTION_ID(); SELECT SLEEP(86400)" \
  > "$lock_output" 2> "$lock_error" &
lock_pid="$!"
lock_acquired=""
lock_wait_seconds=0
while [[ "$lock_wait_seconds" -lt 35 ]]; do
  if [[ -s "$lock_output" ]]; then
    IFS=$'\t' read -r lock_acquired lock_connection_id < "$lock_output" || {
      lock_acquired=""
      lock_connection_id=""
    }
    if [[ -n "$lock_acquired" ]]; then
      break
    fi
  fi
  if ! kill -0 "$lock_pid" 2>/dev/null; then
    break
  fi
  sleep 1
  lock_wait_seconds=$((lock_wait_seconds + 1))
done
if [[ "$lock_acquired" != "1" || ! "$lock_connection_id" =~ ^[0-9]+$ ]]; then
  cat "$lock_error" >&2
  echo "ERRO: não foi possível obter a trava de migrations em 30 segundos." >&2
  exit 1
fi
lock_held=1

mysql_query "CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(120) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)"

applied_count="$(mysql_query 'SELECT COUNT(*) FROM schema_migrations' | tr -d '[:space:]')"
has_trace_schema="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'empresas'" | tr -d '[:space:]')"
if [[ "$has_trace_schema" == "1" ]]; then
  status_pc_table="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'status_pc_industrial'" | tr -d '[:space:]')"
  if [[ "$status_pc_table" != "1" ]]; then
    status_pc_migration="$migrations_dir/051_status_pc_industrial.sql"
    [[ -f "$status_pc_migration" ]] || {
      echo "ERRO: migration 051_status_pc_industrial.sql não encontrada." >&2
      exit 1
    }
    echo "Reparando tabela status_pc_industrial antes das migrations"
    mysql_file "$status_pc_migration"
    mysql_query "INSERT IGNORE INTO schema_migrations (version) VALUES ('051_status_pc_industrial')"
  fi
fi
if [[ "$applied_count" == "0" && "$has_trace_schema" == "1" ]]; then
  # Instalações anteriores não tinham controle de versão. O schema atual já
  # contém as 23 migrations históricas; registra-as sem reaplicar ALTERs.
  for migration in "$migrations_dir"/[0-9][0-9][0-9]_*.sql; do
    version="$(basename "$migration" .sql)"
    [[ "$version" < "024_" ]] || continue
    mysql_query "INSERT IGNORE INTO schema_migrations (version) VALUES ('$version')"
  done
fi

for migration in "$migrations_dir"/[0-9][0-9][0-9]_*.sql; do
  version="$(basename "$migration" .sql)"
  if [[ "$(mysql_query "SELECT COUNT(*) FROM schema_migrations WHERE version = '$version'" | tr -d '[:space:]')" == "1" ]]; then
    continue
  fi
  echo "Aplicando migration $version"
  mysql_file "$migration"
  mysql_query "INSERT INTO schema_migrations (version) VALUES ('$version')"
done

# A tabela do heartbeat do PC industrial é necessária para o diagnóstico e
# pode estar ausente em instalações antigas mesmo quando a migration já foi
# registrada no controle de versão. Revalida a estrutura real para que um
# deploy consiga se autocorrigir sem depender de intervenção manual.
status_pc_table="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'status_pc_industrial'" | tr -d '[:space:]')"
if [[ "$status_pc_table" != "1" ]]; then
  status_pc_migration="$migrations_dir/051_status_pc_industrial.sql"
  [[ -f "$status_pc_migration" ]] || {
    echo "ERRO: migration 051_status_pc_industrial.sql não encontrada." >&2
    exit 1
  }
  echo "Reparando tabela status_pc_industrial"
  mysql_file "$status_pc_migration"
  mysql_query "INSERT IGNORE INTO schema_migrations (version) VALUES ('051_status_pc_industrial')"
fi

echo "OK: migrations controladas e atualizadas."
