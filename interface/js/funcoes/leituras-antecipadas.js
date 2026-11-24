// O leitor pode capturar o código de uma saca antes de ela chegar ao sensor
// M2052 (por exemplo, enquanto o operador a posiciona na esteira) e capturá-lo
// de novo logo depois da passagem. A primeira captura não tem passagem para
// associar e o servidor responde 409. Ela não é erro do operador: fica
// pendente em silêncio e é descartada quando a mesma saca é registrada. Só a
// captura que ninguém confirmar dentro da janela conta como leitura sem passagem.
//
// Janela medida em produção (carregamentos 129-131 e 136): a captura
// antecipada veio 0,35 a 2,6 s antes da passagem e a leitura da própria saca
// 0,4 a 0,9 s depois dela, ou seja, 0,75 a 3,5 s entre as duas capturas. Uma
// janela maior esconderia uma saca que o sensor não viu seguida de outra saca
// do mesmo produto.

export const MENSAGEM_AGUARDANDO_PASSAGEM = "Leitura aguardando o evento de passagem do sensor M2052.";
export const JANELA_LEITURA_ANTECIPADA_MS = 3500;

export function aguardandoPassagemM2052(error) {
  return Number(error?.status) === 409 && error?.message === MENSAGEM_AGUARDANDO_PASSAGEM;
}

export function criarLeiturasAntecipadas({ janelaMs = JANELA_LEITURA_ANTECIPADA_MS } = {}) {
  let pendentes = [];
  return {
    registrar(loadingId, barcode, capturadaEmMs) {
      pendentes.push({ loadingId: Number(loadingId), barcode: String(barcode), capturadaEmMs: Number(capturadaEmMs) });
    },
    // Uma leitura registrada no servidor confirma uma única captura antecipada
    // do mesmo código, a mais recente dentro da janela: era a mesma saca.
    confirmar(loadingId, barcode, capturadaEmMs) {
      let indice = -1;
      pendentes.forEach((pendente, posicao) => {
        const intervalo = Number(capturadaEmMs) - pendente.capturadaEmMs;
        if (pendente.loadingId === Number(loadingId) && pendente.barcode === String(barcode)
          && intervalo >= 0 && intervalo <= janelaMs) {
          indice = posicao;
        }
      });
      if (indice < 0) return 0;
      pendentes.splice(indice, 1);
      return 1;
    },
    // Capturas sem confirmação depois da janela: nenhuma saca com esse código
    // foi registrada logo em seguida.
    expirar(agoraMs) {
      const vencidas = pendentes.filter((pendente) => Number(agoraMs) - pendente.capturadaEmMs > janelaMs);
      pendentes = pendentes.filter((pendente) => Number(agoraMs) - pendente.capturadaEmMs <= janelaMs);
      return vencidas;
    },
    descartarOutrasCargas(loadingId) {
      pendentes = pendentes.filter((pendente) => pendente.loadingId === Number(loadingId));
    },
    quantidade() {
      return pendentes.length;
    },
  };
}
