# Integração do KEYENCE SR-X100W com o Trace

**Situação:** a rota principal de códigos é TCP: o SR-X100W, configurado como cliente de dados Ethernet, conecta em `192.168.1.20:19000` e entrega cada resultado ao `keyence-worker`. As fotos de falha continuam chegando por FTP e podem ser importadas da pasta local. O AutoID Keyboard Wedge (HID) fica documentado como contingência e não deve receber as mesmas leituras. A rota TCP já recebeu um quadro `ERROR` no PC industrial, fora de um carregamento; a homologação depende de um carregamento real com sacas.

## Fluxo implementado

O SR-X100W é disparado pela entrada IN1 e manda exatamente um resultado por disparo: o código lido ou `ERROR`. O leitor abre uma conexão TCP com o PC industrial (`192.168.1.20`, porta `19000`), que o Docker Desktop publica na porta `19000` do contêiner `keyence-worker`. O worker separa os quadros pelo terminador, pede ao Trace a passagem M2052 compatível com o horário de chegada do quadro e registra a leitura com o token `SCANNER`. A tela de operação mostra o resultado sem precisar estar em primeiro plano e sem receber teclas. O detalhamento da rota, da configuração do leitor e da verificação está em [Rota TCP do leitor ao worker](#rota-tcp-do-leitor-ao-worker).

Na contingência HID, o SR-X100W envia os dados ao AutoID Keyboard Wedge no PC industrial, que converte cada código em teclas HID; com o Trace em primeiro plano, a tela de operação captura a sequência rápida, mostra o texto no visor somente leitura e registra a leitura automaticamente. O Trace reconhece Enter quando configurado no Wedge e também envia o código após 180 ms sem novas teclas quando não há terminador. Use uma rota ou a outra, nunca as duas ao mesmo tempo (ver [Contingência: AutoID Keyboard Wedge (HID)](#contingência-autoid-keyboard-wedge-hid)).

O estado de rede do cartão do leitor vem de um ping (ICMP) que o worker envia a cada 5 segundos ao IP de `KEYENCE_TCP_ALLOWED_ADDRESS`. O cartão mostra **ONLINE** quando o último ping, com menos de 15 segundos, respondeu; duas falhas seguidas marcam **SEM RESPOSTA**. A tela não confunde o heartbeat do worker com a presença do leitor. O ping confirma só que a interface de rede do leitor responde, não que ele está focado, decodificando ou conectado à porta `19000`; o estado da conexão TCP e dos quadros recebidos aparece separadamente, a partir do objeto `tcp_route` do heartbeat.

O M2052 fica antes da área de leitura. Cada transição aceita do sensor registra a passagem e seu horário observado no gateway; o Trace calcula a chegada prevista ao leitor como `horário M2052 + distância M2052–leitor / 0,7 m/s`. O prazo de falha só vence depois dessa viagem mais a espera configurada para o leitor. Nas duas rotas, o código é associado à passagem em aberto cuja janela prevista contém o horário da leitura (na rota TCP, o horário em que o quadro chegou ao worker; na rota HID, o horário em que o Wedge o entregou), e candidatos ambíguos são recusados para não atribuir um produto ao saco errado. Na rota TCP, quem escolhe a passagem é o servidor (ação `STATUS` de `keyence_worker.php`), e o worker não usa uma passagem já reservada por outra leitura que ainda está na fila local. Um código nunca é associado a mais de uma passagem. Assim, uma falha anterior não desloca automaticamente o próximo código para a saca seguinte. O marcador `ERROR`, inclusive quando vem colado a um valor numérico de 8 a 14 dígitos, é tratado como falha e não como produto.

Quando o prazo completo vence sem código, o Trace cria `SEM_LEITURA` e uma solicitação de imagem. Um código decodificado confiável que não existe no catálogo é registrado como divergência `PRODUTO_INCORRETO`, solicita a pausa, não consome a quantidade prevista e também pede evidência. Para EAN-8, UPC-A, EAN-13 e GTIN-14 numéricos, o dígito verificador é validado; valor inválido segue como falha de leitura. As fotos podem chegar por FTP ou ser copiadas da pasta de arquivo do leitor, configurada no PC como `C:\Keyence\Falhas`. Essa pasta é montada somente para leitura dentro do worker; o Trace nunca apaga nem altera as imagens originais. Para fotos importadas, o worker usa o horário de modificação do JPEG como horário de captura aproximado e compara a captura com o horário previsto de chegada à área do leitor, e não diretamente com o horário M2052. O nome original do arquivo é preservado na auditoria do recebimento. A API valida a imagem e vincula a foto à solicitação correspondente (`SEM_LEITURA`, `PRODUTO_INCORRETO` ou `EXCESSO`), à leitura e ao evento da saca; o PDF busca essa relação no banco e identifica a carga, o número sequencial do saco e a hora da foto.

Se não houver uma passagem compatível ou houver mais de uma passagem candidata, a foto não é associada: o evento permanece no histórico, a evidência fica disponível para revisão nos logs e o relatório é gerado sem espaço reservado ou foto possivelmente errada. Cada JPEG é processado separadamente; o Trace não agrupa nem descarta quadros só porque chegaram próximos. Cópias sem vínculo continuam na caixa local e são reavaliadas a cada ciclo. O heartbeat indica erro quando há imagem sem vínculo além do prazo de aviso. Ao reiniciar, o worker recupera para nova tentativa as imagens que haviam sido colocadas em quarentena somente por falta de correspondência ou expiração. Arquivos inválidos e respostas definitivas de validação da API continuam isolados para revisão. As imagens originais de `C:\Keyence\Falhas` são preservadas.

O FTP atual não carrega um ID de evento M2052 junto com cada JPEG. Por isso, a associação automática só ocorre quando há exatamente uma solicitação de evidência compatível dentro da janela de tempo configurada; se houver zero ou várias, a foto fica preservada e não é vinculada. O horário é uma aproximação, não uma chave de identificação. Para uma associação determinística mesmo com atrasos, reenvios ou fotos de passagens muito próximas, o perfil da câmera precisa enviar uma chave estável por disparo junto da imagem; o worker não deve adivinhar essa chave.

O sinal M2052 registra cada passagem física, mas a quantidade prevista só recebe eventos classificados como item do romaneio ou falha real de leitura (`SEM_LEITURA`). Código válido sem cadastro e produto fora do romaneio são divergências: pedem pausa, ficam no relatório e não consomem a meta. `ERROR` ou timeout gera `SEM_LEITURA`, ocorrência e pedido de imagem; a passagem conta na meta, sem ser atribuída a um produto quando não há identidade segura. O código recebido só pode ser associado uma vez a um evento M2052. Quando a quantidade prevista é atingida, o Trace solicita `PAUSAR_CARREGAMENTO`; após as passagens serem classificadas e a solicitação de pausa ser registrada, o botão de finalizar fica disponível, sem esperar o retorno `APLICADO` e sem depender de fotos. O relatório é processado após a finalização e permanece disponível mesmo quando uma foto não chega. A foto só aparece se o vínculo com a passagem for inequívoco; ausências ficam nos logs e evidências preservadas, sem espaço reservado no PDF. A interface mostra contagem do sensor e códigos lidos separadamente. `APLICADO` confirma o eco da escrita Modbus, mas não mede movimento físico da esteira; a homologação da parada deve incluir o equipamento real.

No configurador industrial, informe a distância em metros entre o ponto de detecção física do M2052 e o início da área de leitura do Keyence. Foi informado um intervalo aproximado de 10 a 15 cm; o valor inicial é 0,125 m (ponto médio), que deve ser confirmado no local. O configurador grava `TRACE_SENSOR_TO_READER_DISTANCE_M`; o prazo final de `SEM_LEITURA` é o tempo de transporte calculado a 0,7 m/s somado a `TRACE_SCANNER_NO_READ_TIMEOUT_MS`. O gateway envia também o horário observado do sensor para que a correlação e o relatório usem a passagem física, não apenas o horário em que a API recebeu a mensagem.

Finalizar o último carregamento encerra o romaneio e libera o relatório, mesmo que existam fotos pendentes ou ausentes. O relatório resume as passagens classificadas, leituras válidas, sacos sem leitura e fotos recebidas; cada foto Keyence é vinculada à carga e à posição do saco. Uma foto ausente não bloqueia a finalização nem a geração do PDF. O Trace valida o PDF e preserva as fotos originais durante o prazo de retenção. A limpeza só remove um arquivo de evidência antigo quando o PDF atual, com a mesma impressão digital, foi confirmado como sincronizado no Central. Os vínculos do banco permanecem para permitir regenerar o relatório; quando uma foto original já foi removida pela retenção, o Trace tenta recuperá-la do PDF anterior. O PDF final permanece no armazenamento local e é enviado ao Central pela fila de sincronização; se o PC estiver sem conexão, o envio ocorre quando a conexão voltar.

Uma leitura `SEM_LEITURA` é imutável no fluxo operacional: não se permite convertê-la manualmente em produto válido. Se faltar identificação, o relatório registra a divergência por item e mantém a fotografia como evidência, sem pedir conferência manual saco a saco.

## Configuração do PC industrial

1. Provisionar o dispositivo `SCANNER` associado à Dala para o worker consultar as passagens M2052, registrar as leituras recebidas por TCP, finalizar leituras sem código, solicitar fotos e publicar o estado do leitor. Na contingência HID, o código vindo do AutoID é enviado pelo Trace autenticado na sessão do operador, sem passar pelo worker. O token `SCANNER` do worker é separado do token do CLP.

   ```sh
   php scripts/provision_device.php --equipment-id=ID_DA_DALA --device-type=SCANNER --device-code=KEYENCE-SRX100W --token=TOKEN_FORTE
   ```

2. No `.env` local do Trace, configurar `KEYENCE_ENABLED=1`, o token `SCANNER` do worker, uma senha FTP exclusiva com pelo menos 16 caracteres, o IP industrial fixo do PC em `KEYENCE_BIND_ADDRESS` e `KEYENCE_FTP_PASV_ADDRESS`, além do IP fixo do leitor (`192.168.1.30`) em `KEYENCE_FTP_ALLOWED_ADDRESS` e `KEYENCE_TCP_ALLOWED_ADDRESS`. O worker aceita na porta `19000` somente esse IP e o gateway do Docker (ver [Uma conexão por vez](#o-que-o-worker-faz-com-cada-quadro)); a restrição efetiva à origem do leitor é a regra do Firewall do Windows. O worker é necessário nas duas rotas: recebe os códigos na rota TCP e, em qualquer rota, associa passagens sem leitura e imagens de erro.

   `KEYENCE_IMAGE_ARCHIVE_HOST_DIR` define a pasta de origem principal, e `KEYENCE_IMAGE_ARCHIVE_FALLBACK_HOST_DIR` define a pasta alternativa, ambas montadas em modo somente leitura. O padrão também monitora `C:/Windows X-Lite/Keyence/Falhas`, além de `C:/Keyence/Falhas`, para cobrir as duas localizações encontradas no PC industrial. O serviço informa `image_archive_directory_available` no health e no heartbeat e fica degradado se nenhuma pasta configurada estiver acessível.

3. No Windows, o instalador mantém ativo o perfil `industrial`; o serviço Keyence fica inativo até `KEYENCE_ENABLED=1` e os campos obrigatórios passarem pela validação. Em Linux, subir o perfil:

   ```sh
   docker compose --profile industrial up -d --build
   ```

   No PC Windows, a tarefa `Trace-Docker-Start` executa após o login da conta técnica. Ela espera o motor Linux e a chegada do IP da interface industrial, valida a configuração, solicita que o Compose inicie os serviços e aguarda o health check do worker. Com Keyence ativo, confirma também as portas FTP de controle/passivas publicadas no IP industrial e a regra de Firewall restrita ao IP do leitor. O resultado fica em `armazenamento/logs/docker-start.log`. O serviço só inicia depois que a conta configurada entrar no Windows; a tarefa não faz login automático. Essa validação confirma o serviço e a publicação das portas, mas não substitui um envio real de imagem pelo leitor.

4. Liberar no firewall do PC somente a interface industrial e o IP do leitor: porta `19000` para os códigos enviados pelo SR-X ao worker; porta `2121` e faixa `21100–21109` para o FTP passivo das imagens. A porta `9004` do AutoID Keyboard Wedge só é necessária durante a contingência HID. Não publicar essas portas na rede corporativa ou na Internet. O FTP deste perfil não cifra o tráfego; mantenha o enlace isolado e limite a origem ao IP do leitor.

5. No AutoID Network Navigator, configurar o SR-X100W como cliente de dados Ethernet para `192.168.1.20:19000`, com os parâmetros da seção [Rota TCP do leitor ao worker](#rota-tcp-do-leitor-ao-worker). O AutoID Keyboard Wedge deve ficar fechado. Em `Image`, configurar `Error Image = Send by FTP`, JPEG, FTP passivo e destino no mesmo IP do PC, porta `2121`, usuário e senha do `.env`; a faixa passiva deve ser `21100–21109`.

O Trace aceita um código por passagem M2052 e não conta repetições soltas do leitor como sacas. O sensor é a contagem física; o leitor apenas identifica a passagem. O marcador `ERROR` é exibido como falha de leitura e não é enviado como código de produto: o worker conclui `SEM_LEITURA` para aquela passagem e solicita uma imagem. Uma foto por passagem com falha atende à evidência; não são necessárias duas fotos para a mesma solicitação. Se essa passagem fizer a contagem física ultrapassar a prevista, a pausa é solicitada pelo fluxo do sensor, independentemente do resultado do leitor. A área de captura usada na imagem de evidência é a área de leitura configurada no SR-X100W. O nome do arquivo não identifica a saca; a associação usa o horário previsto de chegada à área de leitura e recusa vínculos ambíguos. O AutoID Keyboard Wedge não deve receber as mesmas leituras enquanto a rota TCP estiver em uso.

O receptor FTP do PC salva primeiro as imagens em `armazenamento/keyence-inbox` (pasta local do Trace montada no contêiner). Depois de associar uma foto a uma solicitação de evidência, a API grava a imagem original no armazenamento do Trace e o PDF incorpora seus bytes originais. A pasta de entrada é temporária e não é a fonte do relatório. Os arquivos originais ficam na pasta persistente de evidências até o prazo de retenção e a confirmação da sincronização do PDF correspondente; os registros de vínculo ficam no banco mesmo após a limpeza do JPEG. Para consulta na rede, prefira compartilhar em modo somente leitura a pasta dos relatórios finais e, se necessário, a pasta persistente de evidências `armazenamento/company_<id>/equipment_<id>/`, depois de definir quais usuários podem acessar e por quanto tempo. Não compartilhe a caixa temporária FTP e não exponha as portas FTP fora da rede industrial isolada. O FTP é apenas o protocolo de transferência entre o leitor e o serviço local.

## Rota TCP do leitor ao worker

```
SR-X100W 192.168.1.30 (cliente de dados TCP) ──► 192.168.1.20:19000 (Docker Desktop) ──► keyence-worker:19000 ──HTTP──► Servidor Trace
```

### Configuração do SR-X100W

Configuração conferida no leitor do PC industrial:

| Ajuste | Valor |
| --- | --- |
| Interface de dados | Ethernet, **cliente de dados (TCP)** para `192.168.1.20`, porta `19000` |
| Momento da conexão | **Após a leitura**: o leitor abre a conexão quando tem o primeiro resultado e a mantém aberta |
| TCP keepalive | Ligado |
| Cabeçalho | Nenhum |
| Terminador | CR (`0x0D`) |
| Dados acrescentados | Nenhum |
| Heartbeat do leitor | Desligado |
| Texto de não leitura | `ERROR` |
| Disparo | One-shot de 1000 ms pela entrada IN1, exatamente um resultado por disparo (o código ou `ERROR`) |

Como a conexão só abre no primeiro resultado, é normal o worker não ter conexão do leitor depois de ligar o PC, reiniciar o leitor ou reiniciar o worker, até o primeiro disparo. Isso não é falha da rota.

### O que o worker faz com cada quadro

1. **Uma conexão por vez.** O worker aceita conexões vindas de `KEYENCE_TCP_ALLOWED_ADDRESS` e do gateway do Docker (lido de `/proc/net/route` ao iniciar, somente na faixa `172.16.0.0/12`); as demais são recusadas antes de qualquer outra ação. Com o Docker Desktop, toda conexão publicada chega com o IP do gateway, inclusive um programa local que conecte na porta `19000`; por isso a regra do Firewall restrita ao IP do leitor é o filtro de origem real. O SR-X mantém uma única conexão de dados: quando uma conexão nova é aceita, as anteriores são encerradas com o motivo `substituída por nova conexão do leitor`, depois de processados os dados que elas já tinham entregado. O worker não fecha conexões ociosas. O keepalive TCP (10 s) só enxerga o proxy do Docker, não o leitor; um leitor desligado pode deixar a conexão aparentemente aberta até reconectar ou até o worker reiniciar.
2. **Enquadramento.** Cada quadro termina em CR (também são aceitos LF e ETX, com STX inicial opcional); espaços nas pontas são removidos e terminadores seguidos não geram quadro. Um quadro só é aceito se tiver de 3 a 128 caracteres ASCII imprimíveis e conta em `frames_received`. É recusado, conta em `frames_rejected` e fica em `failed-scans/*.rejected-frame.json` (causa, bytes brutos em base64, prévia, horário e conexão) quando estiver vazio, tiver caractere de controle ou fora do ASCII, tiver menos de 3 ou mais de 128 caracteres, quando o resto sem terminador passar de 2048 bytes (a continuação até o próximo terminador também é recusada) ou quando a conexão fechar com um resto sem terminador (`quadro sem terminador no fechamento da conexão`), que pode ser um código cortado. Um quadro recusado nunca vira leitura e sempre registra `scan_error`. O horário da leitura é o da chegada do pacote ao worker, não o do processamento.
3. **`ERROR`.** O marcador de não leitura (inclusive `ERROR` colado a um número de 8 a 14 dígitos) não vira código de produto. O worker consulta a passagem com `no_read: true`, pelas mesmas regras de não leitura do registro, e envia a leitura com `no_read: true` e código vazio; a passagem vira `SEM_LEITURA`, conta na meta e recebe uma solicitação de foto. Um `ERROR` sem passagem segue o item 5, como qualquer código.
4. **Associação.** Antes de tudo, o quadro aceito é gravado em `armazenamento/keyence-inbox/in-flight-scans/`. Os quadros são associados um de cada vez, na ordem de chegada. Para cada um, o worker chama `STATUS` com o horário de chegada; o servidor devolve o carregamento da Dala (`active_loading_id`, `active_loading_state`) e, se houver exatamente uma passagem compatível com esse horário, o id dela. O worker descarta uma passagem já reservada por leitura ainda na fila. Sem carregamento, a associação termina na primeira resposta. Com carregamento e sem passagem, o `STATUS` é repetido a cada 250 ms até `KEYENCE_ASSOCIATION_WAIT_MS` desde a chegada do quadro (padrão `3000` ms; aceita de `100` a `4000` ms, e um valor inválido ou fora dessa faixa usa o padrão). Se o Trace não responde, as tentativas seguem a cada 250 ms até 4000 ms desde a chegada. Durante uma associação, a consulta periódica de `STATUS` do laço principal (a cada 500 ms) fica suspensa para respeitar o limite do nginx.
5. **Sem passagem.** Sem carregamento ativo (inclusive em emergência, quando o `STATUS` não devolve carregamento), sem passagem compatível dentro da espera ou com o Trace indisponível, o resultado não é perdido nem conta saca: fica em `failed-scans/*.unassociated.json` (motivo `sem carregamento ativo`, `sem passagem disponível` ou `Trace indisponível durante a associação`, código, `no_read`, carregamento, `loading_state` e horário) e conta em `scans_unassociated`. Só é tratado como falha operacional, com `scan_error` e log `warn`/`error`, quando o carregamento estava em `CARREGANDO` ou o Trace estava indisponível. Com a esteira parada (`PAUSADO`), em `FINALIZANDO`, em `FINALIZADO` ou sem carregamento, o código é registrado apenas em log `info`, não gera `scan_error` e não aumenta o contador da tela de operação. O worker não reenvia esses arquivos; eles servem para auditoria.
6. **Fila e reenvio.** Com passagem, a leitura vai para `armazenamento/keyence-inbox/scans/` (gravada em `.tmp` e renomeada), a passagem fica reservada e a cópia em trânsito é apagada. A fila é enviada a `leituras.php` logo após a gravação e a cada 500 ms, com o `offline_id` como chave de idempotência, sem duplicar em reenvios. O arquivo só é apagado depois que o Trace confirma o registro (`scans_registered`). Respostas `401`, `408`, `423` (emergência), `429`, `5xx`, falhas de rede e respostas sem confirmação mantêm a leitura na fila: nova tentativa 500 ms após o início da anterior nas 10 primeiras (cerca de 5 s), depois 1, 2, 4 e 8 s e no máximo 10 s, sem limite de tentativas. Um `429` ou duas falhas temporárias seguidas encerram a passada. Outras recusas `4xx` movem o arquivo para `failed-scans` com o mesmo nome e contam em `scans_rejected_by_trace`; a recusa entra também em `unassociated_by_loading` com o motivo `recusada pelo Trace: HTTP <status> - <mensagem>`, e só aumenta o `count` se o carregamento estava em `CARREGANDO` na associação. Um arquivo ilegível na fila vai para `failed-scans/<nome>.invalid`.
7. **Reinício do worker.** Antes de abrir a porta `19000`, o worker apaga arquivos `.tmp` órfãos de `scans/` e trata cada cópia em `in-flight-scans/`: se já existe em `scans/` ou `failed-scans/` um `.json` legível com o mesmo `offline_id`, a cópia é apagada; senão, ela vai para `failed-scans/*.interrupted.json` com o motivo `worker reiniciado antes da associação` (sem associar a uma passagem, porque não há mais passagem segura), conta em `scans_unassociated` e registra `scan_error`. Uma cópia ilegível vai para `failed-scans/*.interrupted.invalid`. As leituras que já estavam em `scans/` continuam na fila e mantêm suas passagens reservadas.
8. **Estado publicado.** O heartbeat `SCANNER` (a cada 5 s) e o `/health` do worker levam o objeto `tcp_route`:

   | Campo | Significado |
   | --- | --- |
   | `connected`, `connections_open` | Há conexão aceita agora e quantas (normalmente 0 ou 1). |
   | `last_connected_at`, `last_disconnected_at`, `last_disconnect_reason` | Última abertura e último fechamento, com o motivo (por exemplo `conexão encerrada pelo leitor`, `substituída por nova conexão do leitor`, `worker encerrando`). |
   | `last_frame_at` | Último quadro recebido, aceito ou recusado. |
   | `frames_received`, `frames_rejected` | Quadros aceitos e recusados pelo enquadramento. |
   | `scans_registered`, `scans_unassociated`, `scans_rejected_by_trace` | Leituras confirmadas pelo Trace, sem passagem (todos os estados, inclusive cópias interrompidas) e recusadas pelo Trace. |
   | `failed_scans_pending` | Quantidade de arquivos em `failed-scans` (exceto `.tmp`). É histórico: só diminui quando alguém remove os arquivos. |
   | `scan_error`, `scan_error_at` | Último problema de associação ou registro; limpo por um registro bem-sucedido posterior ou após 10 minutos. |
   | `unassociated_by_loading` | Até 5 carregamentos, o mais recente primeiro: `loading_id`, `count` (só códigos recebidos com o carregamento em `CARREGANDO` e recusas de leituras associadas em `CARREGANDO`) e `last` (`barcode`, `no_read`, `reason`, `received_at`, `loading_state`), atualizado em qualquer estado. |

   Os contadores valem desde o início do worker e zeram quando ele reinicia. O servidor acrescenta `stale` e `stale_since` quando o heartbeat do worker está vencido, e descarta `scan_error` com mais de 10 minutos.

### O que as telas mostram

- **Cartão do leitor (Configurações):** a rede do leitor vem do ping. A linha da rota mostra `Rota TCP conectada` somente quando há conexão aberta e o leitor responde ao ping; com conexão aberta e sem resposta, `Rota TCP aberta, mas o leitor não responde na rede`. Sem nenhuma conexão desde o início do worker, `Rota TCP aguardando a primeira leitura`; depois de fechada, `Rota TCP sem conexão desde HH:MM`. Seguem o horário do último código, `N códigos sem passagem` (`scans_unassociated`), `N leituras retidas no PC para conferência` (`failed_scans_pending`, informativo) e `Erro: ...`. Só o `scan_error` deixa o cartão em atenção. Com heartbeat vencido, o cartão mostra `Sem informação recente do worker do leitor (desde HH:MM)`.
- **Tela de operação:** o aviso `Leituras sem passagem neste carregamento: N` soma o contador da rota HID desta tela com o `count` do worker para o carregamento selecionado. O visor mostra o último código sem passagem do worker como falha somente quando ele chegou com o carregamento em `CARREGANDO` e é mais recente que a última leitura da tela. As leituras TCP registradas geram os mesmos avisos da rota HID (produto fora do romaneio, código não cadastrado, excesso e quantidade prevista do produto atingida), a partir da consulta ao servidor, desde que a leitura tenha menos de 30 s.
- **Diferença entre as rotas:** na rota HID, uma captura sem passagem é descartada com o carregamento em `PAUSADO` ou com motivo de parada, mas conta em `FINALIZANDO`; na rota TCP, só conta em `CARREGANDO`.

### Verificar a rota no PC industrial

1. Feche o AutoID Network Navigator (enquanto conectado, ele ocupa a comunicação do leitor) e confirme que o AutoID Keyboard Wedge está fechado.
2. No PowerShell do PC, execute `netstat -ano | findstr :19000`. Deve aparecer a porta `19000` em `LISTENING` e, depois do primeiro disparo, uma conexão `ESTABLISHED` com o leitor.
3. Nos logs do worker (`docker compose --profile industrial logs -f keyence-worker`), confirme na inicialização `Entrada TCP e recepção FTP do Keyence ativas.` com `docker_gateway_ingress` preenchido. No primeiro disparo deve aparecer `Conexão TCP do leitor aberta.`; o `remote_address` é o gateway do Docker, não `192.168.1.30`. A cada disparo durante um carregamento devem aparecer `Código recebido do Keyence e enfileirado para o Trace.` e `Leitura Keyence registrada no Trace.`. Fora de um carregamento aparece `Código Keyence não associado a uma passagem; preservado em failed-scans.` com `reason` `sem carregamento ativo`, o que é esperado. `Conexão TCP anterior do leitor substituída pela nova.` indica que o leitor reconectou. `Quadro TCP do Keyence rejeitado` indica um quadro fora do formato: confira cabeçalho, terminador e dados acrescentados no leitor.
4. Para ver o estado completo, execute `docker compose --profile industrial exec keyence-worker node -e "fetch('http://127.0.0.1:8091/health').then(r=>r.text()).then(console.log)"` e confira `tcp_route`. Os arquivos preservados ficam em `armazenamento\keyence-inbox\failed-scans` (`*.unassociated.json`, `*.rejected-frame.json`, `*.interrupted.json`, recusas do Trace com o nome original e `*.invalid`) e a fila em `armazenamento\keyence-inbox\scans`.
5. No cartão do leitor na tela do Trace, confira a rede do leitor (ping), a linha da rota TCP, o horário do último código, os contadores e se há `Erro:`.

## Contingência: AutoID Keyboard Wedge (HID)

Use somente se a rota TCP não puder operar. O leitor tem um único destino de dados: **nunca deixe as duas rotas recebendo as mesmas leituras**.

1. No AutoID Network Navigator, aponte o cliente de dados do SR-X100W para a porta de entrada do Wedge (`192.168.1.20`, porta `9004`) em vez da `19000`, envie a configuração ao leitor, desconecte e feche o Navigator.
2. Abra o AutoID Keyboard Wedge com `Ethernet communication`, interface `192.168.1.20`, porta `9004` e `Transfer Method = HID`. Não selecione `Clipboard`.
3. Libere a porta `9004` no firewall, restrita ao IP do leitor.
4. Deixe o Wedge em segundo plano e a tela de operação do Trace em primeiro plano durante todo o carregamento. O código digitado pelo Wedge é registrado pela tela de operação, e o marcador `ERROR` recebido pelo AutoID é exibido como falha de leitura.

Nesta rota, o worker continua escutando a porta `19000`, mas não recebe quadros; o cartão mostra a rota TCP sem conexão depois que a conexão anterior fecha. Como o worker não fecha conexões ociosas, se o cartão continuar mostrando a rota aberta, reinicie o worker para encerrá-la. O worker continua necessário para `SEM_LEITURA`, fotos e estado do leitor. Para voltar à rota TCP, feche o Wedge e aponte o cliente de dados do leitor de novo para `192.168.1.20:19000`.

## Opções do AutoID Network Navigator

O pacote fornecido inclui o AutoID Network Navigator e o manual da série SR-X. Os recursos variam conforme o modelo e a versão do leitor.

- **Projeto e configurações:** criar, abrir e salvar arquivos de configuração; usar Quick Setup Code; exportar configurações para Excel ou texto; localizar e registrar leitores; alternar configurações; enviar uma configuração ao leitor ou recuperar a configuração atual.
- **Ferramentas conectadas:** Live View, Image View, File View e Terminal; configuração de várias unidades; Installation Guide; consulta e atualização de firmware; bloqueio por senha.
- **Ajustes do leitor:** bancos de configuração; parâmetros de leitura e filtros disponíveis no modelo; leitura de vários códigos; qualidade e comparação de códigos; presets; formatos de comunicação, entradas e saídas; scripts e substituição de comandos, quando suportados.
- **Imagens:** os resultados OK, Error, NG, Unstable e Captured têm opções separadas. Para o Trace, usar `Error Image = Send by FTP`, formato JPEG e modo passivo. NG significa divergência de comparação e não equivale a falha de leitura. O SR-X permite escolher a porta remota FTP de 1 a 65535; neste perfil, usar 2121. A faixa de dados passiva do PC fica em 21100–21109.
- **Manutenção do Navigator:** seleção da placa de rede, idioma e unidades, tempo limite e comportamento ao reconectar, busca/registro automático, opções de terminal, desconexão automática e sincronização de horário quando suportada.

Enquanto o Network Navigator estiver conectado ao SR-X, o leitor não pode usar Ethernet/RS-232 para se comunicar com o CLP ou outro PC. Desconecte e feche o Navigator antes de deixar o leitor operar com o Trace. Na contingência HID, o AutoID Keyboard Wedge é a ponte entre a rede do SR-X e o teclado HID que o Trace captura; a porta de entrada do Wedge e o destino do HID são etapas diferentes. O campo mostrado na operação continua somente leitura e não aceita digitação manual.

## Variáveis adicionadas ao `.env`

| Variável | Padrão | Uso |
| --- | --- | --- |
| `KEYENCE_ENABLED` | `0` | Habilita o serviço Keyence quando o perfil estiver ativo. |
| `KEYENCE_ARCHIVE_ONLY` | `0` | Recebe e arquiva imagens por FTP sem associar leituras ao Trace nem exigir token `SCANNER`. |
| `KEYENCE_DEVICE_TOKEN` | vazio | Token `SCANNER` provisionado para a Dala. |
| `KEYENCE_BIND_ADDRESS` | `127.0.0.1` | IP da interface do PC onde as portas serão publicadas; trocar pelo IP industrial fixo. |
| `KEYENCE_TCP_PORT` | `19000` | Porta publicada no PC para a rota principal de códigos (cliente de dados do SR-X → worker). O AutoID Keyboard Wedge não deve receber as mesmas leituras. |
| `KEYENCE_ASSOCIATION_WAIT_MS` | `3000` | Espera máxima, desde a chegada do quadro TCP, por uma passagem M2052 compatível quando há carregamento; vencida a espera, o resultado vai para `failed-scans`. Aceita `100` a `4000` ms; valor inválido ou fora da faixa usa `3000`. Com o Trace indisponível, as tentativas seguem até 4000 ms desde a chegada (`KEYENCE_ASSOCIATION_UNAVAILABLE_WAIT_MS` no worker, que o `docker-compose.yml` não repassa). |
| `KEYENCE_READER_PROBE_PORT` | `80` | Não é usada pelo worker atual: a presença do leitor na rede é verificada por ping ao IP de `KEYENCE_TCP_ALLOWED_ADDRESS`. |
| `KEYENCE_FTP_PORT` | `2121` | Porta FTP publicada para o leitor. |
| `KEYENCE_FTP_PASSIVE_START` / `KEYENCE_FTP_PASSIVE_END` | `21100` / `21109` | Faixa passiva TCP do FTP. |
| `KEYENCE_FTP_MINIMUM_FREE_BYTES` | `268435456` | Reserva 256 MiB no volume; sem espaço suficiente, o worker recusa confirmar a imagem e registra erro para permitir nova tentativa da câmera. |
| `KEYENCE_FTP_USER` / `KEYENCE_FTP_PASSWORD` | `trace-keyence` / vazio | Conta dedicada às imagens de erro. |
| `KEYENCE_FTP_PASV_ADDRESS` | vazio | IP industrial anunciado na resposta PASV. |
| `KEYENCE_FTP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W; obrigatório para iniciar o worker. |
| `KEYENCE_TCP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W (`192.168.1.30`) aceito na porta `19000`, junto com o gateway do Docker, e usado no ping de presença; obrigatório para iniciar o worker integrado. |
| `KEYENCE_IMAGE_MATCH_WINDOW_MS` | `3000` | Janela máxima entre a solicitação do Trace e o recebimento FTP. O SR-X não envia um ID do evento junto à foto; a hora de recebimento pode diferir da captura e não constitui uma chave determinística. |
| `KEYENCE_IMAGE_SETTLE_MS` | `800` | Espera antes de tentar associar uma imagem recém-recebida. |
| `KEYENCE_IMAGE_ARCHIVE_MATCH_WINDOW_MS` | `1500` | Janela máxima entre o horário previsto de chegada à área do leitor e o horário do JPEG local. Correspondências próximas demais ficam pendentes. |
| `TRACE_SENSOR_TO_READER_DISTANCE_M` | `0.125` | Valor inicial pela estimativa informada de 10 a 15 cm; confirmar fisicamente no configurador. |
| `TRACE_SCANNER_NO_READ_TIMEOUT_MS` | `1500` | Espera adicional após o tempo estimado de transporte até o leitor. |
| `KEYENCE_IMAGE_ARCHIVE_POLL_MS` | `2000` | Intervalo para procurar novas imagens na pasta local. |
| `KEYENCE_IMAGE_ARCHIVE_HOST_DIR` | `C:/Keyence/Falhas` | Origem no PC, montada em modo somente leitura. |
| `KEYENCE_IMAGE_ARCHIVE_FALLBACK_HOST_DIR` | `C:/Windows X-Lite/Keyence/Falhas` | Pasta alternativa local do software Keyence, também montada somente para leitura. Uma foto encontrada em qualquer origem pode atender à solicitação da passagem. |
| `KEYENCE_IMAGE_UNMATCHED_GRACE_MS` | `300000` | Tempo antes de avisar que há imagens mantidas na fila sem correspondência única. As imagens não são removidas por esse prazo. |
| `KEYENCE_IMAGE_MAX_AGE_MS` | `300000` | Idade máxima para importar cópias da pasta local de arquivo; não remove imagens já recebidas na caixa do Trace. |
| `KEYENCE_IMAGE_RECEIVE_TIMEOUT_MS` | `120000` | Prazo de 2 minutos para receber e associar a foto de erro; uma foto tardia ainda pode recuperar uma solicitação em `ERRO`. |

## Arquivos principais

- `integracoes/keyence-worker/keyence-worker.mjs`: associação dos códigos à passagem, filas `in-flight-scans`, `scans` e `failed-scans`, heartbeat com `tcp_route` e associação das imagens.
- `integracoes/keyence-worker/keyence-core.mjs`: receptor TCP (enquadramento, substituição de conexão, quadros recusados), contadores de `tcp_route`, recuperação de quadros em trânsito e prazos de reenvio da fila.
- `interface/js/funcoes/leitor-rota-tcp.js`: textos do cartão do leitor, contador e visor da tela de operação a partir de `tcp_route`.
- `integracoes/keyence-worker/ftp-receiver.mjs`: receptor FTP passivo, limitado a JPEG e a uma conta.
- `servidor/api/keyence_worker.php`: `STATUS` (carregamento, `active_loading_state` e passagem compatível), solicitações de imagem, validação e armazenamento.
- `servidor/api/operacoes/leituras.php`: aceita leitura autenticada pelo dispositivo `SCANNER` e restringe à Dala vinculada.
- `servidor/api/keyence_worker.php`: consulta solicitações, recebe fotos e registra o timeout sem imagem.
- `servidor/api/relatorios/relatorio_auditoria.php`: prepara o PDF no fechamento e limpa as imagens de origem depois da persistência.

## Homologação necessária

O serviço possui testes locais de enquadramento TCP, substituição de conexão, recuperação de quadros em trânsito, prazos de reenvio, contadores de `tcp_route` (inclusive o worker executado contra uma API simulada), parser de código, validação de correspondência temporal, login FTP, transferência de JPEG e integração com a fila `SEM_LEITURA`. No PC industrial, a rota TCP já recebeu um quadro `ERROR` fora de um carregamento. Ainda é necessário validar com o equipamento real: um carregamento com sacas pela rota TCP (código lido, `ERROR` associado à passagem certa, contagem de sacas, conexão mantida entre disparos, reconexão e substituição da conexão após reiniciar leitor ou worker, códigos com a esteira pausada fora do contador e reenvio da fila depois de uma emergência), modo passivo FTP, resolução/tamanho máximo das imagens, latência da foto, relógios sincronizados, duas falhas próximas, reinício com fila local, perda de API, perda de FTP e geração do PDF final.

O serviço continua desabilitado por padrão. No Windows, marcar `KEYENCE_ENABLED=1` sem preencher os dados obrigatórios interrompe a inicialização com uma mensagem de configuração; com os dados válidos, as portas são publicadas automaticamente no IP industrial indicado. Não considere a integração homologada até os testes físicos passarem. A página da KEYENCE Brasil disponibiliza o [Manual do Usuário da Série SR-X](https://www.keyence.com.br/support/user/barcode/sr-x/manual/) e as [especificações do SR-X100W](https://www.keyence.com/products/barcode/barcode-readers/sr-x/models/sr-x100w/).
