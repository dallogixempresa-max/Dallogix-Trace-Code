param([switch]$Preflight)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

# Atualizador nativo do PC Windows. Deve rodar por tarefa técnica, nunca pela
# conta restrita do operador.
$InstallRoot = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
$DatabaseVolumeGuard = Join-Path $PSScriptRoot 'TraceDatabaseVolume.ps1'
if (-not (Test-Path -LiteralPath $DatabaseVolumeGuard -PathType Leaf)) { throw "Protecao do volume MySQL ausente: $DatabaseVolumeGuard" }
. $DatabaseVolumeGuard
$script:UpdaterScriptPath = Join-Path $PSScriptRoot 'TraceUpdater.ps1'
$ComposeProfile = @('--profile', 'industrial')
# O PC industrial tem memória limitada. O Compose padrão constrói várias
# imagens em paralelo e pode matar o MySQL com código 137 durante a atualização.
$env:COMPOSE_PARALLEL_LIMIT = '1'
$StateRoot = Join-Path $InstallRoot "armazenamento\updates"
$BackupRoot = Join-Path $StateRoot "backups"
$LogRoot = Join-Path $InstallRoot "armazenamento\logs"
$ErrorLogPath = Join-Path $LogRoot "update-stable-errors.log"
$ComposeLogPath = Join-Path $LogRoot "update-compose.log"
$MaintenanceFile = Join-Path $InstallRoot "armazenamento\.maintenance"

# Inicie o diagnóstico antes das validações. Tarefas agendadas que não
# conseguem acessar o Docker devem deixar a causa registrada no disco.
New-Item -ItemType Directory -Force -Path $LogRoot | Out-Null
Start-Transcript -Path (Join-Path $LogRoot "update-stable.log") -Append | Out-Null

function Append-UpdateLog([string]$Path, [string]$Content) {
    try {
        [IO.File]::AppendAllText($Path, $Content + [Environment]::NewLine, (New-Object System.Text.UTF8Encoding($false)))
    } catch {
        Write-Warning ("Nao foi possivel gravar o log " + $Path + ": " + $_.Exception.Message)
    }
}

function Fail-Preflight([string]$Message) {
    try {
        Append-UpdateLog $ErrorLogPath ("$(Get-Date -Format o) Preflight: " + $Message)
        Write-Error $Message
    } finally {
        try { Stop-Transcript | Out-Null } catch { }
    }
    throw $Message
}

$createdMaintenance = $false
$keepMaintenance = $false
$EnvPath = Join-Path $InstallRoot ".env"
if (-not (Test-Path -LiteralPath $EnvPath -PathType Leaf)) { Fail-Preflight "Arquivo .env nao encontrado." }
if (Test-Path $EnvPath) {
    Get-Content $EnvPath | Where-Object { $_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$' } | ForEach-Object {
        $name = $Matches[1]; $value = $Matches[2].Trim().Trim('"').Trim("'")
        if (-not (Get-Item "Env:$name" -ErrorAction SilentlyContinue)) { Set-Item "Env:$name" $value }
    }
}
$ManifestUrl = $env:UPDATE_MANIFEST_URL
$PublicKey = $env:UPDATE_PUBLIC_KEY_FILE
$Channel = if ($env:UPDATE_CHANNEL) { $env:UPDATE_CHANNEL } else { "stable" }
if (-not $ManifestUrl -or -not $PublicKey) { Fail-Preflight "Defina UPDATE_MANIFEST_URL e UPDATE_PUBLIC_KEY_FILE no .env." }
if ($ManifestUrl -notmatch '^https://') { Fail-Preflight "O manifesto deve usar HTTPS." }
if (-not (Test-Path $PublicKey)) { Fail-Preflight "Chave pública não encontrada: $PublicKey" }
$dockerBin = Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'
if (Test-Path -LiteralPath (Join-Path $dockerBin 'docker.exe') -PathType Leaf) {
    $env:PATH = "$dockerBin;$env:PATH"
}
if (-not (Get-Command docker.exe -ErrorAction SilentlyContinue)) { Fail-Preflight "Docker Desktop não está disponível." }
$OpenSsl = Get-Command openssl.exe -ErrorAction SilentlyContinue
if (-not $OpenSsl) {
    $candidate = Join-Path $env:ProgramFiles "Git\usr\bin\openssl.exe"
    if (Test-Path -LiteralPath $candidate -PathType Leaf) { $OpenSsl = $candidate }
}
if (-not $OpenSsl) { Fail-Preflight "OpenSSL do Git for Windows e necessario para validar a assinatura." }
if (-not (Get-Command tar.exe -ErrorAction SilentlyContinue)) { Fail-Preflight "tar.exe é necessário para extrair o pacote." }
$GitBash = Join-Path $env:ProgramFiles "Git\bin\bash.exe"
if (-not (Test-Path -LiteralPath $GitBash -PathType Leaf)) { Fail-Preflight "Git for Windows e necessario para backup e migrations." }

New-Item -ItemType Directory -Force -Path (Join-Path $StateRoot "releases"), $BackupRoot | Out-Null
$Lock = [System.Threading.Mutex]::new($false, 'Global\DallogixTraceStableUpdater')
$hasLock = $false
$rollbackRequired = $false
$stackStopped = $false
$desktopStopped = $false
$desktopRestartScheduled = $false
$desktopUser = $null
$previousArchive = $null
$databaseBackup = $null
$work = $null

function Stop-TraceDesktop {
    $processes = @(Get-Process -Name 'DallogixTrace' -ErrorAction SilentlyContinue)
    if ($processes.Count -eq 0) { return }
    $desktopUsers = foreach ($process in $processes) {
        $desktopProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $($process.Id)"
        $owner = Invoke-CimMethod -InputObject $desktopProcess -MethodName GetOwner
        if ($owner.ReturnValue -ne 0 -or [string]::IsNullOrWhiteSpace($owner.User)) {
            throw 'Nao foi possivel identificar a conta da interface; atualizacao adiada para manter o quiosque aberto.'
        }
        if ([string]::IsNullOrWhiteSpace($owner.Domain)) { $owner.User } else { "$($owner.Domain)\$($owner.User)" }
    }
    $uniqueDesktopUsers = @($desktopUsers | Sort-Object -Unique)
    if ($uniqueDesktopUsers.Count -ne 1) {
        throw 'A interface esta aberta por mais de uma conta; atualizacao adiada para nao deixar sessoes sem quiosque.'
    }
    $script:desktopUser = $uniqueDesktopUsers[0]
    foreach ($process in $processes) {
        Stop-Process -Id $process.Id -Force -ErrorAction Stop
    }
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (@(Get-Process -Name 'DallogixTrace' -ErrorAction SilentlyContinue).Count -eq 0) {
            $script:desktopStopped = $true
            return
        }
        Start-Sleep -Seconds 1
    }
    throw 'A interface DallogixTrace.exe permaneceu aberta; a atualizacao foi adiada para nao substituir arquivos em uso.'
}

function Schedule-TraceDesktopRestart {
    if (-not $desktopStopped -or $desktopRestartScheduled) { return }
    if ([string]::IsNullOrWhiteSpace($desktopUser)) {
        Append-UpdateLog $ErrorLogPath 'Interface desktop nao reiniciada: nenhuma sessao interativa encontrada.'
        return
    }
    $desktop = Join-Path $InstallRoot 'DallogixTrace.exe'
    if (-not (Test-Path -LiteralPath $desktop -PathType Leaf)) {
        Append-UpdateLog $ErrorLogPath "Interface desktop nao reiniciada: executavel ausente em $desktop."
        return
    }
    $taskName = 'Dallogix Trace Reiniciar Interface'
    $action = New-ScheduledTaskAction -Execute $desktop -WorkingDirectory $InstallRoot
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(15)
    $principal = New-ScheduledTaskPrincipal -UserId $desktopUser -LogonType Interactive -RunLevel Limited
    # O executavel e a interface do quiosque permanecem ativos. Limite zero
    # evita que o Agendador encerre a tela cinco minutos apos uma atualizacao.
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Seconds 0)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName
    $script:desktopRestartScheduled = $true
    Append-UpdateLog $ComposeLogPath "Interface desktop agendada para reinicio na sessao $desktopUser."
}

function Ensure-TraceShortcuts {
    $targetPath = Join-Path $InstallRoot 'DallogixTrace.exe'
    $iconPath = Join-Path $InstallRoot 'trace.ico'
    if (-not (Test-Path -LiteralPath $targetPath -PathType Leaf)) {
        throw "Executavel do Trace ausente ao configurar atalhos: $targetPath"
    }
    if (-not (Test-Path -LiteralPath $iconPath -PathType Leaf)) {
        throw "Logo do Trace ausente ao configurar atalhos: $iconPath"
    }

    $shortcutDirectories = @(
        (Join-Path $env:PUBLIC 'Desktop'),
        (Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs')
    ) | Select-Object -Unique
    $shell = New-Object -ComObject WScript.Shell
    foreach ($directory in $shortcutDirectories) {
        New-Item -ItemType Directory -Force -Path $directory | Out-Null
        $shortcutPath = Join-Path $directory 'Dallogix Trace.lnk'
        $shortcut = $shell.CreateShortcut($shortcutPath)
        $shortcut.TargetPath = $targetPath
        $shortcut.WorkingDirectory = $InstallRoot
        $shortcut.IconLocation = "$iconPath,0"
        $shortcut.Description = 'Dallogix Trace'
        $shortcut.Save()
    }
}

function Write-AtomicTextFile([string]$Path, [string]$Content) {
    $temporaryPath = "$Path.tmp-$([guid]::NewGuid().ToString('N'))"
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    try {
        [IO.File]::WriteAllText($temporaryPath, $Content, $utf8)
        # Move-Item com -Force substitui o destino no mesmo volume sem a
        # sobrecarga File.Replace que o Windows PowerShell interpreta com
        # backup nulo e rejeita como caminho inválido.
        Move-Item -LiteralPath $temporaryPath -Destination $Path -Force
    } finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Get-UpdateRetentionCount([string]$Name, [int]$Default) {
    $raw = [Environment]::GetEnvironmentVariable($Name)
    $parsed = 0
    if ([int]::TryParse($raw, [ref]$parsed) -and $parsed -ge 1) { return $parsed }
    return $Default
}

function Invoke-TraceStoragePruning([string]$CurrentVersion) {
    Append-UpdateLog $ComposeLogPath "Retencao do atualizador iniciada para $CurrentVersion."
    $keepReleases = Get-UpdateRetentionCount 'TRACE_UPDATE_KEEP_RELEASES' 3
    $keepBackups = Get-UpdateRetentionCount 'TRACE_UPDATE_KEEP_BACKUPS' 3
    $releaseRoot = Join-Path $StateRoot 'releases'
    $backupRoot = Join-Path $StateRoot 'backups'
    $releaseDirs = @(Get-ChildItem -LiteralPath $releaseRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^v\d+\.\d+\.\d+$' } |
        Sort-Object @{ Expression = { [version]($_.Name.Substring(1)) }; Descending = $true })
    $keep = @{}
    $keep[$CurrentVersion] = $true
    foreach ($release in ($releaseDirs | Select-Object -First $keepReleases)) { $keep[$release.Name] = $true }
    $removedReleases = 0
    foreach ($release in $releaseDirs) {
        if ($keep.ContainsKey($release.Name)) { continue }
        try {
            Remove-Item -LiteralPath $release.FullName -Recurse -Force -ErrorAction Stop
            if (-not (Test-Path -LiteralPath $release.FullName)) { $removedReleases++ }
        } catch {
            Append-UpdateLog $ErrorLogPath "Falha ao remover release antiga $($release.Name): $($_.Exception.Message)"
        }
    }
    $backupFiles = @(Get-ChildItem -LiteralPath $backupRoot -File -Filter 'pre-*.tar.gz' -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending)
    $removedBackups = 0
    foreach ($backup in ($backupFiles | Select-Object -Skip $keepBackups)) {
        Remove-Item -LiteralPath $backup.FullName -Force -ErrorAction SilentlyContinue
        if (-not (Test-Path -LiteralPath $backup.FullName)) { $removedBackups++ }
    }
    $removedTemporary = 0
    foreach ($temporary in @(Get-ChildItem -LiteralPath $StateRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like '.rollback-*' -or $_.Name -like '.staging-*' })) {
        if ($work -and $temporary.FullName -eq $work) { continue }
        try {
            Remove-Item -LiteralPath $temporary.FullName -Recurse -Force -ErrorAction Stop
            if (-not (Test-Path -LiteralPath $temporary.FullName)) { $removedTemporary++ }
        } catch {
            Append-UpdateLog $ErrorLogPath "Falha ao remover sobra temporaria $($temporary.Name): $($_.Exception.Message)"
        }
    }
    Append-UpdateLog $ComposeLogPath ("Retencao do atualizador: $removedReleases releases, $removedBackups backups e $removedTemporary sobras temporarias removidos; preservados $keepReleases releases e $keepBackups backups.")
}

function Download-UpdateArtifact([string]$Uri, [string]$Path, [hashtable]$Headers) {
    # Invoke-WebRequest tenta ler o buffer do console para a barra de progresso
    # no Windows PowerShell 5.1. Em tarefas agendadas e sessões SSH isso pode
    # falhar com Win32 0x5 antes de gravar o pacote. WebClient faz o download
    # diretamente no arquivo e continua funcionando sem console interativo.
    $client = New-Object System.Net.WebClient
    try {
        foreach ($key in $Headers.Keys) {
            $client.Headers[$key] = [string]$Headers[$key]
        }
        $client.DownloadFile($Uri, $Path)
    } finally {
        $client.Dispose()
    }
}

function Invoke-Tar([string[]]$Arguments) {
    $runId = [guid]::NewGuid().ToString('N')
    $stdoutPath = Join-Path $env:TEMP ("trace-tar-" + $runId + ".out")
    $stderrPath = Join-Path $env:TEMP ("trace-tar-" + $runId + ".err")
    $process = $null
    try {
        # Start-Process concatena arrays em uma única linha. Preserve aspas
        # nos caminhos que contêm espaços (por exemplo, PDFs do projeto),
        # caso contrário o tar recebe vários argumentos inexistentes.
        $argumentLine = ($Arguments | ForEach-Object {
            $argument = [string]$_
            if ($argument -match '[\s"]') {
                '"' + ($argument -replace '"', '\"') + '"'
            } else { $argument }
        }) -join ' '
        $process = Start-Process -FilePath 'tar.exe' -ArgumentList $argumentLine -Wait -PassThru -NoNewWindow -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
        if (Test-Path -LiteralPath $stderrPath) {
            Append-UpdateLog $ComposeLogPath ((Get-Content -LiteralPath $stderrPath -Raw))
        }
        if (Test-Path -LiteralPath $stdoutPath) {
            Append-UpdateLog $ComposeLogPath ((Get-Content -LiteralPath $stdoutPath -Raw))
        }
        return $process.ExitCode
    } finally {
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

function Format-ProcessArguments([string[]]$Arguments) {
    return ($Arguments | ForEach-Object {
        $argument = [string]$_
        if ($argument -match '[\s"]') {
            # Uma barra antes da aspa preserva a aspa dentro do argumento no
            # parser de linha de comando do Windows PowerShell 5.1. Duas
            # barras fazem o sh receber a aspa como literal e quebram a
            # consulta de pré-verificação do MySQL.
            '"' + ($argument -replace '"', '\"') + '"'
        } else { $argument }
    }) -join ' '
}

function Invoke-External([string]$FilePath, [string[]]$Arguments, [string]$InputText, [int]$TimeoutMilliseconds = 0) {
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $FilePath
    $startInfo.Arguments = Format-ProcessArguments $Arguments
    # ProcessStartInfo não herda de forma confiável o diretório atual do
    # Windows PowerShell 5.1. Compose e os scripts Bash precisam resolver os
    # arquivos relativos dentro da instalação do Trace.
    $startInfo.WorkingDirectory = (Get-Location).Path
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.RedirectStandardInput = ($null -ne $InputText)
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    try {
        [void]$process.Start()
        if ($null -ne $InputText) {
            $process.StandardInput.Write($InputText)
            $process.StandardInput.Close()
        }
        if ($TimeoutMilliseconds -gt 0) {
            if (-not $process.WaitForExit($TimeoutMilliseconds)) {
                try { & taskkill.exe /PID $process.Id /T /F 2>$null | Out-Null } catch { }
                return [pscustomobject]@{
                    ExitCode = -1
                    Stdout = ''
                    Stderr = "Processo excedeu o limite de ${TimeoutMilliseconds}ms."
                }
            }
            $stdout = $process.StandardOutput.ReadToEnd()
            $stderr = $process.StandardError.ReadToEnd()
            return [pscustomobject]@{
                ExitCode = $process.ExitCode
                Stdout = $stdout
                Stderr = $stderr
            }
        }
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            Stdout = $stdout
            Stderr = $stderr
        }
    } finally {
        $process.Dispose()
    }
}

function Invoke-GitBash([string[]]$Arguments) {
    $result = Invoke-External $GitBash $Arguments $null
    if ($result.Stdout) { Append-UpdateLog $ComposeLogPath $result.Stdout }
    if ($result.Stderr) { Append-UpdateLog $ComposeLogPath $result.Stderr }
    return $result
}

function Invoke-DockerCompose([string[]]$Arguments) {
    if ($Arguments -contains 'up') {
        Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker | Out-Null
    }
    $result = Invoke-External 'docker.exe' (@('compose') + $Arguments) $null
    Append-UpdateLog $ComposeLogPath ("$(Get-Date -Format o) docker compose " + ($Arguments -join ' '))
    if ($result.Stdout) { Append-UpdateLog $ComposeLogPath $result.Stdout }
    if ($result.Stderr) { Append-UpdateLog $ComposeLogPath $result.Stderr }
    return $result.ExitCode
}

function Ensure-TraceDatabaseVolumeGuard {
    if (Test-Path -LiteralPath $MaintenanceFile) {
        throw 'A instalacao esta marcada para manutencao; nao e seguro registrar a identidade do volume agora.'
    }
    $snapshot = Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot
    if (Read-TraceMySqlVolumeMarker) {
        Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker | Out-Null
        return
    }

    # Uma instalacao anterior a esta protecao pode ainda nao ter o marcador.
    # A existencia do volume e verificada antes de permitir que o Compose o use.
    $startResult = Invoke-External 'docker.exe' (@('compose') + $ComposeProfile + @('up', '-d', 'mysql')) $null
    Append-UpdateLog $ComposeLogPath ("$(Get-Date -Format o) inicializacao controlada do MySQL para validar volume existente $($snapshot.Name)")
    if ($startResult.Stdout) { Append-UpdateLog $ComposeLogPath $startResult.Stdout }
    if ($startResult.Stderr) { Append-UpdateLog $ComposeLogPath $startResult.Stderr }
    if ($startResult.ExitCode -ne 0) { throw "Nao foi possivel iniciar o MySQL no volume existente (codigo $($startResult.ExitCode))." }

    $mysqlReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $ping = Invoke-External 'docker.exe' (@('compose') + $ComposeProfile + @('exec', '-T', 'mysql', 'sh', '-lc', 'mysqladmin ping -u$MYSQL_USER -p$MYSQL_PASSWORD --silent')) $null
        if ($ping.ExitCode -eq 0) { $mysqlReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $mysqlReady) { throw 'MySQL nao ficou pronto para validar o esquema existente; marcador nao foi gravado.' }
    if (-not (Test-TraceMySqlApplicationSchema -InstallRoot $InstallRoot)) {
        throw 'O volume existe, mas a tabela carregamentos nao foi confirmada. Atualizacao e inicializacao do sistema bloqueadas; nenhum volume novo foi criado.'
    }

    Register-TraceMySqlVolumeAfterDatabaseCheck -InstallRoot $InstallRoot | Out-Null
    Append-UpdateLog $ComposeLogPath ("$(Get-Date -Format o) identidade do volume MySQL registrada apos confirmar o esquema da aplicacao: $($snapshot.Name)")
}

function Ensure-TraceDatabaseBackupSchedule {
    $taskName = 'Dallogix Trace Backup Diario'
    $backupScript = Join-Path $InstallRoot 'implantacao\windows\TraceDatabaseBackup.ps1'
    if (-not (Test-Path -LiteralPath $backupScript -PathType Leaf)) { throw "Script de backup diario ausente: $backupScript" }
    $existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    $hasExpectedAction = $false
    $hasDailyAtTwo = $false
    $hasRetryPolicy = $false
    if ($existing) {
        $actionText = ($existing.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' '
        $hasExpectedAction = $actionText.Contains($backupScript)
        if (-not $hasExpectedAction) { throw "A tarefa $taskName ja existe com outro destino." }
        $hasRetryPolicy = [int]$existing.Settings.RestartCount -ge 3 -and
            $existing.Settings.RestartInterval -eq (New-TimeSpan -Minutes 10)
        foreach ($trigger in @($existing.Triggers)) {
            if ([string]$trigger.CimClass.CimClassName -eq 'MSFT_TaskDailyTrigger' -and
                ([datetime]$trigger.StartBoundary).TimeOfDay -eq (New-TimeSpan -Hours 2)) {
                $hasDailyAtTwo = $true
            }
        }
    }
    if ($hasExpectedAction -and $hasDailyAtTwo -and $hasRetryPolicy) { return }

    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -InstallRoot `"$InstallRoot`""
    $trigger = New-ScheduledTaskTrigger -Daily -At '02:00'
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Cria e valida diariamente uma copia do banco fora do volume MySQL.' -Force | Out-Null
    Append-UpdateLog $ComposeLogPath 'Agendamento reparado: backup do banco configurado para 02:00, com ate 3 tentativas a cada 10 minutos.'
}

function Start-TraceStack([switch]$Build) {
    if ($Build) {
        $buildExit = Invoke-DockerCompose ($ComposeProfile + @('build'))
        if ($buildExit -ne 0) { throw "A construcao das imagens nao foi concluida (codigo $buildExit)." }
    }
    $startExit = Invoke-DockerCompose ($ComposeProfile + @('up', '-d'))
    if ($startExit -ne 0) { throw "A nova versao nao iniciou (codigo $startExit)." }
}

function Ensure-TraceUpdaterSchedule {
    # O instalador registra os dois gatilhos, mas uma atualização substitui os
    # arquivos sem executar novamente o instalador. Repare a tarefa aqui para
    # que releases antigas também passem a iniciar após o logon, sem depender
    # de um caminho em Downloads ou de uma ação manual no PC.
    $taskName = 'Dallogix Trace Atualizacao Estavel'
    $existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    $hasDailyAtThree = $false
    $hasLogon = $false
    $hasRetryPolicy = $false
    if ($existing) {
        $hasRetryPolicy = [int]$existing.Settings.RestartCount -ge 12 -and
            $existing.Settings.RestartInterval -eq (New-TimeSpan -Minutes 5)
        foreach ($trigger in @($existing.Triggers)) {
            $className = [string]$trigger.CimClass.CimClassName
            if ($className -eq 'MSFT_TaskDailyTrigger' -and
                ([datetime]$trigger.StartBoundary).TimeOfDay -eq (New-TimeSpan -Hours 3)) {
                $hasDailyAtThree = $true
            }
            if ($className -eq 'MSFT_TaskLogonTrigger') { $hasLogon = $true }
        }
    }
    if ($hasDailyAtThree -and $hasLogon -and $hasRetryPolicy) { return }

    $scriptPath = $script:UpdaterScriptPath
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""
    $dailyTrigger = New-ScheduledTaskTrigger -Daily -At '03:00'
    $logonTrigger = New-ScheduledTaskTrigger -AtLogOn -RandomDelay (New-TimeSpan -Minutes 10)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 2) -RestartCount 12 -RestartInterval (New-TimeSpan -Minutes 5)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger @($dailyTrigger, $logonTrigger) -Principal $principal -Settings $settings -Description 'Instala somente releases assinadas sem carregamento ativo.' -Force | Out-Null
    Append-UpdateLog $ComposeLogPath 'Agendamento reparado: atualização estável configurada para 03:00, após o logon e com 12 tentativas a cada 5 minutos quando uma execução falhar.'
}

try { $hasLock = $Lock.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $hasLock = $true }
try {
    if (-not $hasLock) { throw "Já existe uma atualização em execução." }
    Ensure-TraceUpdaterSchedule
    $dockerInfo = $null
    $dockerOs = ''
    $dockerDetail = ''
    for ($dockerAttempt = 1; $dockerAttempt -le 12 -and $dockerOs -ne 'linux'; $dockerAttempt++) {
        $dockerInfo = Invoke-External 'docker.exe' @('info', '--format', '{{.OSType}}') $null 10000
        $dockerOs = ([string]$dockerInfo.Stdout).Trim()
        if ($dockerInfo.ExitCode -ne 0 -or $dockerOs -ne 'linux') {
            $dockerDetail = ((@($dockerInfo.Stdout, $dockerInfo.Stderr) | Where-Object { $_ -and $_.Trim() }) -join ' ').Trim()
            if (-not $dockerDetail) { $dockerDetail = 'docker info nao retornou detalhes.' }
            if ($dockerAttempt -lt 12) { Start-Sleep -Seconds 5 }
        }
    }
    if ($dockerOs -ne 'linux') {
        throw "A tarefa agendada nao consegue acessar o mecanismo Linux do Docker Desktop apos 12 tentativas. Detalhe: $dockerDetail"
    }
    $headers = @{}
    if ($env:UPDATE_MANIFEST_TOKEN) { $headers.Authorization = "Bearer $($env:UPDATE_MANIFEST_TOKEN)" }
    $manifest = $null
    $manifestError = $null
    for ($attempt = 1; $attempt -le 3 -and -not $manifest; $attempt++) {
        try {
            $manifest = Invoke-RestMethod -Uri $ManifestUrl -Headers $headers -TimeoutSec 20
        } catch {
            $manifestError = $_
            if ($attempt -lt 3) { Start-Sleep -Seconds ([int][math]::Pow(2, $attempt)) }
        }
    }
    if (-not $manifest) {
        if ($manifestError) { throw $manifestError }
        throw 'Nao foi possivel ler o manifesto de atualizacao.'
    }
    $manifestChannel = if ($manifest.channel) { $manifest.channel } else { $Channel }
    if ($manifestChannel -ne $Channel) { throw "Manifesto de canal diferente do configurado." }
    foreach ($field in @("version", "artifact_url", "sha256", "signature")) { if (-not $manifest.$field) { throw "Manifesto sem $field." } }
    if ($manifest.artifact_url -notmatch '^https://') { throw "O pacote deve usar HTTPS." }
    if ($manifest.version -notmatch '^v\d+\.\d+\.\d+$') { throw "Versão inválida: esperado vN.N.N." }
    if ($manifest.sha256 -notmatch '^[0-9a-fA-F]{64}$') { throw "SHA-256 inválido." }
    $failedMarker = Join-Path $StateRoot ("failed-" + $manifest.version)
    if (Test-Path $failedMarker) { throw "A versão $($manifest.version) já falhou; revise a instalação e remova $failedMarker para autorizar nova tentativa." }

    $work = Join-Path $StateRoot (".staging-" + [guid]::NewGuid().ToString("N"))
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    $payload = Join-Path $work "payload"
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($payload, "$($manifest.version)`n$($manifest.artifact_url)`n$($manifest.sha256.ToLower())`n", $utf8)
    $sig = Join-Path $work "signature.bin"
    [IO.File]::WriteAllBytes($sig, [Convert]::FromBase64String($manifest.signature))
    $opensslExe = if ($OpenSsl -is [string]) { $OpenSsl } else { $OpenSsl.Source }
    & $opensslExe dgst -sha256 -verify $PublicKey -signature $sig $payload | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Assinatura do pacote rejeitada." }

    $current = Join-Path $StateRoot "current_version"
    if (-not (Test-Path -LiteralPath $current -PathType Leaf)) {
        throw "Versao instalada nao registrada. Execute Register-TraceUpdater.ps1 a partir de um instalador de release."
    }
    $installedVersion = (Get-Content -LiteralPath $current -Raw).Trim()
    if ($installedVersion -notmatch '^v\d+\.\d+\.\d+$') { throw "Versao instalada invalida." }
    if ($Preflight) {
        [IO.File]::WriteAllText((Join-Path $StateRoot 'preflight-ok.txt'), (Get-Date -Format o))
        Write-Host 'Preflight: Docker Linux, chave publica, Git Bash e versao local acessiveis pela tarefa agendada.'
        return
    }
    Set-Location -LiteralPath $InstallRoot
    Ensure-TraceDatabaseVolumeGuard
    Ensure-TraceDatabaseBackupSchedule
    # A retenção também roda quando não há release nova, mas só depois de
    # confirmar que o banco esperado existe e que seus backups estão preservados.
    Invoke-TraceStoragePruning $installedVersion
    if ([version]($manifest.version.Substring(1)) -le [version]($installedVersion.Substring(1))) {
        Write-Output "Sem release nova: instalada $installedVersion; publicada $($manifest.version)."
        return
    }
    $failedMarker = Join-Path $StateRoot ("failed-" + $manifest.version)
    if (Test-Path -LiteralPath $failedMarker) { throw "A versao $($manifest.version) falhou anteriormente e exige revisao tecnica." }

    Set-Location $InstallRoot
    if (Test-Path -LiteralPath $MaintenanceFile) { throw "O sistema ja esta em manutencao. Atualizacao adiada." }
    Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker | Out-Null
    New-Item -ItemType File -Force -Path $MaintenanceFile | Out-Null
    $createdMaintenance = $true
    $sql = "SELECT COUNT(*) FROM carregamentos WHERE state IN ('PREPARANDO','CARREGANDO','PAUSADO','FINALIZANDO','EMERGENCIA');"
    # Pass the query through stdin. Windows PowerShell can strip the nested
    # quotes when a SQL string is assembled into `sh -lc ...`, leaving mysql
    # with an empty or malformed `-e` argument (ERROR 1064). The container
    # keeps reading its credentials and database name from its own environment.
    $activeResult = Invoke-External 'docker.exe' (@('compose') + $ComposeProfile + @('exec', '-T', 'mysql', 'sh', '-lc', 'mysql -N -B -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE')) $sql
    $active = ([string]$activeResult.Stdout).Trim()
    if ($activeResult.ExitCode -ne 0 -or $active -notmatch '^\d+$') { throw "Nao foi possivel confirmar os carregamentos ativos. Atualizacao adiada." }
    if ([int]$active -gt 0) { throw "Atualizacao adiada: existe carregamento ativo ou em intervencao." }
    # A interface WebView2 mantém o executável aberto e impede a substituição
    # do binário durante uma atualização. Só a encerre depois de confirmar
    # que não há carregamento ativo; a interface será reaberta na sessão
    # interativa após o healthcheck da nova versão.
    Stop-TraceDesktop

    # O workflow de release publica tar.gz (o formato é o mesmo usado pelo
    # atualizador Linux); não tente abrir esses bytes como ZIP.
    $artifact = Join-Path $work ("trace-" + $manifest.version + ".tar.gz")
    Download-UpdateArtifact $manifest.artifact_url $artifact $headers
    if ((Get-FileHash $artifact -Algorithm SHA256).Hash.ToLower() -ne $manifest.sha256.ToLower()) { throw "Integridade do pacote rejeitada." }
    $release = Join-Path $StateRoot ("releases\" + $manifest.version)
    if (Test-Path $release) { Remove-Item $release -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $release | Out-Null
    $extractExit = Invoke-Tar @('-xzf', $artifact, '-C', $release)
    if ($extractExit -ne 0) { throw "Não foi possível extrair o pacote (codigo $extractExit)." }
    $source = if (Test-Path (Join-Path $release "trace\docker-compose.yml")) { Join-Path $release "trace" } else { $release }
    if (-not (Test-Path (Join-Path $source "docker-compose.yml")) -or
        -not (Test-Path (Join-Path $source "scripts\migrate.sh"))) {
        throw "Pacote sem docker-compose.yml ou scripts/migrate.sh."
    }
    $releaseCommitFile = Join-Path $source 'trace-build-commit.txt'
    if (-not (Test-Path -LiteralPath $releaseCommitFile -PathType Leaf)) { throw 'Pacote sem identificacao do commit aprovado.' }
    $releaseCommit = [IO.File]::ReadAllText($releaseCommitFile).Trim().ToLowerInvariant()
    if ($releaseCommit -notmatch '^[0-9a-f]{40}$') { throw 'Commit do pacote invalido.' }
    $centralUrl = ([string]$env:TRACE_CENTRAL_URL).Trim().Trim('"').Trim("'").TrimEnd('/')
    $centralUri = $null
    if (-not [Uri]::TryCreate($centralUrl, [UriKind]::Absolute, [ref]$centralUri) -or
        $centralUri.Scheme -ne 'https' -or $centralUri.AbsolutePath -ne '/' -or
        $centralUri.Query -or $centralUri.Fragment -or $centralUri.UserInfo) {
        throw 'TRACE_CENTRAL_URL deve conter a origem HTTPS do servidor Central.'
    }
    $centralHealth = Invoke-RestMethod -Uri "$centralUrl/api/health.php" -TimeoutSec 15
    if ($centralHealth.status -ne 'ok' -or $centralHealth.installation_mode -ne 'central' -or
        $centralHealth.version -ne $manifest.version -or $centralHealth.commit -ne $releaseCommit) {
        throw "O servidor Central ainda nao serve a versao $($manifest.version) e o commit $releaseCommit. Atualizacao adiada."
    }

    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $previousArchive = Join-Path $BackupRoot ("pre-" + $manifest.version + "-" + $stamp + ".tar.gz")
    $stackStopped = $true
    $stopExit = Invoke-DockerCompose ($ComposeProfile + @('stop'))
    if ($stopExit -ne 0) { throw "Nao foi possivel parar os containers antes do backup." }
    $mysqlStartExit = Invoke-DockerCompose ($ComposeProfile + @('up', '-d', 'mysql'))
    if ($mysqlStartExit -ne 0) { throw "Nao foi possivel iniciar o MySQL para o backup." }
    $mysqlReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $pingResult = Invoke-External 'docker.exe' (@('compose') + $ComposeProfile + @('exec', '-T', 'mysql', 'sh', '-lc', 'mysqladmin ping -u$MYSQL_USER -p$MYSQL_PASSWORD --silent')) $null
        if ($pingResult.ExitCode -eq 0) { $mysqlReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $mysqlReady) { throw "O MySQL nao ficou pronto para o backup." }
    $backupResult = Invoke-GitBash @('scripts/backup_db.sh')
    if ($backupResult.ExitCode -ne 0) { throw "Backup do banco falhou." }
    $backupOutput = $backupResult.Stdout -split "`r?`n"
    $databaseBackup = ($backupOutput | Where-Object { $_ -like 'Backup criado: *' } | Select-Object -Last 1) -replace '^Backup criado: ', ''
    if (-not $databaseBackup) { throw "O backup do banco nao informou seu caminho." }
    # O WebView2 mantém arquivos de cache abertos enquanto a interface está
    # em uso. Ele é regenerável e deve ficar fora do backup e da substituição
    # para que o tar do Windows não falhe por acesso negado.
    $preservedNames = @(".env", "armazenamento", ".git", "config", "logs", "DallogixTrace.exe.WebView2")
    $applicationEntries = @(Get-ChildItem -Path $InstallRoot -Force | Where-Object { $_.Name -notin $preservedNames })
    if ($applicationEntries.Count -gt 0) {
        # Empacote o diretório inteiro e exclua os dados preservados. Passar
        # cada nome como argumento quebra no tar.exe do Windows quando há
        # espaços ou acentos (por exemplo, PDFs do projeto).
        $tarArguments = @('-czf', $previousArchive, '-C', $InstallRoot,
            '--exclude=./.env', '--exclude=./armazenamento', '--exclude=./.git',
            '--exclude=./config', '--exclude=./logs',
            '--exclude=./DallogixTrace.exe.WebView2', '.')
        $archiveExit = Invoke-Tar $tarArguments
        if ($archiveExit -ne 0) { throw "Backup dos arquivos atuais falhou (codigo $archiveExit)." }
    } else { throw "Nenhum arquivo da aplicacao encontrado para backup." }
    $rollbackRequired = $true
    Get-ChildItem -Path $InstallRoot -Force | Where-Object { $_.Name -notin $preservedNames } | Remove-Item -Recurse -Force
    Get-ChildItem $source -Force | Where-Object { $_.Name -notin $preservedNames } | Copy-Item -Destination $InstallRoot -Recurse -Force
    foreach ($requiredDesktopFile in @('DallogixTrace.exe', 'WebView2Loader.dll', 'trace.ico')) {
        $releaseDesktopFile = Join-Path $source $requiredDesktopFile
        $installedDesktopFile = Join-Path $InstallRoot $requiredDesktopFile
        if (-not (Test-Path -LiteralPath $releaseDesktopFile -PathType Leaf)) {
            throw "Pacote sem arquivo obrigatório do aplicativo Windows: $requiredDesktopFile."
        }
        if (-not (Test-Path -LiteralPath $installedDesktopFile -PathType Leaf)) {
            throw "Arquivo obrigatório do aplicativo Windows não foi instalado: $requiredDesktopFile."
        }

        $releaseDesktopHash = (Get-FileHash -LiteralPath $releaseDesktopFile -Algorithm SHA256).Hash.ToLowerInvariant()
        $installedDesktopHash = (Get-FileHash -LiteralPath $installedDesktopFile -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($requiredDesktopFile -eq 'DallogixTrace.exe' -and $installedDesktopHash -ne $releaseDesktopHash) {
            # Some Windows file-copy paths can leave an existing desktop EXE in
            # place without surfacing a useful error. Stage and hash the signed
            # release binary, replace it from the same volume, then verify again.
            $stagedDesktopFile = Join-Path $work 'DallogixTrace.exe.staged'
            Copy-Item -LiteralPath $releaseDesktopFile -Destination $stagedDesktopFile -Force
            $stagedDesktopHash = (Get-FileHash -LiteralPath $stagedDesktopFile -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($stagedDesktopHash -ne $releaseDesktopHash) {
                throw 'A cópia temporária do executável Windows não corresponde ao pacote da release.'
            }
            Move-Item -LiteralPath $stagedDesktopFile -Destination $installedDesktopFile -Force
            $installedDesktopHash = (Get-FileHash -LiteralPath $installedDesktopFile -Algorithm SHA256).Hash.ToLowerInvariant()
        }
        if ($installedDesktopHash -ne $releaseDesktopHash) {
            throw "Hash do arquivo instalado diverge do pacote da release: $requiredDesktopFile."
        }
        Append-UpdateLog $ComposeLogPath "Arquivo Windows validado após instalação: $requiredDesktopFile ($installedDesktopHash)."
    }
    Ensure-TraceShortcuts
    $releaseMetadata = @{ version = $manifest.version; commit = $releaseCommit } | ConvertTo-Json -Compress
    Write-AtomicTextFile (Join-Path $InstallRoot 'servidor\.release.json') ($releaseMetadata + "`n")
    $configExit = Invoke-DockerCompose ($ComposeProfile + @('config', '--quiet'))
    if ($configExit -ne 0) { throw "A configuracao da nova versao e invalida." }
    $migrationResult = Invoke-GitBash @('scripts/migrate.sh')
    if ($migrationResult.ExitCode -ne 0) { throw "As migrations da nova versao falharam." }
    Start-TraceStack -Build
    $healthy = $false
    $webPort = if ($env:WEB_PORT) { $env:WEB_PORT } else { '8080' }
    1..60 | ForEach-Object { if (-not $healthy) { try { $r = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$webPort/api/health.php" -TimeoutSec 3; $health = $r.Content | ConvertFrom-Json; if ($r.StatusCode -eq 200 -and $health.status -eq 'ok' -and $health.mysql -eq $true -and $health.version -eq $manifest.version -and $health.commit -eq $releaseCommit) { $healthy = $true } } catch { Start-Sleep -Seconds 2 } } }
    if (-not $healthy) { throw "A nova versão não passou no healthcheck." }
    Write-AtomicTextFile $current "$($manifest.version)`n"
    Remove-Item -LiteralPath $failedMarker -Force -ErrorAction SilentlyContinue
    $rollbackRequired = $false
    Invoke-TraceStoragePruning $manifest.version
    Schedule-TraceDesktopRestart
    Write-Output "Trace atualizado com sucesso para $($manifest.version). Backup: $databaseBackup"
} catch {
    $originalError = $_
    $errorMessage = if ($_.Exception -and $_.Exception.Message) { $_.Exception.Message } else { [string]$_ }
    try {
        Append-UpdateLog $ErrorLogPath ("$(Get-Date -Format o) $errorMessage")
    } catch {
        Write-Warning "Nao foi possivel registrar o erro do atualizador: $($_.Exception.Message)"
    }
    if ($rollbackRequired -and $previousArchive) {
        try {
            [IO.File]::WriteAllText($failedMarker, (Get-Date -Format o))
            Write-Warning "A atualização falhou; iniciando rollback automático."
            Set-Location $InstallRoot
            $rollbackStopExit = Invoke-DockerCompose ($ComposeProfile + @('stop'))
            if ($rollbackStopExit -ne 0) { throw "Nao foi possivel parar os containers para o rollback." }
            $restoreRoot = Join-Path $StateRoot (".rollback-" + [guid]::NewGuid().ToString("N"))
            New-Item -ItemType Directory -Force -Path $restoreRoot | Out-Null
            $rollbackExtractExit = Invoke-Tar @('-xzf', $previousArchive, '-C', $restoreRoot)
            if ($rollbackExtractExit -ne 0) { throw "Não foi possível extrair o backup anterior (codigo $rollbackExtractExit)." }
            Get-ChildItem -Path $InstallRoot -Force | Where-Object { $_.Name -notin $preservedNames } | Remove-Item -Recurse -Force
            Get-ChildItem $restoreRoot -Force | Copy-Item -Destination $InstallRoot -Recurse -Force
            $rollbackMysqlExit = Invoke-DockerCompose ($ComposeProfile + @('up', '-d', 'mysql'))
            if ($rollbackMysqlExit -ne 0) { throw "Nao foi possivel iniciar o MySQL anterior." }
            $mysqlReady = $false
            1..30 | ForEach-Object {
                if (-not $mysqlReady) {
                    $rollbackPingResult = Invoke-External 'docker.exe' (@('compose') + $ComposeProfile + @('exec', '-T', 'mysql', 'sh', '-lc', 'mysqladmin ping -u$MYSQL_USER -p$MYSQL_PASSWORD --silent')) $null
                    if ($rollbackPingResult.ExitCode -eq 0) { $mysqlReady = $true } else { Start-Sleep -Seconds 2 }
                }
            }
            if (-not $mysqlReady) { throw "O banco não ficou disponível para o rollback." }
            $env:TRACE_ALLOW_RESTORE = '1'
            try {
                $restoreResult = Invoke-GitBash @('scripts/restore_db.sh', $databaseBackup)
                if ($restoreResult.ExitCode -ne 0) { throw "Não foi possível restaurar o backup do banco." }
            } finally {
                Remove-Item Env:TRACE_ALLOW_RESTORE -ErrorAction SilentlyContinue
            }
            Start-TraceStack -Build
            $rollbackHealthy = $false
            1..30 | ForEach-Object {
                if (-not $rollbackHealthy) {
                    try {
                        $rollbackPort = if ($env:WEB_PORT) { $env:WEB_PORT } else { '8080' }
                        $health = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$rollbackPort/api/health.php" -TimeoutSec 3
                        $healthData = $health.Content | ConvertFrom-Json
                        if ($health.StatusCode -eq 200 -and $healthData.status -eq 'ok' -and $healthData.mysql -eq $true) { $rollbackHealthy = $true }
                    } catch { Start-Sleep -Seconds 2 }
                }
            }
            if (-not $rollbackHealthy) { throw "O rollback não passou no healthcheck." }
            Remove-Item $restoreRoot -Recurse -Force -ErrorAction SilentlyContinue
            Write-Warning "Rollback automático concluído; a versão anterior foi restaurada."
        } catch {
            [Console]::Error.WriteLine("ROLLBACK FALHOU: $($_.Exception.Message)")
            $keepMaintenance = $true
        }
    } elseif ($stackStopped) {
        Set-Location $InstallRoot
        try {
            Start-TraceStack -Build
            $restartExit = 0
        } catch {
            $restartExit = 1
        }
        if ($restartExit -ne 0) {
            [Console]::Error.WriteLine("Nao foi possivel reiniciar a versao anterior apos falha no backup.")
            $keepMaintenance = $true
        } else {
            $restartHealthy = $false
            $restartPort = if ($env:WEB_PORT) { $env:WEB_PORT } else { '8080' }
            for ($attempt = 0; $attempt -lt 30; $attempt++) {
                try {
                    $response = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$restartPort/api/health.php" -TimeoutSec 3
                    $healthData = $response.Content | ConvertFrom-Json
                    if ($response.StatusCode -eq 200 -and $healthData.status -eq 'ok' -and $healthData.mysql -eq $true) { $restartHealthy = $true; break }
                } catch { Start-Sleep -Seconds 2 }
            }
            if (-not $restartHealthy) {
                [Console]::Error.WriteLine("A versao anterior reiniciou, mas nao passou na verificacao de saude.")
                $keepMaintenance = $true
            }
        }
    }
    throw $originalError
} finally {
    if ($desktopStopped -and -not $desktopRestartScheduled -and -not $keepMaintenance) { Schedule-TraceDesktopRestart }
    if ($createdMaintenance -and -not $keepMaintenance -and (Test-Path -LiteralPath $MaintenanceFile)) { Remove-Item -LiteralPath $MaintenanceFile -Force }
    if ($work -and (Test-Path -LiteralPath $work)) { Remove-Item -LiteralPath $work -Recurse -Force }
    if ($hasLock) { $Lock.ReleaseMutex() }
    $Lock.Dispose()
    Stop-Transcript | Out-Null
}
