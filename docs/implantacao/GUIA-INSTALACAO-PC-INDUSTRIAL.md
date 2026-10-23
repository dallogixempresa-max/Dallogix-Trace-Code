# Dallogix Trace — auditoria e instalação no PC industrial

Revisão feita em 23/10/2026 sobre o código local, o instalador publicado `v1.0.175` e o fluxo de implantação do PC industrial.

> **Estado atual: não instale a release `v1.0.175` em um PC de produção.** O executável publicado não tem assinatura Authenticode e contém o erro de variáveis ausentes mostrado no PC. O código em `production` já corrige a geração das variáveis, passa o `.env` explicitamente ao Docker Compose e usa `https://trace.dallogix.com` como padrão. Essa correção está no commit `c48379506b6c05893a0201f985bb5dd5252ad43f` e já está na VM nova. Ainda falta publicar a VM no hostname `trace.dallogix.com` e gerar uma release Windows aprovada; o hostname público atualmente responde outro commit (`f924d10`).

## Resultado da revisão

| Prioridade | Constatação | Evidência e efeito |
|---|---|---|
| Bloqueio de distribuição | `TraceSetup.exe` v1.0.175 não tem assinatura Authenticode. | O arquivo publicado tem 52.226.062 bytes e SHA-256 `e4f1e456fb6c1ad77e62186116bdff1693e77dc007aa923ad513c14105d10f40`. A tabela de certificado PE está vazia. O GitHub informa o mesmo digest do arquivo, o que valida a integridade do download, mas não identifica o publicador do programa. |
| Bloqueio de instalação reproduzível | O executável v1.0.175 pode iniciar o Compose sem passar explicitamente o caminho do `.env`; isso produziu as mensagens de variáveis ausentes no PC. | O código `c483795` valida as quatro chaves necessárias e passa `--project-directory` e `--env-file`. A correção está no código da branch `production`, mas ainda não em um novo `TraceSetup.exe`. |
| `.env` incompleto | O instalador v1.0.175 não completa chaves vazias em um `.env` já existente. | O código `c483795` gera segredos criptograficamente aleatórios quando não há banco existente. Se encontra banco ou instalação anterior, não troca as senhas do banco: orienta a restaurar as credenciais do backup para preservar os dados. |
| Endereço de produção antigo | O executável v1.0.175 usava `https://trace.santocloud.com.br`. | O código atual define `https://trace.dallogix.com` como padrão. No momento desta revisão, o DNS público ainda aponta para o servidor anterior; a verificação do hostname responde o commit `f924d10`, não a VM nova (`c483795`). |
| Release do repositório da empresa | O repositório é `dallogixempresa-max/Dallogix-Trace-Code`; a branch `production` contém o código atualizado, mas não há tags de release nem workflow de compilação em `.github/workflows`. | `Build-TraceSetup.ps1` exige uma tag exata `vN.N.N`; ainda não existe uma tag aprovada. A release `v1.0.175` está no repositório separado `VItoraaraujoo/Dallogix-Trace-System`. |
| Publicação do atualizador | O instalador e o atualizador procuram o manifesto e os pacotes em `VItoraaraujoo/Dallogix-Trace-System`. | Esse repositório ainda serve a release `v1.0.175`, mas o proprietário é uma conta pessoal. Antes de transferir os artefatos para o repositório da empresa, atualize junto as URLs, a lista de origens permitidas, as assinaturas e o fluxo de release. |
| Segredos locais | `.env` e `config\machine.json` contêm senhas e tokens. | Não encontrei etapa que aplique ACL restritiva a esses arquivos. Limite a leitura à conta técnica, Administradores e SYSTEM; não envie esses arquivos em chamados ou mensagens. |
| Atualização central | O instalador exige que `/api/health.php` responda com a versão e o commit exatos do pacote. | O servidor Central precisa estar publicado antes de instalar o PC. Uma versão diferente é recusada intencionalmente. |
| CLP físico | O assistente só habilita escrita física para o mapa reconhecido do INVT TS621, após confirmar o mapa e vincular um equipamento. | Sem esses dados, o Trace instala em modo sem escrita física. Isso não impede a instalação da interface, banco e sincronização. |
| Backup | A instalação registra uma tarefa diária de backup do banco às 02:00 e valida o SHA-256 do arquivo. | A tarefa está prevista no código; a instalação por si só não comprova que um backup foi restaurado com sucesso. Faça uma restauração de homologação antes de considerar a rotina comprovada. |

### Artefato que foi verificado

- Release publicada: [`v1.0.175`](https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/tag/v1.0.175), commit `556ef8d0bbe781651fa3d3636730e5277b21e965`.
- `TraceSetup.exe`: 52.226.062 bytes; SHA-256 acima; formato PE x64/Windows e empacotado com Inno Setup 6.7.0; sem assinatura Authenticode.
- Pacote estável do atualizador: `trace-v1.0.175.tar.gz`; SHA-256 `72a2577b40eeafc0841f9a6feb05ee8636fc2d2d10a5ba49ffdf624a61976f09`. O manifesto assinado protege esse pacote, não o `TraceSetup.exe`.
- O arquivo foi baixado e inspecionado sem executar o instalador. Não foi possível compilar um novo `.exe` nesta estação: não há .NET SDK, PowerShell 7 nem `ISCC.exe`; o repositório da empresa também não tem tag de release.

## O que precisa estar pronto antes de instalar

1. Uma release aprovada e assinada do `TraceSetup.exe`, compilada a partir de uma tag do repositório da empresa.
2. A VM nova publicada em `https://trace.dallogix.com`, com `version` e `commit` iguais aos da release do instalador. O DNS público ainda aponta para a instalação anterior; não prossiga com a instalação do PC até a verificação de saúde pública mostrar o commit de produção esperado.
3. Manifesto estável e pacote assinado acessíveis no endereço oficial do atualizador.
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

O último comando precisa listar uma tag aprovada como `v1.0.176`. **Hoje o repositório consultado não tem tags; pare aqui até a equipe criar e publicar uma tag e o fluxo de release da empresa.** Não use a tag do repositório pessoal para compilar código que deveria vir do repositório da empresa.

Quando a tag estiver disponível, cole o bloco abaixo e informe a tag aprovada quando solicitado:

```powershell
$releaseTag = Read-Host 'Tag aprovada, por exemplo v1.0.176'
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

O build atual gera um executável **sem assinatura Authenticode**. Não distribua o resultado enquanto a assinatura corporativa não estiver configurada e `Get-AuthenticodeSignature` não retornar `Valid` com o certificado aprovado da Dallogix. O script também precisa ser ajustado para recusar arquivos não rastreados antes de empacotar.

## B. Conferir o servidor Central antes de preparar o PC

Em PowerShell, cole:

```powershell
$centralUrl = 'https://trace.dallogix.com'
$health = Invoke-RestMethod -Uri "$centralUrl/api/health.php" -TimeoutSec 20
$health | Select-Object status, installation_mode, version, commit | Format-List
```

Confirme que `status` é `ok`, `installation_mode` é `central`, e `version` e `commit` correspondem à release do instalador. O próprio instalador bloqueia a configuração se houver divergência.

Confira o manifesto atualmente configurado para o atualizador. Este endpoint ainda pertence ao repositório pessoal e não deve ser tratado como a distribuição oficial da empresa:

```powershell
$manifestUrl = 'https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/latest/download/manifest.json'
$manifest = Invoke-RestMethod -Uri $manifestUrl -TimeoutSec 20
$manifest | Select-Object channel, version, commit, artifact_url, sha256 | Format-List
```

O código atual exige canal `stable`, versão semântica, SHA-256, assinatura e pacote no caminho de release aprovado. O endereço acima ainda aponta para o repositório pessoal listado na tabela de auditoria.

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

Este bloco busca o `TraceSetup.exe` da release e o digest publicado pelo GitHub, calcula o hash local e bloqueia a execução se o arquivo estiver sem assinatura Authenticode válida. Informe a tag aprovada:

```powershell
$releaseTag = Read-Host 'Tag aprovada do instalador, por exemplo v1.0.176'
if ($releaseTag -notmatch '^v\d+\.\d+\.\d+$') { throw 'Tag inválida.' }
$releaseApi = "https://api.github.com/repos/VItoraaraujoo/Dallogix-Trace-System/releases/tags/$releaseTag"
$release = Invoke-RestMethod -Uri $releaseApi -Headers @{ 'User-Agent' = 'Dallogix-Trace-Installer' } -TimeoutSec 30
$asset = $release.assets | Where-Object name -eq 'TraceSetup.exe' | Select-Object -First 1
if (-not $asset -or $asset.digest -notmatch '^sha256:[0-9a-fA-F]{64}$') { throw 'A release não publicou TraceSetup.exe com digest SHA-256.' }
$expectedHash = $asset.digest.Substring(7)
$installerPath = Join-Path $env:USERPROFILE 'Downloads\TraceSetup.exe'
Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $installerPath -TimeoutSec 300
$actualHash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA256).Hash
if ($actualHash -ine $expectedHash) { throw 'O SHA-256 local não corresponde ao digest da release. Não execute o arquivo.' }
$signature = Get-AuthenticodeSignature -FilePath $installerPath
$signature | Format-List Status, StatusMessage, SignerCertificate
if ($signature.Status -ne 'Valid') { throw 'Instalador sem assinatura Authenticode válida. Não execute; solicite uma release assinada.' }
```

Na data desta revisão, a release `v1.0.175` falha corretamente nessa última verificação porque está sem assinatura.

## E. Instalar e responder às perguntas do assistente

Só depois de a seção D terminar sem erro, execute:

```powershell
Start-Process -FilePath $installerPath -Verb RunAs -Wait
```

O instalador exige interação para não inventar os vínculos e credenciais da máquina. Responda assim:

1. **URL HTTPS do servidor central:** `https://trace.dallogix.com`.
2. **Identificação da máquina:** um código único definido pela empresa, por exemplo `PC-INDUSTRIAL-001`.
3. **ID do equipamento:** deixe vazio para vincular a Dala depois. Se preencher, informe também o código e o token real do dispositivo `SERVER` emitidos no Trace Central. Não use token de exemplo.
4. **URL local do Trace:** mantenha `http://127.0.0.1:8080`, salvo se a equipe tiver definido outra porta.
5. **Scanner USB:** selecione o dispositivo identificado como Elgin EL8600. Se não souber qual é, escolha `0` e configure depois.
6. **CLP físico:** responda `S` somente quando a Dala estiver vinculada e o modelo/mapa do CLP tiver sido confirmado. Para o mapa incluído, o assistente exige `INVT TS621` e confirmação das bobinas. Sem confirmação, responda `N`; a instalação continua sem escrita física.
7. **Senha do Node-RED:** informe e confirme uma senha com pelo menos 15 caracteres e no máximo 72 bytes em UTF-8. Ela é digitada no prompt do PowerShell; não a coloque em um comando ou arquivo de texto.
8. Aguarde a construção dos containers e as migrations. Não feche o PowerShell durante esse processo.

O `.env` é criado localmente e não é incluído na release. Não copie o `.env` de outro PC: ele contém credenciais próprias daquele banco. Se o assistente detectar um banco existente sem as senhas correspondentes, pare e restaure o `.env` do backup seguro; não remova o volume MySQL.

Após a preparação, abra o Trace local, informe o código de ativação emitido no Central e entre com o administrador da empresa. Não use o perfil master do banco como login da interface.

## F. Verificar a instalação

Abra PowerShell como Administrador e cole:

```powershell
$installRoot = 'C:\DallogixTrace'
$envFile = Join-Path $installRoot '.env'
if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) { throw 'Arquivo .env ausente.' }
$requiredKeys = @('MYSQL_PASSWORD','MYSQL_ROOT_PASSWORD','TRACE_DEVICE_TOKEN','CAMERA_DEVICE_TOKEN')
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

Se aparecer `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `TRACE_DEVICE_TOKEN` ou `CAMERA_DEVICE_TOKEN` ausente, feche a interface e execute este diagnóstico. Ele mostra somente se cada chave existe, sem revelar os valores:

```powershell
$installRoot = 'C:\DallogixTrace'
$envFile = Join-Path $installRoot '.env'
if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) { throw 'O .env não existe; a configuração do instalador não terminou.' }
$requiredKeys = @('MYSQL_PASSWORD','MYSQL_ROOT_PASSWORD','TRACE_DEVICE_TOKEN','CAMERA_DEVICE_TOKEN')
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
docker compose --project-directory $installRoot --env-file "$installRoot\.env" --profile industrial logs --no-color --tail=100 php mysql node-red camera-worker
```

Remova tokens, endereços privados e informações de clientes antes de enviar qualquer trecho de log.

## Comandos que não devem ser usados como reparo

Não execute `docker compose down -v`, `docker volume prune`, exclusão de `C:\DallogixTrace\armazenamento`, nem substitua `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` em uma máquina que já tenha banco. Essas operações podem apagar ou tornar inacessível o histórico local.

## Referências oficiais

- [Instalação do WSL — Microsoft Learn](https://learn.microsoft.com/en-us/windows/wsl/install)
- [Instalação do Docker Desktop no Windows — Docker Docs](https://docs.docker.com/desktop/setup/install/windows-install/)
- [Comando `winget install` — Microsoft Learn](https://learn.microsoft.com/en-us/windows/package-manager/winget/install)
- [Release `v1.0.175` do Trace](https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/tag/v1.0.175)
