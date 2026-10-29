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

  async registerScannerReading(rawBarcode) {
    const loadingId = Number(this.state.loadingId) || 0;
    if (!loadingId || !["CARREGANDO", "FINALIZANDO"].includes(this.state.operationalState)) {
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

    const associationDeadline = Date.now() + 1500;
    while (true) {
      const response = await this.api.fetch("/api/leituras.php", {
        method: "POST",
        headers: {
          ...this.jsonHeaders(),
          "X-Trace-Offline-ID": offlineEventId,
        },
        body: JSON.stringify({ carregamento_id: loadingId, barcode }),
      });

      if (response.status === 409) {
        const body = await response.clone().json().catch(() => ({}));
        const waitingForPassage = body.error === "Leitura aguardando o evento de passagem do sensor M2052.";
        if (waitingForPassage && Date.now() < associationDeadline) {
          await new Promise((resolve) => setTimeout(resolve, 100));
          continue;
        }
      }

      const result = await this.jsonResponse(response, "Não foi possível registrar a leitura do leitor.");
      await this.loadActiveLoading(loadingId);
      return result.data;
    }
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
