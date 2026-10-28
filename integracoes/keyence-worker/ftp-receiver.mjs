import { timingSafeEqual } from "node:crypto";
import { createServer } from "node:net";
import { randomBytes } from "node:crypto";
import { mkdir, rename, writeFile } from "node:fs/promises";
import { basename, posix } from "node:path";
import { sha256 } from "./keyence-core.mjs";

const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const MAX_SESSIONS = 16;

function safeEqual(left, right) {
  const a = Buffer.from(String(left));
  const b = Buffer.from(String(right));
  return a.length === b.length && a.length > 0 && timingSafeEqual(a, b);
}

function normalizedAddress(address = "") {
  return address.startsWith("::ffff:") ? address.slice(7) : address;
}

class FtpSession {
  constructor(control, options) {
    this.control = control;
    this.options = options;
    this.buffer = "";
    this.username = "";
    this.authenticated = false;
    this.passiveServer = null;
    this.dataSocket = null;
    this.dataWaiter = null;
    this.commandQueue = Promise.resolve();
    control.setEncoding("utf8");
    control.setTimeout(120000, () => control.destroy());
    control.write("220 Trace Keyence FTP ready\r\n");
    control.on("data", (chunk) => {
      this.buffer += chunk;
      if (this.buffer.length > 4096) return control.destroy();
      const lines = this.buffer.split(/\r\n|\n|\r/);
      this.buffer = lines.pop() || "";
      for (const line of lines) {
        if (!line) continue;
        this.commandQueue = this.commandQueue.then(() => this.handle(line)).catch((error) => {
          this.options.log("warn", "Falha em comando FTP do Keyence.", { reason: String(error).slice(0, 180) });
          this.reply("451 Temporary processing error.");
        });
      }
    });
    control.once("close", () => this.closePassive());
  }

  reply(message) {
    if (!this.control.destroyed) this.control.write(message + "\r\n");
  }

  closePassive() {
    if (this.dataSocket && !this.dataSocket.destroyed) this.dataSocket.destroy();
    this.dataSocket = null;
    this.dataWaiter?.reject(new Error("Conexão de dados FTP encerrada."));
    this.dataWaiter = null;
    if (this.passiveServer?.listening) this.passiveServer.close();
    this.passiveServer = null;
  }

  async openPassive(epsv = false) {
    this.closePassive();
    for (let candidate = this.options.passiveStart; candidate <= this.options.passiveEnd; candidate += 1) {
      const dataServer = createServer((socket) => {
        this.dataSocket = socket;
        if (this.dataWaiter) {
          this.dataWaiter.resolve(socket);
          this.dataWaiter = null;
        }
        dataServer.close();
      });
      try {
        await new Promise((resolve, reject) => {
          dataServer.once("error", reject);
          dataServer.listen(candidate, this.options.bindAddress, () => {
            dataServer.removeListener("error", reject);
            resolve();
          });
        });
        this.passiveServer = dataServer;
        if (epsv) {
          this.reply(`229 Entering Extended Passive Mode (|||${candidate}|)`);
        } else {
          const octets = this.options.advertisedAddress.split(".").map(Number);
          if (octets.length !== 4 || octets.some((part) => !Number.isInteger(part) || part < 0 || part > 255)) {
            this.reply("425 Passive address is not configured.");
            this.closePassive();
            return;
          }
          this.reply(`227 Entering Passive Mode (${octets.join(",")},${candidate >> 8},${candidate & 255})`);
        }
        return;
      } catch (error) {
        if (error?.code !== "EADDRINUSE") throw error;
        dataServer.close();
      }
    }
    this.reply("425 No passive ports available.");
  }

  waitForDataSocket() {
    if (this.dataSocket && !this.dataSocket.destroyed) return Promise.resolve(this.dataSocket);
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.dataWaiter = null;
        reject(new Error("Tempo esgotado aguardando dados FTP."));
      }, 10000);
      this.dataWaiter = {
        resolve: (socket) => { clearTimeout(timer); resolve(socket); },
        reject: (error) => { clearTimeout(timer); reject(error); },
      };
    });
  }

  async storeFile(argument) {
    const inputName = argument.trim();
    const filename = posix.basename(inputName);
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}\.(?:jpe?g)$/i.test(filename)
      || inputName !== filename || filename !== basename(filename)) {
      this.reply("553 Only JPEG image files are accepted.");
      return;
    }
    this.reply("150 Opening data connection.");
    let data;
    try {
      const socket = await this.waitForDataSocket();
      data = await new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        let ended = false;
        socket.on("data", (chunk) => {
          size += chunk.length;
          if (size > MAX_IMAGE_BYTES) {
            socket.destroy(new Error("Imagem acima de 10 MiB."));
            reject(new Error("Imagem acima de 10 MiB."));
            return;
          }
          chunks.push(chunk);
        });
        socket.once("error", reject);
        socket.once("end", () => {
          ended = true;
          resolve(Buffer.concat(chunks, size));
        });
        socket.once("close", () => {
          if (!ended) reject(new Error("Transferência FTP incompleta."));
        });
      });
    } catch (error) {
      this.closePassive();
      this.reply(error.message.includes("10 MiB") ? "552 File too large." : "425 Data connection failed.");
      return;
    }
    this.closePassive();
    if (data.length < 4 || data[0] !== 0xff || data[1] !== 0xd8 || data[data.length - 2] !== 0xff || data[data.length - 1] !== 0xd9) {
      this.reply("550 Invalid JPEG image.");
      return;
    }
    await mkdir(this.options.inboxDirectory, { recursive: true, mode: 0o770 });
    const hash = sha256(data);
    const target = `${this.options.inboxDirectory}/${Date.now()}--${hash}-${randomBytes(4).toString("hex")}.jpg`;
    const temporary = `${target}.tmp-${randomBytes(6).toString("hex")}`;
    await writeFile(temporary, data, { flag: "wx", mode: 0o600 });
    await rename(temporary, target);
    this.reply("226 Image received.");
    this.options.log("info", "Imagem JPEG recebida do Keyence por FTP.", {
      source_filename: filename,
      bytes: data.length,
      sha256: hash,
    });
    this.options.onImage?.(target);
  }

  async handle(line) {
    const separator = line.indexOf(" ");
    const command = (separator < 0 ? line : line.slice(0, separator)).toUpperCase();
    const argument = separator < 0 ? "" : line.slice(separator + 1);
    if (command === "QUIT") { this.reply("221 Goodbye."); this.control.end(); return; }
    if (command === "USER") { this.username = argument; this.reply("331 Password required."); return; }
    if (command === "PASS") {
      this.authenticated = safeEqual(this.username, this.options.username)
        && safeEqual(argument, this.options.password);
      this.reply(this.authenticated ? "230 Login successful." : "530 Login incorrect.");
      return;
    }
    if (!this.authenticated) { this.reply("530 Please login first."); return; }
    if (command === "TYPE") { this.reply("200 Binary mode enabled."); return; }
    if (command === "SYST") { this.reply("215 UNIX Type: L8"); return; }
    if (command === "FEAT") { this.reply("211 End."); return; }
    if (command === "PWD") { this.reply('257 "/"'); return; }
    if (command === "CWD" || command === "CDUP") { this.reply("250 Directory changed."); return; }
    if (command === "NOOP") { this.reply("200 OK."); return; }
    if (command === "PASV") { await this.openPassive(false); return; }
    if (command === "EPSV") { await this.openPassive(true); return; }
    if (command === "STOR") { await this.storeFile(argument); return; }
    if (command === "OPTS") { this.reply("200 Command accepted."); return; }
    this.reply("502 Command not supported.");
  }
}

export function createFtpReceiver(options) {
  const sessions = new Set();
  const server = createServer((control) => {
    if (sessions.size >= MAX_SESSIONS) {
      control.end("421 Too many sessions.\r\n");
      return;
    }
    const session = new FtpSession(control, options);
    sessions.add(session);
    control.once("close", () => sessions.delete(session));
  });

  server.on("connection", (socket) => {
    const remote = normalizedAddress(socket.remoteAddress || "");
    if (options.allowedAddress && remote !== normalizedAddress(options.allowedAddress)) {
      options.log("warn", "Conexão FTP Keyence rejeitada por endereço de origem.", { remote_address: remote });
      socket.destroy();
    }
  });

  return {
    listen() {
      return new Promise((resolve, reject) => {
        server.once("error", reject);
        server.listen(options.port, options.bindAddress, () => {
          server.removeListener("error", reject);
          resolve(server.address());
        });
      });
    },
    close() {
      for (const session of sessions) session.control.destroy();
      return new Promise((resolve) => server.close(() => resolve()));
    },
  };
}
