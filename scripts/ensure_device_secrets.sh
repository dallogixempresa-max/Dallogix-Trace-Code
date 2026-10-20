#!/usr/bin/env bash
set -euo pipefail

env_file="${1:-.env}"
if [[ ! -f "$env_file" ]]; then
  echo "ERRO: arquivo .env ausente; não é possível preparar os tokens técnicos." >&2
  exit 1
fi

command -v openssl >/dev/null 2>&1 || {
  echo "ERRO: openssl é obrigatório para gerar tokens técnicos." >&2
  exit 1
}

umask 077
changed=0

replace_or_append() {
  local key="$1"
  local value="$2"
  local temporary
  temporary="$(mktemp "${env_file}.tmp.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    BEGIN { replaced = 0 }
    $0 ~ ("^" key "=") {
      if (!replaced) {
        print key "=" value
        replaced = 1
      }
      next
    }
    { print }
    END {
      if (!replaced) print key "=" value
    }
  ' "$env_file" > "$temporary"
  chmod 600 "$temporary"
  mv -f "$temporary" "$env_file"
}

for key in TRACE_DEVICE_TOKEN CAMERA_DEVICE_TOKEN; do
  current="$(awk -F= -v key="$key" '$1 == key { print substr($0, index($0, "=") + 1); exit }' "$env_file")"
  case "$current" in
    ""|change-me-*|password|password1234|trace-device-local-token-2026-v1|trace-camera-local-token-2026-v1)
      replace_or_append "$key" "$(openssl rand -hex 32)"
      changed=1
      ;;
  esac
done

if [[ "$changed" -eq 1 ]]; then
  echo "OK: tokens técnicos ausentes foram criados e gravados com segurança."
else
  echo "OK: tokens técnicos próprios já existentes foram preservados."
fi
