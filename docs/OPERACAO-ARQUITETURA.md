# Operações: escopo vigente

A interface foi restaurada integralmente à base `d3cd3c24` (v1.0.261),
conforme a orientação do usuário de manter o trabalho restrito ao backend.
A extração dos componentes, as alterações de mensagens e os ajustes de cache
realizados nos commits locais anteriores foram desfeitos. Os commits anteriores
continuam no histórico para rastreabilidade.

## Alterações mantidas no backend

- `ValidadorFilaSensor.php`: interpretação única da contagem da fila local,
  recusando valores ausentes, negativos, fracionários ou malformados.
- `RepositorioFilaSensor.php`: consulta compartilhada que exige SENSOR ONLINE
  e heartbeat dos últimos cinco segundos; o encerramento utiliza FOR UPDATE.
- `resumo_carregamento.php` e `encerrar_carregamento.php`: usam a mesma consulta
  e a mesma validação para evitar divergência de prontidão.
- Testes PHP da fila e do contrato PDO; verificações de integração por inspeção
  de código em `scanner-display.mjs` seguem as classes extraídas do backend.

## Limites

A consulta PDO controlada não substitui integração com MySQL real. Não houve
homologação física ou publicação destas alterações locais. A contagem no sentido
normal permanece pendente da identificação de feedback físico do sentido no CLP.

## Compatibilidade com o frontend finalizado — 16/11/2026

Foram conferidos os serviços existentes de comandos, carregamento ativo,
monitoramento, leituras, devoluções e finalização, seus caminhos públicos e
os formatos de resposta usados pela tela.

Foi corrigido o vínculo de Dala no PATCH de `carregamentos.php`: a operação
ficava em AGUARDANDO, enquanto o comando de início aceita PREPARANDO/PAUSADO.
A política vigente de vínculo está detalhada na revisão independente abaixo;
o started_at anterior é preservado. Banco, auditoria e resposta
passam a informar o mesmo estado. Vincular não envia comando ao CLP.

`testes/contrato-operacoes.mjs` cobre dez casos: caminhos dos controladores,
estado do vínculo aceito pelo frontend e serialização real do validador PHP
consumida pela regra de finalização JavaScript existente. A suíte completa
passou em 137 testes Node; os testes PHP da fila, consulta, classificação,
retenção e histórico também passaram. Nenhum arquivo em interface foi alterado.

O Docker local estava indisponível nesta revisão. Não foram executadas chamadas
HTTP autenticadas contra MySQL real nem comandos no equipamento. Os testes de
contrato não substituem essas validações.

## Revisão independente incorporada — 16/11/2026

Referência da revisão recebida: `86d91926`. A revisão anterior considerava o
estado desse commit; as correções seguintes não estavam naquele diff.

- Cancelamento de romaneio também passa a usar `RepositorioFilaSensor` e
  `ValidadorFilaSensor`, com lock dentro da transação já existente. Assim,
  mapa `[]`, literal JSON `null`, valor inválido e dispositivo sem confirmação
  recente seguem a mesma recusa no cancelamento e no encerramento.
- A política de vínculo aceita apenas cargas sem equipamento em AGUARDANDO,
  PREPARANDO ou PAUSADO. AGUARDANDO passa para PREPARANDO; PREPARANDO e PAUSADO
  preservam seus estados. CARREGANDO, FINALIZANDO, EMERGENCIA, FINALIZADO e
  estados desconhecidos são recusados. Vincular não libera emergência nem
  inicia movimento.
- O horário original permanece preservado. Um timestamp recebido por
  sincronização pode representar início real; não há evidência suficiente
  nesta revisão para apagá-lo ou reinterpretá-lo automaticamente.
- Ao ficar PREPARANDO, a Dala tem operação ativa e sua exclusão é recusada
  pelo bloqueio existente. Um teste exercita a política de vínculo e a
  recusa no repositório de Dalas com SQLite.
- Foi removido o teste de `false` após a função que retorna apenas int/null.

Validação local: 139 testes Node e 16 casos PHP da fila passaram. A revisão
externa relatou falta de PHP no ambiente dela; isso não foi reproduzido neste
Mac, onde os testes PHP foram executados. O teste PDO controlado não comprova
lock real de MySQL e essa limitação permanece aberta. Frontend byte-idêntico
à base d3cd3c24; nenhuma publicação efetuada nesta rodada.
