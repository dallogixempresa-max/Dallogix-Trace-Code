#!/usr/bin/env bash
set -euo pipefail

root_dir="${1:-/opt/dallogix-trace}"
script_dir="$(cd "$(dirname "$0")" && pwd)"
project_dir="$(cd "$script_dir/.." && pwd)"
service_source="$project_dir/deploy/systemd/dallogix-trace-backup.service"
timer_source="$project_dir/deploy/systemd/dallogix-trace-backup.timer"

if [[ "$root_dir" != "/opt/dallogix-trace" ]]; then
  printf 'Este serviço usa o caminho fixo /opt/dallogix-trace; recebido: %s\n' "$root_dir" >&2
  exit 1
fi

if [[ ! -x "$project_dir/scripts/backup_db.sh" || ! -x "$project_dir/scripts/backup_snapshot.sh" ]]; then
  printf 'backup_db.sh e backup_snapshot.sh precisam estar presentes e executáveis em %s\n' "$project_dir" >&2
  exit 1
fi

if [[ ! -f "$service_source" || ! -f "$timer_source" ]]; then
  printf 'Arquivos systemd do backup não encontrados em %s\n' "$project_dir/deploy/systemd" >&2
  exit 1
fi

if [[ "${TRACE_BACKUP_INSTALL_DRY_RUN:-0}" == "1" ]]; then
  printf 'Configuração válida para %s\n' "$root_dir"
  printf 'Instalaria %s e %s em /etc/systemd/system/\n' \
    "$(basename "$service_source")" "$(basename "$timer_source")"
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  printf 'Execute este instalador como root (por exemplo, com sudo).\n' >&2
  exit 1
fi

install -o root -g root -m 0644 "$service_source" /etc/systemd/system/dallogix-trace-backup.service
install -o root -g root -m 0644 "$timer_source" /etc/systemd/system/dallogix-trace-backup.timer

systemctl daemon-reload
systemctl enable --now dallogix-trace-backup.timer

printf 'Timer instalado e ativado.\n'
systemctl --no-pager --full status dallogix-trace-backup.timer || true
