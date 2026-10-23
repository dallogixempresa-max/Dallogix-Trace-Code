#!/usr/bin/env bash
set -euo pipefail

[[ "$EUID" -eq 0 ]] || {
  echo "O aplicador de release precisa executar como root." >&2
  exit 2
}
[[ "$#" -eq 3 ]] || {
  echo "Uso: trace-github-deploy-apply <tag> <commit> <bundle>." >&2
  exit 2
}

tag="$1"
commit="$2"
bundle="$3"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ && "$commit" =~ ^[0-9a-f]{40}$ ]] || {
  echo "A tag ou o commit do deploy são inválidos." >&2
  exit 2
}
[[ "$bundle" == /var/lib/trace-deploy/incoming/*.bundle && -f "$bundle" && ! -L "$bundle" ]] || {
  echo "O pacote de release está fora do diretório de entrada permitido." >&2
  exit 2
}
[[ "$(stat -c '%U' "$bundle")" == trace-deploy ]] || {
  echo "O proprietário do pacote de release é inválido." >&2
  exit 2
}
[[ "$(stat -c '%s' "$bundle")" -le 536870912 ]] || {
  echo "O pacote de release ultrapassa o limite de 512 MiB." >&2
  exit 2
}

repo=/opt/dallogix-trace
[[ -d "$repo/.git" && -f "$repo/.env" ]] || {
  echo "A instalação de produção ou o arquivo .env não foi encontrado." >&2
  exit 2
}
git -C "$repo" bundle verify "$bundle"
tag_ref="refs/tags/$tag"
if git -C "$repo" show-ref --verify --quiet "$tag_ref"; then
  existing_tag_commit="$(git -C "$repo" rev-parse "$tag_ref^{commit}")"
  [[ "$existing_tag_commit" == "$commit" ]] || {
    echo "A tag $tag já existe localmente e aponta para outro commit." >&2
    exit 2
  }
else
  git -C "$repo" fetch --quiet --no-tags "$bundle" "$tag_ref:$tag_ref"
fi

tag_commit="$(git -C "$repo" rev-parse "$tag_ref^{commit}")"
[[ "$tag_commit" == "$commit" ]] || {
  echo "A tag $tag não aponta para o commit informado; deploy cancelado." >&2
  exit 2
}

deploy_script="$(mktemp /run/trace-git-deploy.XXXXXX.sh)"
trap 'rm -f -- "$deploy_script"' EXIT
git -C "$repo" show "$commit:scripts/deploy_from_git.sh" > "$deploy_script"
chmod 700 "$deploy_script"
TRACE_GIT_DEPLOY_ROOT="$repo" \
  TRACE_GIT_DEPLOY_TARGET_COMMIT="$commit" \
  TRACE_GIT_DEPLOY_VERSION="$tag" \
  /usr/bin/bash "$deploy_script"
