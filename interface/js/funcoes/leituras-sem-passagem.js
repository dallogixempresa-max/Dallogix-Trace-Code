// O leitor Keyence dispara com o M2052. Uma leitura que chega sem passagem
// correspondente (o servidor responde 409 depois da espera) é uma saca que o
// gateway não registrou: o pulso do M2052 caiu entre duas consultas ou a
// passagem não chegou ao servidor. Ela não pode ser descartada em silêncio:
// entra no contador "Leituras sem passagem" da tela, sem aviso de erro.

export const MENSAGEM_AGUARDANDO_PASSAGEM = "Leitura aguardando o evento de passagem do sensor M2052.";

export function aguardandoPassagemM2052(error) {
  return Number(error?.status) === 409 && error?.message === MENSAGEM_AGUARDANDO_PASSAGEM;
}
