import { erroRespostaHttp } from "../api/ClienteApi.js?v=202610140003";

const PAGINACAO_COMANDOS_PADRAO = { page: 1, per_page: 30, total: 0, pages: 1 };
const PAGINACAO_LOGS_PADRAO = { page: 1, per_page: 100, total: 0, pages: 1 };

/** Coordena status, históricos e diagnósticos sem acoplar consultas ao store da tela. */
export class ServicoObservabilidade {
  constructor({ api, state, jsonHeaders, jsonResponse } = {}) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.commandHistoryRequestId = 0;
    this.commandHistoryPending = null;
    this.errorLogsRequestId = 0;
    this.errorLogsPending = null;
  }

  async loadDalaStatuses() {
    const response = await this.api.fetch("/api/status_dalas.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar o status das Dalas.");
    this.state.dalaStatuses = Array.isArray(result.data) ? result.data : [];
    return this.state.dalaStatuses;
  }

  async loadDeviceStatuses() {
    const response = await this.api.fetch("/api/status_dispositivos.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar o status dos dispositivos.");
    this.state.deviceStatuses = Array.isArray(result.data) ? result.data : [];
    this.state.deviceStatusesUpdatedAt = new Date().toISOString();
    return this.state.deviceStatuses;
  }

  async loadAlertMonitoring() {
    const response = await this.api.fetch("/api/monitoramento.php?view=alertas");
    const result = await this.jsonResponse(response, "Não foi possível carregar os alertas.");
    this.state.alertMonitoring = result.data || {};
    this.state.alertMonitoringUpdatedAt = new Date().toISOString();
    return this.state.alertMonitoring;
  }

  async loadSummaryMonitoring() {
    const response = await this.api.fetch("/api/monitoramento.php?view=resumo");
    const result = await this.jsonResponse(response, "Não foi possível carregar o resumo final.");
    this.state.summaryMonitoring = result.data || {};
    this.state.summaryMonitoringUpdatedAt = new Date().toISOString();
    return this.state.summaryMonitoring;
  }

  async loadDalaCommandHistory(id, page = null) {
    const equipmentKey = String(id);
    const currentPage = this.state.dalaCommandHistoryEquipmentId === equipmentKey
      ? this.state.dalaCommandHistoryRequestedPage || this.state.dalaCommandsPagination?.page || 1
      : 1;
    const normalizedPage = Math.max(1, Number.parseInt(page ?? currentPage, 10) || 1);
    if (this.commandHistoryPending?.equipmentKey === equipmentKey && this.commandHistoryPending.page === normalizedPage) {
      return this.commandHistoryPending.promise;
    }

    const previousEquipmentId = this.state.dalaCommandHistoryEquipmentId;
    const requestId = ++this.commandHistoryRequestId;
    this.state.dalaCommandHistoryEquipmentId = equipmentKey;
    this.state.dalaCommandHistoryRequestedPage = normalizedPage;
    const promise = Promise.resolve().then(async () => {
      try {
        const response = await this.api.fetch(`/api/comandos_industriais.php?equipment_id=${encodeURIComponent(id)}&page=${normalizedPage}`);
        const result = await this.jsonResponse(response, "Não foi possível carregar o diagnóstico do CLP.");
        if (requestId !== this.commandHistoryRequestId) return this.state.dalaCommands;

        this.state.dalaCommands = Array.isArray(result.data) ? result.data : [];
        this.state.dalaCommandsPagination = result.pagination || {
          ...PAGINACAO_COMANDOS_PADRAO,
          page: normalizedPage,
          total: this.state.dalaCommands.length,
        };
        this.state.dalaCommandHistoryRequestedPage = this.state.dalaCommandsPagination.page;
        return this.state.dalaCommands;
      } catch (error) {
        if (requestId === this.commandHistoryRequestId) {
          this.state.dalaCommandHistoryEquipmentId = previousEquipmentId;
          this.state.dalaCommandHistoryRequestedPage = previousEquipmentId === equipmentKey
            ? this.state.dalaCommandsPagination?.page || 1
            : 1;
        }
        throw error;
      } finally {
        if (this.commandHistoryPending?.requestId === requestId) this.commandHistoryPending = null;
      }
    });
    this.commandHistoryPending = { equipmentKey, page: normalizedPage, requestId, promise };
    return promise;
  }

  async loadErrorLogs(page = null) {
    const currentPage = page ?? this.state.errorLogsRequestedPage ?? this.state.errorLogsPagination?.page ?? 1;
    const normalizedRequestedPage = Math.max(1, Number.parseInt(currentPage, 10) || 1);
    if (this.errorLogsPending?.page === normalizedRequestedPage) return this.errorLogsPending.promise;

    const requestId = ++this.errorLogsRequestId;
    this.state.errorLogsRequestedPage = normalizedRequestedPage;
    const promise = Promise.resolve().then(async () => {
      try {
        const response = await this.api.fetch(`/api/logs_erros.php?page=${normalizedRequestedPage}`);
        const result = await this.jsonResponse(response, "Não foi possível carregar os logs de erro.");
        if (requestId !== this.errorLogsRequestId) return this.state.errorLogs;

        this.state.errorLogs = result.data || [];
        this.state.errorLogsPagination = result.pagination || {
          ...PAGINACAO_LOGS_PADRAO,
          page: normalizedRequestedPage,
          total: this.state.errorLogs.length,
        };
        this.state.errorLogsRequestedPage = this.state.errorLogsPagination.page;
        this.state.errorLogRetentionDays = Number.isInteger(Number(result.retention_days))
          ? Math.max(0, Number(result.retention_days))
          : 365;
        return this.state.errorLogs;
      } catch (error) {
        if (requestId === this.errorLogsRequestId) {
          this.state.errorLogsRequestedPage = this.state.errorLogsPagination?.page || 1;
        }
        throw error;
      } finally {
        if (this.errorLogsPending?.requestId === requestId) this.errorLogsPending = null;
      }
    });
    this.errorLogsPending = { page: normalizedRequestedPage, requestId, promise };
    return promise;
  }

  async persistFrontendLog(record) {
    const response = await this.api.fetch("/api/logs_erros.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(record),
    });
    if (!response.ok) {
      const error = await erroRespostaHttp(response, "Não foi possível persistir o log do navegador.");
      throw error || new Error("Não foi possível persistir o log do navegador.");
    }
    return response;
  }

  async loadTechnicalDiagnostics() {
    const response = await this.api.fetch("/api/diagnostico.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar o diagnóstico técnico.");
    this.state.technicalDiagnostics = result.data || null;
    return this.state.technicalDiagnostics;
  }

  async loadDeadLetters() {
    const response = await this.api.fetch("/api/sync_dead_letter.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar a fila morta.");
    this.state.deadLetters = result.data || [];
    return this.state.deadLetters;
  }

  async updateDeadLetter(id, action, resolutionNote) {
    const response = await this.api.fetch(`/api/sync_dead_letter.php?id=${encodeURIComponent(id)}`, {
      method: "PATCH",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ id, action, resolution_note: resolutionNote }),
    });
    const result = await this.jsonResponse(response, "Não foi possível atualizar a fila morta.");
    await Promise.all([this.loadDeadLetters(), this.loadTechnicalDiagnostics()]);
    return result.data;
  }
}
