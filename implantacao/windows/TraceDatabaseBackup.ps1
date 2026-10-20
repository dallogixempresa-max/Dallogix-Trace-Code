param(
    [string]$InstallRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path
$LogRoot = Join-Path $InstallRoot 'armazenamento\logs'
$BackupRoot = Join-Path $InstallRoot 'armazenamento\backups'
$LogPath = Join-Path $LogRoot 'database-backup.log'
New-Item -ItemType Directory -Force -Path $LogRoot | Out-Null

function Write-TraceDatabaseBackupLog([string]$Message) {
    [IO.File]::AppendAllText(
        $LogPath,
        ("{0:o} {1}" -f (Get-Date), $Message) + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Find-TraceGitBash {
    $candidates = @(
        (Join-Path $env:ProgramFiles 'Git\bin\bash.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\Git\bin\bash.exe')
    )
    $programFilesX86 = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
    if ($programFilesX86) { $candidates += Join-Path $programFilesX86 'Git\bin\bash.exe' }
    $git = Get-Command git.exe -ErrorAction SilentlyContinue
    if ($git) { $candidates += Join-Path (Split-Path -Parent (Split-Path -Parent $git.Source)) 'bin\bash.exe' }
    return $candidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
}

try {
    $volumeGuardPath = Join-Path $PSScriptRoot 'TraceDatabaseVolume.ps1'
    if (-not (Test-Path -LiteralPath $volumeGuardPath -PathType Leaf)) {
        throw "Protecao do volume MySQL ausente: $volumeGuardPath"
    }
    . $volumeGuardPath
    Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker | Out-Null

    $bash = Find-TraceGitBash
    if (-not $bash) { throw 'Git Bash nao encontrado; backup diario nao executado.' }
    $retentionDays = 30
    $envPath = Join-Path $InstallRoot '.env'
    $retentionLines = @([IO.File]::ReadAllLines($envPath) | Where-Object { $_ -match '^\s*TRACE_BACKUP_RETENTION_DAYS=(.*)$' })
    if ($retentionLines.Count -gt 1) { throw 'O .env repete TRACE_BACKUP_RETENTION_DAYS.' }
    if ($retentionLines.Count -eq 1) {
        $retentionValue = ($retentionLines[0] -replace '^\s*TRACE_BACKUP_RETENTION_DAYS=', '').Trim().Trim('"').Trim("'")
        if (-not [int]::TryParse($retentionValue, [ref]$retentionDays) -or $retentionDays -lt 1 -or $retentionDays -gt 3650) {
            throw 'TRACE_BACKUP_RETENTION_DAYS deve ser um inteiro entre 1 e 3650.'
        }
    }
    $env:TRACE_BACKUP_RETENTION_DAYS = [string]$retentionDays

    $existingBackups = @()
    if (Test-Path -LiteralPath $BackupRoot -PathType Container) {
        $existingBackups = @(Get-ChildItem -LiteralPath $BackupRoot -File -Filter 'trace_local_*.sql' -ErrorAction Stop |
            ForEach-Object { $_.FullName.ToLowerInvariant() })
    }

    Set-Location -LiteralPath $InstallRoot
    $output = & $bash 'scripts/backup_db.sh' 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "backup_db.sh falhou (codigo $LASTEXITCODE): $(($output | Out-String).Trim())"
    }

    $newBackups = @(Get-ChildItem -LiteralPath $BackupRoot -File -Filter 'trace_local_*.sql' -ErrorAction Stop |
        Where-Object { $existingBackups -notcontains $_.FullName.ToLowerInvariant() } |
        Sort-Object LastWriteTimeUtc -Descending)
    if ($newBackups.Count -eq 0) { throw 'O comando terminou sem erro, mas nao criou um novo arquivo de backup.' }

    $backup = $newBackups[0]
    $checksumPath = "$($backup.FullName).sha256"
    if (-not (Test-Path -LiteralPath $checksumPath -PathType Leaf)) {
        throw "O checksum do backup nao foi encontrado: $checksumPath"
    }
    $checksumLine = [IO.File]::ReadAllText($checksumPath).Trim()
    if ($checksumLine -notmatch '^([0-9a-fA-F]{64})\s+\*?(.+)$') {
        throw "O arquivo de checksum do backup tem formato ou nome invalido: $checksumPath"
    }
    $expectedHash = $matches[1]
    if ($matches[2] -ne $backup.Name) { throw "O checksum nao corresponde ao arquivo de backup: $checksumPath" }
    $actualHash = (Get-FileHash -LiteralPath $backup.FullName -Algorithm SHA256).Hash
    if ($actualHash -ine $expectedHash) { throw "O SHA-256 do backup nao confere: $($backup.Name)" }

    Write-TraceDatabaseBackupLog "Backup diario validado: $($backup.Name); SHA-256 confirmado; retencao de $retentionDays dias."
    Write-Output "Backup diario validado: $($backup.FullName)"
} catch {
    $message = $_.Exception.Message
    Write-TraceDatabaseBackupLog "FALHA no backup diario: $message"
    throw $message
}
