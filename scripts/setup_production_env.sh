#!/usr/bin/env bash
set -euo pipefail
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
target="${1:-$root_dir/.env}"
app_url="${2:-https://localhost:8443}"
python3 - "$root_dir/.env.example" "$target" "$app_url" <<'PY'
import os, secrets, sys
from pathlib import Path
from urllib.parse import urlsplit
src, dst, url = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
u = urlsplit(url)
if u.scheme != 'https' or not u.hostname or u.username or u.password or u.query or u.fragment or u.path not in ('', '/') or any(c in url for c in '\n\r\t $;{}"\''):
    raise SystemExit('ERRO: informe uma origem HTTPS válida, sem caminho ou credenciais.')
if dst.exists():
    raise SystemExit('ERRO: arquivo já existe; preservado. Use outro destino para uma instalação nova. Não troque senhas de um banco existente somente no arquivo.')
values = dict(APP_ENV='production', TRACE_INSTALLATION_MODE='central', SESSION_SECURE='true', APP_URL=url.rstrip('/'), WEB_BIND_ADDRESS='127.0.0.1', BIND_ADDRESS='127.0.0.1')
for key in ('MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'TRACE_DEVICE_TOKEN', 'CAMERA_DEVICE_TOKEN'):
    values[key] = secrets.token_hex(32)
lines = []
for line in src.read_text().splitlines():
    key = line.partition('=')[0]
    lines.append(f'{key}={values[key]}' if key in values else line)
fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'w') as f:
    f.write('\n'.join(lines) + '\n')
print('OK: configuração de produção criada com segredos próprios; arquivo protegido.')
PY
# A configuração ainda não aponta para um banco de produção em execução. A
# checagem de hashes de demonstração pertence ao banco já provisionado e não
# pode consultar por engano o compose local do desenvolvedor durante a criação
# deste ambiente isolado.
TRACE_ENV_CHECK_SKIP_DATABASE=1 bash "$root_dir/scripts/check_production_env.sh" "$target"
