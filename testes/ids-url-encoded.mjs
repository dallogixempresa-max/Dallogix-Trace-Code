import assert from "node:assert/strict";
import test from "node:test";
import { ServicoDalas } from "../interface/js/servicos/ServicoDalas.js";
import { ServicoRomaneios } from "../interface/js/servicos/ServicoRomaneios.js";
import { ServicoCatalogoProdutos } from "../interface/js/servicos/ServicoCatalogoProdutos.js";

test("IDs usados nas rotas de detalhe e exclusão são codificados na URL", async () => {
  const id = "A&B ?#";
  const encodedId = "A%26B%20%3F%23";
  const urls = [];
  const api = {
    fetch: async (url) => {
      urls.push(url);
      return { ok: true, json: async () => ({ data: {} }) };
    },
  };
  const jsonResponse = async (response) => response.json();
  const dalas = new ServicoDalas({
    api,
    state: {},
    jsonHeaders: () => ({}),
    jsonResponse,
    fetchAllPages: async () => [],
  });
  dalas.loadAll = async () => [];
  await dalas.loadOne(id);
  await dalas.checkStatus(id);
  await dalas.delete(id);

  const romaneios = new ServicoRomaneios({
    api,
    state: {},
    jsonHeaders: () => ({}),
    jsonResponse,
    getCsrfToken: () => "",
    setManifestRows: () => {},
  });
  await romaneios.loadManifest(id);

  const produtos = new ServicoCatalogoProdutos({
    api,
    state: {},
    jsonHeaders: () => ({}),
    jsonResponse,
    fetchAllPages: async () => [],
  });
  produtos.mutate = async (url, method) => urls.push(`${method} ${url}`);
  produtos.delete(id);

  assert.deepEqual(urls, [
    `/api/equipamentos.php?id=${encodedId}`,
    `/api/equipamentos.php?id=${encodedId}&check=status`,
    `/api/equipamentos.php?id=${encodedId}`,
    `/api/romaneios.php?id=${encodedId}`,
    `DELETE /api/produtos.php?id=${encodedId}`,
  ]);
});
