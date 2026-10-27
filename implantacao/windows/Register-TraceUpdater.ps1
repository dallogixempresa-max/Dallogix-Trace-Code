param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$taskName = 'Dallogix Trace Atualizacao Estavel'
$legacyTaskName = 'Dallogix Trace Atualizacao'
$manifestUrl = 'https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest/download/manifest.json'
$publicKey = Join-Path $PackageRoot 'servidor\configuracao\trace-update-public.pem'
$envPath = Join-Path $PackageRoot '.env'
$updater = Join-Path $PackageRoot 'implantacao\windows\TraceUpdater.ps1'
$versionFile = Join-Path $PackageRoot 'trace-build-version.txt'
$commitFile = Join-Path $PackageRoot 'trace-build-commit.txt'
$stateRoot = Join-Path $PackageRoot 'armazenamento\updates'

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { throw 'O .env deve estar configurado antes de habilitar atualizacoes.' }
if (-not (Test-Path -LiteralPath $publicKey -PathType Leaf)) { throw 'A chave publica do release nao foi encontrada.' }
if (-not (Test-Path -LiteralPath $updater -PathType Leaf)) { throw 'TraceUpdater.ps1 nao foi encontrado.' }
if (-not (Test-Path -LiteralPath $versionFile -PathType Leaf)) {
    throw 'A versao inicial do instalador nao foi identificada. Use um TraceSetup.exe gerado a partir de uma release aprovada.'
}
if (-not (Test-Path -LiteralPath $commitFile -PathType Leaf)) {
    throw 'O commit inicial do instalador nao foi identificado.'
}
$installedVersion = [IO.File]::ReadAllText($versionFile).Trim()
if ($installedVersion -notmatch '^v\d+\.\d+\.\d+$') { throw 'A versao inicial do instalador deve ser uma tag SemVer (vN.N.N).' }
$installedCommit = [IO.File]::ReadAllText($commitFile).Trim().ToLowerInvariant()
if ($installedCommit -notmatch '^[0-9a-f]{40}$') { throw 'O commit inicial do instalador e invalido.' }

$defaults = @{
    UPDATE_MANIFEST_URL = $manifestUrl
    UPDATE_PUBLIC_KEY_FILE = $publicKey
    UPDATE_CHANNEL = 'stable'
}
$lines = New-Object 'System.Collections.Generic.List[string]'
$seen = @{}
$changed = $false
foreach ($line in [IO.File]::ReadAllLines($envPath)) {
    if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$' -and $defaults.ContainsKey($matches[1])) {
        $key = $matches[1]
        if ($seen.ContainsKey($key)) { throw "O .env repete $key. Corrija antes de habilitar atualizacoes." }
        $seen[$key] = $true
        if ([string]::IsNullOrWhiteSpace($matches[2])) {
            $lines.Add("$key=$($defaults[$key])")
            $changed = $true
        } else {
            $lines.Add($line)
        }
    } else {
        $lines.Add($line)
    }
}
foreach ($key in $defaults.Keys) {
    if (-not $seen.ContainsKey($key)) { $lines.Add("$key=$($defaults[$key])"); $changed = $true }
}
$values = @{}
foreach ($line in $lines) {
    if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { $values[$matches[1]] = $matches[2].Trim('"').Trim("'") }
}
if ($values.UPDATE_CHANNEL -ne 'stable') { throw 'Esta instalacao de producao exige UPDATE_CHANNEL=stable.' }
if ($values.UPDATE_MANIFEST_URL -notmatch '^https://') { throw 'UPDATE_MANIFEST_URL deve usar HTTPS.' }
if (-not [IO.Path]::IsPathRooted($values.UPDATE_PUBLIC_KEY_FILE)) { throw 'UPDATE_PUBLIC_KEY_FILE deve ser um caminho absoluto.' }
if (-not (Test-Path -LiteralPath $values.UPDATE_PUBLIC_KEY_FILE -PathType Leaf)) { throw 'UPDATE_PUBLIC_KEY_FILE aponta para um arquivo inexistente.' }
$centralUrl = ([string]$values.TRACE_CENTRAL_URL).Trim().Trim('"').Trim("'").TrimEnd('/')
$centralUri = $null
if (-not [Uri]::TryCreate($centralUrl, [UriKind]::Absolute, [ref]$centralUri) -or
    $centralUri.Scheme -ne 'https' -or $centralUri.AbsolutePath -ne '/' -or
    $centralUri.Query -or $centralUri.Fragment -or $centralUri.UserInfo) {
    throw 'TRACE_CENTRAL_URL deve conter a origem HTTPS do servidor Central.'
}
$centralHealth = Invoke-RestMethod -Uri "$centralUrl/api/health.php" -TimeoutSec 15
if ($centralHealth.status -ne 'ok' -or $centralHealth.installation_mode -ne 'central' -or
    $centralHealth.version -ne $installedVersion -or $centralHealth.commit -ne $installedCommit) {
    throw "O servidor Central ainda nao serve a versao $installedVersion e o commit $installedCommit. Aguarde a implantacao da mesma release antes de ativar este PC."
}

New-Item -ItemType Directory -Force -Path $stateRoot | Out-Null
$currentVersion = Join-Path $stateRoot 'current_version'
if (Test-Path -LiteralPath $currentVersion) {
    $recorded = [IO.File]::ReadAllText($currentVersion).Trim()
    if ($recorded -notmatch '^v\d+\.\d+\.\d+$') { throw 'A versao registrada e invalida.' }
    if ([version]($recorded.Substring(1)) -gt [version]($installedVersion.Substring(1))) {
        throw 'O pacote e mais antigo que a versao registrada. A instalacao foi bloqueada.'
    }
    if ([version]($recorded.Substring(1)) -lt [version]($installedVersion.Substring(1))) {
        [IO.File]::WriteAllText($currentVersion, "$installedVersion`n", (New-Object System.Text.UTF8Encoding($false)))
    }
} else {
    [IO.File]::WriteAllText($currentVersion, "$installedVersion`n", (New-Object System.Text.UTF8Encoding($false)))
}
if ($changed) {
    [IO.File]::WriteAllLines($envPath, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$legacyTask = Get-ScheduledTask -TaskName $legacyTaskName -ErrorAction SilentlyContinue
if ($legacyTask) {
    Unregister-ScheduledTask -TaskName $legacyTaskName -Confirm:$false -ErrorAction Stop
}
$preflightTask = "$taskName Preflight"
$preflightMarker = Join-Path $stateRoot 'preflight-ok.txt'
Remove-Item -LiteralPath $preflightMarker -Force -ErrorAction SilentlyContinue
$preflightAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$updater`" -Preflight"
$preflightTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5)
Register-ScheduledTask -TaskName $preflightTask -Action $preflightAction -Trigger $preflightTrigger -Principal $principal -Force | Out-Null
try {
    Start-ScheduledTask -TaskName $preflightTask
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (Test-Path -LiteralPath $preflightMarker -PathType Leaf) { $ready = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) {
        throw "A conta SYSTEM nao conseguiu acessar o Docker Linux nesta maquina. Veja armazenamento\logs\update-stable.log; a atualizacao nao foi agendada."
    }
} finally {
    Unregister-ScheduledTask -TaskName $preflightTask -Confirm:$false -ErrorAction SilentlyContinue
}

$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    $existingAction = ($existingTask.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' '
    if (-not $existingAction.Contains($updater)) { throw "A tarefa $taskName ja existe com outro destino." }
}
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$updater`""
# Além da janela diária, tente após o logon da conta técnica. O Docker
# Desktop só disponibiliza o mecanismo Linux depois de iniciar na sessão
# interativa; o atraso evita uma corrida entre as duas tarefas.
$dailyTrigger = New-ScheduledTaskTrigger -Daily -At '03:00'
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn -RandomDelay (New-TimeSpan -Minutes 10)
$trigger = @($dailyTrigger, $logonTrigger)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 2) -RestartCount 12 -RestartInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Instala somente releases assinadas sem carregamento ativo.' -Force | Out-Null
$backupScript = Join-Path $PackageRoot 'implantacao\windows\TraceDatabaseBackup.ps1'
if (-not (Test-Path -LiteralPath $backupScript -PathType Leaf)) { throw 'TraceDatabaseBackup.ps1 nao foi encontrado.' }
$backupTaskName = 'Dallogix Trace Backup Diario'
$existingBackupTask = Get-ScheduledTask -TaskName $backupTaskName -ErrorAction SilentlyContinue
if ($existingBackupTask) {
    $existingBackupAction = ($existingBackupTask.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' '
    if (-not $existingBackupAction.Contains($backupScript)) { throw "A tarefa $backupTaskName ja existe com outro destino." }
}
$backupAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" -InstallRoot `"$PackageRoot`""
$backupTrigger = New-ScheduledTaskTrigger -Daily -At '02:00'
$backupSettings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName $backupTaskName -Action $backupAction -Trigger $backupTrigger -Principal $principal -Settings $backupSettings -Description 'Cria e valida diariamente uma copia do banco fora do volume MySQL.' -Force | Out-Null
$releaseMetadata = @{ version = $installedVersion; commit = $installedCommit } | ConvertTo-Json -Compress
[IO.File]::WriteAllText((Join-Path $PackageRoot 'servidor\.release.json'), $releaseMetadata + "`n", (New-Object System.Text.UTF8Encoding($false)))
Write-Host "Atualizacoes estaveis agendadas para 03:00 e apos o logon, com ate 12 novas tentativas a cada 5 minutos quando uma execucao falhar. Versao inicial: $installedVersion."
Write-Host 'Backup local do banco agendado diariamente para 02:00, com validacao SHA-256 e retencao configurada.'
