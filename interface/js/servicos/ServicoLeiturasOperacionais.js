export class ServicoLeiturasOperacionais {
  constructor({
    api,
    state,
    jsonHeaders,
    jsonResponse,
    requestWithOfflineQueue,
    nextPendingReadingsRequestId,
    getPendingReadingsRequestId,
    loadActiveLoading,
    loadPendingReadings,
    onScannerRefreshError = () => {},
    createEventId = () => globalThis.crypto?.randomUUID?.() || "",
  }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.requestWithOfflineQueue = requestWithOfflineQueue;
    this.nextPendingReadingsRequestId = nextPendingReadingsRequestId;
    this.getPendingReadingsRequestId = getPendingReadingsRequestId;
    this.loadActiveLoading = loadActiveLoading;
    this.reloadPendingReadings = loadPendingReadings;
    this.createEventId = createEventId;
    this.onScannerRefreshError = onScannerRefreshError;
  }

  async loadPendingReadings(page = this.state.pendingReadingsPage) {
    const requestId = this.nextPendingReadingsRequestId();
    if (!this.state.loadingId) {
      this.state.pendingReadings = [];
      this.state.pendingReadingsPage = 1;
      this.state.pendingReadingsPages = 1;
      this.state.pendingReadingsTotal = 0;
      this.state.pendingReadingsLoadingId = null;
      return [];
    }
    const loadingId = Number(this.state.loadingId);
    const requestedPage = this.state.pendingReadingsLoadingId === loadingId ? page : 1;
    const response = await this.api.fetch(
      `/api/leituras.php?carregamento_id=${encodeURIComponent(loadingId)}&page=${encodeURIComponent(requestedPage)}`,
    );
    const result = await this.jsonResponse(response, "Não foi possível carregar leituras pendentes.");
    if (requestId !== this.getPendingReadingsRequestId() || Number(this.state.loadingId) !== loadingId) {
      return this.state.pendingReadings;
    }
    this.state.pendingReadings = result.data || [];
    this.state.pendingReadingsPage = Number(result.pagination?.page) || 1;
    this.state.pendingReadingsPages = Number(result.pagination?.pages) || 1;
    this.state.pendingReadingsTotal = Number(result.pagination?.total) || this.state.pendingReadings.length;
    this.state.pendingReadingsLoadingId = loadingId;
    return this.state.pendingReadings;
  }

  async registerScannerReading(rawBarcode, scannerReceivedAtEpochMs = Date.now()) {
    const loadingId = Number(this.state.loadingId) || 0;
    if (!loadingId || !["CARREGANDO", "FINALIZANDO", "PAUSADO"].includes(this.state.operationalState)) {
      throw new Error("Não há carregamento ativo para associar a leitura do leitor.");
    }

    const barcode = String(rawBarcode || "").trim();
    if (!barcode || barcode.length > 128 || /[\x00-\x1F\x7F]/.test(barcode)) {
      throw new Error("O leitor enviou um código de barras inválido.");
    }
    const offlineEventId = String(this.createEventId() || "");
    if (!/^[a-f0-9-]{16,36}$/i.test(offlineEventId)) {
      throw new Error("Não foi possível identificar esta leitura automática.");
    }

    const receivedAt = Number(scannerReceivedAtEpochMs) || Date.now();
    // A passagem pode chegar ao servidor até ~1,5 s depois da detecção quando
    // um deadlock faz o Node-RED reenviá-la. Espere até 3 s contados da
    // captura, não do início do envio: uma captura que ficou na fila atrás de
    // outra tenta por no máximo 200 ms e libera a fila, sem atrasar as
    // leituras seguintes. O servidor associa pelo horário da captura.
    const startedAt = Date.now();
    const associationDeadline = Math.max(startedAt + 200, Math.min(receivedAt, startedAt) + 3000);
    let transientRetryCount = 0;
    while (Number(this.state.loadingId) === loadingId) {
      const response = await this.api.fetch("/api/leituras.php", {
        method: "POST",
        headers: {
          ...this.jsonHeaders(),
          "X-Trace-Offline-ID": offlineEventId,
        },
        body: JSON.stringify({
          carregamento_id: loadingId,
          barcode,
          scanner_received_at_epoch_ms: receivedAt,
        }),
      });

      if (response.status === 409) {
        const body = await response.clone().json().catch(() => ({}));
        const waitingForPassage = body.error === "Leitura aguardando o evento de passagem do sensor M2052.";
        if (waitingForPassage && Date.now() < associationDeadline) {
          await new Promise((resolve) => setTimeout(resolve, 100));
          continue;
        }
      }

      // A janela de associação só limita espera por um evento M2052 ainda não
      // recebido. Deadlock/lock timeout é outra espera: a transação foi revertida
      // e o mesmo identificador idempotente pode ser repetido mesmo após a janela de associação,
      // enquanto o finalizador ainda não tiver registrado SEM_LEITURA.
      if (response.status === 503 && transientRetryCount < 2) {
        const body = await response.clone().json().catch(() => ({}));
        if (body.retryable === true) {
          transientRetryCount++;
          await new Promise((resolve) => setTimeout(resolve, 75 * transientRetryCount));
          continue;
        }
      }

      const result = await this.jsonResponse(response, "Não foi possível registrar a leitura do leitor.");
      if (Number(this.state.loadingId) === loadingId) {
        try {
          await this.loadActiveLoading(loadingId, { onlyIfCurrent: true });
        } catch (error) {
          this.onScannerRefreshError(error);
        }
      }
      return result.data;
    }
    return null;
  }

  /**
   * Guarda no servidor o resultado do leitor que não foi ligado a nenhuma
   * passagem (sem passagem, esteira parada ou captura descartada pela tela),
   * para que ele apareça no relatório de auditoria e não só no contador.
   */
  async registrarLeituraSemPassagem(loadingId, barcode, capturedAtEpochMs, motivo, { noRead = false } = {}) {
    const id = Number(loadingId) || 0;
    if (!id) return null;
    const offlineEventId = String(this.createEventId() || "");
    const response = await this.api.fetch("/api/leituras_sem_passagem.php", {
      method: "POST",
      headers: {
        ...this.jsonHeaders(),
        ...(/^[a-f0-9-]{16,36}$/i.test(offlineEventId) ? { "X-Trace-Offline-ID": offlineEventId } : {}),
      },
      body: JSON.stringify({
        carregamento_id: id,
        barcode: noRead ? "" : String(barcode || "").trim(),
        no_read: Boolean(noRead),
        motivo,
        scanner_received_at_epoch_ms: Number(capturedAtEpochMs) || Date.now(),
      }),
    });
    return this.jsonResponse(response, "Não foi possível registrar a leitura sem passagem.");
  }

  async confirmStopRemoval(loadingId = this.state.loadingId) {
    const id = Number(loadingId) || 0;
    if (!id) throw new Error("Nenhum carregamento selecionado.");
    const response = await this.api.fetch("/api/confirmar_remocao.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ carregamento_id: id, confirmar_remocao: true }),
    });
    const result = await this.jsonResponse(response, "Não foi possível registrar a confirmação de segurança.");
    await this.loadActiveLoading(id);
    return result.data;
  }

  async registerReturn(readingId, reason) {
    const response = await this.requestWithOfflineQueue("/api/retornos.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        carregamento_id: this.state.loadingId,
        leitura_id: readingId,
        reason,
      }),
    });
    const result = await this.jsonResponse(response, "Não foi possível registrar o retorno.");
    if (result.data?.queued) return result.data;
    await this.loadActiveLoading(this.state.loadingId);
    return result.data;
  }
}
