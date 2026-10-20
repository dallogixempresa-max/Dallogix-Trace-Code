"use strict";

const username = String(process.env.NODE_RED_ADMIN_USER || "").trim();
const passwordHash = String(process.env.NODE_RED_ADMIN_PASSWORD_HASH || "").trim();
const validUsername = /^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/.test(username);
const validBcryptHash = /^\$2[ab]\$\d{2}\$[./A-Za-z0-9]{53}$/.test(passwordHash);

if (!validUsername || !validBcryptHash) {
    throw new Error(
        "Configure NODE_RED_ADMIN_USER e NODE_RED_ADMIN_PASSWORD_HASH com um usuário técnico e hash bcrypt antes de iniciar o Node-RED.",
    );
}

module.exports = {
    functionGlobalContext: {
        traceCreateTcpSocket: () => new (require("node:net").Socket)(),
    },
    adminAuth: {
        type: "credentials",
        sessionExpiryTime: 28800,
        users: [
            {
                username,
                password: passwordHash,
                permissions: "*",
            },
        ],
    },
};
