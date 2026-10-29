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

  async registerManualReading(barcode) {
    const loadingId = Number(this.state.loadingId) || 0;
    const code = String(barcode || "").trim();
    const noRead = /^ERROR$/i.test(code);
    if (!loadingId) throw new Error("Nenhum carregamento selecionado.");
    if (!code && !noRead) throw new Error("Informe o código de barras.");
    const response = await this.requestWithOfflineQueue("/api/leituras.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        carregamento_id: loadingId,
        barcode: noRead ? "" : code,
        no_read: noRead,
        attempt_number: 1,
      }),
    });
    const result = await this.jsonResponse(response, "Não foi possível registrar a leitura manual.");
    if (result.data?.queued) return result.data;
    let refreshWarning = "";
    try {
      await this.loadActiveLoading(loadingId);
      await this.reloadPendingReadings();
    } catch (error) {
      // Evita repetir uma leitura já confirmada quando falha apenas a atualização da tela.
      refreshWarning = error?.message || "Falha ao atualizar os dados da operação.";
    }
    return refreshWarning ? { ...result.data, refreshWarning } : result.data;
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

  async identifyReading(readingId, barcode) {
    const response = await this.requestWithOfflineQueue("/api/identificar_leitura.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ leitura_id: readingId, barcode }),
    });
    const result = await this.jsonResponse(response, "Não foi possível identificar a leitura.");
    if (result.data?.queued) return result.data;
    await this.reloadPendingReadings();
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
