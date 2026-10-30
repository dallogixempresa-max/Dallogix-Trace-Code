# Integração do KEYENCE SR-X100W com o Trace

**Situação:** a rota principal de códigos usa AutoID Keyboard Wedge em HID; as fotos de falha continuam chegando por FTP e podem ser importadas da pasta local. A homologação final depende de confirmar a configuração do Wedge e fazer uma leitura física no PC industrial.

## Fluxo implementado

O SR-X100W envia os dados pela rede ao AutoID Keyboard Wedge no PC industrial. O AutoID converte cada código em teclas HID; com o Trace em primeiro plano, a tela de operação captura a sequência rápida, mostra o texto no visor somente leitura e registra a leitura automaticamente. O Trace reconhece Enter quando configurado no Wedge e também envia o código após 180 ms sem novas teclas quando não há terminador. O caminho TCP direto do leitor ao `keyence-worker` permanece como alternativa, mas não participa da contagem quando o Wedge estiver entregando por HID.

O cartão do leitor só mostra **ONLINE** quando o worker, no PC industrial, abre uma conexão TCP de diagnóstico com o IP do SR-X100W na porta `KEYENCE_READER_PROBE_PORT` (padrão `80`), ou recebe um código decodificado nos últimos 15 segundos. Duas sondagens sem resposta marcam **SEM RESPOSTA**; a tela não confunde o heartbeat do worker com a presença do leitor. A sondagem confirma que a interface de rede do endereço configurado responde, não que o leitor está focado ou decodificando corretamente. Um código recebido confirma o fluxo de leitura. O SR-X suporta HTTP e HTTPS; se a porta da interface Web foi alterada, configure a porta correspondente no `.env` do PC.

O M2052 fica antes da área de leitura. Cada transição aceita do sensor registra a passagem e seu horário observado no gateway; o Trace calcula a chegada prevista ao leitor como `horário M2052 + distância M2052–leitor / 0,7 m/s`. O prazo de falha só vence depois dessa viagem mais a espera configurada para o leitor. O código é associado à passagem cuja janela prevista mais se aproxima do horário em que o AutoID/TCP o entregou; candidatos ambíguos são recusados para não atribuir um produto ao saco errado. Assim, uma falha anterior não desloca automaticamente o próximo código para a saca seguinte. O marcador `ERROR`, inclusive quando vem colado a um valor numérico de 8 a 14 dígitos, é tratado como falha e não como produto.

Quando o prazo completo vence sem código, o Trace cria `SEM_LEITURA` e uma solicitação de imagem. As fotos podem chegar por FTP ou ser copiadas da pasta de arquivo do leitor, configurada no PC como `C:\Keyence\Falhas`. Essa pasta é montada somente para leitura dentro do worker; o Trace nunca apaga nem altera as imagens originais. Para fotos importadas, o worker usa o horário de modificação do JPEG como horário de captura aproximado, agrupa quadros do mesmo disparo e compara a captura com o horário previsto de chegada à área do leitor, e não diretamente com o horário M2052. O nome original do arquivo é preservado na auditoria do recebimento. A API valida a imagem e vincula a foto à solicitação `SEM_LEITURA`, à leitura e ao evento da saca; o PDF busca essa relação no banco e identifica a carga, o número sequencial do saco e a hora da foto.

Se não houver uma passagem compatível ou houver duas passagens com horários próximos demais, a foto não é associada: o relatório mantém a ocorrência `SEM_LEITURA` e informa que a evidência está pendente/ausente, sem anexar uma foto possivelmente errada. Cópias temporárias sem vínculo ficam na fila do Trace por até cinco minutos; depois são removidas e registradas no log. As imagens originais de `C:\Keyence\Falhas` são preservadas. Imagens antigas não são reaproveitadas para um novo romaneio: só entram na fila as que foram gravadas nos últimos cinco minutos, para evitar ligar uma foto histórica à saca errada. Códigos desconhecidos continuam sendo classificados pela regra atual do Trace.

O sinal de passagem M2052 é a contagem física: cada evento aceito soma uma saca, mesmo quando o leitor não decodifica o código. O código recebido do leitor só pode ser associado uma vez a um evento M2052. `ERROR` ou o timeout do leitor gera `SEM_LEITURA`, ocorrência e pedido de imagem; isso não pausa a esteira nem transforma o evento em leitura válida. A interface mostra sacas detectadas e códigos válidos separadamente. Ao atingir a quantidade física prevista, o carregamento entra em finalização e continua aceitando a resposta final do leitor.

No configurador industrial, informe a distância em metros entre o ponto de detecção física do M2052 e o início da área de leitura do Keyence. Foi informado um intervalo aproximado de 10 a 15 cm; o valor inicial é 0,125 m (ponto médio), que deve ser confirmado no local. O configurador grava `TRACE_SENSOR_TO_READER_DISTANCE_M`; o prazo final de `SEM_LEITURA` é o tempo de transporte calculado a 0,7 m/s somado a `TRACE_SCANNER_NO_READ_TIMEOUT_MS`. O gateway envia também o horário observado do sensor para que a correlação e o relatório usem a passagem física, não apenas o horário em que a API recebeu a mensagem.

Finalizar o último carregamento não encerra o romaneio. Depois que todos os carregamentos estiverem finalizados e não houver pedidos de imagem pendentes, a tela de detalhes mostra **Encerrar romaneio**. A confirmação muda o status do romaneio e libera o botão do relatório de auditoria. O relatório resume as passagens, leituras válidas, sacos sem leitura e fotos recebidas; cada foto Keyence é vinculada à carga e à posição do saco. Se a foto não chegar, a falha permanece listada com o estado da evidência. O Trace valida o PDF antes de remover as imagens JPEG de origem; se a geração falhar, preserva as evidências. O PDF final permanece no armazenamento local.

Uma leitura `SEM_LEITURA` é imutável no fluxo operacional: não se permite convertê-la manualmente em produto válido. Se faltar identificação, o relatório registra a divergência por item e mantém a fotografia como evidência, sem pedir conferência manual saco a saco.

## Configuração do PC industrial

1. Provisionar o dispositivo `SCANNER` associado à Dala para o worker consultar as passagens M2052, finalizar leituras sem código, solicitar fotos e publicar o estado do leitor. O código HID vindo do AutoID é enviado pelo Trace autenticado na sessão do operador; ele não depende da conexão TCP direta do leitor ao worker. O token `SCANNER` do worker é separado do token do CLP.

   ```sh
   php scripts/provision_device.php --equipment-id=ID_DA_DALA --device-type=SCANNER --device-code=KEYENCE-SRX100W --token=TOKEN_FORTE
   ```

2. No `.env` local do Trace, configurar `KEYENCE_ENABLED=1`, o token `SCANNER` do worker, uma senha FTP exclusiva com pelo menos 16 caracteres, o IP industrial fixo do PC em `KEYENCE_BIND_ADDRESS` e `KEYENCE_FTP_PASV_ADDRESS`, além do IP fixo do leitor em `KEYENCE_FTP_ALLOWED_ADDRESS` e `KEYENCE_TCP_ALLOWED_ADDRESS`. O worker continua necessário para associar passagens sem leitura e imagens de erro; selecionar HID no AutoID não desativa esse serviço.

   `KEYENCE_IMAGE_ARCHIVE_HOST_DIR` define a pasta de origem montada no contêiner em modo somente leitura. No PC industrial Windows, o configurador usa `C:/Keyence/Falhas`; o serviço informa `image_archive_directory_available` no health e no heartbeat, e fica degradado se essa pasta configurada não estiver acessível.

3. No Windows, o instalador mantém ativo o perfil `industrial`; o serviço Keyence fica inativo até `KEYENCE_ENABLED=1` e os campos obrigatórios passarem pela validação. Em Linux, subir o perfil:

   ```sh
   docker compose --profile industrial up -d --build
   ```

4. Liberar no firewall do PC somente a interface industrial e o IP do leitor: porta `9004` para a comunicação Ethernet recebida pelo AutoID Keyboard Wedge; porta `2121` e faixa `21100–21109` para o FTP passivo das imagens. A porta `19000` fica disponível apenas se a alternativa TCP direta for usada. Não publicar essas portas na rede corporativa ou na Internet. O FTP deste perfil não cifra o tráfego; mantenha o enlace isolado e limite a origem ao IP do leitor.

5. No AutoID Keyboard Wedge, selecionar `Ethernet communication`, a interface `192.168.1.20`, porta `9004` e `Transfer Method = HID`. Deixar o Wedge iniciado em segundo plano e o Trace em primeiro plano durante a operação. Não selecionar `Clipboard` para esta rota. Configure o SR-X100W para enviar o resultado ao AutoID nesta porta, sem enviar cópias simultâneas à alternativa TCP `19000`. No AutoID Network Navigator, manter a leitura contínua já homologada para a linha; cada leitura HID é associada ao evento M2052 pela distância e pelo horário, sem exigir um trigger elétrico do CLP. Em `Image`, configurar `Error Image = Send by FTP`, JPEG, FTP passivo e destino no mesmo IP do PC, porta `2121`, usuário e senha do `.env`; a faixa passiva deve ser `21100–21109`.

O Trace aceita um código por passagem M2052 e não conta repetições soltas do leitor como sacas. O sensor é a contagem física; o leitor apenas identifica a passagem. O marcador `ERROR` recebido pelo AutoID é exibido como falha de leitura e não é enviado como código de produto: o worker conclui `SEM_LEITURA` quando vence a janela de transporte e solicita a imagem, sem pausar a esteira. A área de captura usada na imagem de evidência é a área de leitura configurada no SR-X100W. O nome do arquivo não identifica a saca; a associação usa o horário previsto de chegada à área de leitura e recusa vínculos ambíguos. A alternativa TCP direta continua disponível para outra instalação, mas não deve receber as mesmas leituras enquanto HID estiver em uso.

O receptor FTP do PC salva primeiro as imagens em `armazenamento/keyence-inbox` (pasta local do Trace montada no contêiner). Depois de associar uma foto a uma solicitação `SEM_LEITURA`, a API grava a imagem de evidência no armazenamento do Trace; o PDF final incorpora a imagem e permanece como evidência após a limpeza dos arquivos de origem. A pasta de entrada é temporária e não é a fonte do relatório. Para consulta na rede, prefira compartilhar em modo somente leitura a pasta dos relatórios finais e, se necessário, a pasta persistente de evidências `armazenamento/company_<id>/equipment_<id>/`, depois de definir quais usuários podem acessar e por quanto tempo. Não compartilhe a caixa temporária FTP e não exponha as portas FTP fora da rede industrial isolada. O FTP é apenas o protocolo de transferência entre o leitor e o serviço local.

## Opções do AutoID Network Navigator

O pacote fornecido inclui o AutoID Network Navigator e o manual da série SR-X. Os recursos variam conforme o modelo e a versão do leitor.

- **Projeto e configurações:** criar, abrir e salvar arquivos de configuração; usar Quick Setup Code; exportar configurações para Excel ou texto; localizar e registrar leitores; alternar configurações; enviar uma configuração ao leitor ou recuperar a configuração atual.
- **Ferramentas conectadas:** Live View, Image View, File View e Terminal; configuração de várias unidades; Installation Guide; consulta e atualização de firmware; bloqueio por senha.
- **Ajustes do leitor:** bancos de configuração; parâmetros de leitura e filtros disponíveis no modelo; leitura de vários códigos; qualidade e comparação de códigos; presets; formatos de comunicação, entradas e saídas; scripts e substituição de comandos, quando suportados.
- **Imagens:** os resultados OK, Error, NG, Unstable e Captured têm opções separadas. Para o Trace, usar `Error Image = Send by FTP`, formato JPEG e modo passivo. NG significa divergência de comparação e não equivale a falha de leitura. O SR-X permite escolher a porta remota FTP de 1 a 65535; neste perfil, usar 2121. A faixa de dados passiva do PC fica em 21100–21109.
- **Manutenção do Navigator:** seleção da placa de rede, idioma e unidades, tempo limite e comportamento ao reconectar, busca/registro automático, opções de terminal, desconexão automática e sincronização de horário quando suportada.

Enquanto o Network Navigator estiver conectado ao SR-X, o leitor não pode usar Ethernet/RS-232 para se comunicar com o CLP ou outro PC. Desconecte e feche o Navigator antes de deixar o leitor operar com o Trace. O AutoID Keyboard Wedge é a ponte entre a rede do SR-X e o teclado HID que o Trace captura; a porta de entrada do Wedge e o destino do HID são etapas diferentes. O campo mostrado na operação continua somente leitura e não aceita digitação manual.

## Variáveis adicionadas ao `.env`

| Variável | Padrão | Uso |
| --- | --- | --- |
| `KEYENCE_ENABLED` | `0` | Habilita o serviço Keyence quando o perfil estiver ativo. |
| `KEYENCE_ARCHIVE_ONLY` | `0` | Recebe e arquiva imagens por FTP sem associar leituras ao Trace nem exigir token `SCANNER`. |
| `KEYENCE_DEVICE_TOKEN` | vazio | Token `SCANNER` provisionado para a Dala. |
| `KEYENCE_BIND_ADDRESS` | `127.0.0.1` | IP da interface do PC onde as portas serão publicadas; trocar pelo IP industrial fixo. |
| `KEYENCE_TCP_PORT` | `19000` | Porta da rota alternativa de códigos diretamente ao worker; não usar junto com HID para a mesma leitura. |
| `KEYENCE_READER_PROBE_PORT` | `80` | Porta TCP da interface Web do SR-X usada para confirmar presença na rede; use `443` se o leitor estiver configurado apenas com HTTPS. |
| `KEYENCE_FTP_PORT` | `2121` | Porta FTP publicada para o leitor. |
| `KEYENCE_FTP_PASSIVE_START` / `KEYENCE_FTP_PASSIVE_END` | `21100` / `21109` | Faixa passiva TCP do FTP. |
| `KEYENCE_FTP_USER` / `KEYENCE_FTP_PASSWORD` | `trace-keyence` / vazio | Conta dedicada às imagens de erro. |
| `KEYENCE_FTP_PASV_ADDRESS` | vazio | IP industrial anunciado na resposta PASV. |
| `KEYENCE_FTP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W; obrigatório para iniciar o worker. |
| `KEYENCE_TCP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W permitido na rota alternativa de códigos diretamente ao worker; obrigatório para iniciar o worker integrado. |
| `KEYENCE_IMAGE_MATCH_WINDOW_MS` | `3000` | Janela máxima entre a solicitação do Trace e a chegada da imagem. |
| `KEYENCE_IMAGE_SETTLE_MS` | `800` | Espera antes de tentar associar uma imagem recém-recebida. |
| `KEYENCE_IMAGE_ARCHIVE_MATCH_WINDOW_MS` | `1500` | Janela máxima entre o horário previsto de chegada à área do leitor e o horário do JPEG local. Correspondências próximas demais ficam pendentes. |
| `TRACE_SENSOR_TO_READER_DISTANCE_M` | `0.125` | Valor inicial pela estimativa informada de 10 a 15 cm; confirmar fisicamente no configurador. |
| `TRACE_SCANNER_NO_READ_TIMEOUT_MS` | `1500` | Espera adicional após o tempo estimado de transporte até o leitor. |
| `KEYENCE_IMAGE_BURST_WINDOW_MS` | `100` | Agrupa quadros do mesmo disparo e usa o mais recente como evidência. |
| `KEYENCE_IMAGE_ARCHIVE_POLL_MS` | `2000` | Intervalo para procurar novas imagens na pasta local. |
| `KEYENCE_IMAGE_ARCHIVE_HOST_DIR` | `C:/Keyence/Falhas` | Origem no PC, montada em modo somente leitura. |
| `KEYENCE_IMAGE_MAX_AGE_MS` | `300000` | Prazo para imagem sem correspondência antes da remoção da caixa de entrada. |
| `KEYENCE_IMAGE_RECEIVE_TIMEOUT_MS` | `6000` | Prazo para receber e associar a foto de erro antes de marcar a solicitação como `ERRO`. |

## Arquivos principais

- `integracoes/keyence-worker/keyence-worker.mjs`: recepção TCP, fila de leituras, heartbeat e associação das imagens.
- `integracoes/keyence-worker/ftp-receiver.mjs`: receptor FTP passivo, limitado a JPEG e a uma conta.
- `servidor/api/keyence_worker.php`: estado da Dala, solicitações de imagem, validação e armazenamento.
- `servidor/api/operacoes/leituras.php`: aceita leitura autenticada pelo dispositivo `SCANNER` e restringe à Dala vinculada.
- `servidor/api/keyence_worker.php`: consulta solicitações, recebe fotos e registra o timeout sem imagem.
- `servidor/api/relatorios/relatorio_auditoria.php`: prepara o PDF no fechamento e limpa as imagens de origem depois da persistência.

## Homologação necessária

O serviço possui testes locais de enquadramento TCP alternativo, parser de código, validação de correspondência temporal, login FTP, transferência de JPEG e integração com a fila `SEM_LEITURA`. Ainda é necessário validar com o equipamento real: AutoID em HID, entrada Ethernet na porta `9004`, Trace em primeiro plano, leitura automática no visor somente leitura, marcador `ERROR`, modo passivo FTP, resolução/tamanho máximo das imagens, latência da foto, relógios sincronizados, duas falhas próximas, reinício com fila local, perda de API, perda de FTP e geração do PDF final.

O serviço continua desabilitado por padrão. No Windows, marcar `KEYENCE_ENABLED=1` sem preencher os dados obrigatórios interrompe a inicialização com uma mensagem de configuração; com os dados válidos, as portas são publicadas automaticamente no IP industrial indicado. Não considere a integração homologada até os testes físicos passarem. A página da KEYENCE Brasil disponibiliza o [Manual do Usuário da Série SR-X](https://www.keyence.com.br/support/user/barcode/sr-x/manual/) e as [especificações do SR-X100W](https://www.keyence.com/products/barcode/barcode-readers/sr-x/models/sr-x100w/).
