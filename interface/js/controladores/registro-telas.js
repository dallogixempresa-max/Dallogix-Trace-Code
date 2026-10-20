/** Carrega a implementação de cada tela somente quando ela será exibida. */
export function criarRegistroTelas(carregadores) {
  const renderizadores = new Map();
  const carregamentos = new Map();
  const telas = Object.fromEntries(
    Object.keys(carregadores).map((pagina) => [pagina, (store) => {
      const renderizar = renderizadores.get(pagina);
      if (!renderizar) {
        throw new Error(`A tela "${pagina}" ainda não foi carregada.`);
      }
      return renderizar(store);
    }]),
  );

  async function carregar(pagina) {
    const carregador = carregadores[pagina];
    if (typeof carregador !== "function") {
      throw new Error(`Não existe tela registrada para "${pagina}".`);
    }
    if (renderizadores.has(pagina)) return;

    let carregamento = carregamentos.get(pagina);
    if (!carregamento) {
      carregamento = Promise.resolve()
        .then(carregador)
        .then((renderizar) => {
          if (typeof renderizar !== "function") {
            throw new TypeError(`O módulo da tela "${pagina}" não exporta um renderizador.`);
          }
          renderizadores.set(pagina, renderizar);
        })
        .finally(() => carregamentos.delete(pagina));
      carregamentos.set(pagina, carregamento);
    }
    await carregamento;
  }

  return {
    telas,
    existe: (pagina) => typeof carregadores[pagina] === "function",
    carregar,
  };
}
