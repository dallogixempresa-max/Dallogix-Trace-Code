import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import test from "node:test";

const screensRoot = new URL("../interface/telas/", import.meta.url);
const serviceWorker = await readFile(new URL("../interface/service-worker.js", import.meta.url), "utf8");
const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const assetVersion = serviceWorker.match(/const APPLICATION_ASSET_VERSION = "([0-9]+)";/)?.[1];
const cacheName = serviceWorker.match(/const CACHE_NAME = "([^"]+)";/)?.[1];

async function htmlFiles(directoryUrl) {
  const entries = await readdir(directoryUrl, { withFileTypes: true });
  const nested = await Promise.all(entries.map(async (entry) => {
    const path = new URL(`${entry.name}${entry.isDirectory() ? "/" : ""}`, directoryUrl);
    if (entry.isDirectory()) return htmlFiles(path);
    return entry.isFile() && entry.name.endsWith(".html") ? [path] : [];
  }));
  return nested.flat();
}

test("service worker e páginas usam a mesma versão do aplicativo", async () => {
  assert.ok(assetVersion, "versão dos recursos deve estar configurada");
  assert.ok(cacheName?.includes(assetVersion.slice(0, 8)), "nome do cache deve acompanhar a versão dos recursos");
  assert.match(application, new RegExp(`serviceWorkerUrl: "/service-worker\\.js\\?v=${assetVersion}"`));
  assert.match(application, new RegExp(`captura-leitor-hid\\.js\\?v=${assetVersion}`));

  const pages = await htmlFiles(screensRoot);
  const applicationPages = [];
  for (const page of pages) {
    const source = await readFile(page, "utf8");
    if (!source.includes("/js/aplicacao.js")) continue;
    applicationPages.push(page);
    const versions = [...source.matchAll(/\/js\/aplicacao\.js\?v=([0-9]+)/g)].map((match) => match[1]);
    assert.deepEqual(versions, [assetVersion], `${page.pathname} deve carregar a versão atual do aplicativo`);
  }
  assert.ok(applicationPages.length > 0, "as telas devem carregar o aplicativo compartilhado");
});
