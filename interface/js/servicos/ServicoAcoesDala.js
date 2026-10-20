export class ServicoAcoesDala {
  constructor({ api, state, jsonHeaders, jsonResponse }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
  }

  async loadConfig(equipmentId) {
    const response = await this.api.fetch(this.url(equipmentId));
    const result = await this.jsonResponse(
      response,
      "Não foi possível carregar as ações da Dala.",
    );
    this.state.dalaActionConfig = result.data || {
      acoes: [],
      gatilhos: [],
      can_manage: false,
    };
    return this.state.dalaActionConfig;
  }

  async saveAction(equipmentId, payload) {
    const method = payload.id ? "PATCH" : "POST";
    return this.updateConfig(equipmentId, {
      method,
      body: payload,
      fallback: "Não foi possível salvar a ação.",
    });
  }

  async reorderActions(equipmentId, ids) {
    return this.updateConfig(equipmentId, {
      method: "PATCH",
      body: { action: "reorder", ids },
      fallback: "Não foi possível ordenar as ações.",
    });
  }

  async deleteAction(equipmentId, actionId) {
    return this.updateConfig(equipmentId, {
      method: "DELETE",
      actionId,
      fallback: "Não foi possível excluir a ação.",
    });
  }

  async saveTrigger(equipmentId, payload) {
    return this.updateConfig(equipmentId, {
      method: "PATCH",
      body: { action: "trigger", ...payload },
      fallback: "Não foi possível salvar o gatilho.",
    });
  }

  async updateConfig(equipmentId, { method, body = undefined, actionId = null, fallback }) {
    const url = this.url(equipmentId, actionId);
    const options = {
      method,
      headers: this.jsonHeaders(),
    };
    if (method !== "DELETE") options.body = JSON.stringify(body);

    const response = await this.api.fetch(url, options);
    await this.jsonResponse(response, fallback);
    return this.loadConfig(equipmentId);
  }

  url(equipmentId, actionId = null) {
    const params = new URLSearchParams({ equipment_id: String(equipmentId) });
    if (actionId !== null && actionId !== undefined) {
      params.set("id", String(actionId));
    }
    return `/api/acoes_dala.php?${params.toString()}`;
  }
}
