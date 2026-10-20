export class ServicoDalas {
  constructor({ api, state, jsonHeaders, jsonResponse, fetchAllPages }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.fetchAllPages = fetchAllPages;
    this.request = null;
  }

  async loadOne(id) {
    const response = await this.api.fetch(`/api/equipamentos.php?id=${id}`);
    const result = await this.jsonResponse(response, "Dala não encontrada.");
    this.state.equipmentDetail = result.data;
  }

  async checkStatus(id) {
    const response = await this.api.fetch(`/api/equipamentos.php?id=${id}&check=status`);
    if (!response.ok) {
      return {
        status: "DESCONHECIDO",
        message: (await response.json().catch(() => ({}))).error || "Falha na verificação.",
      };
    }
    const result = await response.json();
    return result.data;
  }

  async loadAll({ force = false } = {}) {
    if (this.state.equipmentsLoaded && !force) return this.state.equipments;
    if (this.request) return this.request;

    this.state.equipmentsLoading = true;
    this.state.equipmentsError = "";
    const request = this.fetchAllPages("/api/equipamentos.php")
      .then((equipments) => {
        this.state.equipments = equipments;
        const hasIndustrialPcData = equipments.some((equipment) =>
          Object.hasOwn(equipment, "industrial_pc_name"),
        );
        this.state.equipments.sort((left, right) => {
          if (hasIndustrialPcData) {
            const pcOrder = String(left.industrial_pc_name || "").localeCompare(
              String(right.industrial_pc_name || ""),
              "pt-BR",
            );
            if (pcOrder !== 0) return pcOrder;
            const nameOrder = String(left.name || "").localeCompare(String(right.name || ""), "pt-BR");
            if (nameOrder !== 0) return nameOrder;
          }
          return String(left.equipment_code || "").localeCompare(String(right.equipment_code || ""), "pt-BR");
        });
        this.state.equipmentsLoaded = true;
        return this.state.equipments;
      })
      .catch((error) => {
        this.state.equipmentsError = error.message || "Não foi possível carregar as Dalas.";
        return this.state.equipments;
      })
      .finally(() => {
        this.state.equipmentsLoading = false;
        if (this.request === request) this.request = null;
      });

    this.request = request;
    return request;
  }

  async create(data) {
    const response = await this.api.fetch("/api/equipamentos.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(data),
    });
    const result = await this.jsonResponse(response, "Não foi possível cadastrar a Dala.");
    await this.loadAll({ force: true });
    return result.data;
  }

  async update(data) {
    const response = await this.api.fetch("/api/equipamentos.php", {
      method: "PUT",
      headers: this.jsonHeaders(),
      body: JSON.stringify(data),
    });
    await this.jsonResponse(response, "Não foi possível salvar a Dala.");
    await this.loadAll({ force: true });
  }

  async delete(id) {
    const response = await this.api.fetch(`/api/equipamentos.php?id=${id}`, {
      method: "DELETE",
      headers: this.jsonHeaders(),
    });
    await this.jsonResponse(response, "Não foi possível excluir a Dala.");
    await this.loadAll({ force: true });
  }
}
