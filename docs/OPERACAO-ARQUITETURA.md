# Operações: regras e histórico das revisões

**Regra vigente:** ver a seção de conclusão por meta no final deste documento.
Os registros abaixo descrevem as etapas anteriores.

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

## Regra vigente: concluir na meta, relatório posterior — 16/11/2026

O usuário definiu explicitamente: ao atingir a quantidade prevista de passagens,
solicitar parada da esteira e liberar o botão de finalizar. Relatório é processo
posterior. Essa orientação substitui as exigências anteriores de fotos e filas
vazias para a conclusão operacional.

- Permanece a exigência de PAUSADO, confirmação do comando de parada e ausência
  de comandos em voo. Quantidade abaixo/acima da prevista, excesso conhecido de
  produto e emergência continuam bloqueando o fechamento.
- Não se aguarda fila do sensor, resultado do leitor, imagens pendentes ou fotos
  com falha para finalizar operacionalmente. Os indicadores continuam reais;
  nenhum contador de pendência é zerado para liberar o botão.
- O botão Finalizar permanece o acionador. Foi retirado o disparo automático
  que podia finalizar sem o clique do operador. A parada na meta continua no
  fluxo original do sensor/CLP, sem alteração de endereços ou Ladder.
- O endpoint encerra o romaneio ao concluir o último carregamento com quantidades
  compatíveis. Não gera PDF nessa transação. Auditoria e resposta indicam
  report_processing_deferred=true. A autorização é mantida para os três perfis
  operacionais; as divergências da conferência serão apuradas no relatório.
- A rota explícita de encerramento do romaneio também deixa de depender de fotos.
  O cancelamento conserva suas regras próprias, não foi equiparado à conclusão.
- O relatório mantém seus bloqueios de leitura/evidência pendente. Não foi alterado
  para publicar um PDF como se fotos ausentes tivessem sido recebidas.
- Worker e timeout do sensor também selecionam cargas FINALIZADAS com passagens
  já registradas ainda sem resultado, antes da próxima carga. Cargas resolvidas
  não bloqueiam a seguinte e canceladas não entram nessa seleção.
- Leituras de carga finalizada permanecem restritas a evento existente, Dala e
  empresa correspondentes. Produto incorreto descoberto depois gera registro e
  evidência, sem enviar parada para a nova operação ou reabrir a antiga.
- Mudança mínima no frontend autorizada pela nova definição: decisão do botão,
  tratamento do clique e textos relativos à conferência. CSS e layout intactos.
  Recursos tiveram apenas atualização de versão de cache.

Validação: 146 testes Node passaram. Há execução do endpoint PHP real com PDO e
transporte controlados para meta exata, abaixo/acima da meta, emergência, comando
pendente e falta de confirmação da parada. Consultas de seleção do worker e timeout
foram executadas com SQLite para pendência finalizada, próxima carga, empresa
incorreta e carga cancelada. Classificação, retenção e proteção do histórico também
passaram. Isso não é validação HTTP autenticada, de lock MySQL ou de hardware.

As correções aqui estão locais: não houve deploy ou comando enviado ao CLP.

Análise estática desta alteração: Semgrep nos sete módulos centrais apontou dois
avisos no armazenamento de imagens do worker (hash_file/unlink). A mesma análise
sobre a versão anterior do worker reproduziu os dois avisos; o diff do worker
altera somente a consulta de seleção das pendências. Não foram classificados
como defeitos introduzidos por esta mudança.

## Identidade das evidências do relatório — 16/11/2026

O processamento do relatório continua posterior ao encerramento operacional.
Esta revisão altera apenas backend e testes; não altera o botão Finalizar,
layout, CSS, comandos da esteira ou mapa do CLP.

- Antes de incorporar uma foto, conferir solicitação, empresa, Dala, carga,
  passagem, motivo e leitura vinculada quando informada. O nome do arquivo
  deve identificar a solicitação e seu SHA-256; os bytes continuam conferidos
  contra esse hash. Não há tentativa de adivinhar um vínculo divergente.
- Conferir cobertura de todas as solicitações capturadas selecionadas, sem
  duplicar a mesma solicitação. As regras existentes de retorno de excesso
  continuam aplicadas. A legenda registra evento e solicitação de evidência.
- No PC, reconstruir o relatório com os dados atuais em vez de reutilizar o
  PDF só porque a quantidade de fotos permanece igual. Preservar originais e
  recuperação de bytes do PDF anterior. Inconsistência não libera PDF parcial.
- O manifesto registra UUID da passagem, leitura, carga, motivo e hash de cada
  foto. A assinatura do conjunto usa UUID estável, motivo e hash, independentes
  dos IDs diferentes entre PC e Central. A assinatura acompanha a publicação
  autenticada e é armazenada junto ao hash do PDF no Central.
- O Central não replica as solicitações de captura nem recebe JPEGs separados.
  Quando não há solicitações locais, confia na identidade conferida no PC e
  recebida pelo upload autenticado, além dos controles existentes de quantidade
  de incidentes e hash do PDF. Não reconfere associação física de fotos no Central.
  Quando existem solicitações locais, compara também suas identidades individuais.
- PDFs antigos sem assinatura nova permanecem preservados. Para serem servidos
  pelo novo caminho de validação, precisam ser regenerados e republicados pelo
  PC; esta revisão não apaga nem migra silenciosamente os arquivos anteriores.

Validação local: 148 testes Node passaram, além dos testes PHP de classificação,
retenção/recuperação e proteção de histórico. O novo teste recusa troca de
empresa, Dala, carga, passagem, leitura, motivo, foto ausente e mudança de hash
mesmo com totais iguais. PDF sintético gerado pelo renderizador de produção,
inspecionado visualmente e com os três originais extraídos byte a byte.
Isso não homologa captura física Keyence, transporte HTTP real ou concorrência
MySQL. Nenhuma publicação ou ação sobre hardware realizada nesta revisão.

## Regra vigente: foto ausente não bloqueia relatório — 16/11/2026

Orientação posterior e explícita do usuário substitui as travas de foto citadas
nas seções anteriores: o relatório de romaneio finalizado/cancelado pode ser
emitido com os dados disponíveis. Evidência ausente não impede emissão, download
ou envio ao Central. O encerramento operacional permanece independente.

- Leituras em processamento, capturas pendentes/com erro, incidentes sem solicitação
  e arquivos ausentes constam como pendências nesta emissão. Não são convertidos
  em captura bem-sucedida e os incidentes continuam no relatório.
- Vínculos ambíguos/incompatíveis, bytes corrompidos ou hash divergente excluem
  somente a foto afetada, com registro do motivo. As demais fotos conferidas são
  incorporadas; não se tenta atribuir outra foto ao incidente.
- Os rótulos e totais de fotos refletem as imagens disponíveis e incorporadas,
  não apenas o status CAPTURADA no banco. Originais, solicitações e histórico
  são preservados. A recuperação pelo PDF anterior continua disponível.
- Nova solicitação de relatório no PC reconstrói a emissão com os dados atuais,
  permitindo incorporar uma foto tardia. Não há promessa de reprocessamento
  automático de um arquivo já baixado pelo usuário.
- Envio/recebimento permitem zero fotos e quantidade menor que a de incidentes;
  mantêm autenticação, limites do arquivo e verificação do hash do PDF. Falha
  ao enfileirar sincronização é registrada e sinalizada no cabeçalho da resposta,
  sem impedir o download do PDF íntegro já gerado.
- O gerador de PDF reserva o espaço de todas as linhas da legenda, evitando
  corte de identificação ou sobreposição com o título seguinte. Isso é backend
  do PDF; frontend, CSS, finalização, esteira e CLP não foram alterados.

Validação: 150 testes Node passaram. O teste PHP executa os trechos reais do
endpoint de processamento e renderização com ausência total/parcial de fotos,
chegada tardia, hash divergente, vínculo incompatível e recuperação do PDF
anterior. Também verifica preservação da indicação de retirada e espaço para
legenda longa. Retenção, identidade, classificação e proteção de histórico
passaram. PDF sintético com uma foto disponível e outra ausente inspecionado
visualmente. Não é homologação HTTP/MySQL ou captura física Keyence.
Alteração local; nenhuma publicação realizada nesta rodada.

## R11 e conferência da R6 — 16/11/2026

O usuário trouxe a revisão independente e reafirmou a R11: foto ausente não
reserva espaço e não produz indicação no PDF; a falha fica somente no log.
Essa orientação substitui a apresentação de pendências em PDF documentada na
seção anterior. O relatório continua disponível após finalizar/cancelar.

- Mantidos os incidentes e o histórico de retirada. Retiradas as colunas/rótulos
  de disponibilidade de imagem, os cartões de quantidade de fotos e o bloco
  de pendências de evidência no PDF. Só fotos válidas ocupam espaço na seção
  de evidências. Falhas são registradas em relatorio_pendencia_evidencia.
- A recuperação de originais, comparação de hash/vínculo e reemissão com foto
  tardia continuam funcionando. O manifesto mantém as identidades e quantidades
  reais das fotos; não inclui o texto dos avisos de foto ausente.
- Removido um texto residual incorreto no PDF que ainda dizia aguardar todas
  as fotos. Pendência de leitura pode ser informada como dado operacional;
  não é aviso de foto nem bloqueio da emissão.
- R6 na finalização por meta: o código vigente já exclui COTA_ROMANEIO_ATINGIDA
  do work-stop-dialog. Com PAUSADO, meta exata e PAUSAR_CARREGAMENTO/APLICADO,
  Finalizar é liberado sem checkbox ou confirmação manual da parada. Há teste
  de renderização dessa condição e teste do endpoint sem confirmação manual.
  APLICADO é retorno do comando do gateway, não sensor físico de
  movimento. Comando ainda PENDENTE/PROCESSANDO continua bloqueando conclusão.
  O diálogo de outras ocorrências não foi retirado nesta correção.
- Contra d3cd3c24, a branch acumula 28 arquivos de interface alterados: oito
  JavaScript e vinte HTML de versão de cache. Os commits c268d074 e deb93a23
  individualmente não alteraram interface. A frase anterior “frontend intacto”
  tinha escopo da rodada, não de toda a branch; não deve ser usada para o diff
  acumulado. Esta correção também não modifica arquivos de interface.

A revisão independente ficou limitada por ausência de PHP na VM dela. Isso
não representa execução das asserções PHP naquele ambiente. Neste Mac, PHP
8.5.9 está disponível e os testes são executados, sem usar skip para falta de PHP.

## Contador de passagens M2052 no CLP — 23/11/2026

O Node-RED consultava o M2052 a cada 200 ms (50 ms desde a v1.0.281). Um pulso
mais curto que esse intervalo pode ficar entre duas consultas e a passagem se perde: a tela mostra
mais códigos do leitor do que sacas detectadas e acusa “Leitura sem passagem
M2052 correspondente”. Na simulação do fluxo, pulsos de 60, 100 e 150 ms
registraram 47, 90 e 144 de 200 passagens; a partir de 200 ms, todas.

Para não depender da duração do pulso, o gateway aceita um contador no CLP:

1. No Ladder, a cada borda de subida do M2052, somar 1 em um registrador D
   (16 bits, sem zerar a cada romaneio). Exemplo: `LDP M2052` → `INC D100`.
2. No `.env` do PC industrial, informar o endereço Modbus desse registrador
   (holding register, FC3): `TRACE_MODBUS_SENSOR_COUNTER_REGISTER=100`.
   O instalador preserva essa linha nas atualizações.
3. Reiniciar o Trace. Sem a variável, o gateway continua lendo o bit M2052.

Cada incremento vira uma passagem, mesmo que uma ou mais consultas falhem: a
próxima resposta informa quantas sacas passaram. A virada 65535 → 0 é tratada.
Um salto maior que `TRACE_SENSOR_COUNTER_MAX_JUMP` (padrão 20) é tratado como
contador zerado: o gateway ressincroniza sem criar passagens e informa
`counter_reset_detected` no estado do sensor. Passagens do contador não passam
pelo debounce de 400 ms do servidor; a duplicidade continua bloqueada pelo
`event_uuid`. Sacas que passam sem carregamento ativo atualizam a referência
sem gerar passagem.

Validação pendente: endereço real do registrador e teste com sacas na Dala.
