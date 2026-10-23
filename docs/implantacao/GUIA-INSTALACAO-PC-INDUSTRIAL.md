# Dallogix Trace — revisão e instalação no PC industrial

Revisão feita em 23/10/2026 sobre a falha do instalador `v1.0.175`, a release empresarial atual, a VM `trace-dallogix-prod` e o fluxo de instalação do PC industrial.

> **Não reutilize o `TraceSetup.exe` v1.0.175 que falhou.** Essa versão iniciou o Docker Compose sem entregar o `.env`, por isso o PC exibiu `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `TRACE_DEVICE_TOKEN` e `CAMERA_DEVICE_TOKEN` ausentes. A correção do instalador empresarial gera credenciais aleatórias em uma instalação realmente nova, preserva as senhas de um banco existente e passa explicitamente o diretório do projeto e o `.env` ao Compose. O fluxo protegido preserva `TRACE_COMMIT` e `TRACE_VERSION`, passa o `.env` ao Compose e valida o commit exato no health check. A partir da v1.0.180, use o artefato `TraceSetup-v1.0.180` somente depois que os jobs de build e deploy do GitHub terminarem com sucesso. O executável continua sem assinatura corporativa Authenticode; o SHA-256 confirma integridade do arquivo, não a identidade de quem o publicou.

## Resultado da revisão

| Prioridade | Constatação | Evidência e efeito |
|---|---|---|
| Assinatura do instalador | O instalador empresarial continua sem assinatura corporativa Authenticode. | O workflow empresarial calcula SHA-256, mas a assinatura requer um certificado de código da Dallogix. O arquivo não identifica criptograficamente o publicador. |
| Erro reproduzido no PC | A versão v1.0.175 tentou interpolar o Compose sem as quatro credenciais obrigatórias. | O instalador empresarial atual valida e gera os segredos quando não há banco anterior e sempre passa `--project-directory` e `--env-file` ao Compose. A regressão é verificada por `Test-InstallerEnv.ps1` no workflow Windows. |
| Proteção de banco existente | Não é seguro inventar senhas se o banco já foi inicializado. | O instalador recusa substituir `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD` quando detecta volume ou vestígios de uma instalação anterior. Restaure o `.env` seguro correspondente ao banco; não remova o volume. |
| Metadados do deploy | O servidor usa `sudo` para executar Docker. | `scripts/docker_compose.sh` preserva somente `TRACE_COMMIT` e `TRACE_VERSION` para que o health check e a página pública indiquem o commit efetivamente implantado. |
| Argumento de ambiente do deploy | O primeiro deploy da v1.0.177 fez backup do banco, mas parou antes do Compose porque `deploy_from_git.sh` omitiu o caminho do `.env` na chamada de `deploy_production.sh`. | A v1.0.178 passa o `.env` explicitamente e adiciona um teste funcional do fluxo protegido de deploy ao CI. A tentativa anterior não reiniciou os containers. |
| Publicação GitHub → VM | Cada tag semântica de release no histórico da branch `production` executa validações, compila o instalador e envia o bundle exato para a VM por uma chave SSH limitada ao deploy. | A VM não precisa ler o repositório privado. O aplicador remoto rejeita tags fora da branch de produção, mudanças não fast-forward e pacotes que não correspondem ao commit da tag; o deploy protegido verifica carregamentos ativos, faz backup, executa migrations e exige health check do commit publicado. |
| Endereço e versão Central | A instalação pública é a VM nova `trace-dallogix-prod`, IP `164.152.197.177`. | O teste de saúde de `https://trace.dallogix.com/api/health.php` deve mostrar `status=ok`, `php=true`, `mysql=true`, `installation_mode=central` e o commit da release aprovada. O domínio raiz `dallogix.com` não faz parte do deploy. |
| Release empresarial | O código oficial fica em `dallogixempresa-max/Dallogix-Trace-Code`. | `Build-TraceSetup.ps1` exige uma tag semântica exata. Use o artefato `TraceSetup-v1.0.178` do workflow empresarial; não use artefatos do repositório pessoal. |
| Atualização automática dos PCs | O instalador e as tarefas do atualizador ainda consultam o manifesto assinado em `VItoraaraujoo/Dallogix-Trace-System`; a release empresarial não publica esse manifesto nem o pacote assinado. | A atualização automática empresarial ainda não está migrada. Não troque a URL apenas para apontar ao repositório novo: é necessário migrar o fluxo de publicação e a chave privada correspondente à chave pública já instalada nos PCs, depois validar assinatura e download ponta a ponta. |
| Segredos locais | `.env` e `config\machine.json` contêm senhas e tokens. | Não encontrei etapa que aplique ACL restritiva a esses arquivos. Limite a leitura à conta técnica, Administradores e SYSTEM; não envie esses arquivos em chamados ou mensagens. |
| Atualização central | O instalador exige que `/api/health.php` responda com a versão e o commit exatos do pacote. | O servidor Central precisa estar publicado antes de instalar o PC. Uma versão diferente é recusada intencionalmente. |
| CLP físico | O assistente só habilita escrita física para o mapa reconhecido do INVT TS621, após confirmar o mapa e vincular um equipamento. | Sem esses dados, o Trace instala em modo sem escrita física. Isso não impede a instalação da interface, banco e sincronização. |
| Backup | A instalação registra uma tarefa diária de backup do banco às 02:00 e valida o SHA-256 do arquivo. | A tarefa está prevista no código; a instalação por si só não comprova que um backup foi restaurado com sucesso. Faça uma restauração de homologação antes de considerar a rotina comprovada. |

### Validação do artefato empresarial

- O workflow **Build Windows Installer** compila uma tag empresarial exata, analisa os scripts PowerShell, testa a criação das quatro variáveis do `.env`, confirma os parâmetros `--project-directory` e `--env-file`, inspeciona o executável e anexa o SHA-256.
- Baixe `TraceSetup-v1.0.180` em **Actions → execução da tag → Artifacts** após o workflow terminar com sucesso. Compare o SHA-256 do EXE com o arquivo `TraceSetup.sha256.txt` incluído no ZIP.
- O instalador é x64/Windows e inclui o runtime publicado do aplicativo. O tamanho aproximado de 52 MB vem do aplicativo auto-contido, WebView2/bootstrapper e componentes necessários; o arquivo não é só o código PHP.
- Não há certificado corporativo Authenticode. O artefato continua sujeito ao aviso do Windows para publicador desconhecido, mesmo que o hash corresponda.

## O que precisa estar pronto antes de instalar

1. O artefato `TraceSetup-v1.0.180` do workflow empresarial concluído com sucesso. O artefato continua sem Authenticode válido enquanto a empresa não configurar um certificado de assinatura.
2. A VM nova publicada em `https://trace.dallogix.com`, com `version` e `commit` iguais aos da release do instalador. Não prossiga até a verificação de saúde pública mostrar o commit de produção esperado.
3. Para ativar atualização automática em novos PCs, primeiro conclua a migração do manifesto e do pacote assinado para a publicação empresarial. Até essa migração, o atualizador continua dependente do repositório pessoal indicado no `.env`.
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

O último comando precisa listar uma tag de release aprovada. Uma tag semântica criada sobre a branch `production` aciona o workflow **Build Windows Installer**: ele valida os scripts, testa o contrato de deploy, compila `TraceSetup.exe`, calcula o SHA-256, guarda o artefato privado do Actions por 30 dias e, após os testes, envia a mesma tag para `trace.dallogix.com`. A VM confirma a versão implantada em `/api/health.php`. O executável não tem assinatura corporativa Authenticode. Não use uma tag do repositório pessoal para publicar código empresarial.

Para compilar localmente quando a tag estiver disponível, cole o bloco abaixo e informe a tag aprovada quando solicitado. Na compilação automatizada, abra **Actions → Build Windows Installer** depois de publicar a tag e baixe `TraceSetup-<tag>` em **Artifacts** quando todos os jobs terminarem com sucesso. O job `Deploy tagged release to trace.dallogix.com` só termina depois de validar o commit exato no health check público.

```powershell
$releaseTag = Read-Host 'Tag aprovada, por exemplo v1.0.180'
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

O build atual gera um executável **sem assinatura Authenticode**. O digest SHA-256 permite conferir que o arquivo baixado é o mesmo artefato produzido, mas não autentica quem publicou o instalador. Para remover esse bloqueio, configure um certificado de assinatura corporativo e assine o arquivo antes de distribuí-lo. O script de build já recusa arquivos alterados ou não rastreados antes de empacotar.

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

O repositório da empresa é privado. Em uma estação administrativa, entre no GitHub com uma conta autorizada, abra `dallogixempresa-max/Dallogix-Trace-Code` → **Actions** → **Build Windows Installer**, escolha a execução concluída para a tag aprovada e baixe o artefato `TraceSetup-<tag>`. Extraia `TraceSetup.exe` e `TraceSetup.sha256.txt` juntos para a pasta Downloads do PC industrial. O artefato expira após 30 dias; guarde a cópia aprovada no repositório interno de instaladores da empresa.

No PowerShell, confira a integridade e a assinatura:

```powershell
$installerPath = Join-Path $env:USERPROFILE 'Downloads\TraceSetup.exe'
$hashPath = Join-Path $env:USERPROFILE 'Downloads\TraceSetup.sha256.txt'
if (-not (Test-Path -LiteralPath $installerPath -PathType Leaf)) { throw 'TraceSetup.exe não foi encontrado.' }
if (-not (Test-Path -LiteralPath $hashPath -PathType Leaf)) { throw 'TraceSetup.sha256.txt não foi encontrado.' }
$expectedHash = ((Get-Content -LiteralPath $hashPath -Raw).Trim() -split '\s+')[0]
if ($expectedHash -notmatch '^[0-9a-fA-F]{64}$') { throw 'Arquivo SHA-256 inválido.' }
$actualHash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA256).Hash
if ($actualHash -ine $expectedHash) { throw 'O SHA-256 não corresponde. Não execute o arquivo; baixe novamente o artefato da execução aprovada.' }
Get-AuthenticodeSignature -FilePath $installerPath | Format-List Status, StatusMessage, SignerCertificate
```

O workflow registra se o executável tem assinatura Authenticode válida e avisa quando não tem. O digest confirma que o arquivo corresponde ao arquivo acompanhado pelo artefato, mas não substitui uma assinatura corporativa. **Não distribua o instalador para uso de produção até a Dallogix configurar e validar o certificado Authenticode.**

## E. Instalar e responder às perguntas do assistente

Só depois de a seção D terminar sem erro e a assinatura Authenticode retornar `Valid` com o certificado corporativo aprovado, execute:

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
