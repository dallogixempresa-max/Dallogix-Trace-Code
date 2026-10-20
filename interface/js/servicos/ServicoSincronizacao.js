import { exigirRespostaHttp } from "../api/ClienteApi.js?v=202610140003";
import { logFrontend } from "../utilitarios/LogFrontend.js?v=202610060007";

const LIMITE_QUADRO_EVENTO = 256 * 1024;
const LIMITE_DADOS_EVENTO = 128 * 1024;
const ENDPOINTS_COM_FILA_OFFLINE = [
  "/api/identificar_leitura.php",
  "/api/retornos.php",
  "/api/leituras.php",
];

/**
 * Coordena comunicação contínua, fila offline e estado de sincronização.
 * A classe não conhece a tela: recebe um ClienteApi e callbacks pequenos,
 * o que permite testar rede, reconexão e eventos sem abrir o aplicativo.
 */
export class ServicoSincronizacao {
  constructor({
    api,
    offlineBuffer,
    createEventId,
    onOfflineQueueChanged,
  }) {
    if (!api || typeof api.fetch !== "function") {
      throw new TypeError("ClienteApi é obrigatório para sincronizar.");
    }
    if (!offlineBuffer || typeof offlineBuffer.flush !== "function") {
      throw new TypeError("Fila offline é obrigatória para sincronizar.");
    }
    this.api = api;
    this.offlineBuffer = offlineBuffer;
    this.createEventId = createEventId;
    this.onOfflineQueueChanged = onOfflineQueueChanged;
  }

  canQueueOffline(url, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    return ["POST", "PUT", "PATCH"].includes(method) &&
      ENDPOINTS_COM_FILA_OFFLINE.some((path) => url.startsWith(path)) &&
      typeof options.body === "string";
  }

  async requestWithOfflineQueue(url, options = {}) {
    const queueable = this.canQueueOffline(url, options);
    if (queueable && typeof this.createEventId !== "function") {
      throw new Error("Gerador seguro de identificadores indisponível.");
    }
    // O mesmo ID no envio e na fila permite ao servidor deduplicar uma resposta perdida.
    const eventId = queueable ? this.createEventId() : null;
    const requestOptions = queueable
      ? { ...options, headers: { ...(options.headers || {}), "X-Trace-Offline-Id": eventId } }
      : options;
    try {
      return await this.api.fetch(url, requestOptions);
    } catch (error) {
      if (!queueable) throw error;
      const id = await this.offlineBuffer.enqueue({
        url,
        method: options.method || "POST",
        headers: options.headers || {},
        body: options.body,
        eventId,
      });
      this.onOfflineQueueChanged?.((await this.offlineBuffer.all()).length);
      return new Response(JSON.stringify({
        data: { queued: true, offline_id: id },
        message: "Operação guardada e será enviada quando a conexão voltar.",
      }), { status: 202, headers: { "Content-Type": "application/json" } });
    }
  }

  async monitoramento() {
    const response = await this.api.fetch("/api/monitoramento.php");
    await exigirRespostaHttp(response, "Não foi possível carregar o monitoramento.");
    return { data: (await response.json().catch(() => ({}))).data, updatedAt: new Date().toISOString() };
  }

  async statusFila() {
    const response = await this.api.fetch("/api/sync_status.php");
    const result = await response.json().catch(() => ({}));
    await exigirRespostaHttp(response, result.error || "Não foi possível carregar a fila de sincronização.");
    return result.data;
  }

  async reprocessarEvento(id, headers = {}) {
    const response = await this.api.fetch("/api/sync_queue.php", {
      method: "POST",
      headers,
      body: JSON.stringify({ id }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok && response.status !== 202) {
      await exigirRespostaHttp(response, result.error || "Não foi possível reprocessar o evento.");
    }
    return result.data;
  }

  async enviarPendencias(headers = {}) {
    const result = await this.offlineBuffer.flush(headers);
    this.onOfflineQueueChanged?.(result.pending);
    return result;
  }

  assinarEventos({ onData, onError } = {}) {
    if (typeof ReadableStream === "undefined" || typeof TextDecoder === "undefined") return null;
    const controller = new AbortController();
    let fechado = false;
    const escutar = async () => {
      let eventoRecebido = false;
      try {
        const response = await this.api.fetch("/api/eventos_carregamento.php?period_days=30", {
          headers: { Accept: "text/event-stream" },
          signal: controller.signal,
          timeoutMs: 0,
          retry: 0,
        });
        if (!response.ok || !response.body) throw new Error("Conexão de eventos indisponível.");
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let pendente = "";
        let nomeEvento = "";
        let dados = [];
        let tamanhoDados = 0;
        const despachar = () => {
          if (nomeEvento === "carregamento" && dados.length) {
            let payload;
            try {
              payload = JSON.parse(dados.join("\n"));
            } catch (error) {
              throw new Error("Evento de carregamento inválido.", { cause: error });
            }
            onData?.(payload);
            eventoRecebido = true;
          }
          nomeEvento = "";
          dados = [];
          tamanhoDados = 0;
        };
        const processarLinha = (linha) => {
          if (linha === "") {
            despachar();
          } else if (linha.startsWith("event:")) {
            nomeEvento = linha.slice(6).trim();
          } else if (linha.startsWith("data:")) {
            const dado = linha.slice(5).trimStart();
            tamanhoDados += dado.length;
            if (tamanhoDados > LIMITE_DADOS_EVENTO) {
              throw new Error("Evento de sincronização excedeu o limite permitido.");
            }
            dados.push(dado);
          }
        };
        while (!fechado) {
          const { value, done } = await reader.read();
          if (done) break;
          const trecho = decoder.decode(value, { stream: true });
          if (pendente.length + trecho.length > LIMITE_QUADRO_EVENTO) {
            throw new Error("Fluxo de eventos excedeu o limite permitido.");
          }
          pendente += trecho;
          let quebra;
          while ((quebra = pendente.indexOf("\n")) !== -1) {
            const linha = pendente.slice(0, quebra).replace(/\r$/, "");
            pendente = pendente.slice(quebra + 1);
            processarLinha(linha);
          }
        }
        const final = decoder.decode();
        if (pendente.length + final.length > LIMITE_QUADRO_EVENTO) {
          throw new Error("Fluxo de eventos excedeu o limite permitido.");
        }
        pendente += final;
        if (pendente) processarLinha(pendente.replace(/\r$/, ""));
        if (nomeEvento || dados.length) despachar();
      } catch (error) {
        if (!fechado) {
          logFrontend.aviso("sincronizacao.eventos", error);
          onError?.(error);
        }
        return;
      }
      if (!fechado) {
        if (!eventoRecebido) {
          const error = new Error("Fluxo de eventos encerrado sem entregar um evento.");
          logFrontend.aviso("sincronizacao.eventos", error);
          onError?.(error);
          return;
        }
        // Este endpoint entrega um snapshot e fecha a resposta por projeto.
        // O controlador agenda a próxima conexão; EOF após evento é normal.
        onError?.();
      }
    };
    void escutar();
    return { close() { fechado = true; controller.abort(); } };
  }
}
