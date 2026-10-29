const CACHE_NAME = "trace-shell-20261028-58";
const APPLICATION_ASSET_VERSION = "202610280004";
const PRECACHE_CONCURRENCY = 4;
const SHELL = [
  "/",
  "/service-worker.js",
  "/index.html",
  "/dashboard.html",
  "/manifests.html",
  "/manifest.html",
  "/manifest-edit.html",
  "/import.html",
  "/division.html",
  "/work.html",
  "/occurrences.html",
  "/summary.html",
  "/products.html",
  "/alerts.html",
  "/emergency.html",
  "/settings.html",
  "/dalas.html",
  "/dala.html",
  "/dala-edit.html",
  "/companies.html",
  "/company.html",
  "/master-home.html",
  "/users.html",
  "/error-logs.html",
  "/telas/acesso/acesso.html",
  "/telas/alertas/alertas.html",
  "/telas/configuracoes/configuracoes.html",
  "/telas/dalas/dalas.html",
  "/telas/divisao-carga/divisao-carga.html",
  "/telas/editar-dala/editar-dala.html",
  "/telas/editar-romaneio/editar-romaneio.html",
  "/telas/emergencia/emergencia.html",
  "/telas/empresa/empresa.html",
  "/telas/empresas/empresas.html",
  "/telas/importar-romaneio/importar-romaneio.html",
  "/telas/logs-erros/logs-erros.html",
  "/telas/ocorrencias/ocorrencias.html",
  "/telas/operacao/operacao.html",
  "/telas/painel/painel.html",
  "/telas/painel-dallogix/painel-dallogix.html",
  "/telas/produtos/produtos.html",
  "/telas/resumo-final/resumo-final.html",
  "/telas/romaneio/romaneio.html",
  "/telas/romaneios/romaneios.html",
  "/telas/usuarios/usuarios.html",
  "/telas/visualizar-dala/visualizar-dala.html",
  "/telas/acesso/acesso.js",
  "/telas/alertas/alertas.js",
  "/telas/configuracoes/configuracoes.js",
  "/telas/dalas/dalas.js",
  "/telas/divisao-carga/divisao-carga.js",
  "/telas/editar-dala/editar-dala.js",
  "/telas/editar-romaneio/editar-romaneio.js",
  "/telas/emergencia/emergencia.js",
  "/telas/empresa/empresa.js",
  "/telas/empresas/empresas.js",
  "/telas/importar-romaneio/importar-romaneio.js",
  "/telas/logs-erros/logs-erros.js",
  "/telas/ocorrencias/ocorrencias.js",
  "/telas/operacao/operacao.js",
  "/telas/painel/painel.js",
  "/telas/painel-dallogix/painel-dallogix.js",
  "/telas/produtos/produtos.js",
  "/telas/resumo-final/resumo-final.js",
  "/telas/romaneio/romaneio.js",
  "/telas/romaneios/romaneios.js",
  "/telas/usuarios/usuarios.js",
  "/telas/visualizar-dala/visualizar-dala.js",
  "/telas/acesso/acesso.css",
  "/telas/alertas/alertas.css",
  "/telas/configuracoes/configuracoes.css",
  "/telas/dalas/dalas.css",
  "/telas/divisao-carga/divisao-carga.css",
  "/telas/editar-dala/editar-dala.css",
  "/telas/editar-romaneio/editar-romaneio.css",
  "/telas/emergencia/emergencia.css",
  "/telas/empresa/empresa.css",
  "/telas/empresas/empresas.css",
  "/telas/importar-romaneio/importar-romaneio.css",
  "/telas/logs-erros/logs-erros.css",
  "/telas/ocorrencias/ocorrencias.css",
  "/telas/operacao/operacao.css",
  "/telas/painel/painel.css",
  "/telas/painel-dallogix/painel-dallogix.css",
  "/telas/produtos/produtos.css",
  "/telas/resumo-final/resumo-final.css",
  "/telas/romaneio/romaneio.css",
  "/telas/romaneios/romaneios.css",
  "/telas/usuarios/usuarios.css",
  "/telas/visualizar-dala/visualizar-dala.css",
  "/css/styles.css",
  "/css/light-theme.css",
  "/css/auth.css",
  "/css/responsive.css",
  "/css/dalas-screen.css",
  "/css/sistema-design.css",
  "/js/aplicacao.js",
  "/js/configuracaoAplicacao.js",
  "/js/api/ClienteApi.js",
  "/js/componentes/notificacoes.js",
  "/js/componentes/form-feedback.js",
  "/js/componentes/estados.js",
  "/js/servicos/ServicoEmergencia.js",
  "/js/servicos/ServicoOperacao.js",
  "/js/servicos/ServicoSincronizacao.js",
  "/js/servicos/ServicoInstalacoesIndustriais.js",
  "/js/servicos/ServicoAdministracaoContas.js",
  "/js/servicos/ServicoAtivacaoLocal.js",
  "/js/servicos/ServicoRomaneios.js",
  "/js/servicos/ServicoCatalogoProdutos.js",
  "/js/servicos/ServicoDalas.js",
  "/js/servicos/ServicoAcoesDala.js",
  "/js/servicos/ServicoObservabilidade.js",
  "/js/servicos/ServicoCarregamentoAtivo.js",
  "/js/servicos/ServicoComandosMaquina.js",
  "/js/servicos/ServicoLeiturasOperacionais.js",
  "/js/classes/ArmazenamentoTrace.js",
  "/js/classes/OfflineOperationBuffer.js",
  "/js/constantes/acoes.js",
  "/js/controladores/operacao.js",
  "/js/controladores/tempo-real.js",
  "/js/controladores/page-refresh.js",
  "/js/controladores/status-dalas-tempo-real.js",
  "/js/controladores/comandos-maquina.js",
  "/js/controladores/registro-telas.js",
  "/js/controladores/acoes-navegacao.js",
  "/js/controladores/acoes-administracao.js",
  "/js/controladores/acoes-operacionais.js",
  "/js/controladores/acoes-diagnostico.js",
  "/js/controladores/acoes-cadastros.js",
  "/js/controladores/acoes-interface.js",
  "/js/controladores/acoes-relatorios.js",
  "/js/controladores/despachante-acoes.js",
  "/js/controladores/carregador-paginas.js",
  "/js/controladores/estado-visualizacao.js",
  "/js/controladores/conectividade-local.js",
  "/js/controladores/formulario-romaneio.js",
  "/js/controladores/formulario-produto.js",
  "/js/controladores/formulario-dala.js",
  "/js/controladores/formulario-importacao-romaneio.js",
  "/js/controladores/formularios-administrativos.js",
  "/js/controladores/formularios-operacionais.js",
  "/js/controladores/formularios-telas.js",
  "/js/funcoes/formato.js",
  "/js/funcoes/estado-comando.js",
  "/js/funcoes/html.js",
  "/js/funcoes/relogio.js",
  "/js/funcoes/romaneio.js",
  "/js/funcoes/status-dala.js",
  "/js/funcoes/rotulos.js",
  "/js/funcoes/view.js",
  "/js/funcoes/dialogo.js",
  "/js/funcoes/downloads.js",
  "/js/funcoes/paginacao-api.js",
  "/js/utilitarios/Validadores.js",
  "/js/utilitarios/LogFrontend.js",
  "/js/login.js",
  "/js/redirecionar-tela.js",
  "/js/sessao.js",
];

async function cacheResponse(request, response) {
  if (!response || !response.ok || response.type === "opaque") return response;
  const cache = await caches.open(CACHE_NAME);
  await cache.put(request, response.clone());
  return response;
}

async function fetchWithTimeout(request, options = {}, timeoutMs = 8000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(new Request(request, { ...options, signal: controller.signal }));
  } finally {
    clearTimeout(timer);
  }
}

function isVersionedStaticAsset(url) {
  return /\.(?:css|js)$/.test(url.pathname) && /^[0-9]{8,}/.test(url.searchParams.get("v") || "");
}

async function precacheShell(cache) {
  let nextAssetIndex = 0;
  const workerCount = Math.min(PRECACHE_CONCURRENCY, SHELL.length);
  const worker = async () => {
    while (nextAssetIndex < SHELL.length) {
      const path = SHELL[nextAssetIndex++];
      try {
        await cache.add(new Request(path));
      } catch (error) {
        // Mantém a instalação disponível; registra o arquivo para diagnóstico offline.
        console.warn("Não foi possível pré-carregar o recurso do shell:", path, error);
      }
    }
  };

  await Promise.all(Array.from({ length: workerCount }, () => worker()));
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(precacheShell),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then(async (names) => {
      await Promise.all(
        names
          .filter((name) => name.startsWith("trace-shell-") && name !== CACHE_NAME)
          .map((name) => caches.delete(name)),
      );
      await self.clients.claim();
    }),
  );
});

self.addEventListener("message", (event) => {
  if (event.data?.type === "ATIVAR_NOVA_VERSAO") {
    event.waitUntil(self.skipWaiting());
  }
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== "GET" || url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/api/")) return;

  if (request.mode === "navigate") {
    event.respondWith(
      fetchWithTimeout(request)
        .then((response) => cacheResponse(request, response))
        .catch(async () => (await caches.match(request, { ignoreSearch: true })) || caches.match("/index.html")),
    );
    return;
  }

  if (/\.(?:css|js)$/.test(url.pathname)) {
    event.respondWith(
      (async () => {
        if (isVersionedStaticAsset(url)) {
          const cached = await caches.match(request);
          if (cached) return cached;
          if (url.searchParams.get("v") === APPLICATION_ASSET_VERSION) {
            const currentCache = await caches.open(CACHE_NAME);
            const precached = await currentCache.match(url.pathname);
            if (precached) return precached;
          }
        }
        try {
          return await cacheResponse(request, await fetchWithTimeout(request));
        } catch {
          return (await caches.match(request, { ignoreSearch: true })) || Response.error();
        }
      })(),
    );
    return;
  }

  event.respondWith(
    caches.match(request, { ignoreSearch: true }).then((cached) => {
      const refresh = fetchWithTimeout(request)
        .then((response) => cacheResponse(request, response))
        .catch(() => cached);
      return cached || refresh;
    }),
  );
});
