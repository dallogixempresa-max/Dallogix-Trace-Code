# Dallogix Trace — revisão e instalação no PC industrial

Revisão inicial feita em 23/10/2026 e atualizada em 28/10/2026 para a release empresarial `v1.0.184`, a VM `trace-dallogix-prod` e o fluxo de instalação do PC industrial.

> **Não reutilize o `TraceSetup.exe` v1.0.175 que falhou.** Essa versão iniciou o Docker Compose sem entregar o `.env`. O instalador empresarial atual gera credenciais aleatórias em uma instalação realmente nova, preenche os segredos recém-adicionados do leitor sem trocar senhas de banco existente e passa explicitamente o diretório do projeto e o `.env` ao Compose. O fluxo protegido preserva `TRACE_COMMIT` e `TRACE_VERSION`, passa o `.env` ao Compose, reinicia o Nginx para atualizar o endereço interno do PHP e valida o commit exato no health check. O instalador empresarial é publicado sem assinatura Authenticode; confira o SHA-256 no asset da release oficial antes de executar. O hash confirma integridade, não a identidade do publicador.

## Caminho recomendado de instalação

1. Confirme que o servidor Central está ativo e serve a mesma versão do instalador. O próprio assistente verifica versão, commit, manifesto estável e acesso ao pacote antes de iniciar.
2. No PC, o mecanismo Linux/WSL 2 e a virtualização precisam estar disponíveis. Se o Docker Desktop estiver ausente, o assistente oferece instalá-lo pelo Windows Package Manager; aceite os termos somente depois de confirmar que a licença de uso se aplica à empresa. A primeira abertura pode exigir configuração e reinício do Windows.
3. Baixe `TraceSetup.exe` e `TraceSetup.sha256.txt` nos assets da [última release empresarial](https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest). Execute `TraceSetup.exe` como administrador. Se estiver com uma cópia do repositório, `Instalar-PC-Windows.cmd` baixa os mesmos assets e confere o SHA-256 automaticamente.
4. Na primeira janela, confira servidor Central, identificação sugerida com o nome do PC, endereço local e, se a Dala já estiver cadastrada, ID, código e token SERVER. O vínculo pode ser feito depois.
5. Na segunda janela, selecione o modelo do CLP e o sensor, e escolha o KEYENCE SR-X100W ou o leitor USB. Para Keyence, informe os IPs fixos do PC e do leitor na rede isolada. A configuração Keyence do leitor no software KEYENCE e a conexão física precisam estar prontas.
6. Defina a senha administrativa do Node-RED na janela protegida e guarde-a no gerenciador técnico da empresa. Ao terminar, o instalador valida os serviços e oferece abrir o Trace.

Se a instalação não concluir, leia a mensagem na tela e `C:\DallogixTrace\armazenamento\logs\installation-setup.log`. Os diálogos principais são anteriores à inicialização do banco e dos serviços, para evitar deixar um ambiente parcialmente configurado se a máquina ou o leitor forem cancelados.

## Resultado da revisão

| Prioridade | Constatação | Evidência e efeito |
|---|---|---|
| Assinatura do instalador | O instalador empresarial é publicado sem assinatura Authenticode. | O SHA-256 confirma que o arquivo corresponde ao asset da release oficial, mas não identifica criptograficamente o publicador. O Windows pode exibir publicador desconhecido ou aviso do SmartScreen. |
| Erro reproduzido no PC | A versão v1.0.175 tentou interpolar o Compose sem as quatro credenciais obrigatórias. | O instalador empresarial atual valida e gera os segredos quando não há banco anterior e sempre passa `--project-directory` e `--env-file` ao Compose. A regressão é verificada por `Test-InstallerEnv.ps1` no workflow Windows. |
| Proteção de banco existente | Não é seguro inventar senhas se o banco já foi inicializado. | O instalador recusa substituir `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD` quando detecta volume ou vestígios de uma instalação anterior. Restaure o `.env` seguro correspondente ao banco; não remova o volume. |
| Metadados do deploy | O servidor usa `sudo` para executar Docker. | `scripts/docker_compose.sh` preserva somente `TRACE_COMMIT` e `TRACE_VERSION` para que o health check e a página pública indiquem o commit efetivamente implantado. |
| Argumento de ambiente do deploy | O primeiro deploy da v1.0.177 fez backup do banco, mas parou antes do Compose porque `deploy_from_git.sh` omitiu o caminho do `.env` na chamada de `deploy_production.sh`. | A v1.0.178 passa o `.env` explicitamente e adiciona um teste funcional do fluxo protegido de deploy ao CI. A tentativa anterior não reiniciou os containers. |
| Publicação GitHub → VM | Cada tag semântica de release no histórico da branch `production` executa validações, compila o instalador e envia o bundle exato para a VM por uma chave SSH limitada ao deploy. | A VM não precisa ler o repositório privado. O aplicador remoto rejeita tags fora da branch de produção, mudanças não fast-forward e pacotes que não correspondem ao commit da tag; o deploy protegido verifica carregamentos ativos, faz backup, executa migrations e exige health check do commit publicado. |
| Endereço e versão Central | A instalação pública é a VM nova `trace-dallogix-prod`, IP `164.152.197.177`. | O teste de saúde de `https://trace.dallogix.com/api/health.php` deve mostrar `status=ok`, `php=true`, `mysql=true`, `installation_mode=central` e o commit da release aprovada. O domínio raiz `dallogix.com` não faz parte do deploy. |
| Release empresarial | O código oficial fica em `dallogixempresa-max/Dallogix-Trace-Code`. | `Build-TraceSetup.ps1` exige uma tag semântica exata. A release empresarial atual é `v1.0.184`; baixe `TraceSetup.exe` e `TraceSetup.sha256.txt` dos assets da GitHub Release. Artefatos de revisão de pull request não são instaladores de produção. |
| Atualização automática dos PCs | A partir da v1.0.183, o instalador empresarial configura o manifesto e a chave pública do repositório da empresa. O registro do atualizador migra URL vazia ou a URL pessoal antiga para `dallogixempresa-max/Dallogix-Trace-Code`. A release v1.0.184 publica o manifesto assinado e o pacote; assinatura e SHA-256 foram verificados contra a chave pública empresarial. | O fluxo de publicação e integridade dos artefatos está validado. Ainda é necessário confirmar no PC industrial que a tarefa agendada está registrada e executando, que o `.env` aponta para a chave e o manifesto empresariais e que o preflight passa. Instalações com uma chave pública diferente precisam da migração aprovada antes de aceitar releases empresariais; a versão do Central não comprova, por si só, a atualização do PC. |
| Segredos locais | `.env`, `config\machine.json` e `config\node-red-clp.env` contêm credenciais e dados da máquina. | O instalador restringe as permissões NTFS da pasta `config` e do `.env` à conta que instalou, Administradores e SYSTEM. Não envie esses arquivos em chamados ou mensagens. |
| Atualização central | O instalador exige que `/api/health.php` responda com a versão e o commit exatos do pacote. | O servidor Central precisa estar publicado antes de instalar o PC. Uma versão diferente é recusada intencionalmente. |
| CLP físico | O assistente só habilita escrita física para o mapa reconhecido do INVT TS621, após confirmar o mapa e vincular um equipamento. | Sem esses dados, o Trace instala em modo sem escrita física. Isso não impede a instalação da interface, banco e sincronização. |
| Backup | A instalação registra uma tarefa diária de backup do banco às 02:00 e valida o SHA-256 do arquivo. | A tarefa está prevista no código; a instalação por si só não comprova que um backup foi restaurado com sucesso. Faça uma restauração de homologação antes de considerar a rotina comprovada. |

### Validação do artefato empresarial

- O workflow **Windows installer** compila a tag empresarial exata depois que a implantação e o health check confirmam a mesma versão e o mesmo commit no Central. Ele analisa os scripts PowerShell, testa a criação das quatro variáveis do `.env`, confirma os parâmetros `--project-directory` e `--env-file`, inspeciona o executável e publica o SHA-256 com a release.
- Baixe o instalador e `TraceSetup.sha256.txt` nos assets da release oficial da empresa. Compare o SHA-256 antes de executar; não use um EXE sem o arquivo de hash correspondente.
- O instalador é x64/Windows e inclui o runtime publicado do aplicativo. O tamanho aproximado de 52 MB vem do aplicativo auto-contido, WebView2/bootstrapper e componentes necessários; o arquivo não é só o código PHP.
- O instalador não identifica criptograficamente o publicador e o Windows pode exibir avisos mesmo com o hash correto. O download oficial e a conferência do SHA-256 são obrigatórios neste fluxo sem Authenticode.

## O que precisa estar pronto antes de instalar

1. O instalador e `TraceSetup.sha256.txt` da release oficial empresarial, publicados somente após o health check exato do Central. O instalador não tem assinatura Authenticode.
2. A VM nova publicada em `https://trace.dallogix.com`, com `version` e `commit` iguais aos da release do instalador. Não prossiga até a verificação de saúde pública mostrar o commit de produção esperado.
3. O instalador empresarial configura o manifesto e a chave pública corporativos para novos PCs. Em uma máquina já instalada, confirme a versão, a chave e a tarefa agendada conforme a tabela acima antes de considerar a atualização automática ativa.
4. Um PC x64 com Windows atualizado, virtualização habilitada no BIOS/UEFI, WSL 2 e Docker Desktop. O Docker Desktop exige pelo menos 8 GB de RAM segundo sua documentação atual; para uso comercial em organizações maiores, confirme a licença antes da instalação.
5. Código de ativação e credenciais do administrador da empresa no Trace. Se o PC já tiver banco local, faça e valide um backup antes de atualizar ou reparar.

## A. Preparar o repositório e gerar o instalador

Faça esta etapa em um computador Windows x64 de desenvolvimento, não no PC industrial. Abra **PowerShell como Administrador** e cole:

```powershell
winget source update
winget install --exact --id Git.Git --accept-source-agreements --accept-package-agreements
winget install --exact --id Microsoft.DotNet.SDK.8 --accept-source-agreements --accept-package-agreements
winget install --exact --id JRSoftware.InnoSetup --accept-source-agreements --accept-package-agreements
```

Feche e reabra o PowerShell. Clone o repositório oficial da empresa:

```powershell
$buildRoot = 'C:\DallogixTrace-Build'
git clone --branch production --single-branch https://github.com/dallogixempresa-max/Dallogix-Trace-Code.git $buildRoot
Set-Location $buildRoot
git fetch --all --tags
git ls-remote --tags origin
```

O último comando lista as tags disponíveis. Uma tag semântica sobre um commit de `main` que descende de `production` aciona o workflow **Windows installer**: ele valida a chave do manifesto, envia o bundle da tag à VM, confirma versão e commit pelo health check, avança `production` por fast-forward, compila `TraceSetup.exe` sem Authenticode, calcula o SHA-256, assina o manifesto do atualizador e publica os quatro assets na GitHub Release empresarial. Para produção, baixe os assets da release aprovada; os artefatos de revisão de pull request são apenas para revisão. Não use uma tag do repositório pessoal para publicar código empresarial.

Para compilar localmente quando a tag estiver disponível, cole o bloco abaixo e informe a tag aprovada quando solicitado. Na compilação automatizada, acompanhe **Actions → Windows installer**. O instalador de produção, seu SHA-256, o pacote assinado do atualizador e o manifesto são publicados nos assets da GitHub Release somente depois de o deploy e o health check do Central confirmarem o commit exato.

```powershell
$releaseTag = Read-Host 'Tag aprovada, por exemplo v1.0.184'
if ($releaseTag -notmatch '^v\d+\.\d+\.\d+$') { throw 'Tag inválida.' }
git fetch --tags origin
git checkout --detach $releaseTag
if ((git describe --tags --exact-match HEAD) -ne $releaseTag) { throw 'O checkout não está exatamente na tag informada.' }
if ((git status --porcelain).Count -ne 0) { throw 'A árvore contém arquivos alterados ou não rastreados. Não gere o instalador.' }
git rev-parse HEAD
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File .\implantacao\windows\Build-TraceSetup.ps1
$setup = Get-ChildItem .\implantacao\windows -Filter TraceSetup.exe -Recurse -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $setup) { throw 'TraceSetup.exe não foi gerado.' }
$setup | Select-Object FullName, Length, LastWriteTime
Get-FileHash -LiteralPath $setup.FullName -Algorithm SHA256
Get-AuthenticodeSignature -FilePath $setup.FullName | Format-List Status, StatusMessage, SignerCertificate
```

O build atual gera um executável **sem assinatura Authenticode** por decisão do fluxo empresarial. O digest SHA-256 permite conferir que o arquivo baixado é o mesmo asset da release oficial, mas não autentica quem publicou o instalador. O Windows pode avisar que o publicador é desconhecido; só continue quando o download veio da release oficial e o SHA-256 corresponde. O script de build recusa arquivos alterados ou não rastreados antes de empacotar.

## B. Conferir o servidor Central antes de preparar o PC

Em PowerShell, cole:

```powershell
$centralUrl = 'https://trace.dallogix.com'
$health = Invoke-RestMethod -Uri "$centralUrl/api/health.php" -TimeoutSec 20
$health | Select-Object status, installation_mode, version, commit | Format-List
```

Confirme que `status` é `ok`, `installation_mode` é `central`, e `version` e `commit` correspondem à release do instalador. O próprio instalador bloqueia a configuração se houver divergência.

Confira a configuração local do atualizador sem exibir tokens ou as demais credenciais do `.env`:

```powershell
$installRoot = 'C:\DallogixTrace'
Get-Content (Join-Path $installRoot '.env') |
  Where-Object { $_ -match '^(UPDATE_MANIFEST_URL|UPDATE_PUBLIC_KEY_FILE|UPDATE_CHANNEL)=' }

$taskName = 'Dallogix Trace Atualizacao Estavel'
Get-ScheduledTask -TaskName $taskName | Select-Object TaskName, State
Get-ScheduledTaskInfo -TaskName $taskName |
  Select-Object LastRunTime, LastTaskResult, NextRunTime

$manifestUrl = 'https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest/download/manifest.json'
$manifest = Invoke-RestMethod -Uri $manifestUrl -TimeoutSec 20
$manifest | Select-Object channel, version, artifact_url, sha256 | Format-List
```

O código exige canal `stable`, versão semântica, SHA-256 e assinatura válida da chave pública indicada em `UPDATE_PUBLIC_KEY_FILE`. A tarefa executa diariamente às 03:00 e tenta novamente após o logon; o pré-flight verifica Docker, chave pública, Git Bash e acesso à versão publicada. Leia o log local em `armazenamento\logs\update-stable-errors.log` se a tarefa falhar. Não considere a atualização automática de um PC comprovada sem verificar a tarefa e o preflight nessa máquina.

## C. Preparar um Windows limpo

Abra **PowerShell como Administrador** no PC industrial. Confira sistema e arquitetura:

```powershell
Get-CimInstance Win32_OperatingSystem | Select-Object Caption, Version, BuildNumber
[Environment]::Is64BitOperatingSystem
Get-CimInstance Win32_ComputerSystem | Select-Object @{Name='RAM_GB';Expression={[math]::Round($_.TotalPhysicalMemory / 1GB, 1)}}
```

Instale ou atualize o WSL 2:

```powershell
wsl --install
```

Reinicie o Windows se solicitado. Depois do reinício, abra PowerShell como Administrador novamente e cole:

```powershell
wsl --update
wsl --status
```

Instale Docker Desktop e Git for Windows:

```powershell
winget source update
winget install --exact --id Docker.DockerDesktop --accept-source-agreements --accept-package-agreements
winget install --exact --id Git.Git --accept-source-agreements --accept-package-agreements
```

Feche e reabra o PowerShell. Inicie o Docker Desktop. O comando localiza as instalações por usuário ou para todos os usuários:

```powershell
$dockerCandidates = @(
    (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'),
    (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe')
)
$dockerDesktop = $dockerCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $dockerDesktop) { throw 'Docker Desktop não foi localizado.' }
Start-Process -FilePath $dockerDesktop
```

Na primeira abertura do Docker Desktop, aceite os termos aplicáveis e selecione o mecanismo **WSL 2**. Aguarde o estado **Engine running**. Em seguida, no PowerShell:

```powershell
$engineType = docker info --format '{{.OSType}}'
if ($LASTEXITCODE -ne 0 -or $engineType -ne 'linux') { throw 'O mecanismo Linux do Docker não está pronto.' }
docker compose version
$gitBash = @(
    "$env:ProgramFiles\Git\bin\bash.exe",
    "$env:LOCALAPPDATA\Programs\Git\bin\bash.exe",
    "${env:ProgramFiles(x86)}\Git\bin\bash.exe"
) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $gitBash) { throw 'Git Bash não foi localizado. Reinstale Git for Windows e abra um novo PowerShell.' }
& $gitBash --version
```

## D. Baixar e validar o instalador aprovado

Em uma estação administrativa, abra a [última GitHub Release de `dallogixempresa-max/Dallogix-Trace-Code`](https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest) e confirme que a versão e o commit correspondem ao health check do Central. Baixe os assets `TraceSetup.exe` e `TraceSetup.sha256.txt` e extraia os dois juntos na pasta Downloads do PC industrial. Não use o artefato de revisão de uma pull request.

No PowerShell, confira a integridade e a origem:

```powershell
$installerPath = Join-Path $env:USERPROFILE 'Downloads\TraceSetup.exe'
$hashPath = Join-Path $env:USERPROFILE 'Downloads\TraceSetup.sha256.txt'
if (-not (Test-Path -LiteralPath $installerPath -PathType Leaf)) { throw 'TraceSetup.exe não foi encontrado.' }
if (-not (Test-Path -LiteralPath $hashPath -PathType Leaf)) { throw 'TraceSetup.sha256.txt não foi encontrado.' }
$expectedHash = ((Get-Content -LiteralPath $hashPath -Raw).Trim() -split '\s+')[0]
if ($expectedHash -notmatch '^[0-9a-fA-F]{64}$') { throw 'Arquivo SHA-256 inválido.' }
$actualHash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA256).Hash
if ($actualHash -ine $expectedHash) { throw 'O SHA-256 não corresponde. Não execute o arquivo; baixe novamente o asset da release oficial.' }
Get-AuthenticodeSignature -FilePath $installerPath | Format-List Status, StatusMessage, SignerCertificate
```

O resultado `NotSigned` é esperado. O digest confirma que o arquivo corresponde ao asset publicado, mas não autentica o publicador. Continue somente se o arquivo veio da release oficial e o SHA-256 confere; o Windows pode exibir um aviso de publicador desconhecido.

## E. Instalar e responder às perguntas do assistente

Só depois de a seção D terminar sem erro e o SHA-256 corresponder ao asset da release oficial, execute:

```powershell
Start-Process -FilePath $installerPath -Verb RunAs -Wait
```

O assistente tem duas janelas de configuração, antes de iniciar banco e serviços:

1. **Dados da máquina:** o servidor Central já vem preenchido; o identificador sugerido vem do nome deste PC. Mantenha o endereço local padrão, a menos que a empresa tenha definido outra porta. Se a Dala já estiver cadastrada, marque o vínculo e preencha o ID, código e token `SERVER`. Caso contrário, deixe desmarcado e conclua o vínculo depois.
2. **CLP, sensor e leitor:** escolha o modelo instalado. Só confirme o mapa do INVT TS621 e habilite escrita física quando modelo, mapa e vínculo da Dala estiverem confirmados. Para o KEYENCE SR-X100W, informe os IPs fixos do PC e do leitor na rede isolada; configure o leitor no software KEYENCE para TCP/19000 e FTP passivo/2121. Se usar um leitor USB HID, conecte-o antes de abrir a janela para identificá-lo automaticamente.
3. **Senha do Node-RED:** informe e confirme uma senha de pelo menos 15 caracteres na janela protegida. Guarde-a no gerenciador de credenciais técnico.
4. **Docker Desktop:** se estiver ausente, o instalador oferece instalá-lo pelo Windows Package Manager. Confirme que a licença se aplica à empresa. A primeira abertura pode pedir aceite de termos, configuração do WSL 2 ou reinício; depois do reinício, abra o TraceSetup novamente se solicitado.
5. Aguarde o Trace validar e iniciar os serviços. Na última página, deixe marcada a opção para abrir o aplicativo se quiser iniciar a ativação imediatamente.

O `.env` é criado localmente e não é incluído na release. Não copie o `.env` de outro PC: ele contém credenciais próprias daquele banco. Se o assistente detectar um banco existente sem as senhas correspondentes, pare e restaure o `.env` do backup seguro; não remova o volume MySQL. Os campos preenchidos e o `.env` existente são preservados ao reabrir o instalador para concluir ou reparar uma instalação.

Após a preparação, abra o Trace local, informe o código de ativação emitido no Central e entre com o administrador da empresa. Não use o perfil master do banco como login da interface.

## F. Verificar a instalação

Abra PowerShell como Administrador e cole:

```powershell
$installRoot = 'C:\DallogixTrace'
$envFile = Join-Path $installRoot '.env'
if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) { throw 'Arquivo .env ausente.' }
$requiredKeys = @('MYSQL_PASSWORD','MYSQL_ROOT_PASSWORD','TRACE_DEVICE_TOKEN','KEYENCE_DEVICE_TOKEN','KEYENCE_FTP_PASSWORD')
$envLines = Get-Content -LiteralPath $envFile
foreach ($key in $requiredKeys) {
    $valueLine = $envLines | Where-Object { $_ -match "^\s*$([regex]::Escape($key))=(.*)$" } | Select-Object -Last 1
    if (-not $valueLine -or [string]::IsNullOrWhiteSpace(($valueLine -split '=',2)[1].Trim().Trim('"').Trim("'"))) {
        Write-Output "${key}: AUSENTE OU VAZIA"
    } else {
        Write-Output "${key}: OK"
    }
}
docker compose --project-directory $installRoot --env-file $envFile --profile industrial config --quiet
if ($LASTEXITCODE -ne 0) { throw 'A configuração do Compose não passou.' }
docker compose --project-directory $installRoot --env-file $envFile --profile industrial ps --all
Invoke-RestMethod -Uri 'http://127.0.0.1:8080/api/health.php' -TimeoutSec 10 | Select-Object status, installation_mode, version, commit | Format-List
Invoke-RestMethod -Uri 'http://127.0.0.1:8080/api/ativacao_local.php' -TimeoutSec 10 | ConvertTo-Json -Depth 5
Get-ScheduledTask | Where-Object TaskName -match 'Trace' | Select-Object TaskName, State
Get-ChildItem -LiteralPath (Join-Path $installRoot 'armazenamento\backups') -Filter 'trace_local_*.sql*' -ErrorAction SilentlyContinue | Select-Object Name, Length, LastWriteTime
```

Confirme:

- Compose não retorna erro de configuração.
- MySQL, PHP, Nginx e Node-RED ficam `running`; o Node-RED precisa ficar `healthy`.
- A API local responde e a ativação local informa o estado esperado.
- As tarefas `Trace-Docker-Start`, `Dallogix Trace Atualizacao Estavel` e `Dallogix Trace Backup Diario` aparecem no Agendador.
- O Central mostra heartbeat recente da máquina e a versão instalada corresponde à release.
- Um backup diário aparece na pasta local. Em homologação, restaure uma cópia em um banco de teste para validar o processo de recuperação.

## G. Diagnóstico do erro de variáveis ausentes

Se aparecer uma das credenciais obrigatórias ausente, feche a interface e execute este diagnóstico. Ele mostra somente se cada chave existe, sem revelar os valores:

```powershell
$installRoot = 'C:\DallogixTrace'
$envFile = Join-Path $installRoot '.env'
if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) { throw 'O .env não existe; a configuração do instalador não terminou.' }
$requiredKeys = @('MYSQL_PASSWORD','MYSQL_ROOT_PASSWORD','TRACE_DEVICE_TOKEN','KEYENCE_DEVICE_TOKEN','KEYENCE_FTP_PASSWORD')
$envLines = Get-Content -LiteralPath $envFile
foreach ($key in $requiredKeys) {
    $matchesForKey = @($envLines | Where-Object { $_ -match "^\s*$([regex]::Escape($key))=(.*)$" })
    if ($matchesForKey.Count -ne 1 -or [string]::IsNullOrWhiteSpace(($matchesForKey[0] -split '=',2)[1].Trim().Trim('"').Trim("'"))) {
        Write-Output "${key}: AUSENTE, VAZIA OU DUPLICADA"
    } else {
        Write-Output "${key}: OK"
    }
}
docker compose --project-directory $installRoot --env-file $envFile --profile industrial config --quiet
```

Se alguma senha do banco estiver ausente e a máquina já tiver sido iniciada antes, **não gere outra senha, não apague o `.env` e não remova volumes**. A senha do `.env` precisa corresponder ao banco já inicializado. Restaure a cópia segura do `.env` ou chame a equipe que mantém o backup.

Para ler logs localmente sem compartilhar dados sensíveis:

```powershell
$installRoot = 'C:\DallogixTrace'
docker compose --project-directory $installRoot --env-file "$installRoot\.env" --profile industrial logs --no-color --tail=100 php mysql node-red keyence-worker
```

Remova tokens, endereços privados e informações de clientes antes de enviar qualquer trecho de log.

## Comandos que não devem ser usados como reparo

Não execute `docker compose down -v`, `docker volume prune`, exclusão de `C:\DallogixTrace\armazenamento`, nem substitua `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` em uma máquina que já tenha banco. Essas operações podem apagar ou tornar inacessível o histórico local.

## Referências oficiais

- [Instalação do WSL — Microsoft Learn](https://learn.microsoft.com/en-us/windows/wsl/install)
- [Instalação do Docker Desktop no Windows — Docker Docs](https://docs.docker.com/desktop/setup/install/windows-install/)
- [Comando `winget install` — Microsoft Learn](https://learn.microsoft.com/en-us/windows/package-manager/winget/install)
- [Release `v1.0.175` do Trace](https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/tag/v1.0.175)
