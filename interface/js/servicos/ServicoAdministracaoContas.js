import { ServicoAtivacaoLocal } from "./ServicoAtivacaoLocal.js?v=202610190001";

export class ServicoAdministracaoContas {
  constructor({ api, state, jsonHeaders, jsonResponse, fetchAllPages, loadIndustrialInstallations }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.fetchAllPages = fetchAllPages;
    this.loadIndustrialInstallations = loadIndustrialInstallations;
    this.servicoAtivacaoLocal = new ServicoAtivacaoLocal({
      api,
      state,
      jsonHeaders,
      jsonResponse,
    });
  }

  async loadCompanies() {
    const query = this.state.userRole === "ADMIN_DALLOGIX"
      ? "?include_archived=1"
      : "";
    this.state.companies = await this.fetchAllPages(`/api/empresas.php${query}`);
    this.state.companies.sort((left, right) =>
      String(left.name).localeCompare(String(right.name), "pt-BR"),
    );
  }

  async loadUsers() {
    const companyId = this.state.selectedCompanyId;
    const query = companyId
      ? `?company_id=${encodeURIComponent(companyId)}`
      : "";

    try {
      this.state.users = await this.fetchAllPages(`/api/usuarios.php${query}`);
      this.state.users.sort((left, right) =>
        String(left.name).localeCompare(String(right.name), "pt-BR"),
      );
    } catch (error) {
      if (error.status === 422 && this.state.userRole === "ADMIN_DALLOGIX") {
        this.state.users = [];
        return;
      }
      throw error;
    }
  }

  async createUser(payload) {
    const requestPayload = { ...(payload || {}) };
    if (
      this.state.userRole === "ADMIN_DALLOGIX" &&
      !requestPayload.company_id &&
      this.state.selectedCompanyId
    ) {
      requestPayload.company_id = this.state.selectedCompanyId;
    }

    const response = await this.api.fetch("/api/usuarios.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(requestPayload),
    });
    const result = await this.jsonResponse(response, "Não foi possível criar o login.");
    return result.data;
  }

  async updateUser(payload) {
    const response = await this.api.fetch("/api/usuarios.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify(payload),
    });
    const result = await this.jsonResponse(response, "Não foi possível atualizar o login.");
    await this.loadUsers();
    return result.data;
  }

  async createCompany(payload) {
    const response = await this.api.fetch("/api/empresas.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(payload),
    });
    const result = await this.jsonResponse(response, "Não foi possível criar a empresa.");
    this.state.companyActivation = result.data;
    return result.data;
  }

  async generateCompanyActivation(companyId) {
    const response = await this.api.fetch("/api/empresas.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        action: "generate_activation_code",
        company_id: Number(companyId),
      }),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível gerar o código da empresa.",
    );
    this.state.companyActivation = result.data;
    return result.data;
  }

  async renameCompany(id, name) {
    const response = await this.api.fetch("/api/empresas.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify({ id: Number(id), name: String(name || "").trim() }),
    });
    const result = await this.jsonResponse(response, "Não foi possível renomear a empresa.");
    await this.loadCompanies();
    return result.data;
  }

  async setCompanyArchiveState(id, archived) {
    const response = await this.api.fetch("/api/empresas.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        id: Number(id),
        action: archived ? "archive" : "restore",
      }),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível atualizar o arquivamento da empresa.",
    );
    await this.loadCompanies();
    return result.data;
  }

  async loadLocalActivation() {
    return this.servicoAtivacaoLocal.loadLocalActivation();
  }

  async activateLocalInstallation(payload) {
    return this.servicoAtivacaoLocal.activateLocalInstallation(payload);
  }

  async deleteCompany(
    id,
    { force = false, permanent = false, confirmation = "", password = "" } = {},
  ) {
    const query = new URLSearchParams({ id: String(id) });
    if (force) query.set("force", "1");
    if (permanent) query.set("permanent", "1");

    const response = await this.api.fetch(`/api/empresas.php?${query.toString()}`, {
      method: "DELETE",
      headers: this.jsonHeaders(),
      body: permanent ? JSON.stringify({ confirmation, password }) : undefined,
    });
    const result = await this.jsonResponse(response, "Não foi possível remover a empresa.");
    await this.loadCompanies();
    return result.data;
  }

  async updateLicense(payload) {
    const response = await this.api.fetch("/api/licencas.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify(payload),
    });
    const result = await this.jsonResponse(response, "Não foi possível atualizar a licença.");
    await this.loadCompanies();
    this.state.companyActivation = null;
    return result.data;
  }

  selectCompany(id) {
    this.state.selectedCompanyId = Number(id);
  }

  async loadCompanyDetail() {
    if (!this.state.selectedCompanyId) {
      throw new Error("Nenhuma empresa selecionada.");
    }

    const response = await this.api.fetch(
      `/api/empresas.php?company_id=${this.state.selectedCompanyId}`,
    );
    const result = await this.jsonResponse(response, "Não foi possível carregar a empresa.");
    this.state.companyDetail = result.data;
    await this.loadIndustrialInstallations();
  }
}
