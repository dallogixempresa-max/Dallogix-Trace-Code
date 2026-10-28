FROM nodered/node-red:5.0.7-24-minimal@sha256:828be222da3c61b10eebb85d5192d1da98e3e5d59c2fc1d7e9cd7958e0a69314

WORKDIR /opt/trace-keyence-worker
COPY --chown=1000:1000 keyence-worker.mjs keyence-core.mjs ftp-receiver.mjs ./
USER 1000:1000

ENV NODE_ENV=production
ENTRYPOINT ["node"]
CMD ["/opt/trace-keyence-worker/keyence-worker.mjs"]
