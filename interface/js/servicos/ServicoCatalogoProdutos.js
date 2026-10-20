const CACHE_PRODUTOS_MS = 60_000;

export class ServicoCatalogoProdutos {
  constructor({ api, state, jsonHeaders, jsonResponse, fetchAllPages }) {
    this.api = api;
    this.state = state;
    this.jsonHeaders = jsonHeaders;
    this.jsonResponse = jsonResponse;
    this.fetchAllPages = fetchAllPages;
    this.request = null;
    this.revision = 0;
  }

  invalidate() {
    this.revision += 1;
    this.state.productsLoaded = false;
    this.state.productsLoadedAt = null;
  }

  async load({ force = false } = {}) {
    const cacheAge = Date.now() - (this.state.productsLoadedAt || 0);
    if (!force && this.state.productsLoaded && cacheAge < CACHE_PRODUTOS_MS) {
      return this.state.products;
    }
    if (this.request) return this.request;

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10_000);
    const request = (async () => {
      while (true) {
        const revision = this.revision;
        const products = await this.fetchAllPages("/api/produtos.php", {
          signal: controller.signal,
        });

        // Uma gravação concluída durante a paginação invalida a resposta antiga.
        if (revision !== this.revision) continue;

        products.sort((left, right) =>
          String(left.name).localeCompare(String(right.name), "pt-BR"),
        );
        this.state.products = products;
        this.state.productsLoaded = true;
        this.state.productsLoadedAt = Date.now();
        return this.state.products;
      }
    })()
      .catch((error) => {
        if (error.name === "AbortError") {
          throw new Error("O carregamento dos produtos demorou. Tente novamente.");
        }
        throw error;
      })
      .finally(() => {
        window.clearTimeout(timeout);
        if (this.request === request) this.request = null;
      });

    this.request = request;
    return request;
  }

  async create(data) {
    const response = await this.api.fetch("/api/produtos.php", {
      method: "POST",
      headers: this.jsonHeaders(),
      body: JSON.stringify(data),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(
        response.status === 401
          ? "Sua sessão expirou. Entre novamente para cadastrar o produto."
          : result.error || "Não foi possível cadastrar o produto.",
      );
      error.status = response.status;
      throw error;
    }

    this.invalidate();
    await this.load();
  }

  update(data) {
    return this.mutate("/api/produtos.php", "PUT", data);
  }

  delete(id) {
    return this.mutate(`/api/produtos.php?id=${id}`, "DELETE", {});
  }

  async mutate(url, method, body) {
    const response = await this.api.fetch(url, {
      method,
      headers: this.jsonHeaders(),
      body: method === "DELETE" ? undefined : JSON.stringify(body),
    });
    const result = await this.jsonResponse(response, "Falha na requisição.");
    this.invalidate();
    await this.load();
    return result.data;
  }
}
