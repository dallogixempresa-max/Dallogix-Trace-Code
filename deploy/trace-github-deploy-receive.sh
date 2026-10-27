#!/usr/bin/env bash
set -euo pipefail

incoming_command="${SSH_ORIGINAL_COMMAND:-}"
read -r -a args <<< "$incoming_command"
[[ "${#args[@]}" -eq 3 && "${args[0]}" == deploy ]] || {
  echo "Somente o recebimento de um pacote de release é permitido." >&2
  exit 2
}

tag="${args[1]}"
commit="${args[2]}"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ && "$commit" =~ ^[0-9a-f]{40}$ ]] || {
  echo "A tag ou o commit do deploy são inválidos." >&2
  exit 2
}

incoming_dir=/var/lib/trace-deploy/incoming
bundle="$(mktemp "$incoming_dir/${tag}-${commit}.XXXXXX.bundle")"
trap 'rm -f -- "$bundle"' EXIT
cat > "$bundle"
[[ -s "$bundle" ]] || {
  echo "O pacote de release recebido está vazio." >&2
  exit 2
}

/usr/bin/sudo -n /usr/local/sbin/trace-github-deploy-apply "$tag" "$commit" "$bundle"
