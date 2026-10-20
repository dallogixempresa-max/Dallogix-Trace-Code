#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "$0")/.." && pwd)"
errors=0
required_commands=(docker)
for command_name in "${required_commands[@]}"; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "ERRO: comando obrigatório ausente: $command_name" >&2
    errors=$((errors + 1))
  fi
done

if ! docker compose version >/dev/null 2>&1; then
  echo "ERRO: Docker Compose Plugin não está disponível." >&2
  errors=$((errors + 1))
fi
if [[ ! -f "$root_dir/.env" ]]; then
  echo "ERRO: crie o arquivo .env a partir de .env.example." >&2
  errors=$((errors + 1))
else
  # O Compose lê o .env automaticamente, mas este script também precisa usar
  # APP_ENV e os segredos para executar a validação correta.
  set -a
  # shellcheck disable=SC1091
  . "$root_dir/.env"
  set +a
fi
if [[ "${APP_ENV:-}" == "production" ]]; then
  if ! (cd "$root_dir" && bash scripts/check_production_env.sh); then
    errors=$((errors + 1))
  fi
fi
if [[ "$errors" -gt 0 ]]; then
  exit 1
fi
echo "OK: pré-requisitos do servidor físico encontrados."
