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
O vínculo agora exige carregamento AGUARDANDO sem equipamento e passa para
PREPARANDO, preservando o started_at anterior. Banco, auditoria e resposta
passam a informar o mesmo estado. Vincular não envia comando ao CLP.

`testes/contrato-operacoes.mjs` cobre dez casos: caminhos dos controladores,
estado do vínculo aceito pelo frontend e serialização real do validador PHP
consumida pela regra de finalização JavaScript existente. A suíte completa
passou em 137 testes Node; os testes PHP da fila, consulta, classificação,
retenção e histórico também passaram. Nenhum arquivo em interface foi alterado.

O Docker local estava indisponível nesta revisão. Não foram executadas chamadas
HTTP autenticadas contra MySQL real nem comandos no equipamento. Os testes de
contrato não substituem essas validações.
