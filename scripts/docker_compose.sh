#!/usr/bin/env bash
set -euo pipefail

docker_bin="${TRACE_DOCKER_BIN:-$(command -v docker || true)}"
[[ -n "$docker_bin" ]] || { echo "Docker não encontrado no servidor." >&2; exit 1; }

if command -v sudo >/dev/null 2>&1 && sudo -n "$docker_bin" info >/dev/null 2>&1; then
  exec sudo -n "$docker_bin" compose "$@"
fi

if "$docker_bin" info >/dev/null 2>&1; then
  exec "$docker_bin" compose "$@"
fi

exec "$docker_bin" compose "$@"
