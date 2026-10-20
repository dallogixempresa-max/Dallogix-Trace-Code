export class ServicoAtivacaoLocal {
  constructor({ api, state, jsonHeaders, jsonResponse }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
  }

  async loadLocalActivation() {
    const response = await this.api.fetch("/api/ativacao_local.php", {
      cache: "no-store",
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível consultar a ativação local.",
    );
    this.state.localActivation = result.data || { active: false };
    return this.state.localActivation;
  }

  async activateLocalInstallation(payload) {
    const response = await this.api.fetch("/api/ativar_empresa.php", {
      method: "POST",
      headers: { ...this.jsonHeaders(), Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível ativar esta instalação.",
    );
    this.state.localActivation = result.data || { active: false };
    return this.state.localActivation;
  }
}
