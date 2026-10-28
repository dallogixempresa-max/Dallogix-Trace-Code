# Integração do KEYENCE SR-X100W com o Trace

**Situação:** software implementado e opcional; configuração física e homologação do SR-X100W ainda pendentes.

## Fluxo implementado

O SR-X100W envia os códigos pela conexão TCP local ao `keyence-worker` do PC industrial. O worker usa credencial de dispositivo `SCANNER`, consulta a Dala ativa, envia o código para a API de leitura do Trace e grava uma fila local idempotente para tolerar indisponibilidade curta da API.

O CLP continua registrando a passagem da saca pelo M2052. Quando o Trace encerra o tempo de leitura sem receber código, cria `SEM_LEITURA` e uma solicitação de imagem. O SR-X100W envia a imagem JPEG de erro por FTP. O worker compara o horário de chegada com as solicitações pendentes e só envia a imagem quando encontra uma correspondência sem ambiguidade. A API valida a imagem, associa a solicitação ao evento da saca e grava o arquivo no armazenamento local do Trace.

Se a imagem Keyence não chegar dentro do prazo ou a correspondência não for segura, a solicitação termina como `ERRO`; o relatório mantém a ocorrência `SEM_LEITURA` sem inventar uma associação de imagem. Imagens sem vínculo ficam na caixa local por até cinco minutos; depois são removidas e registradas no log. Códigos desconhecidos continuam sendo classificados pela regra atual do Trace.

Quando o último carregamento do romaneio é finalizado, a tela chama a geração do PDF final. O Trace confirma que o PDF foi gravado e validado antes de remover as fotos JPEG e os PDFs intermediários de evidência. Se a geração falhar, mantém as fontes; se a limpeza falhar depois de salvar o PDF, uma nova tentativa conclui a limpeza a partir do relatório persistido. O relatório final permanece no armazenamento local.

## Configuração do PC industrial

1. No servidor, provisionar um dispositivo `SCANNER` associado ao ID da Dala que o SR-X100W atenderá. O token deste leitor é separado do token do CLP.

   ```sh
   php scripts/provision_device.php --equipment-id=ID_DA_DALA --device-type=SCANNER --device-code=KEYENCE-SRX100W --token=TOKEN_FORTE
   ```

2. No `.env` local do Trace, configurar `KEYENCE_ENABLED=1`, `KEYENCE_DEVICE_TOKEN`, uma senha FTP exclusiva com pelo menos 16 caracteres, o IP industrial fixo do PC em `KEYENCE_BIND_ADDRESS` e `KEYENCE_FTP_PASV_ADDRESS`, além do IP fixo do leitor em `KEYENCE_FTP_ALLOWED_ADDRESS` e `KEYENCE_TCP_ALLOWED_ADDRESS`.

3. No Windows, o instalador mantém ativo o perfil `industrial`; o serviço Keyence fica inativo até `KEYENCE_ENABLED=1` e os campos obrigatórios passarem pela validação. Em Linux, subir o perfil:

   ```sh
   docker compose --profile industrial up -d --build
   ```

4. Liberar no firewall do PC somente a interface industrial e os IPs necessários: TCP 19000 para os códigos, TCP 2121 para FTP e TCP 21100–21109 para o modo passivo. Não publicar essas portas na rede corporativa ou na Internet. O FTP deste perfil não cifra o tráfego; mantenha o enlace isolado e limite a origem ao IP do leitor.

5. No AutoID Network Navigator, em `Timing`, selecionar `Timing Mode = Standard` e um único código por trigger. O trigger deve vir de um pulso do CLP correspondente a cada passagem M2052, usando uma saída eletricamente compatível ou isolada e validada pelo responsável da máquina. Não usar `Continuous` para esta associação: esse modo pode emitir várias leituras durante uma passagem. Em `Communication`, enviar o código decodificado por TCP, com delimitador CR/LF, ao IP industrial do PC na porta 19000. Em `Image`, configurar `Error Image = Send by FTP`, JPEG, FTP passivo e destino no mesmo IP do PC, porta 2121, usuário e senha do `.env`; a faixa passiva deve ser 21100–21109.

Cada trigger representa uma passagem e deve produzir no máximo um quadro TCP com o resultado. O Trace consome os eventos M2052 em ordem, serializa os quadros recebidos e reserva um evento para cada leitura; o banco aceita no máximo uma leitura por evento. Se o leitor estiver em `Continuous`, vários quadros de uma saca podem ser associados a eventos de sacas seguintes. O software não consegue distinguir com segurança um quadro repetido de duas sacas consecutivas com o mesmo código; por isso, não habilite a operação até confirmar `Timing Mode = Standard` e um pulso de trigger por passagem em bancada. Códigos iguais em sacas consecutivas continuam válidos. Os nomes de arquivo não identificam a saca. Para imagens, a associação segue a solicitação `SEM_LEITURA` e sua janela temporal; se houver ambiguidade, a imagem não é vinculada.

O receptor FTP do PC salva primeiro as imagens em `armazenamento/keyence-inbox` (pasta local do Trace montada no contêiner). Depois de associar uma foto a uma solicitação `SEM_LEITURA`, a API grava a imagem de evidência no armazenamento do Trace; o PDF final incorpora a imagem e permanece como evidência após a limpeza dos arquivos de origem. A pasta de entrada é temporária e não é a fonte do relatório. Para consulta na rede, prefira compartilhar em modo somente leitura a pasta dos relatórios finais e, se necessário, a pasta persistente de evidências `armazenamento/company_<id>/equipment_<id>/`, depois de definir quais usuários podem acessar e por quanto tempo. Não compartilhe a caixa temporária FTP e não exponha as portas FTP fora da rede industrial isolada. O FTP é apenas o protocolo de transferência entre o leitor e o serviço local.

## Opções do AutoID Network Navigator

O pacote fornecido inclui o AutoID Network Navigator e o manual da série SR-X. Os recursos variam conforme o modelo e a versão do leitor.

- **Projeto e configurações:** criar, abrir e salvar arquivos de configuração; usar Quick Setup Code; exportar configurações para Excel ou texto; localizar e registrar leitores; alternar configurações; enviar uma configuração ao leitor ou recuperar a configuração atual.
- **Ferramentas conectadas:** Live View, Image View, File View e Terminal; configuração de várias unidades; Installation Guide; consulta e atualização de firmware; bloqueio por senha.
- **Ajustes do leitor:** bancos de configuração; parâmetros de leitura e filtros disponíveis no modelo; leitura de vários códigos; qualidade e comparação de códigos; presets; formatos de comunicação, entradas e saídas; scripts e substituição de comandos, quando suportados.
- **Imagens:** os resultados OK, Error, NG, Unstable e Captured têm opções separadas. Para o Trace, usar `Error Image = Send by FTP`, formato JPEG e modo passivo. NG significa divergência de comparação e não equivale a falha de leitura. O SR-X permite escolher a porta remota FTP de 1 a 65535; neste perfil, usar 2121. A faixa de dados passiva do PC fica em 21100–21109.
- **Manutenção do Navigator:** seleção da placa de rede, idioma e unidades, tempo limite e comportamento ao reconectar, busca/registro automático, opções de terminal, desconexão automática e sincronização de horário quando suportada.

Enquanto o Network Navigator estiver conectado ao SR-X, o leitor não pode usar Ethernet/RS-232 para se comunicar com o CLP ou outro PC. Desconecte e feche o Navigator antes de deixar o leitor operar com o Trace. O AutoID Keyboard Wedge é um utilitário separado que transforma dados seriais/TCP em entrada de teclado; o Trace recebe os códigos diretamente por TCP, então o Wedge não é necessário nesta integração.

## Variáveis adicionadas ao `.env`

| Variável | Padrão | Uso |
| --- | --- | --- |
| `KEYENCE_ENABLED` | `0` | Habilita o serviço Keyence quando o perfil estiver ativo. |
| `KEYENCE_DEVICE_TOKEN` | vazio | Token `SCANNER` provisionado para a Dala. |
| `KEYENCE_BIND_ADDRESS` | `127.0.0.1` | IP da interface do PC onde as portas serão publicadas; trocar pelo IP industrial fixo. |
| `KEYENCE_TCP_PORT` | `19000` | Porta publicada para saída TCP do leitor. |
| `KEYENCE_FTP_PORT` | `2121` | Porta FTP publicada para o leitor. |
| `KEYENCE_FTP_PASSIVE_START` / `KEYENCE_FTP_PASSIVE_END` | `21100` / `21109` | Faixa passiva TCP do FTP. |
| `KEYENCE_FTP_USER` / `KEYENCE_FTP_PASSWORD` | `trace-keyence` / vazio | Conta dedicada às imagens de erro. |
| `KEYENCE_FTP_PASV_ADDRESS` | vazio | IP industrial anunciado na resposta PASV. |
| `KEYENCE_FTP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W; obrigatório para iniciar o worker. |
| `KEYENCE_TCP_ALLOWED_ADDRESS` | vazio | IP fixo do SR-X100W permitido na conexão de códigos; obrigatório para iniciar o worker. |
| `KEYENCE_IMAGE_MATCH_WINDOW_MS` | `3000` | Janela máxima entre a solicitação do Trace e a chegada da imagem. |
| `KEYENCE_IMAGE_SETTLE_MS` | `800` | Espera antes de tentar associar uma imagem recém-recebida. |
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

O serviço possui testes locais de enquadramento TCP, parser de código, validação de correspondência temporal, login FTP, transferência de JPEG e integração com a fila `SEM_LEITURA`. Ainda é necessário validar com o equipamento real: formato e delimitador configurados no SR-X100W, modo passivo FTP, resolução/tamanho máximo das imagens, latência da foto, relógios sincronizados, duas falhas próximas, reinício com fila local, perda de API, perda de FTP e geração do PDF final.

O serviço continua desabilitado por padrão. No Windows, marcar `KEYENCE_ENABLED=1` sem preencher os dados obrigatórios interrompe a inicialização com uma mensagem de configuração; com os dados válidos, as portas são publicadas automaticamente no IP industrial indicado. Não considere a integração homologada até os testes físicos passarem. A página da KEYENCE Brasil disponibiliza o [Manual do Usuário da Série SR-X](https://www.keyence.com.br/support/user/barcode/sr-x/manual/) e as [especificações do SR-X100W](https://www.keyence.com/products/barcode/barcode-readers/sr-x/models/sr-x100w/).
