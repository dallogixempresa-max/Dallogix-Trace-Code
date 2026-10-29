export class ServicoCarregamentoAtivo {
  constructor({
    api,
    state,
    jsonHeaders,
    jsonResponse,
    loadCommandStatus,
    loadEquipments,
    loadSummaryMonitoring,
    hasPendingCommand,
    applyCommandIntent,
    getRefreshQueue,
    setRefreshQueue,
    applySnapshotFromStore,
    applyListFromStore,
  }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.loadCommandStatus = loadCommandStatus;
    this.loadEquipments = loadEquipments;
    this.loadSummaryMonitoring = loadSummaryMonitoring;
    this.hasPendingCommand = hasPendingCommand;
    this.applyCommandIntent = applyCommandIntent;
    this.getRefreshQueue = getRefreshQueue;
    this.setRefreshQueue = setRefreshQueue;
    this.applySnapshotFromStore = applySnapshotFromStore;
    this.applyListFromStore = applyListFromStore;
  }

  async prepareLoading(payload) {
    const response = await this.api.fetch("/api/carregamentos.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(payload),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível preparar o carregamento.",
    );
    this.state.selectedLoadingId = Number(result.data.id);
    await this.load(this.state.selectedLoadingId);
    return result.data;
  }

  async reassignLoading(loadingId, equipmentId) {
    const response = await this.api.fetch("/api/carregamentos.php", {
      method: "PATCH",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        carregamento_id: Number(loadingId),
        equipment_id: Number(equipmentId),
      }),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível vincular a nova Dala.",
    );
    await this.loadEquipments({ force: true });
    await this.load(Number(loadingId));
    return result.data;
  }

  async unlockMachine() {
    if (!this.state.loadingId) {
      throw new Error("Nenhum carregamento ativo encontrado.");
    }

    const response = await this.api.fetch("/api/desbloquear_maquina.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ carregamento_id: this.state.loadingId }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (response.status === 409) {
        await this.load();
        if (!this.state.emergency) {
          return { alreadyUnlocked: true, state: this.state.operationalState };
        }
      }
      throw new Error(result.error || "Não foi possível desbloquear a máquina.");
    }

    await this.load();
    return result.data;
  }

  async finishLoading(loadingId, justification = "") {
    if (!loadingId) {
      throw new Error("Nenhum carregamento ativo encontrado.");
    }

    const response = await this.api.fetch("/api/encerrar_carregamento.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ carregamento_id: loadingId, justification }),
    });
    const result = await this.jsonResponse(response, "Não foi possível finalizar o carregamento.");
    this.state.operationalState = "FINALIZADO";
    this.state.running = false;
    await Promise.all([
      this.loadSummaryMonitoring(),
      this.loadEquipments({ force: true }),
    ]);
    return result.data;
  }

  load(
    selectedId = this.state.selectedLoadingId,
    loadOperation = () => this._load(selectedId),
  ) {
    const refresh = this.getRefreshQueue()
      .catch(() => {})
      .then(loadOperation);
    this.setRefreshQueue(refresh.catch(() => {}));
    return refresh;
  }

  async _load(selectedId = this.state.selectedLoadingId) {
    const response = await this.api.fetch("/api/carregamentos.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar o carregamento ativo.");
    const loadings = Array.isArray(result.data) ? result.data : [];
    const loading = this.applyListFromStore(loadings, selectedId);
    if (this.state.loadingId) await this.loadCommandStatus(this.state.loadingId);
    return loading;
  }

  applyQueuedSnapshot(
    loadings,
    selectedId = this.state.selectedLoadingId,
    isCurrent = () => true,
  ) {
    const refresh = this.getRefreshQueue()
      .catch(() => {})
      .then(() => isCurrent() ? this.applySnapshotFromStore(loadings, selectedId) : null);
    this.setRefreshQueue(refresh.catch(() => {}));
    return refresh;
  }

  _applyList(loadings, selectedId = this.state.selectedLoadingId) {
    const activeLoadings = (Array.isArray(loadings) ? loadings : []).filter(
      (item) => item.state !== "FINALIZADO",
    );
    const requestedId = Number(selectedId) || null;
    const requestedLoading = activeLoadings.find((item) => Number(item.id) === requestedId);
    const preservePendingSelection = Boolean(
      requestedId &&
      Number(this.state.loadingId) === requestedId &&
      !requestedLoading &&
      this.hasPendingCommand(),
    );
    if (!preservePendingSelection) {
      this.state.activeLoadings = activeLoadings;
    }
    const loading = requestedLoading?.equipment_id
      ? requestedLoading
      : (requestedId
        ? null
        : (activeLoadings.length === 1 && activeLoadings[0]?.equipment_id
          ? activeLoadings[0]
          : null));
    if (!loading) {
      if (preservePendingSelection) {
        return this.state.activeLoadings.find(
          (item) => Number(item.id) === requestedId,
        ) || null;
      }
      this.state.loadingId = null;
      this.state.selectedLoadingId = null;
      this.state.returnMode = false;
      this.state.loaded = 0;
      this.state.detectedBags = 0;
      this.state.planned = 0;
      this.state.running = false;
      this.state.emergency = false;
      this.state.operationalState = "AGUARDANDO";
      this.state.stopReason = null;
      this.state.operatorAlert = "";
      this.state.truck = "—";
      this.state.romaneio = "—";
      this.state.equipmentCode = "—";
      this.state.equipmentId = null;
      this.state.reversalCommand = null;
      this.state.plcCommand = null;
      this.state.commandIntent = null;
      this.state.commandFeedback = "";
      this.state.loadingItems = [];
      this.state.latestScannerReading = null;
      return null;
    }

    const selectionChanged = Number(this.state.loadingId) !== Number(loading.id);
    if (selectionChanged) {
      this.state.commandFeedback = "";
      this.state.commandIntent = null;
      this.state.plcCommand = null;
    }
    this.state.loadingId = Number(loading.id);
    this.state.selectedLoadingId = Number(loading.id);
    this.state.operationalState = loading.state;
    this.state.stopReason = loading.stop_reason || null;
    this.state.operatorAlert = loading.operator_alert || "";
    this.state.emergency = loading.state === "EMERGENCIA";
    this.state.running = ["CARREGANDO", "FINALIZANDO"].includes(loading.state);
    this.state.planned = Number(loading.planned_quantity) || 0;
    this.state.loaded = Number(loading.valid_readings) || 0;
    this.state.detectedBags = Number(loading.detected_bags) || 0;
    this.state.latestScannerReading = loading.last_scanner_read_at
      ? {
          barcode: String(loading.last_scanner_barcode || ""),
          result: String(loading.last_scanner_result || ""),
          readAt: String(loading.last_scanner_read_at),
          value: String(loading.last_scanner_result || "") === "SEM_LEITURA"
            ? "Falha na leitura (sem código)"
            : String(loading.last_scanner_barcode || "Leitura sem código"),
        }
      : null;
    this.state.truck = loading.plate || "—";
    this.state.romaneio = loading.romaneio_number || "—";
    this.state.equipmentCode = loading.equipment_code || "—";
    this.state.equipmentId = Number(loading.equipment_id) || null;
    this.state.loadingItems = Array.isArray(loading.items) ? loading.items : [];
    this.applyCommandIntent();
    return loading;
  }
}
