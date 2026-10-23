const TAMANHO_PAGINA = 100;
const LIMITE_PAGINAS = 100;
const LIMITE_REGISTROS = TAMANHO_PAGINA * LIMITE_PAGINAS;

export class ServicoInstalacoesIndustriais {
  constructor({ api, state, jsonHeaders, jsonResponse, validarCursorPaginacao }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.validarCursorPaginacao = validarCursorPaginacao;
  }

  async loadIndustrialInstallations() {
    if (!this.state.selectedCompanyId) {
      throw new Error("Nenhuma empresa selecionada.");
    }

    const url = new URL("/api/instalacoes_industriais.php", "http://trace.local");
    url.searchParams.set("company_id", String(this.state.selectedCompanyId));
    url.searchParams.set("include_archived", "1");

    const installations = [];
    const equipmentOptions = [];
    let cursor = 0;
    let equipmentCursor = 0;
    let hasMoreInstallations = true;
    let hasMoreEquipment = true;

    for (let pageIndex = 0; pageIndex < LIMITE_PAGINAS; pageIndex += 1) {
      url.searchParams.set("limit", String(TAMANHO_PAGINA));
      url.searchParams.set("cursor", String(cursor));
      url.searchParams.set("equipment_limit", String(TAMANHO_PAGINA));
      url.searchParams.set("equipment_cursor", String(equipmentCursor));

      const response = await this.api.fetch(`${url.pathname}${url.search}`, {
        cache: "no-store",
      });
      const result = await this.jsonResponse(
        response,
        "Não foi possível carregar os PCs industriais.",
      );
      const data = result.data || {};

      if (!Array.isArray(data.installations) || !Array.isArray(data.equipment_options)) {
        throw new Error("O servidor retornou uma lista incompleta de PCs industriais.");
      }

      if (hasMoreInstallations) installations.push(...data.installations.map((installation) => {
        const hasTimestamp = (field) => installation[field] !== null &&
          installation[field] !== undefined && installation[field] !== "";
        return {
          ...installation,
          access_blocked: typeof installation.access_blocked === "boolean"
            ? installation.access_blocked
            : hasTimestamp("access_blocked_at"),
          archived: typeof installation.archived === "boolean"
            ? installation.archived
            : hasTimestamp("archived_at"),
        };
      }));
      if (hasMoreEquipment) equipmentOptions.push(...data.equipment_options);

      const nextInstallationCursor = this.validarCursorPaginacao(
        result.pagination,
        cursor,
      );
      const nextEquipmentCursor = this.validarCursorPaginacao(
        result.equipment_pagination,
        equipmentCursor,
      );
      hasMoreInstallations = nextInstallationCursor !== null;
      hasMoreEquipment = nextEquipmentCursor !== null;

      if (!hasMoreInstallations && !hasMoreEquipment) {
        installations.sort((left, right) =>
          String(left.name).localeCompare(String(right.name), "pt-BR"),
        );
        equipmentOptions.sort((left, right) =>
          String(left.name).localeCompare(String(right.name), "pt-BR"),
        );
        this.state.industrialInstallations = installations;
        this.state.industrialEquipmentOptions = equipmentOptions;
        return { installations, equipment_options: equipmentOptions };
      }

      cursor = hasMoreInstallations
        ? nextInstallationCursor
        : Number.MAX_SAFE_INTEGER;
      equipmentCursor = hasMoreEquipment
        ? nextEquipmentCursor
        : Number.MAX_SAFE_INTEGER;
    }

    throw new Error(
      `A lista de PCs industriais ultrapassa ${LIMITE_REGISTROS.toLocaleString("pt-BR")} registros.`,
    );
  }

  async createIndustrialInstallation(name) {
    const response = await this.api.fetch("/api/instalacoes_industriais.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        action: "create",
        company_id: Number(this.state.selectedCompanyId),
        name: String(name || "").trim(),
      }),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível cadastrar o PC industrial.",
    );
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async assignIndustrialEquipment(installationId, equipmentId) {
    const response = await this.api.fetch("/api/instalacoes_industriais.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        action: "assign_equipment",
        installation_id: Number(installationId),
        equipment_id: Number(equipmentId),
      }),
    });
    const result = await this.jsonResponse(
      response,
      "Não foi possível vincular a Dala ao PC.",
    );
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async revokeIndustrialAccess(installationId) {
    const result = await this.performAction(
      "revoke_access",
      installationId,
      "Não foi possível revogar o acesso deste PC.",
    );
    this.state.industrialInstallationActivation = null;
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async setIndustrialAccessBlocked(installationId, blocked) {
    const result = await this.performAction(
      blocked ? "block_access" : "unblock_access",
      installationId,
      "Não foi possível alterar o acesso do PC industrial.",
    );
    this.state.industrialInstallationActivation = null;
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async setIndustrialInstallationArchived(installationId, archived) {
    const result = await this.performAction(
      archived ? "archive" : "restore_archive",
      installationId,
      "Não foi possível arquivar ou restaurar o PC industrial.",
    );
    this.state.industrialInstallationActivation = null;
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async deleteArchivedIndustrialInstallation(installationId) {
    const result = await this.performAction(
      "delete_archived",
      installationId,
      "Não foi possível excluir o PC industrial arquivado.",
    );
    this.state.industrialInstallationActivation = null;
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async generateIndustrialActivationCode(installationId) {
    const result = await this.performAction(
      "generate_activation_code",
      installationId,
      "Não foi possível gerar o código deste PC.",
    );
    this.state.industrialInstallationActivation = result.data;
    await this.loadIndustrialInstallations();
    return result.data;
  }

  async performAction(action, installationId, fallback) {
    const response = await this.api.fetch("/api/instalacoes_industriais.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify({
        action,
        installation_id: Number(installationId),
      }),
    });
    return this.jsonResponse(response, fallback);
  }
}
