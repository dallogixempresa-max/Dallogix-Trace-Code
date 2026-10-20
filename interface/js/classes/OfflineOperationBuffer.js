import { agora } from "../funcoes/relogio.js?v=202609170015";
import { ClienteApi } from "../api/ClienteApi.js?v=202610140003";
import { logFrontend } from "../utilitarios/LogFrontend.js?v=202610060007";

export const MAX_OFFLINE_OPERATIONS_PER_OWNER = 20;

class OfflineQueueLimitError extends Error {
  constructor() {
    super(`A fila offline atingiu ${MAX_OFFLINE_OPERATIONS_PER_OWNER} operações. Aguarde o envio das pendências antes de tentar novamente.`);
    this.name = "OfflineQueueLimitError";
    this.code = "OFFLINE_QUEUE_LIMIT";
  }
}

export function secureRandomId() {
  const webCrypto = globalThis.crypto;
  if (typeof webCrypto?.randomUUID === "function") return webCrypto.randomUUID();
  if (typeof webCrypto?.getRandomValues !== "function") {
    throw new Error("Geração segura de identificadores indisponível neste navegador.");
  }
  const bytes = webCrypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/**
 * Fila pequena e idempotente para gravações operacionais feitas sem rede.
 * IndexedDB é usado quando disponível; o fallback em memória mantém a sessão
 * utilizável em navegadores privados que bloqueiam armazenamento persistente.
 */
export class OfflineOperationBuffer {
  constructor({ logger = logFrontend } = {}) {
    this.databaseName = "trace-offline-operations";
    this.storeName = "operations";
    this.lockStoreName = "locks";
    this.ownerIndexName = "by_owner";
    this.memory = [];
    this.flushing = false;
    this.owner = null;
    // Só precisamos de um identificador quando o navegador realmente oferece
    // IndexedDB; assim a fila em memória continua funcionando em ambientes
    // privados sem Web Crypto.
    this.instanceId = null;
    this.databasePromise = null;
    this.leaseMs = 60000;
    this.api = new ClienteApi({ timeoutMs: 15000, retry: 1 });
    this.logger = logger && typeof logger.aviso === "function" ? logger : logFrontend;
    this.volatilityWarningReported = false;
  }

  reportarFalha(contexto, error, { armazenamento = "IndexedDB", fallback = "manter-operacao" } = {}) {
    this.logger.aviso(`fila-offline.${contexto}`, error, {
      armazenamento,
      fallback,
    });
  }

  reportarArmazenamentoVolatil() {
    if (this.volatilityWarningReported) return;
    this.volatilityWarningReported = true;
    this.reportarFalha(
      "armazenamento-volatil",
      new Error("IndexedDB indisponível; operações offline valem apenas nesta sessão."),
      { armazenamento: "memoria", fallback: "perda-ao-fechar-a-aba" },
    );
  }

  exigirEspacoNaMemoria() {
    const quantidade = this.memory.filter((item) => item.owner === this.owner).length;
    if (quantidade >= MAX_OFFLINE_OPERATIONS_PER_OWNER) {
      throw new OfflineQueueLimitError();
    }
  }

  setOwner(user) {
    const companyId = Number(user?.company_id);
    const userId = Number(user?.id);
    this.owner = Number.isSafeInteger(companyId) && companyId > 0 &&
      Number.isSafeInteger(userId) && userId > 0 ? `${companyId}:${userId}` : null;
  }

  supported() {
    return typeof indexedDB !== "undefined";
  }

  open() {
    if (!this.supported()) return Promise.resolve(null);
    if (this.databasePromise) return this.databasePromise;
    this.databasePromise = new Promise((resolve, reject) => {
      const request = indexedDB.open(this.databaseName, 3);
      request.onupgradeneeded = () => {
        const database = request.result;
        const operations = database.objectStoreNames.contains(this.storeName)
          ? request.transaction.objectStore(this.storeName)
          : database.createObjectStore(this.storeName, { keyPath: "id", autoIncrement: true });
        if (!operations.indexNames.contains(this.ownerIndexName)) {
          // O índice evita carregar registros de outras contas só para filtrá-los depois.
          operations.createIndex(this.ownerIndexName, "owner", { unique: false });
        }
        if (!database.objectStoreNames.contains(this.lockStoreName)) {
          database.createObjectStore(this.lockStoreName, { keyPath: "owner" });
        }
      };
      request.onsuccess = () => {
        const database = request.result;
        database.onversionchange = () => database.close();
        resolve(database);
      };
      request.onerror = () => {
        this.databasePromise = null;
        reject(request.error || new Error("IndexedDB indisponível."));
      };
    });
    return this.databasePromise;
  }

  async enqueue(operation) {
    if (!this.owner) throw new Error("Entre na sua conta para guardar uma operação local.");
    const record = {
      eventId: operation.eventId || secureRandomId(),
      owner: this.owner,
      status: "PENDENTE",
      attempts: 0,
      lastError: "",
      url: operation.url,
      method: operation.method,
      // Credenciais e CSRF da aba não devem permanecer no IndexedDB.
      headers: { "Content-Type": "application/json" },
      body: operation.body || null,
      createdAt: agora().toISOString(),
    };
    try {
      const database = await this.open();
      if (!database) {
        this.exigirEspacoNaMemoria();
        this.reportarArmazenamentoVolatil();
        record.id = secureRandomId();
        this.memory.push(record);
        return record.id;
      }
      return await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.storeName, "readwrite");
        const store = transaction.objectStore(this.storeName);
        // A contagem e a inserção compartilham a transação para que abas
        // concorrentes não ultrapassem o limite do armazenamento local.
        const request = store.indexNames.contains(this.ownerIndexName)
          ? store.index(this.ownerIndexName).count(this.owner)
          : store.getAll();
        let id;
        let limiteAtingido = false;
        request.onsuccess = () => {
          const quantidade = Array.isArray(request.result)
            ? request.result.filter((item) => item.owner === this.owner).length
            : Number(request.result) || 0;
          const quantidadeTotal = quantidade + this.memory.filter((item) => item.owner === this.owner).length;
          if (quantidadeTotal >= MAX_OFFLINE_OPERATIONS_PER_OWNER) {
            limiteAtingido = true;
            transaction.abort();
            return;
          }
          const addRequest = store.add(record);
          addRequest.onsuccess = () => { id = addRequest.result; };
          addRequest.onerror = () => reject(addRequest.error || new Error("Não foi possível guardar a operação."));
        };
        request.onerror = () => reject(request.error || new Error("Não foi possível verificar o espaço da fila offline."));
        transaction.oncomplete = () => resolve(id);
        transaction.onerror = () => reject(limiteAtingido
          ? new OfflineQueueLimitError()
          : transaction.error || new Error("A transação da fila offline falhou."));
        transaction.onabort = () => reject(limiteAtingido
          ? new OfflineQueueLimitError()
          : transaction.error || new Error("A transação da fila offline foi cancelada."));
      });
    } catch (error) {
      if (error?.code === "OFFLINE_QUEUE_LIMIT") throw error;
      this.reportarFalha("gravar", error);
      this.exigirEspacoNaMemoria();
      record.id = secureRandomId();
      this.memory.push(record);
      return record.id;
    }
  }

  async all() {
    if (!this.owner) return [];
    const ownedMemory = this.memory.filter((item) => item.owner === this.owner);
    if (!this.supported()) return ownedMemory;
    try {
      const database = await this.open();
      if (!database) return ownedMemory;
      return await new Promise((resolve, reject) => {
        const store = database.transaction(this.storeName, "readonly").objectStore(this.storeName);
        const request = store.indexNames.contains(this.ownerIndexName)
          ? store.index(this.ownerIndexName).getAll(this.owner)
          : store.getAll();
        request.onsuccess = () => resolve([
          ...ownedMemory,
          ...(request.result || []).filter((item) => item.owner === this.owner),
        ]);
        request.onerror = () => reject(request.error || new Error("Não foi possível ler a fila."));
      });
    } catch (error) {
      this.reportarFalha("ler", error);
      return ownedMemory;
    }
  }

  async pending() {
    return (await this.all()).filter((item) => item.status !== "ERRO");
  }

  async update(id, changes) {
    this.memory = this.memory.map((item) => item.id === id ? { ...item, ...changes } : item);
    if (!this.supported()) return;
    try {
      const database = await this.open();
      if (!database) return;
      await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.storeName, "readwrite");
        const store = transaction.objectStore(this.storeName);
        const request = store.get(id);
        request.onsuccess = () => {
          if (!request.result) return;
          store.put({ ...request.result, ...changes });
        };
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error || new Error("Não foi possível atualizar a operação."));
      });
    } catch (error) {
      this.reportarFalha("atualizar", error);
    }
  }

  async acquireLease() {
    if (!this.owner) return false;
    if (!this.supported()) return true;
    if (!this.instanceId) {
      try {
        this.instanceId = secureRandomId();
      } catch (error) {
        this.reportarFalha("identificador", error, { armazenamento: "WebCrypto", fallback: "bloquear-envio" });
        return false;
      }
    }
    try {
      const database = await this.open();
      if (!database) return true;
      return await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.lockStoreName, "readwrite");
        const store = transaction.objectStore(this.lockStoreName);
        const request = store.get(this.owner);
        request.onsuccess = () => {
          const current = request.result;
          const now = Date.now();
          if (current && current.token !== this.instanceId && Number(current.expiresAt) > now) {
            resolve(false);
            return;
          }
          store.put({ owner: this.owner, token: this.instanceId, expiresAt: now + this.leaseMs });
          resolve(true);
        };
        request.onerror = () => reject(request.error || new Error("Não foi possível reservar a fila offline."));
      });
    } catch (error) {
      this.reportarFalha("reservar", error);
      return false;
    }
  }

  async renewLease() {
    if (!this.owner || !this.supported()) return true;
    try {
      const database = await this.open();
      if (!database) return true;
      return await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.lockStoreName, "readwrite");
        const store = transaction.objectStore(this.lockStoreName);
        const request = store.get(this.owner);
        request.onsuccess = () => {
          if (!request.result || request.result.token !== this.instanceId) {
            resolve(false);
            return;
          }
          store.put({ ...request.result, expiresAt: Date.now() + this.leaseMs });
          resolve(true);
        };
        request.onerror = () => reject(request.error || new Error("Não foi possível renovar a fila offline."));
      });
    } catch (error) {
      this.reportarFalha("renovar-reserva", error);
      return false;
    }
  }

  async releaseLease() {
    if (!this.owner || !this.supported()) return;
    try {
      const database = await this.open();
      if (!database) return;
      await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.lockStoreName, "readwrite");
        const store = transaction.objectStore(this.lockStoreName);
        const request = store.get(this.owner);
        request.onsuccess = () => {
          if (request.result?.token === this.instanceId) store.delete(this.owner);
        };
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error || new Error("Não foi possível liberar a fila offline."));
      });
    } catch (error) {
      this.reportarFalha("liberar-reserva", error);
    }
  }

  async remove(id) {
    if (!this.supported()) {
      this.memory = this.memory.filter((item) => item.id !== id);
      return;
    }
    try {
      const database = await this.open();
      if (!database) throw new Error("IndexedDB indisponível para remover a operação.");
      await new Promise((resolve, reject) => {
        const transaction = database.transaction(this.storeName, "readwrite");
        const request = transaction.objectStore(this.storeName).delete(id);
        request.onerror = () => reject(request.error || new Error("Não foi possível remover a operação."));
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error || new Error("A transação de remoção da fila offline falhou."));
        transaction.onabort = () => reject(transaction.error || new Error("A transação de remoção da fila offline foi cancelada."));
      });
      this.memory = this.memory.filter((item) => item.id !== id);
    } catch (error) {
      this.reportarFalha("remover", error);
    }
  }

  async flush(currentHeaders = {}) {
    if (this.flushing || typeof fetch !== "function") return { sent: 0, pending: (await this.all()).length };
    this.flushing = true;
    let sent = 0;
    let leaseAcquired = false;
    try {
      leaseAcquired = await this.acquireLease();
      if (!leaseAcquired) return { sent: 0, pending: (await this.all()).length };
      const owner = this.owner;
      for (const operation of await this.all()) {
        if (!owner || this.owner !== owner) break;
        if (!(await this.renewLease())) break;
        try {
          const headers = { ...operation.headers, ...currentHeaders,
            "X-Trace-Offline-Id": String(operation.eventId || operation.id) };
          const response = await this.api.fetch(operation.url, {
            method: operation.method,
            headers,
            body: operation.body || undefined,
            credentials: "same-origin",
          });
          if (response.ok) {
            await this.remove(operation.id);
            sent += 1;
          } else if (response.status >= 400 && response.status < 500 && ![408, 429].includes(response.status)) {
            await this.update(operation.id, {
              status: "ERRO",
              attempts: Number(operation.attempts || 0) + 1,
              lastError: `HTTP ${response.status}`,
              failedAt: agora().toISOString(),
            });
            // Uma operação inválida não pode impedir as seguintes. Ela fica
            // visível na fila para correção ou reenvio explícito.
            continue;
          } else {
            break;
          }
        } catch (error) {
          this.logger.aviso("fila-offline.envio", error, { acao: "tentar-novamente" });
          break;
        }
      }
    } finally {
      if (leaseAcquired) await this.releaseLease();
      this.flushing = false;
    }
    return { sent, pending: (await this.all()).length };
  }
}
