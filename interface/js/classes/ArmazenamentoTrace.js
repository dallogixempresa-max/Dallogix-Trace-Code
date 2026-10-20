import { OfflineOperationBuffer, secureRandomId } from "./OfflineOperationBuffer.js?v=202610190032";
import { ClienteApi, erroRespostaHttp } from "../api/ClienteApi.js?v=202610140003";
import { ServicoSincronizacao } from "../servicos/ServicoSincronizacao.js?v=202610200004";
import { ServicoDalas } from "../servicos/ServicoDalas.js?v=202610190001";
import { ServicoObservabilidade } from "../servicos/ServicoObservabilidade.js?v=202610190003";
import { ServicoCarregamentoAtivo } from "../servicos/ServicoCarregamentoAtivo.js?v=202610190054";
import { ServicoComandosMaquina } from "../servicos/ServicoComandosMaquina.js?v=202610190029";
import { ServicoLeiturasOperacionais } from "../servicos/ServicoLeiturasOperacionais.js?v=202610190029";
import { resolverComandoAtual } from "../funcoes/estado-comando.js?v=202610130003";
import {
  buscarTodasPaginas,
  validarCursorPaginacao as validarCursorPaginacaoApi,
} from "../funcoes/paginacao-api.js?v=202610190044";

const LIMITE_IDADE_MONITORAMENTO_OPERACIONAL_MS = 6000;

export class ArmazenamentoTrace {
  constructor() {
    this.state = {
      page: "manifests",
      loaded: 0,
      detectedBags: 0,
      planned: 0,
      running: false,
      emergency: false,
      returnMode: false,
      loadingId: null,
      selectedLoadingId: null,
      activeLoadings: [],
      loadingItems: [],
      operationalState: "AGUARDANDO",
      stopReason: null,
      operatorAlert: "",
      truck: "—",
      romaneio: "—",
      equipmentCode: "—",
      equipmentId: null,
      // null indica que a Dala ainda não tem reversão confirmada pelo gateway.
      reversalCommand: null,
      monitoring: null,
      monitoringUpdatedAt: null,
      alertMonitoring: null,
      alertMonitoringUpdatedAt: null,
      summaryMonitoring: null,
      summaryMonitoringUpdatedAt: null,
      occurrences: [],
      deviceStatuses: [],
      deviceStatusesUpdatedAt: null,
      // O status do servidor vem do health check HTTP, não de um heartbeat
      // de dispositivo. Até a primeira resposta, ele permanece desconhecido.
      serverStatus: "DESCONHECIDO",
      syncStatus: null,
      products: [],
      productsLoaded: false,
      productsLoadedAt: null,
      users: [],
      userRole: null,
      companyLoginDomain: null,
      configuration: null,
      equipments: [],
      equipmentsLoaded: false,
      equipmentsLoading: false,
      equipmentsError: "",
      dalaStatuses: [],
      companies: [],
      companyDetail: null,
      industrialInstallations: [],
      industrialEquipmentOptions: [],
      industrialInstallationActivation: null,
      selectedCompanyId: null,
      manifestFilters: {
        date_from: "",
        date_to: "",
        number: "",
        expedidor: "",
        status: "",
      },
      manifestPage: 1,
      manifestMeta: { page: 1, per_page: 50, total: 0, pages: 0 },
      manifestActionFeedback: null,
      manifestActionPending: false,
      manifestDetail: null,
      equipmentDetail: null,
      companyActivation: null,
      localActivation: null,
      dalaCommands: [],
      dalaCommandsPagination: { page: 1, per_page: 30, total: 0, pages: 1 },
      dalaCommandHistoryEquipmentId: null,
      dalaCommandHistoryRequestedPage: 1,
      dalaActionConfig: { acoes: [], gatilhos: [], can_manage: false },
      errorLogs: [],
      errorLogsPagination: { page: 1, per_page: 100, total: 0, pages: 1 },
      errorLogsRequestedPage: 1,
      errorLogRetentionDays: 365,
      technicalDiagnostics: null,
      deadLetters: [],
      plcCommand: null,
      // Intenção visível enquanto o gateway ainda não confirmou o ACK físico.
      commandIntent: null,
      commandFeedback: "",
      commandInFlight: false,
      commandInFlightToken: null,
      productFormOpen: false,
      dalaFormOpen: false,
      editingProductId: null,
      productSearch: "",
      loadErrors: [],
      pendingReadings: [],
      pendingReadingsPage: 1,
      pendingReadingsPages: 1,
      pendingReadingsTotal: 0,
      pendingReadingsLoadingId: null,
      offlineQueueSize: 0,
    };
    this.csrfToken = "";
    this.api = new ClienteApi();
    this.manifests = [];
    // Evita instâncias duplicadas quando duas ações pedem o mesmo serviço juntas.
    this.servicosCarregando = new Map();
    this.offlineBuffer = new OfflineOperationBuffer();
    this.sincronizacao = new ServicoSincronizacao({
      api: this.api,
      offlineBuffer: this.offlineBuffer,
      createEventId: secureRandomId,
      onOfflineQueueChanged: (count) => { this.state.offlineQueueSize = count; },
    });
    this.servicoDalas = new ServicoDalas({
      api: this.api,
      state: this.state,
      jsonHeaders: () => this.jsonHeaders(),
      jsonResponse: (...args) => this.jsonResponse(...args),
      fetchAllPages: (...args) => this.fetchAllPages(...args),
    });
    // Consultas do carregamento e eventos em tempo real podem chegar juntas.
    // A fila garante que uma resposta antiga não sobrescreva a mais recente.
    this.activeLoadingRefresh = Promise.resolve();
    this.commandStatusRefresh = Promise.resolve();
    this.pendingReadingsRequestId = 0;
    this.servicoCarregamentoAtivo = new ServicoCarregamentoAtivo({
      api: this.api,
      state: this.state,
      jsonHeaders: () => this.jsonHeaders(),
      jsonResponse: (...args) => this.jsonResponse(...args),
      loadCommandStatus: (...args) => this.loadPlcCommandStatus(...args),
      loadEquipments: (...args) => this.loadEquipments(...args),
      loadSummaryMonitoring: (...args) => this.loadSummaryMonitoring(...args),
      hasPendingCommand: () => this.comandoOperacionalPendente(),
      applyCommandIntent: () => this.aplicarIntencaoDeComando(),
      getRefreshQueue: () => this.activeLoadingRefresh,
      setRefreshQueue: (queue) => { this.activeLoadingRefresh = queue; },
      applySnapshotFromStore: (...args) => this._applyActiveLoadingSnapshot(...args),
      applyListFromStore: (...args) => this._applyActiveLoadingList(...args),
    });
    this.servicoComandosMaquina = new ServicoComandosMaquina({
      api: this.api,
      state: this.state,
      jsonHeaders: () => this.jsonHeaders(),
      jsonResponse: (...args) => this.jsonResponse(...args),
      applyCommandIntent: () => this.aplicarIntencaoDeComando(),
      getStatusQueue: () => this.commandStatusRefresh,
      setStatusQueue: (queue) => { this.commandStatusRefresh = queue; },
    });
    this.servicoLeiturasOperacionais = new ServicoLeiturasOperacionais({
      api: this.api,
      state: this.state,
      jsonHeaders: () => this.jsonHeaders(),
      jsonResponse: (...args) => this.jsonResponse(...args),
      requestWithOfflineQueue: (...args) => this.requestWithOfflineQueue(...args),
      nextPendingReadingsRequestId: () => ++this.pendingReadingsRequestId,
      getPendingReadingsRequestId: () => this.pendingReadingsRequestId,
      loadActiveLoading: (...args) => this.loadActiveLoading(...args),
      loadPendingReadings: (...args) => this.loadPendingReadings(...args),
    });
  }
  navigate(page) {
    this.state.page = page;
  }
  obterServicoSobDemanda(chave, importar, criar) {
    if (this[chave]) return Promise.resolve(this[chave]);

    const carregamentoAtual = this.servicosCarregando.get(chave);
    if (carregamentoAtual) return carregamentoAtual;

    const carregamento = importar()
      .then(criar)
      .then((servico) => {
        this[chave] = servico;
        return servico;
      })
      .finally(() => {
        if (this.servicosCarregando.get(chave) === carregamento) {
          this.servicosCarregando.delete(chave);
        }
      });

    this.servicosCarregando.set(chave, carregamento);
    return carregamento;
  }
  delegarParaServico(obterServico, metodo, ...argumentos) {
    return obterServico.call(this).then((servico) => servico[metodo](...argumentos));
  }
  obterServicoRomaneios() {
    return this.obterServicoSobDemanda(
      "servicoRomaneios",
      () => import("../servicos/ServicoRomaneios.js?v=202610190050"),
      ({ ServicoRomaneios }) => new ServicoRomaneios({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
        getCsrfToken: () => this.csrfToken,
        setManifestRows: (rows) => { this.manifests = rows; },
      }),
    );
  }
  obterServicoCatalogoProdutos() {
    return this.obterServicoSobDemanda(
      "servicoCatalogoProdutos",
      () => import("../servicos/ServicoCatalogoProdutos.js?v=202610190050"),
      ({ ServicoCatalogoProdutos }) => new ServicoCatalogoProdutos({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
        fetchAllPages: (...args) => this.fetchAllPages(...args),
      }),
    );
  }
  obterServicoAdministracaoContas() {
    return this.obterServicoSobDemanda(
      "servicoAdministracaoContas",
      () => import("../servicos/ServicoAdministracaoContas.js?v=202610190050"),
      ({ ServicoAdministracaoContas }) => new ServicoAdministracaoContas({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
        fetchAllPages: (...args) => this.fetchAllPages(...args),
        loadIndustrialInstallations: () => this.loadIndustrialInstallations(),
      }),
    );
  }
  obterServicoInstalacoesIndustriais() {
    return this.obterServicoSobDemanda(
      "servicoInstalacoesIndustriais",
      () => import("../servicos/ServicoInstalacoesIndustriais.js?v=202610190050"),
      ({ ServicoInstalacoesIndustriais }) => new ServicoInstalacoesIndustriais({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
        validarCursorPaginacao: (...args) => this.validarCursorPaginacao(...args),
      }),
    );
  }
  obterServicoAcoesDala() {
    return this.obterServicoSobDemanda(
      "servicoAcoesDala",
      () => import("../servicos/ServicoAcoesDala.js?v=202610190051"),
      ({ ServicoAcoesDala }) => new ServicoAcoesDala({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
      }),
    );
  }
  obterServicoObservabilidade() {
    if (!this.servicoObservabilidade) {
      this.servicoObservabilidade = new ServicoObservabilidade({
        api: this.api,
        state: this.state,
        jsonHeaders: () => this.jsonHeaders(),
        jsonResponse: (...args) => this.jsonResponse(...args),
      });
    }
    return this.servicoObservabilidade;
  }
  setUser(user) {
    this.offlineBuffer.setOwner(user);
    this.state.userRole = user?.role || null;
    this.state.currentUserId = user?.id || null;
    this.state.companyLoginDomain = user?.company_login_domain || null;
  }
  setCsrfToken(token) {
    this.csrfToken = token || "";
  }
  jsonHeaders() {
    return {
      "Content-Type": "application/json",
      ...(this.csrfToken ? { "X-CSRF-Token": this.csrfToken } : {}),
    };
  }
  async jsonResponse(response, fallback) {
    if (!response.ok) {
      // Classifique o erro antes de consumir o corpo. erroRespostaHttp() usa clone()
      // para preservar a mensagem enviada pelo servidor, inclusive quando a
      // resposta não contém JSON válido.
      const error = await erroRespostaHttp(response, fallback);
      throw error || new Error(fallback);
    }
    return response.json().catch(() => ({}));
  }
  async fetchAllPages(path, { signal = undefined, cursorParam = "cursor" } = {}) {
    return buscarTodasPaginas({
      api: this.api,
      jsonResponse: (response, fallback) => this.jsonResponse(response, fallback),
      path,
      signal,
      cursorParam,
    });
  }
  validarCursorPaginacao(pagination, cursorAtual) {
    return validarCursorPaginacaoApi(pagination, cursorAtual);
  }
  canQueueOffline(url, options = {}) {
    return this.sincronizacao.canQueueOffline(url, options);
  }
  requestWithOfflineQueue(url, options = {}) {
    return this.sincronizacao.requestWithOfflineQueue(url, options);
  }
  async flushOfflineOperations() {
    return this.sincronizacao.enviarPendencias(this.jsonHeaders());
  }
  subscribeOperationalEvents(onData, onError) {
    return this.sincronizacao.assinarEventos({ onData, onError });
  }
  beginCommand() {
    if (this.state.commandInFlight) return null;
    const token = secureRandomId();
    this.state.commandInFlight = true;
    this.state.commandInFlightToken = token;
    return token;
  }
  endCommand(token) {
    if (!token || this.state.commandInFlightToken !== token) return false;
    this.state.commandInFlight = false;
    this.state.commandInFlightToken = null;
    return true;
  }
  comandoOperacionalPendente() {
    const current = resolverComandoAtual(this.state.plcCommand, this.state.commandIntent);
    const status = String(current?.status || "").toUpperCase();
    return this.state.commandInFlight || ["PENDENTE", "PROCESSANDO"].includes(status);
  }
  aplicarIntencaoDeComando() {
    const intent = this.state.commandIntent;
    const current = resolverComandoAtual(this.state.plcCommand, intent);
    const status = String(current?.status || "").toUpperCase();
    if (!intent || !["PENDENTE", "PROCESSANDO"].includes(status)) return;
    if (Number(intent.loadingId) !== Number(this.state.loadingId)) return;
    const intentId = Number(intent.id) || null;
    const currentId = Number(current.id) || null;
    if (!intentId || (currentId && currentId !== intentId)) return;
    if (String(current.command || "").toUpperCase() !== String(intent.command || "").toUpperCase()) return;
    if (intent.command === "INICIAR_CARREGAMENTO") {
      this.state.operationalState = "CARREGANDO";
      this.state.running = true;
    } else if (intent.command === "PAUSAR_CARREGAMENTO") {
      this.state.operationalState = "PAUSADO";
      this.state.running = false;
    } else if (intent.command === "REVERSAO_ATIVAR") {
      this.state.returnMode = true;
      this.state.reversalCommand = "REVERSAO_ATIVAR";
    } else if (intent.command === "REVERSAO_DESATIVAR") {
      this.state.returnMode = false;
      this.state.reversalCommand = "REVERSAO_DESATIVAR";
    }
  }
  loadManifests() {
    return this.delegarParaServico(this.obterServicoRomaneios, "loadManifests");
  }
  applyManifestFilters(raw) {
    return this.delegarParaServico(this.obterServicoRomaneios, "applyFilters", raw);
  }
  clearManifestFilters() {
    return this.delegarParaServico(this.obterServicoRomaneios, "clearFilters");
  }
  setManifestPage(page) {
    return this.delegarParaServico(this.obterServicoRomaneios, "setPage", page);
  }
  loadManifest(id) {
    return this.delegarParaServico(this.obterServicoRomaneios, "loadManifest", id);
  }
  createManifest(payload) {
    return this.delegarParaServico(this.obterServicoRomaneios, "createManifest", payload);
  }
  updateManifest(payload) {
    return this.delegarParaServico(this.obterServicoRomaneios, "updateManifest", payload);
  }
  prepareLoading(payload) {
    return this.servicoCarregamentoAtivo.prepareLoading(payload);
  }
  reassignLoading(loadingId, equipmentId) {
    return this.servicoCarregamentoAtivo.reassignLoading(loadingId, equipmentId);
  }
  importPdf(formData) {
    return this.delegarParaServico(this.obterServicoRomaneios, "importPdf", formData);
  }
  loadEquipment(id) {
    return this.servicoDalas.loadOne(id);
  }
  checkEquipmentStatus(id) {
    return this.servicoDalas.checkStatus(id);
  }
  async loadDalaStatuses() {
    return this.obterServicoObservabilidade().loadDalaStatuses();
  }
  async loadDeviceStatuses() {
    return this.obterServicoObservabilidade().loadDeviceStatuses();
  }
  async loadDalaCommandHistory(id, page = null) {
    return this.obterServicoObservabilidade().loadDalaCommandHistory(id, page);
  }
  loadDalaActionConfig(id) {
    return this.delegarParaServico(this.obterServicoAcoesDala, "loadConfig", id);
  }
  saveDalaAction(id, payload) {
    return this.delegarParaServico(this.obterServicoAcoesDala, "saveAction", id, payload);
  }
  reorderDalaActions(id, ids) {
    return this.delegarParaServico(this.obterServicoAcoesDala, "reorderActions", id, ids);
  }
  deleteDalaAction(equipmentId, actionId) {
    return this.delegarParaServico(
      this.obterServicoAcoesDala,
      "deleteAction",
      equipmentId,
      actionId,
    );
  }
  saveDalaTrigger(equipmentId, payload) {
    return this.delegarParaServico(
      this.obterServicoAcoesDala,
      "saveTrigger",
      equipmentId,
      payload,
    );
  }
  async loadErrorLogs(page = null) {
    return this.obterServicoObservabilidade().loadErrorLogs(page);
  }
  async persistFrontendLog(record) {
    return this.obterServicoObservabilidade().persistFrontendLog(record);
  }
  async loadTechnicalDiagnostics() {
    return this.obterServicoObservabilidade().loadTechnicalDiagnostics();
  }
  async loadDeadLetters() {
    return this.obterServicoObservabilidade().loadDeadLetters();
  }
  async updateDeadLetter(id, action, resolutionNote) {
    return this.obterServicoObservabilidade().updateDeadLetter(id, action, resolutionNote);
  }
  updateEquipment(data) {
    return this.servicoDalas.update(data);
  }
  deleteEquipment(id) {
    return this.servicoDalas.delete(id);
  }
  updateProduct(data) {
    return this.delegarParaServico(this.obterServicoCatalogoProdutos, "update", data);
  }
  deleteProduct(id) {
    return this.delegarParaServico(this.obterServicoCatalogoProdutos, "delete", id);
  }
  async loadActiveLoading(selectedId = this.state.selectedLoadingId) {
    return this.servicoCarregamentoAtivo.load(
      selectedId,
      () => this._loadActiveLoading(selectedId),
    );
  }
  async _loadActiveLoading(selectedId = this.state.selectedLoadingId) {
    return this.servicoCarregamentoAtivo._load(selectedId);
  }
  applyActiveLoadingSnapshot(
    loadings,
    selectedId = this.state.selectedLoadingId,
    isCurrent = () => true,
  ) {
    return this.servicoCarregamentoAtivo.applyQueuedSnapshot(loadings, selectedId, isCurrent);
  }
  _applyActiveLoadingSnapshot(loadings, selectedId = this.state.selectedLoadingId) {
    return this._applyActiveLoadingList(loadings, selectedId);
  }
  _applyActiveLoadingList(loadings, selectedId = this.state.selectedLoadingId) {
    return this.servicoCarregamentoAtivo._applyList(loadings, selectedId);
  }
  clpDaDalaAtual() {
    const devices = this.state.deviceStatusesUpdatedAt
      ? this.state.deviceStatuses || []
      : this.state.monitoring?.dispositivos || [];
    return devices.find(
      (device) =>
        device.device_type === "CLP" &&
        Number(device.equipment_id) === Number(this.state.equipmentId),
    );
  }
  monitoramentoOperacionalAtual() {
    const updatedAt = Date.parse(
      this.state.deviceStatusesUpdatedAt || this.state.monitoringUpdatedAt || "",
    );
    if (!Number.isFinite(updatedAt)) return false;
    const ageMs = Date.now() - updatedAt;
    return ageMs >= -1000 && ageMs <= LIMITE_IDADE_MONITORAMENTO_OPERACIONAL_MS;
  }
  clpDisponivel() {
    if (!this.monitoramentoOperacionalAtual()) return false;
    const status = String(this.clpDaDalaAtual()?.status || "").toUpperCase();
    return status === "ONLINE";
  }
  comandosMaquinaDisponiveis() {
    if (!this.clpDisponivel()) return false;
    return this.clpDaDalaAtual()?.machine_commands_available === true;
  }
  mensagemComandosMaquinaIndisponiveis() {
    if (!this.monitoramentoOperacionalAtual()) {
      return "Sem confirmação recente do gateway. Os comandos da máquina estão bloqueados.";
    }
    const device = this.clpDaDalaAtual();
    if (String(device?.status || "").toUpperCase() !== "ONLINE") {
      return this.mensagemClpIndisponivel();
    }
    const mode = String(device?.machine_command_mode || "").toUpperCase();
    if (mode === "BLOQUEADO") {
      return "O gateway está conectado, mas a escrita física não foi habilitada para esta Dala.";
    }
    return "O gateway ainda não confirmou que os comandos da máquina estão liberados.";
  }
  mensagemClpIndisponivel() {
    if (!this.monitoramentoOperacionalAtual()) {
      return "Sem comunicação recente com o servidor local. Novos comandos estão bloqueados até o status ser atualizado.";
    }
    const device = this.clpDaDalaAtual();
    const status = String(device?.status || "").toUpperCase();
    const rawSeconds = device?.segundos_sem_sinal;
    const seconds = rawSeconds === null || rawSeconds === undefined || rawSeconds === ""
      ? Number.NaN
      : Number(rawSeconds);
    const time = Number.isFinite(seconds) && seconds >= 0
      ? ` há ${seconds === 0 ? "menos de 1 segundo" : `${seconds} segundos`}`
      : "";
    if (!device || status === "NAO_REGISTRADO") {
      return "CLP sem status registrado. Novos comandos estão bloqueados até chegar um heartbeat válido.";
    }
    if (status === "ERRO") {
      return "O gateway reportou erro na comunicação com o CLP. Novos comandos estão bloqueados.";
    }
    if (status === "DESCONHECIDO") {
      return "O status do CLP está desconhecido. Novos comandos estão bloqueados até chegar um heartbeat válido.";
    }
    return `CLP sem comunicação${time}. Novos comandos estão bloqueados; reconectando a cada 2 segundos.`;
  }
  async requestMachineCommand(loadingId, command) {
    return this.servicoComandosMaquina.solicitar(loadingId, command);
  }
  async requestMachineOperation(command, loadingId = this.state.loadingId) {
    if (!["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"].includes(command))
      throw new Error("Comando operacional inválido.");
    return this.requestMachineCommand(loadingId, command);
  }
  async requestMachineReverse(loadingId, command = "REVERSAO_ATIVAR") {
    if (!["REVERSAO_ATIVAR", "REVERSAO_DESATIVAR"].includes(command))
      throw new Error("Comando de reversão inválido.");
    return this.requestMachineCommand(loadingId, command);
  }
  async requestMachineEmergency(loadingId = this.state.loadingId) {
    const result = await this.requestMachineCommand(loadingId, "EMERGENCIA");
    if (Number(this.state.loadingId) !== Number(loadingId)) return result;
    this.state.operationalState = "EMERGENCIA";
    this.state.emergency = true;
    this.state.running = false;
    return result;
  }
  async loadPlcCommandStatus(loadingId = this.state.loadingId) {
    return this.servicoComandosMaquina.loadStatus(
      loadingId,
      () => this._loadPlcCommandStatus(loadingId),
    );
  }
  async _loadPlcCommandStatus(loadingId = this.state.loadingId) {
    return this.servicoComandosMaquina._loadStatus(loadingId);
  }
  unlockMachine() {
    return this.servicoCarregamentoAtivo.unlockMachine();
  }
  finishLoading(justification = "") {
    return this.servicoCarregamentoAtivo.finishLoading(
      this.state.loadingId,
      justification,
    );
  }
  async loadPendingReadings(page = this.state.pendingReadingsPage) {
    return this.servicoLeiturasOperacionais.loadPendingReadings(page);
  }
  async registerManualReading(barcode) {
    return this.servicoLeiturasOperacionais.registerManualReading(barcode);
  }
  async confirmStopRemoval(loadingId = this.state.loadingId) {
    return this.servicoLeiturasOperacionais.confirmStopRemoval(loadingId);
  }
  async identifyReading(readingId, barcode) {
    return this.servicoLeiturasOperacionais.identifyReading(readingId, barcode);
  }
  async cancelManifest(manifestId, justification = "") {
    const reason = String(justification).trim();
    const response = await this.api.fetch("/api/romaneios.php", {
      method: "PATCH",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        action: "cancel",
        romaneio_id: manifestId,
        justification: reason,
      }),
    });
    const result = await this.jsonResponse(response, "Não foi possível cancelar o romaneio.");
    return result.data;
  }
  async registerReturn(readingId, reason) {
    return this.servicoLeiturasOperacionais.registerReturn(readingId, reason);
  }
  async loadMonitoring() {
    const result = await this.sincronizacao.monitoramento();
    this.state.monitoring = result.data;
    this.state.monitoringUpdatedAt = result.updatedAt;
  }
  loadAlertMonitoring() {
    return this.obterServicoObservabilidade().loadAlertMonitoring();
  }
  loadSummaryMonitoring() {
    return this.obterServicoObservabilidade().loadSummaryMonitoring();
  }
  async loadOccurrences() {
    const response = await this.api.fetch("/api/ocorrencias.php?limit=10");
    const result = await this.jsonResponse(response, "Não foi possível carregar as ocorrências.");
    this.state.occurrences = Array.isArray(result.data) ? result.data : [];
    return this.state.occurrences;
  }
  async loadSyncStatus() {
    this.state.syncStatus = await this.sincronizacao.statusFila();
  }
  async retrySync(id) {
    const result = await this.sincronizacao.reprocessarEvento(id, this.jsonHeaders());
    await this.loadSyncStatus();
    return result;
  }
  loadProducts(options) {
    return this.delegarParaServico(this.obterServicoCatalogoProdutos, "load", options);
  }
  async loadConfiguration() {
    const response = await this.api.fetch("/api/configuracoes.php");
    const result = await this.jsonResponse(response, "Não foi possível carregar a configuração.");
    this.state.configuration = result.data;
  }
  loadEquipments(options) {
    return this.servicoDalas.loadAll(options);
  }
  loadCompanies() {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "loadCompanies");
  }
  loadUsers() {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "loadUsers");
  }
  createUser(payload) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "createUser", payload);
  }
  updateUser(payload) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "updateUser", payload);
  }
  createCompany(payload) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "createCompany", payload);
  }
  generateCompanyActivation(companyId) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "generateCompanyActivation", companyId);
  }
  renameCompany(id, name) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "renameCompany", id, name);
  }
  setCompanyArchiveState(id, archived) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "setCompanyArchiveState", id, archived);
  }
  loadLocalActivation() {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "loadLocalActivation");
  }
  activateLocalInstallation(payload) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "activateLocalInstallation", payload);
  }
  deleteCompany(id, options = {}) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "deleteCompany", id, options);
  }
  updateLicense(payload) {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "updateLicense", payload);
  }
  selectCompany(id) {
    this.state.selectedCompanyId = Number(id);
  }
  loadCompanyDetail() {
    return this.delegarParaServico(this.obterServicoAdministracaoContas, "loadCompanyDetail");
  }
  loadIndustrialInstallations() {
    return this.delegarParaServico(this.obterServicoInstalacoesIndustriais, "loadIndustrialInstallations");
  }
  createIndustrialInstallation(name) {
    return this.delegarParaServico(this.obterServicoInstalacoesIndustriais, "createIndustrialInstallation", name);
  }
  assignIndustrialEquipment(installationId, equipmentId) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "assignIndustrialEquipment",
      installationId,
      equipmentId,
    );
  }
  revokeIndustrialAccess(installationId) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "revokeIndustrialAccess",
      installationId,
    );
  }
  setIndustrialAccessBlocked(installationId, blocked) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "setIndustrialAccessBlocked",
      installationId,
      blocked,
    );
  }
  setIndustrialInstallationArchived(installationId, archived) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "setIndustrialInstallationArchived",
      installationId,
      archived,
    );
  }
  deleteArchivedIndustrialInstallation(installationId) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "deleteArchivedIndustrialInstallation",
      installationId,
    );
  }
  generateIndustrialActivationCode(installationId) {
    return this.delegarParaServico(
      this.obterServicoInstalacoesIndustriais,
      "generateIndustrialActivationCode",
      installationId,
    );
  }
  async saveConfiguration(data) {
    const response = await this.api.fetch("/api/configuracoes.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify(data),
    });
    const result = await this.jsonResponse(response, "Não foi possível salvar a configuração.");
    await this.loadConfiguration();
  }
  createEquipment(data) {
    return this.servicoDalas.create(data);
  }
  async createOccurrence(data) {
    const response = await this.api.fetch("/api/ocorrencias.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(data),
    });
    await this.jsonResponse(response, "Não foi possível salvar a ocorrência.");
    await this.loadOccurrences();
  }
  toggleRun() {
    this.state.running = !this.state.running;
    if (this.state.running)
      this.state.loaded = Math.min(this.state.planned, this.state.loaded + 1);
  }
  stop() {
    this.state.running = false;
  }
  toggleReturn() {
    this.state.returnMode = !this.state.returnMode;
  }
  createProduct(data) {
    return this.delegarParaServico(this.obterServicoCatalogoProdutos, "create", data);
  }
  activateEmergency() {
    this.state.emergency = true;
    this.state.operationalState = "EMERGENCIA";
    this.state.running = false;
  }
}
