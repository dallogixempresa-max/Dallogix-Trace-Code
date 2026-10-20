#!/usr/bin/env bash
set -euo pipefail

kiosk_url="${TRACE_KIOSK_URL:-http://127.0.0.1:8080}"
browser="${TRACE_KIOSK_BROWSER:-}"
if [[ -z "$browser" ]]; then
  for candidate in chromium chromium-browser google-chrome; do
    if command -v "$candidate" >/dev/null 2>&1; then
      browser="$candidate"
      break
    fi
  done
fi
if [[ -z "$browser" ]]; then
  echo "Nenhum Chromium/Chrome instalado para o modo quiosque." >&2
  exit 1
fi

exec "$browser" \
  --kiosk "$kiosk_url" \
  --incognito \
  --no-first-run \
  --no-default-browser-check \
  --disable-session-crashed-bubble \
  --disable-infobars \
  --disable-pinch \
  --overscroll-history-navigation=0 \
  --password-store=basic
