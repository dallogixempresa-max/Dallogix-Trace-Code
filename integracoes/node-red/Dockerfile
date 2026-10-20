FROM nodered/node-red:5.0.7-24-minimal@sha256:828be222da3c61b10eebb85d5192d1da98e3e5d59c2fc1d7e9cd7958e0a69314

USER root

# Refresh Alpine security packages and npm's bundled dependency tree.
RUN apk upgrade --no-cache \
    && npm install --global npm@11.19.1 \
    && test "$(npm --version)" = "11.19.1" \
    && cd /usr/src/node-red/node_modules/express \
    && npm install qs@6.16.0 --no-save --package-lock=false --omit=dev --ignore-scripts \
    && node -e "const path = require.resolve('qs/package.json', { paths: [require.resolve('express')] }); if (require(path).version !== '6.16.0') process.exit(1)" \
    && apk del --no-network git curl

USER 1000:1000
