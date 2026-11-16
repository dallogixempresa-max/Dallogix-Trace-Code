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
