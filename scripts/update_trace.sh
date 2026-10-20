#!/usr/bin/env bash
set -euo pipefail

# Atualiza uma instalação local somente quando não há carregamento em andamento.
# O servidor de atualização publica um manifesto assinado e um pacote imutável.
updater_dir="$(cd "$(dirname "$0")" && pwd)"
root_dir="$(cd "${TRACE_UPDATE_ROOT:-$updater_dir/..}" && pwd)"
cd "$root_dir"
if [[ -f "$root_dir/.env" ]]; then
  set -a
  # O .env é criado pelo administrador e já é usado pelo Compose.
  . "$root_dir/.env"
  set +a
fi
compose_common_args=(--project-directory "$root_dir")
installation_mode="$(printf '%s' "${TRACE_INSTALLATION_MODE:-}" | tr '[:upper:]' '[:lower:]')"
compose_industrial=0
if [[ "$installation_mode" == "local" || "$installation_mode" == "industrial" ]]; then
  compose_industrial=1
fi
trace_compose_production=0
case "$installation_mode" in
  central|remoto|server)
    compose_common_args+=(--env-file "$root_dir/.env" -f "$root_dir/docker-compose.yml" -f "$root_dir/docker-compose.production.yml")
    trace_compose_production=1
    ;;
esac
export TRACE_COMPOSE_PRODUCTION="$trace_compose_production"
compose() {
  if [[ "$compose_industrial" == "1" ]]; then
    bash "$root_dir/scripts/docker_compose.sh" "${compose_common_args[@]}" --profile industrial "$@"
  else
    bash "$root_dir/scripts/docker_compose.sh" "${compose_common_args[@]}" "$@"
  fi
}

# Limita o estado persistente do atualizador. Cada release é uma cópia
# completa do aplicativo e os backups de pré-atualização podem ser grandes.
prune_update_state() {
  local keep_releases="${TRACE_UPDATE_KEEP_RELEASES:-3}"
  local keep_backups="${TRACE_UPDATE_KEEP_BACKUPS:-3}"
  local value
  for value in "$keep_releases" "$keep_backups"; do
    [[ "$value" =~ ^[1-9][0-9]*$ ]] || {
      echo "Retenção do atualizador inválida; use inteiros positivos." >&2
      return 1
    }
  done

  local before_bytes after_bytes reclaimed_bytes
  before_bytes="$(du -sb "$state_dir" 2>/dev/null | awk '{print $1}')"
  before_bytes="${before_bytes:-0}"
  local -a release_names=()
  mapfile -t release_names < <(
    find "$state_dir/releases" -mindepth 1 -maxdepth 1 -type d -name 'v*' -printf '%f\n' | sort -Vr
  )
  declare -A keep_release=()
  keep_release["$1"]=1
  local name
  for name in "${release_names[@]:0:$keep_releases}"; do keep_release["$name"]=1; done
  local removed_releases=0
  for name in "${release_names[@]}"; do
    if [[ -z "${keep_release[$name]:-}" ]]; then
      rm -rf -- "$state_dir/releases/$name"
      removed_releases=$((removed_releases + 1))
    fi
  done

  local -a backup_names=()
  mapfile -t backup_names < <(
    find "$state_dir/backups" -mindepth 1 -maxdepth 1 -type f -name 'pre-*.tar.gz' -printf '%T@ %f\n' |
      sort -nr | cut -d' ' -f2-
  )
  local index=0 removed_backups=0
  for name in "${backup_names[@]}"; do
    if (( index >= keep_backups )); then
      rm -f -- "$state_dir/backups/$name"
      removed_backups=$((removed_backups + 1))
    fi
    index=$((index + 1))
  done

  # Remove sobras de execuções interrompidas, sem remover o staging desta
  # execução, que ainda será eliminado pelo trap de saída.
  local candidate
  for candidate in "$state_dir"/.rollback-* "$state_dir"/.staging.*; do
    [[ -e "$candidate" ]] || continue
    [[ "${work_dir:-}" == "$candidate" ]] && continue
    rm -rf -- "$candidate"
  done

  after_bytes="$(du -sb "$state_dir" 2>/dev/null | awk '{print $1}')"
  after_bytes="${after_bytes:-0}"
  reclaimed_bytes=$(( before_bytes > after_bytes ? before_bytes - after_bytes : 0 ))
  printf 'Retenção do atualizador: %d releases, %d backups e sobras temporárias removidos; %d bytes liberados.\n' \
    "$removed_releases" "$removed_backups" "$reclaimed_bytes"
}
curl_download() {
  local timeout_seconds="$1"
  local url="$2"
  local destination="$3"
  if [[ -n "${UPDATE_MANIFEST_TOKEN:-}" ]]; then
    curl --fail --silent --show-error --location --max-time "$timeout_seconds" \
      -H "Authorization: Bearer ${UPDATE_MANIFEST_TOKEN}" "$url" -o "$destination"
  else
    curl --fail --silent --show-error --location --max-time "$timeout_seconds" "$url" -o "$destination"
  fi
}

manifest_url="${UPDATE_MANIFEST_URL:-https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/latest/download/manifest.json}"
public_key="${UPDATE_PUBLIC_KEY_FILE:-$root_dir/servidor/configuracao/trace-update-public.pem}"
channel="${UPDATE_CHANNEL:-stable}"
dry_run="${TRACE_UPDATE_DRY_RUN:-0}"
[[ "$manifest_url" == https://* ]] || { echo "O manifesto remoto deve usar HTTPS." >&2; exit 3; }
[[ -f "$public_key" ]] || { echo "Chave pública não encontrada: $public_key" >&2; exit 4; }

command -v curl >/dev/null || { echo "curl é necessário." >&2; exit 5; }
command -v openssl >/dev/null || { echo "openssl é necessário para verificar a assinatura." >&2; exit 6; }
command -v python3 >/dev/null || { echo "python3 é necessário para validar o manifesto." >&2; exit 7; }
command -v rsync >/dev/null || { echo "rsync é necessário para uma instalação segura." >&2; exit 8; }

# A verificação automática roda a cada minuto. Adiamentos por carga ativa,
# manutenção ou outra atualização são resultados esperados; a publicação
# explícita passa TRACE_UPDATE_EXPECT_VERSION e precisa receber o código para
# continuar aguardando e validar o deploy.
adiar_atualizacao() {
  local codigo="$1"
  shift
  echo "$*"
  if [[ -n "${TRACE_UPDATE_EXPECT_VERSION:-}" ]]; then
    exit "$codigo"
  fi
  exit 0
}

state_dir="$root_dir/armazenamento/updates"
if ! mkdir -p "$state_dir/releases" "$state_dir/backups"; then
  echo "Não foi possível preparar o estado da atualização; verifique as permissões de $state_dir." >&2
  exit 23
fi
if [[ ! -w "$state_dir" || ! -x "$state_dir" ]]; then
  echo "Sem permissão para gravar o estado da atualização em $state_dir; execute pela conta administrativa configurada." >&2
  exit 23
fi
maintenance_file="$root_dir/armazenamento/.maintenance"
lock_dir="$state_dir/.install.lock"
if ! mkdir "$lock_dir" 2>/dev/null; then
  if [[ -d "$lock_dir" ]]; then
    adiar_atualizacao 9 "Já existe uma atualização em execução; nova verificação agendada."
  fi
  echo "Não foi possível criar a trava de atualização; verifique as permissões de $state_dir." >&2
  exit 23
fi
maintenance_owned=0
keep_maintenance=0
cleanup() {
  rmdir "$lock_dir" 2>/dev/null || true
  if [[ "$maintenance_owned" == "1" && "$keep_maintenance" != "1" ]]; then rm -f -- "$maintenance_file"; fi
  if [[ -n "${work_dir:-}" ]]; then rm -rf -- "$work_dir"; fi
}
trap cleanup EXIT
work_dir="$(mktemp -d "$state_dir/.staging.XXXXXX")"
manifest="${TRACE_UPDATE_MANIFEST_FILE:-$work_dir/manifest.json}"
if [[ -z "${TRACE_UPDATE_MANIFEST_FILE:-}" ]]; then
  curl_download 20 "$manifest_url" "$manifest"
elif [[ ! -s "$manifest" ]]; then
  echo "Manifesto local ausente ou vazio: $manifest" >&2
  exit 10
fi

fields=()
while IFS= read -r field; do
  fields[${#fields[@]}]="$field"
done < <(python3 - "$manifest" "$channel" <<'PY'
import json, pathlib, sys
data = json.loads(pathlib.Path(sys.argv[1]).read_text())
channel = sys.argv[2]
if data.get("channel", channel) != channel:
    raise SystemExit("manifesto de canal diferente do configurado")
required = ("version", "artifact_url", "sha256", "signature")
if any(not isinstance(data.get(k), str) or not data[k].strip() for k in required):
    raise SystemExit("manifesto sem campos obrigatórios")
if not data["artifact_url"].startswith("https://"):
    raise SystemExit("artefato remoto deve usar HTTPS")
if len(data["sha256"]) != 64 or any(c not in "0123456789abcdefABCDEF" for c in data["sha256"]):
    raise SystemExit("SHA-256 inválido")
print(data["version"])
print(data["artifact_url"])
print(data["sha256"].lower())
print(data["signature"])
PY
)
if [[ "${#fields[@]}" -ne 4 ]]; then
  echo "Não foi possível ler os campos obrigatórios do manifesto." >&2
  exit 10
fi
version="${fields[0]}"; artifact_url="${fields[1]}"; expected_sha="${fields[2]}"; signature_b64="${fields[3]}"
[[ "$version" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "Versão inválida." >&2; exit 10; }
if [[ -n "${TRACE_UPDATE_EXPECT_VERSION:-}" && "$version" != "$TRACE_UPDATE_EXPECT_VERSION" ]]; then
  echo "A versão do manifesto não corresponde à versão solicitada." >&2
  exit 10
fi
expected_commit="${TRACE_UPDATE_EXPECT_COMMIT:-}"
if [[ -n "$expected_commit" ]]; then
  if [[ ! "$expected_commit" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "O commit solicitado é inválido." >&2
    exit 10
  fi
  expected_commit="$(printf '%s' "$expected_commit" | tr '[:upper:]' '[:lower:]')"
fi
failed_marker="$state_dir/failed-$version"
if [[ -f "$failed_marker" && "${TRACE_RETRY_FAILED_UPDATE:-0}" != "1" ]]; then
  echo "A versão $version já falhou anteriormente; revisão manual obrigatória (use TRACE_RETRY_FAILED_UPDATE=1 para repetir)." >&2
  exit 21
fi
mark_failed_update() {
  printf 'version=%s\nfailed_at=%s\n' "$version" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$failed_marker"
}
printf '%s\n%s\n%s\n' "$version" "$artifact_url" "$expected_sha" > "$work_dir/payload"
python3 - "$signature_b64" "$work_dir/signature.bin" <<'PY'
import base64, pathlib, sys
pathlib.Path(sys.argv[2]).write_bytes(base64.b64decode(sys.argv[1], validate=True))
PY
openssl dgst -sha256 -verify "$public_key" -signature "$work_dir/signature.bin" "$work_dir/payload" >/dev/null || {
  echo "Assinatura do pacote rejeitada." >&2; exit 11;
}

if [[ -z "${MYSQL_PASSWORD:-}" ]]; then
  echo "MYSQL_PASSWORD não configurado; atualização cancelada por segurança." >&2
  exit 12
fi
# Bloqueia novas preparações antes de consultar cargas ativas; o endpoint de
# preparação consulta este marcador dentro da mesma instalação.
if [[ -e "$maintenance_file" ]]; then
  adiar_atualizacao 14 "O sistema já está em manutenção; atualização adiada."
fi
if ! (set -o noclobber; : > "$maintenance_file") 2>/dev/null; then
  if [[ -e "$maintenance_file" ]]; then
    adiar_atualizacao 14 "O sistema já está em manutenção; atualização adiada."
  fi
  echo "Não foi possível gravar o marcador de manutenção; verifique as permissões de $root_dir/armazenamento." >&2
  exit 23
fi
maintenance_owned=1
active="$(compose exec -T mysql sh -lc 'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql -N -B -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM carregamentos WHERE state IN ('\''PREPARANDO'\'', '\''CARREGANDO'\'', '\''PAUSADO'\'', '\''FINALIZANDO'\'', '\''EMERGENCIA'\'');"' 2>/dev/null | tr -d '[:space:]')" || {
  echo "Não foi possível verificar o estado do carregamento; atualização cancelada por segurança." >&2
  exit 12
}
[[ "$active" =~ ^[0-9]+$ ]] || {
  echo "Resposta inválida ao verificar o estado do carregamento; atualização cancelada por segurança." >&2
  exit 13
}
if [[ "$active" != "0" ]]; then
  adiar_atualizacao 14 "Atualização adiada: existe carregamento ativo ou em estado de intervenção."
fi

# Reexecuta a preparação como root antes de parar a aplicação. Além de corrigir
# a propriedade de instalações antigas, o probe impede publicar uma versão que
# só descobriria armazenamento sem escrita ao gerar o primeiro relatório.
if ! compose run --rm armazenamento-init >/dev/null; then
  echo "O armazenamento não passou no teste de escrita; atualização cancelada." >&2
  exit 23
fi

if [[ -f "$state_dir/current_version" && "$(cat "$state_dir/current_version")" == "$version" ]]; then
  if [[ -n "${TRACE_UPDATE_EXPECT_COMMIT:-}" ]]; then
    installed_commit="$(python3 - "$root_dir/servidor/.release.json" <<'PY'
import json, pathlib, sys
try:
    data = json.loads(pathlib.Path(sys.argv[1]).read_text())
except (OSError, ValueError):
    print("")
else:
    print(str(data.get("commit", "")).lower())
PY
)"
    if [[ "$installed_commit" != "$expected_commit" ]]; then
      echo "A versão $version já está registrada com outro commit; use uma nova tag SemVer." >&2
      exit 22
    fi
  fi
  echo "Trace já está na versão $version."
  exit 0
fi

artifact="${TRACE_UPDATE_ARTIFACT_FILE:-$work_dir/trace-$version.tar.gz}"
if [[ -z "${TRACE_UPDATE_ARTIFACT_FILE:-}" ]]; then
  curl_download 120 "$artifact_url" "$artifact"
elif [[ ! -s "$artifact" ]]; then
  echo "Pacote local ausente ou vazio: $artifact" >&2
  exit 15
fi
if command -v sha256sum >/dev/null; then
  actual_sha="$(sha256sum "$artifact" | awk '{print tolower($1)}')"
else
  actual_sha="$(shasum -a 256 "$artifact" | awk '{print tolower($1)}')"
fi
[[ "$actual_sha" == "$expected_sha" ]] || { echo "Integridade do pacote rejeitada." >&2; exit 15; }
tar -tzf "$artifact" >/dev/null || { echo "Pacote inválido." >&2; exit 16; }
if [[ "$dry_run" == "1" ]]; then
  echo "Manifesto, assinatura e integridade válidos para $version (simulação; nada foi alterado)."
  exit 0
fi

backup="$state_dir/backups/pre-$version-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
db_backup_output="$(TRACE_COMPOSE_PRODUCTION="$trace_compose_production" bash "$root_dir/scripts/backup_db.sh" "$state_dir/backups")"
db_backup="$(printf '%s\n' "$db_backup_output" | sed -n 's/^Backup criado: //p' | tail -n 1)"
[[ -s "$db_backup" && -s "$db_backup.sha256" ]] || {
  echo "O backup do banco foi criado sem caminho verificável; atualização cancelada." >&2
  exit 17
}
tar --exclude='./.env' --exclude='./armazenamento' --exclude='./.git' -czf "$backup" -C "$root_dir" .
release_dir="$state_dir/releases/$version"
rm -rf "$release_dir"
mkdir -p "$release_dir"
tar -xzf "$artifact" -C "$release_dir" --no-same-owner
source_dir="$release_dir"
if [[ -d "$release_dir/trace" && -f "$release_dir/trace/docker-compose.yml" ]]; then source_dir="$release_dir/trace"; fi
[[ -f "$source_dir/docker-compose.yml" ]] || { echo "Pacote sem docker-compose.yml." >&2; exit 17; }
[[ -f "$source_dir/trace-build-version.txt" && -f "$source_dir/trace-build-commit.txt" ]] || {
  echo "Pacote sem identificadores de versão e commit." >&2
  exit 17
}
package_version="$(tr -d '\r\n' < "$source_dir/trace-build-version.txt")"
package_commit="$(tr -d '\r\n' < "$source_dir/trace-build-commit.txt" | tr '[:upper:]' '[:lower:]')"
[[ "$package_version" == "$version" && "$package_commit" =~ ^[0-9a-f]{40}$ ]] || {
  echo "Os identificadores do pacote não correspondem ao manifesto." >&2
  exit 17
}
if [[ -n "$expected_commit" && "$package_commit" != "$expected_commit" ]]; then
  echo "O commit do pacote não corresponde ao commit solicitado." >&2
  exit 17
fi

rollback() {
  echo "Restaurando a versão anterior." >&2
  compose stop >/dev/null 2>&1 || true
  if ! tar -xzf "$backup" -C "$root_dir" --no-same-owner; then
    echo "Não foi possível restaurar os arquivos da versão anterior." >&2
    return 1
  fi
  if ! compose up -d mysql >/dev/null; then
    echo "Não foi possível iniciar o banco para o rollback." >&2
    return 1
  fi
  mysql_ready=0
  for _ in $(seq 1 "${MYSQL_ROLLBACK_ATTEMPTS:-30}"); do
    if compose exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot --silent' >/dev/null 2>&1; then
      mysql_ready=1
      break
    fi
    sleep 2
  done
  if [[ "$mysql_ready" != "1" ]]; then
    echo "O banco não ficou pronto para o rollback." >&2
    return 1
  fi
  if ! bash "$root_dir/scripts/verify_backup.sh" "$db_backup" >/dev/null; then
    echo "O backup do banco não passou na verificação durante o rollback." >&2
    return 1
  fi
  if ! restore_database_from_verified_backup "$db_backup"; then
    echo "Não foi possível restaurar o banco da versão anterior." >&2
    return 1
  fi
  if ! compose up -d --build >/dev/null; then
    echo "Não foi possível iniciar a versão anterior." >&2
    return 1
  fi
  rollback_healthy=0
  for _ in $(seq 1 "${HEALTHCHECK_ATTEMPTS:-30}"); do
    if curl --fail --silent --max-time 3 "http://127.0.0.1:${WEB_PORT:-8080}/api/prontidao.php" >/dev/null; then rollback_healthy=1; break; fi
    sleep 2
  done
  if [[ "$rollback_healthy" != "1" ]]; then
    echo "Rollback concluído, mas o healthcheck da versão anterior também falhou." >&2
    return 1
  fi
}

restore_database_from_verified_backup() {
  local backup_file="$1"
  local drop_statements=""

  if ! bash "$root_dir/scripts/verify_backup.sh" "$backup_file" >/dev/null; then
    echo "O dump não passou na verificação antes de remover qualquer tabela." >&2
    return 1
  fi

  drop_statements="$(compose exec -T mysql sh -lc \
    'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql --connect-timeout=5 --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "$1"' \
    trace-rollback "SELECT CONCAT('DROP TABLE IF EXISTS ', CHAR(96), REPLACE(table_name, CHAR(96), CONCAT(CHAR(96), CHAR(96))), CHAR(96), ';') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")" || {
      echo "Não foi possível enumerar as tabelas antes de restaurar o dump." >&2
      return 1
    }

  {
    printf 'SET FOREIGN_KEY_CHECKS=0;\n'
    if [[ -n "$drop_statements" ]]; then
      printf '%s\n' "$drop_statements"
    fi
  } | compose exec -T mysql sh -lc \
    'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql --connect-timeout=5 -u"$MYSQL_USER" "$MYSQL_DATABASE"' || {
      echo "Não foi possível limpar o schema parcial antes do rollback." >&2
      return 1
    }

  if ! compose exec -T mysql sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --connect-timeout=5 -uroot "$MYSQL_DATABASE"' < "$backup_file"; then
    return 1
  fi
  return 0
}

if ! compose stop >/dev/null; then
  echo "Não foi possível parar os serviços para a atualização." >&2
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 18
fi
if ! rsync -a --delete --exclude='.env' --exclude='armazenamento/' --exclude='.git/' "$source_dir/" "$root_dir/"; then
  echo "Não foi possível instalar os arquivos da nova versão." >&2
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 18
fi
release_metadata="$root_dir/servidor/.release.json"
release_metadata_tmp="$release_metadata.tmp"
if ! python3 - "$package_version" "$package_commit" "$release_metadata_tmp" <<'PY'
import json, pathlib, sys
pathlib.Path(sys.argv[3]).write_text(json.dumps({"version": sys.argv[1], "commit": sys.argv[2]}) + "\n")
PY
then
  echo "Não foi possível registrar a versão do pacote; iniciando rollback." >&2
  rm -f -- "$release_metadata_tmp"
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 19
fi
if ! mv -f -- "$release_metadata_tmp" "$release_metadata"; then
  echo "Não foi possível instalar os metadados da versão; iniciando rollback." >&2
  rm -f -- "$release_metadata_tmp"
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 19
fi
if ! compose up -d --wait --wait-timeout 180 mysql >/dev/null; then
  echo "O banco não ficou saudável no prazo para receber as migrations; iniciando rollback." >&2
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 19
fi
if ! bash "$root_dir/scripts/migrate.sh"; then
  echo "As migrations da nova versão falharam; iniciando rollback." >&2
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 19
fi
if ! compose up -d --build >/dev/null; then
  echo "A nova versão não conseguiu iniciar; iniciando rollback." >&2
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 19
fi
healthy=0
for _ in $(seq 1 "${HEALTHCHECK_ATTEMPTS:-30}"); do
  if curl --fail --silent --max-time 3 "http://127.0.0.1:${WEB_PORT:-8080}/api/prontidao.php" >/dev/null; then healthy=1; break; fi
  sleep 2
done
if [[ "$healthy" != "1" ]]; then
  echo "A versão $version não passou no healthcheck; iniciando rollback." >&2
  if ! rollback; then keep_maintenance=1; fi
  mark_failed_update
  exit 20
fi
rm -f "$failed_marker"
current_version_tmp="$state_dir/.current_version.tmp"
if ! printf '%s\n' "$version" > "$current_version_tmp" || ! mv -f -- "$current_version_tmp" "$state_dir/current_version"; then
  echo "Não foi possível registrar a versão instalada; iniciando rollback." >&2
  rm -f -- "$current_version_tmp"
  mark_failed_update
  if ! rollback; then keep_maintenance=1; fi
  exit 20
fi
if ! prune_update_state "$version"; then
  echo "Aviso: não foi possível aplicar a retenção do estado do atualizador." >&2
fi
echo "Trace atualizado com sucesso para $version. Backup: $backup"
