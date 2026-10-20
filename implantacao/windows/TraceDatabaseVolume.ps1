function Get-TraceMySqlVolumeSnapshot([string]$InstallRoot) {
    $composeFile = Join-Path $InstallRoot 'docker-compose.yml'
    if (-not (Test-Path -LiteralPath $composeFile -PathType Leaf)) {
        throw "docker-compose.yml nao encontrado em $InstallRoot."
    }

    $configOutput = & docker.exe compose --project-directory $InstallRoot config --format json 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Nao foi possivel resolver o volume MySQL pelo Docker Compose: $(($configOutput | Out-String).Trim())"
    }
    try {
        $composeConfig = ($configOutput -join [Environment]::NewLine) | ConvertFrom-Json
    } catch {
        throw 'A configuracao resolvida do Docker Compose nao e um JSON valido.'
    }

    $volumeConfig = $composeConfig.volumes.mysql_data
    $volumeName = ([string]$volumeConfig.name).Trim()
    if (-not $volumeConfig -or $volumeName -notmatch '^[A-Za-z0-9][A-Za-z0-9_.-]*$') {
        throw 'O volume mysql_data nao tem um nome Docker valido na configuracao resolvida.'
    }

    $inspectOutput = & docker.exe volume inspect --format '{{.CreatedAt}}' $volumeName 2>&1
    if ($LASTEXITCODE -eq 0) {
        $createdAt = ([string]($inspectOutput | Out-String)).Trim()
        if ([string]::IsNullOrWhiteSpace($createdAt)) {
            throw "O Docker nao informou quando o volume MySQL $volumeName foi criado."
        }
        return [pscustomobject]@{
            Name = $volumeName
            CreatedAt = $createdAt
            Exists = $true
        }
    }

    $volumeList = & docker.exe volume ls --format '{{.Name}}' 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Nao foi possivel consultar os volumes Docker: $(($volumeList | Out-String).Trim())"
    }
    if (@($volumeList | Where-Object { ([string]$_).Trim() -ceq $volumeName }).Count -gt 0) {
        throw "O volume $volumeName existe, mas o Docker nao conseguiu inspecionar sua identidade: $(($inspectOutput | Out-String).Trim())"
    }

    return [pscustomobject]@{
        Name = $volumeName
        CreatedAt = $null
        Exists = $false
    }
}

function Get-TraceMySqlVolumeMarkerPath {
    $programData = [Environment]::GetFolderPath([Environment+SpecialFolder]::CommonApplicationData)
    if ([string]::IsNullOrWhiteSpace($programData)) {
        throw 'O Windows nao informou a pasta ProgramData para registrar a identidade do banco.'
    }
    return Join-Path $programData 'DallogixTrace\state\mysql-volume.json'
}

function Test-TraceMySqlPriorInstallEvidence([string]$InstallRoot) {
    $evidencePaths = @(
        (Join-Path $InstallRoot 'armazenamento\updates\current_version'),
        (Join-Path $InstallRoot 'servidor\.release.json'),
        (Join-Path $InstallRoot 'armazenamento\.maintenance')
    )
    if (@($evidencePaths | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }).Count -gt 0) {
        return $true
    }

    foreach ($backupRoot in @(
        (Join-Path $InstallRoot 'armazenamento\backups'),
        (Join-Path $InstallRoot 'armazenamento\updates\backups')
    )) {
        if (-not (Test-Path -LiteralPath $backupRoot -PathType Container)) { continue }
        $backup = Get-ChildItem -LiteralPath $backupRoot -File -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($backup) { return $true }
    }
    return $false
}

function Read-TraceMySqlVolumeMarker {
    $markerPath = Get-TraceMySqlVolumeMarkerPath
    if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) { return $null }
    try {
        $marker = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json
    } catch {
        throw "O registro protegido do volume MySQL esta ilegivel: $markerPath. Inicializacao bloqueada."
    }
    if ([int]$marker.schema_version -ne 1 -or
        [string]::IsNullOrWhiteSpace([string]$marker.volume_name) -or
        [string]::IsNullOrWhiteSpace([string]$marker.volume_created_at)) {
        throw "O registro protegido do volume MySQL esta incompleto: $markerPath. Inicializacao bloqueada."
    }
    return $marker
}

function Write-TraceMySqlVolumeMarker([pscustomobject]$Snapshot) {
    if (-not $Snapshot.Exists) {
        throw 'Nao e possivel registrar a identidade de um volume MySQL ausente.'
    }

    $markerPath = Get-TraceMySqlVolumeMarkerPath
    $markerDirectory = Split-Path -Parent $markerPath
    New-Item -ItemType Directory -Force -Path $markerDirectory | Out-Null
    $marker = [ordered]@{
        schema_version = 1
        volume_name = $Snapshot.Name
        volume_created_at = $Snapshot.CreatedAt
        recorded_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    }
    $temporaryPath = "$markerPath.$([guid]::NewGuid().ToString('N')).tmp"
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    try {
        [IO.File]::WriteAllText($temporaryPath, (($marker | ConvertTo-Json -Compress) + [Environment]::NewLine), $utf8)
        Move-Item -LiteralPath $temporaryPath -Destination $markerPath -Force
    } finally {
        Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
    }
}

function Assert-TraceMySqlVolumeExists([string]$InstallRoot, [switch]$RequireMarker) {
    $snapshot = Get-TraceMySqlVolumeSnapshot $InstallRoot
    if (-not $snapshot.Exists) {
        throw "Volume persistente do MySQL ausente: $($snapshot.Name). Inicializacao bloqueada para impedir que o Compose crie um banco vazio. Preserve os backups e restaure o volume ou o banco antes de iniciar o Trace."
    }

    $marker = Read-TraceMySqlVolumeMarker
    if ($marker) {
        if ([string]$marker.volume_name -ne $snapshot.Name -or
            [string]$marker.volume_created_at -ne $snapshot.CreatedAt) {
            throw "A identidade do volume MySQL mudou. Esperado '$($marker.volume_name)' criado em '$($marker.volume_created_at)', encontrado '$($snapshot.Name)' criado em '$($snapshot.CreatedAt)'. Inicializacao bloqueada para evitar usar uma base vazia."
        }
    } elseif ($RequireMarker) {
        throw 'A identidade do volume MySQL ainda nao foi registrada depois da validacao do banco. Inicializacao bloqueada.'
    }

    return $snapshot
}

function Register-TraceMySqlVolumeAfterDatabaseCheck([string]$InstallRoot) {
    $snapshot = Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot
    $marker = Read-TraceMySqlVolumeMarker
    if (-not $marker) {
        Write-TraceMySqlVolumeMarker $snapshot
    }
    return Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker
}

function Initialize-TraceMySqlVolumeForInstall([string]$InstallRoot, [switch]$AllowFreshDatabaseCreation) {
    $snapshot = Get-TraceMySqlVolumeSnapshot $InstallRoot
    $marker = Read-TraceMySqlVolumeMarker
    $hasPriorInstallEvidence = Test-TraceMySqlPriorInstallEvidence $InstallRoot

    if ($snapshot.Exists) {
        if ($marker) {
            $snapshot = Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker
            $snapshot | Add-Member -NotePropertyName NeedsDatabaseValidation -NotePropertyValue $false
            return $snapshot
        }
        if (-not $hasPriorInstallEvidence) {
            throw "Foi encontrado o volume MySQL '$($snapshot.Name)' sem registro de identidade e sem evidencias suficientes da instalacao. Nao vou iniciar nem substituir esse banco; valide seus dados antes de continuar."
        }
        $snapshot | Add-Member -NotePropertyName NeedsDatabaseValidation -NotePropertyValue $true
        return $snapshot
    }

    if ($hasPriorInstallEvidence) {
        throw "Foi detectada uma instalacao anterior ou backups, mas o volume MySQL '$($snapshot.Name)' esta ausente. Nenhum volume vazio sera criado; restaure os dados existentes primeiro."
    }
    if (-not $AllowFreshDatabaseCreation) {
        throw "O volume MySQL '$($snapshot.Name)' esta ausente. A criacao de banco vazio so e permitida pelo fluxo explicito de primeira instalacao."
    }

    $createOutput = & docker.exe volume create $snapshot.Name 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Nao foi possivel criar o volume MySQL inicial '$($snapshot.Name)': $(($createOutput | Out-String).Trim())"
    }
    $snapshot = Get-TraceMySqlVolumeSnapshot $InstallRoot
    if (-not $snapshot.Exists) {
        throw "O Docker nao confirmou a criacao do volume MySQL '$($snapshot.Name)'."
    }
    Write-TraceMySqlVolumeMarker $snapshot
    $snapshot | Add-Member -NotePropertyName NeedsDatabaseValidation -NotePropertyValue $false
    return $snapshot
}

function Test-TraceMySqlApplicationSchema([string]$InstallRoot) {
    $query = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 0x636172726567616d656e746f73;'
    $dockerCommand = Get-Command 'docker.exe' -ErrorAction Stop
    $arguments = @(
        'compose', '--project-directory', $InstallRoot, '--profile', 'industrial',
        'exec', '-T', '-i', 'mysql', 'sh', '-lc',
        'mysql -N -B -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE'
    )
    $argumentLine = ($arguments | ForEach-Object {
        $argument = [string]$_
        if ($argument -match '[\s"]') { '"' + ($argument -replace '"', '\"') + '"' } else { $argument }
    }) -join ' '
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $dockerCommand.Source
    $startInfo.Arguments = $argumentLine
    $startInfo.WorkingDirectory = $InstallRoot
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    try {
        [void]$process.Start()
        $process.StandardInput.WriteLine($query)
        $process.StandardInput.Close()
        $output = $process.StandardOutput.ReadToEnd()
        $errorOutput = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        if ($process.ExitCode -ne 0) {
            $detail = (($errorOutput, $output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }) -join [Environment]::NewLine).Trim()
            throw "Nao foi possivel consultar o esquema MySQL (codigo $($process.ExitCode)): $detail"
        }
        $tableCount = $output.Trim()
        if ($tableCount -eq '1') { return $true }
        if ($tableCount -eq '0') { return $false }
        throw "A consulta do esquema MySQL retornou um resultado inesperado: $tableCount"
    } finally {
        $process.Dispose()
    }
}
