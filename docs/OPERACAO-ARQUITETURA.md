# Tela Operações: responsabilidades e validação

Base: `origin/main` d3cd3c24 (v1.0.261). Branch de trabalho:
`fix/operacao-regras-negocio-20261115`.

## Fluxo e dependências

| Responsabilidade | Código |
|---|---|
| Montagem da tela e compatibilidade dos exports existentes | `interface/telas/operacao/operacao.js` |
| Seleção/vínculo de Dala | `interface/telas/operacao/selecao-dala.js`, `controladores/operacao.js`, `formularios-operacionais.js` |
| Estado derivado, motivos de bloqueio e permissões dos controles | `interface/js/funcoes/controles-operacao.js` |
| Itens e quantidades por produto | `interface/telas/operacao/itens-romaneio.js` |
| Mensagens de progresso no render inicial e ao vivo | `interface/js/funcoes/progresso-operacao.js`, `controladores/estado-visualizacao.js` |
| Navegação, fechamento e avisos operacionais | `interface/js/controladores/acoes-operacionais.js` |
| Envio, trava de clique e tratamento de erro | `interface/js/controladores/executor-comandos-operacao.js` |
| Serialização dos comandos e confirmação | `controladores/comandos-maquina.js`, `servicos/ServicoComandosMaquina.js`, `ServicoOperacao.js` |
| Estado selecionado e consultas assíncronas | `classes/ArmazenamentoTrace.js`, `servicos/ServicoCarregamentoAtivo.js`, `ServicoObservabilidade.js` |
| SSE e polling | `interface/js/controladores/tempo-real.js` |
| Leitor e devoluções | `captura-leitor-hid.js`, `ServicoLeiturasOperacionais.js` |
| Comandos, transições e confirmação de parada | `servidor/api/operacoes`, `ServicoComandoClp.php`, `ServicoEstadoCarregamento.php`, `ServicoGatewayClp.php` |
| Confirmação recente da fila do sensor | `RepositorioFilaSensor.php`, `ValidadorFilaSensor.php` |
| Contagem e evidência | `sensor_eventos.php`, `leituras.php`, `ClassificadorLeitura.php`, `ServicoPausasCarregamento.php`, `ValidadorEvidenciasOperacionais.php` |
| Equipamento e entrega de imagens | `integracoes/node-red/trace-clp-bridge.flow.json`, worker Keyence |
| Recursos offline | `interface/service-worker.js` |

Os caminhos abreviados das classes PHP ficam em `servidor/src/Aplicacao`,
e os controladores e serviços JavaScript em `interface/js`.

## Contratos preservados

- Os quatro botões de esteira permanecem visíveis. FINALIZADO bloqueia partida.
- Durante início pendente ou movimento, disponibilidade dos controles mantém o contrato existente.
- Emergência durante operação ativa não depende da trava de cliques comuns.
- ACK do gateway não prova movimento físico.
- Códigos válidos, passagens físicas, sem leitura e devoluções mantêm suas contagens próprias.
- Fotos continuam obrigatórias conforme as regras de evidência vigentes.
- Encerramento continua transacional, com validação de fila, comandos, quantidades e evidências no servidor.
- A leitura da fila exige heartbeat ONLINE recente. Dados inválidos produzem pendência desconhecida, nunca zero fabricado.

## Falhas corrigidas nesta refatoração

1. Resumo atrasado de outra seleção, ou consulta mais antiga da mesma seleção,
   podia sobrescrever resumo e contagem. Um identificador de consulta e a conferência
   do carregamento selecionado impedem aplicar essas respostas.
2. Contagem ausente/null não pode zerar a contagem exibida.
3. Atualização ao vivo passa a preservar o estilo de falha de PRODUTO_INCORRETO.
4. Mensagens de progresso passam a usar a mesma função no render inicial e ao vivo.

## Validação e limites

- 1.008 combinações de estado, perfil, reversão, motivo de parada e comando:
  HTML anterior e posterior idênticos após extração dos componentes.
- Testes Node incluem concorrência de consultas e de comandos.
- Testes PHP incluem valores inválidos da fila e contrato da consulta com PDO controlado.
- Checagem de sintaxe e análise Semgrep nos módulos revisados.

A consulta PDO controlada não substitui integração com MySQL real. Não houve
homologação física, alteração do Ladder, publicação no Central ou atualização do PC.
M2050 está documentado como comando de reversão; M17 é retorno da emergência.
O mapa disponível não identifica feedback do sentido real da esteira. A regra de
contagem exclusiva no sentido normal permanece pendente dessa informação.
