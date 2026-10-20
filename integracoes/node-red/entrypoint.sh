#!/bin/sh
set -eu

flow_file="/data/flows.json"
seed_flow="/seed/trace-clp-bridge.flow.json"

# Atualiza somente a aba gerenciada pelo Trace, guarda cópia do arquivo anterior
# e preserva as demais abas do operador.
/usr/local/bin/node /seed/sync-managed-flow.js "$flow_file" "$seed_flow"

if [ -n "${FLOWS:-}" ]; then
    set -- "$FLOWS" "$@"
fi

exec /usr/local/bin/node ${NODE_OPTIONS:-} node_modules/node-red/red.js --userDir /data --settings /seed/settings.js "$@"
