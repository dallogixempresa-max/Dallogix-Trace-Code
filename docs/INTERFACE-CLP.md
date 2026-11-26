# Interface CLP ↔ Trace

Documento para a equipe que programa o CLP. Descreve **o que o Trace lê do
CLP, o que escreve nele, com que frequência e como processa cada sinal**.
Tudo aqui foi tirado do código (branch `fix/m2052-conexao-persistente`,
25/11/2026). A tabela da seção 6 diz, para cada tempo, se é fixo ou
configurável por cliente.

## 1. Caminho dos dados

```
Sensor de passagem ──► CLP ──Modbus TCP──► Node-RED (PC industrial) ──HTTP──► Servidor Trace (PC industrial) ──► Nuvem
                        ▲                         │
                        └──── comandos (coils) ◄──┘
Leitor Keyence ──► código de barras ──► navegador da tela de operação (HID) ──HTTP──► Servidor Trace
```

- O **Node-RED** é o único que conversa com o CLP. A leitura do sensor usa
  **uma conexão Modbus TCP mantida aberta** por CLP; o sinal de vida e cada
  escrita de comando abrem uma conexão própria e fecham em seguida. Ou seja, o
  CLP precisa aceitar **pelo menos 2 conexões simultâneas** (a do sensor + uma
  de comando ou sinal de vida).
- O **servidor Trace** recebe as passagens do Node-RED e as leituras do leitor,
  liga cada código a uma passagem, conta e decide paradas.
- O **leitor de código** não passa pelo CLP: o código chega pela tela de
  operação (leitor HID) e vai direto ao servidor.

## 2. O que o Trace LÊ do CLP

### 2.1 Sensor de passagem — modo pulso (é o modo em uso hoje)

| Item | Valor | Onde muda |
|---|---|---|
| Função Modbus | **FC1 – Read Coils** | automático no modo pulso |
| Endereço | **2052** (M2052) | `TRACE_MODBUS_SENSOR_REGISTER` |
| Unit ID | 1 | `TRACE_MODBUS_UNIT_ID` |
| IP / porta do CLP | cadastro da Dala no Trace | tela de Dalas |
| Frequência | **a cada 20 ms** | fixo no fluxo Node-RED |
| Conexão | **mantida aberta**; refeita sozinha se cair | — |
| Tempo máximo de resposta | 300 ms (depois disso a consulta é abandonada e a conexão é refeita) | fixo |
| O que conta como passagem | **borda de subida** (0 → 1) | `TRACE_SENSOR_SIGNAL_MODE` = `rising_edge` / `falling_edge` / `toggle` |
| Nível ativo | 1 | `TRACE_SENSOR_ACTIVE_LEVEL` (0 ou 1) |

Como funciona: a cada 20 ms o Node-RED lê o bit. Quando o valor anterior era 0
e o atual é 1, ele registra **uma passagem** com o horário da resposta.

**O que o CLP precisa garantir no modo pulso:**

1. **O bit precisa ficar em 1 por pelo menos ~40 ms.** A consulta é a cada
   20 ms e o CLP leva de 7 a 17 ms para responder. Um pulso mais curto pode
   cair entre duas consultas e **a saca não é contada**. (Foi isso que fez o
   carregamento 138 contar 42 em vez de 44, quando a consulta era a cada 200 ms.)
2. **Entre um pulso e o próximo, o bit precisa voltar a 0 por pelo menos
   ~40 ms.** Se dois pulsos ficarem colados, o Trace vê uma borda só e conta
   **uma saca no lugar de duas**.
3. Um pulso por saca. Repique do sensor vira saca a mais.

### 2.2 Sensor de passagem — modo contador (recomendado)

O modo contador elimina os riscos do modo pulso: **o CLP conta, o Trace só lê
o total**. Não importa a duração do pulso nem a velocidade da consulta.

| Item | Valor | Onde muda |
|---|---|---|
| Função Modbus | **FC3 – Read Holding Registers** | automático no modo contador |
| Endereço | registrador do contador (ex.: um D) | `TRACE_MODBUS_SENSOR_COUNTER_REGISTER` ou campo `modbus_sensor_counter_register` da Dala |
| Formato | inteiro **16 bits sem sinal**, +1 por saca | — |
| Virada 65535 → 0 | tratada (não perde nem inventa sacas) | — |
| Salto máximo por consulta | 20 | `TRACE_SENSOR_COUNTER_MAX_JUMP` |

Como funciona: a cada consulta o Trace compara com o valor anterior. Se subiu
2, registra 2 passagens, com os horários distribuídos entre as duas consultas.
Se subir **mais que 20** de uma vez, o Trace entende que o contador foi zerado
ou reprogramado: **ressincroniza sem contar sacas**.

**O que o CLP precisa garantir no modo contador:**

1. Somar 1 por saca, no mesmo evento do sensor que hoje gera o M2052.
2. Não zerar o contador durante um carregamento (zerar fora dele é seguro).
3. Para ativar: informar o número do registrador para configurarmos a variável.

### 2.3 Sinal de vida do CLP

O Node-RED reporta o CLP e o sensor como "online" ao servidor **a cada 1 s**.
Para o CLP ele lê um endereço de diagnóstico (padrão **2052, FC1**:
`TRACE_MODBUS_HEARTBEAT_REGISTER` / `TRACE_MODBUS_HEARTBEAT_FUNCTION`); quando
é o mesmo endereço do sensor, aproveita a própria leitura do sensor. Junto com
o sinal do sensor vai o tamanho da fila de passagens ainda não entregues.
Se o servidor ficar **mais de 5 s** sem esse sinal, considera o sensor sem
confirmação (isso, por exemplo, bloqueia o cancelamento normal de um romaneio).

## 3. O que o Trace ESCREVE no CLP

O Trace escreve **duas bobinas**, sempre com **FC5 – Write Single Coil**
(`0xFF00` = ligar, `0x0000` = desligar):

| Comando do Trace | Bobina | Valor | Quando é aceito |
|---|---|---|---|
| `INICIAR_CARREGAMENTO` | esteira (`TRACE_MODBUS_COIL_CONVEYOR_RUN`) | 1 | carregamento em PREPARANDO ou PAUSADO e sem parada por divergência pendente |
| `PAUSAR_CARREGAMENTO` | esteira (`TRACE_MODBUS_COIL_CONVEYOR_RUN`) | 0 | qualquer estado com a esteira em uso |
| `REVERSAO_ATIVAR` | reversão (`TRACE_MODBUS_COIL_REVERSAL`) | 1 | só com a esteira parada (PREPARANDO ou PAUSADO) |
| `REVERSAO_DESATIVAR` | reversão (`TRACE_MODBUS_COIL_REVERSAL`) | 0 | só com a esteira parada |

- **Confirmação:** o comando só é dado como aplicado quando o CLP devolve o
  **eco correto da escrita FC5**. Sem eco em **5 s**, o comando vira ERRO.
- **Emergência:** o Trace **não escreve nem lê** as bobinas de emergência
  (`TRACE_MODBUS_COIL_EMERGENCY` e `…_EMERGENCY_FEEDBACK`). Os endereços só
  precisam estar preenchidos para o Trace mostrar que a Dala aceita comandos.
  A emergência física é responsabilidade do CLP.
- **Travas de segurança:** nenhuma escrita acontece se o CLP físico estiver
  desabilitado (`TRACE_PHYSICAL_CLP_ENABLED`), se o mapa Modbus não estiver
  aprovado (`TRACE_IO_MAP_STATUS=APPROVED`), se o mapa for de outra Dala
  (`TRACE_MODBUS_MAP_EQUIPMENT_ID`) ou se as duas bobinas forem iguais.
- **Tempo até o CLP:** o Node-RED busca comandos pendentes **a cada 2 s**.
  Exceção: quando a meta do carregamento é atingida, a parada é buscada
  **na mesma hora**, sem esperar os 2 s.

**Paradas automáticas** (o servidor gera `PAUSAR_CARREGAMENTO` sozinho):
meta do carregamento atingida, **excesso** de um produto e **produto
incorreto** ou fora do romaneio. Depois de uma parada por divergência, a
esteira só volta a ligar após o operador confirmar a retirada da saca no PC.

## 4. O que o servidor faz com cada passagem

1. **Chega a passagem** (do Node-RED, com identificador único). Repetições do
   mesmo identificador são ignoradas, então o reenvio após falha de rede não
   duplica sacas.
2. **Conta como saca detectada** do carregamento ativo da Dala.
3. **Espera o código de barras** dessa saca (seção 5).
4. Resultado da saca:
   - código do romaneio dentro da quantidade → **válida**;
   - código acima da quantidade prevista → **excesso** → parada automática;
   - código de outro produto ou não cadastrado → **produto incorreto** →
     parada automática;
   - nenhum código até ~4,7 s depois da passagem → **sem leitura**
     (fica de fora dos itens válidos e pede foto de evidência).
5. Quando as **sacas detectadas chegam à quantidade programada**, o servidor
   pede a parada da esteira na mesma hora.

Números da tela do romaneio:

| Campo | De onde vem |
|---|---|
| Programado | soma das quantidades dos itens do romaneio |
| Sacas detectadas | passagens do sensor que contam para a meta |
| Itens válidos após retornos | leituras válidas menos sacas retornadas |
| Sacas retornadas | sacas devolvidas registradas pelo operador |

Por isso, **"sacas detectadas" maior que "itens válidos" sem retornos**
significa sacas que passaram pelo sensor mas não tiveram o código ligado a
elas, como no teste com sacas a 5 cm.

Estados do carregamento: `PREPARANDO` → `CARREGANDO` ⇄ `PAUSADO` →
`FINALIZANDO` → `FINALIZADO`, além de `EMERGENCIA`. Em `EMERGENCIA` nenhuma
leitura é aceita; com a esteira `PAUSADO` só é aceita a leitura de uma
passagem que já tinha sido detectada.

## 5. Como o código de barras é ligado à saca

O sensor fica **antes** do leitor. Para cada passagem, o servidor calcula
quando aquela saca deve chegar ao leitor e aceita leituras dentro de uma janela:

```
chegada prevista ao leitor = horário da passagem + distância sensor→leitor ÷ velocidade da esteira
janela de aceitação        = de (chegada prevista − 250 ms) até (chegada prevista + 1.500 ms)
```

| Valor | Hoje | Onde muda |
|---|---|---|
| Distância sensor → início da área de leitura | 0,125 m | `TRACE_SENSOR_TO_READER_DISTANCE_M` |
| **Velocidade da esteira** | **0,7 m/s** | `TRACE_BELT_SPEED_M_S` (0,05–5 m/s) |
| Tolerância antes da chegada | 250 ms | `TRACE_SCANNER_EARLY_TOLERANCE_MS` (0–2.000 ms) |
| Folga depois da chegada | 1.500 ms | `TRACE_SCANNER_NO_READ_TIMEOUT_MS` |
| Regra com duas ou mais passagens na janela | fila (`sequencial`) | `TRACE_SCANNER_LINK_MODE` (`sequencial` ou `janela`) |

Regras:

- **Uma passagem na janela** → o código é ligado a ela.
- **Nenhuma passagem na janela** → a tela espera até 3 s pela passagem; se não
  vier, a leitura fica como "leitura sem passagem" (saca fora da contagem).
- **Duas ou mais passagens na janela** → o código vai **na hora** para a
  passagem **mais antiga ainda sem leitura** (fila, `TRACE_SCANNER_LINK_MODE=sequencial`,
  padrão desde a v1.0.285), pela hora em que o sensor viu cada saca. Se alguma
  candidata chegou ao servidor mais de 1 s depois da detecção ou não tem o
  intervalo Modbus, vale a regra antiga: **recusa** com "Leitura ambígua entre
  passagens do sensor". Com `TRACE_SCANNER_LINK_MODE=janela` a recusa volta a
  valer sempre (regra até a v1.0.284).
- Passagem que não recebeu código até ~4,7 s depois vira **SEM_LEITURA**.

**Consequência para o espaçamento das sacas.** Os números abaixo valem só para
a esteira do teste (0,7 m/s, sacas de 0,60–0,70 m). Em outra esteira eles
mudam, e é por isso que esta regra precisa deixar de depender da esteira
(seção 8).

| Distância entre sacas | Resultado |
|---|---|
| 5 cm | metade ou mais das leituras recusadas (é o que aconteceu no teste: 40 válidos de 79) |
| **20 cm** | **mínimo**: sem recusas se o leitor ler todas as sacas |
| **35–45 cm** | **ideal**: sem recusas mesmo quando uma leitura falha |

Uma leitura recusada deixa a passagem aberta, e ela disputa a leitura da saca
seguinte: com sacas coladas, **um erro vira uma sequência de erros**.

## 6. Tempos e valores do software

| Tempo / valor | Hoje | Configurável? | Depende da esteira? |
|---|---|---|---|
| Consulta do sensor M2052 | 20 ms | não (fluxo Node-RED) | não |
| Tempo máximo de resposta do CLP à consulta do sensor (depois disso a conexão é refeita) | 300 ms | não | não |
| Sinal de vida do CLP e do sensor | 1 s | não | não |
| Sinal do sensor considerado recente | 5 s | não | não |
| Busca de comandos para o CLP | 2 s (imediata na meta) | não | não |
| Prazo para o eco da escrita FC5 | 5 s | não | não |
| **Velocidade da esteira** | **0,7 m/s** | `TRACE_BELT_SPEED_M_S` | **sim** |
| Distância sensor → leitor | 0,125 m | `TRACE_SENSOR_TO_READER_DISTANCE_M` | sim |
| Tolerância antes da chegada ao leitor | 250 ms | `TRACE_SCANNER_EARLY_TOLERANCE_MS` | sim |
| Folga depois da chegada ao leitor | 1.500 ms | `TRACE_SCANNER_NO_READ_TIMEOUT_MS` (500–10.000) | sim |
| Espera da tela por uma passagem atrasada | 3 s | não | não |
| Passagem sem código vira "sem leitura" | ~4,7 s (folga + chegada + 3 s) | indireto, pela folga | sim |
| Salto máximo do contador por consulta | 20 | `TRACE_SENSOR_COUNTER_MAX_JUMP` | não |

**Os tempos que dependem da esteira estão todos na ligação do código de barras
à saca (seção 5).** É ali que o software hoje não é universal (seção 8).

## 7. Checklist para a programação do CLP

- [ ] Sensor gera **um pulso por saca**, sem repique.
- [ ] Pulso em 1 por **≥ 40 ms** e intervalo em 0 entre pulsos **≥ 40 ms** —
      ou, melhor, **contador de sacas em um registrador** (seção 2.2).
- [ ] Servidor Modbus TCP do CLP aceitando **pelo menos 2 conexões
      simultâneas** e sem derrubar a conexão do sensor, que fica sempre aberta.
- [ ] Informar ao Trace: IP, porta, unit ID, endereço do sensor (ou do
      contador) e endereços das coils de comando.
- [ ] Sacas com **pelo menos 20 cm** entre si no ponto do leitor (ideal 35–45 cm).
- [ ] Informar a **velocidade real da esteira** e a **distância sensor → leitor**
      de cada cliente.

## 8. O que hoje não é universal no software

**Hoje:** o código de barras é ligado à saca **pelo horário**. O software
calcula quando cada saca chega ao leitor usando a velocidade da esteira e a
distância sensor → leitor, e aceita leituras numa janela de 1,5 s.

**Já resolvido nesta versão:**

- a velocidade da esteira (`TRACE_BELT_SPEED_M_S`) e a tolerância
  (`TRACE_SCANNER_EARLY_TOLERANCE_MS`) passaram a ser **configuração do
  cliente**: mudar a esteira não exige mais mudar o código.

**Testado e descartado:** uma "regra física" (tirar da disputa a saca cuja
seguinte já chegou ao leitor) melhorava a simulação, mas a revisão mostrou que
ela pode **trocar o código entre sacas**: a distância vai só até o início da
área de leitura, o leitor lê em qualquer ponto dela, e com a esteira parada o
tempo decorrido não diz onde a saca está. Recusar é mais seguro que trocar.

**O que continua dependendo da esteira:** como a ligação ainda é por horário,
os tempos precisam ser configurados por cliente, e com sacas coladas (5 cm) uma
parte das leituras ainda fica ambígua — o código no fundo de uma saca e o da
frente da seguinte passam pelo leitor quase juntos. Consultar o CLP mais rápido
**não** resolve isso.

**Proposta universal — ligar pela ordem, não pelo horário.** O leitor já é
disparado pelo sensor, e o Keyence manda um resultado por disparo (o código, ou
`ERROR` quando não lê). Então:

> a 1ª leitura pertence à 1ª passagem em aberto, a 2ª à 2ª, e assim por diante.

- **Sem velocidade, distância nem janela de tempo no software.** Muda a esteira,
  o software não muda.
- **Sacas coladas deixam de ser problema**, desde que o sensor separe uma saca
  da outra.
- O que passa a ser obrigação do CLP/leitor, e precisa ser garantido:
  1. **nenhuma passagem perdida** — por isso o **modo contador** (seção 2.2)
     vira obrigatório: uma passagem perdida desalinha todas as seguintes;
  2. **um resultado do leitor por disparo**, inclusive `ERROR` na falha;
  3. a leitura de uma saca chegar antes da leitura da saca seguinte.
- O software mantém só um limite de segurança genérico (ex.: leitura sem
  passagem há vários segundos é descartada), não ligado à esteira.

**v1.0.285 — fila dentro da janela.** Nos carregamentos 148 e 149 (26/11/2026)
as sacas passaram com 0,4–1,2 s de vão e o código chegou 0,9–1,5 s depois da
própria passagem: duas ou três passagens cabiam na janela e quase todas as
leituras foram recusadas como ambíguas (16 válidas de 136 no 148). A partir da
v1.0.285, nesse caso o código vai na hora para a passagem mais antiga ainda sem
leitura, no modo pulso, sem contador no CLP. A janela continua como limite: só
passagens dentro dela entram na fila, então um resultado perdido afeta no
máximo as sacas dos ~1,7 s seguintes, e não a sequência toda como na fila pura
do PR #97 (que valia por até ~6,7 s e seguia a ordem de inserção).

**Histórico importante:** essa ligação pela ordem já foi implementada uma vez
(PR #97, 23/11/2026, "passagem pendente mais antiga") e **revertida no mesmo
dia** (PR #99, "restaura vínculo HID da v1.0.263"), sem o motivo registrado.
Ela **não foi reintroduzida**. Antes de voltar com ela, é preciso saber o que
falhou naquela vez e garantir os três requisitos acima — em especial o modo
contador no CLP.
