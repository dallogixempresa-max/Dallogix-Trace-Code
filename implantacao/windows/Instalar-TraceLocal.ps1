param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [string]$CentralUrl = '',
    [switch]$NoBrowser,
    [switch]$ProductionMachine,
    [switch]$AllowFreshDatabaseCreation
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$PackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$script:TraceComposeRoot = $PackageRoot
$script:TraceComposeEnvFile = $null
$DatabaseVolumeGuard = Join-Path $PSScriptRoot 'TraceDatabaseVolume.ps1'
if (-not (Test-Path -LiteralPath $DatabaseVolumeGuard -PathType Leaf)) { throw "Protecao do volume MySQL ausente: $DatabaseVolumeGuard" }
. $DatabaseVolumeGuard
$TraceFileSecurity = Join-Path $PSScriptRoot 'TraceFileSecurity.ps1'
if (-not (Test-Path -LiteralPath $TraceFileSecurity -PathType Leaf)) { throw "Protecao da configuracao industrial ausente: $TraceFileSecurity" }
. $TraceFileSecurity

function Assert-Success([string]$Stage, [int]$Code) {
    if ($Code -ne 0) {
        throw "$Stage falhou (codigo $Code). A instalacao parou sem apagar o banco ou a configuracao."
    }
}

function Invoke-TraceCompose([string[]]$Arguments) {
    if (-not $script:TraceComposeEnvFile) {
        throw "O contexto do Docker Compose do Trace ainda nao foi configurado."
    }
    $composeArguments = @(
        "--project-directory", $script:TraceComposeRoot,
        "--env-file", $script:TraceComposeEnvFile
    ) + $Arguments
    & docker.exe compose @composeArguments
}

function Find-GitBash {
    $candidates = @(
        (Join-Path $env:ProgramFiles "Git\bin\bash.exe"),
        (Join-Path $env:LOCALAPPDATA "Programs\Git\bin\bash.exe")
    )
    $programFilesX86 = [Environment]::GetEnvironmentVariable("ProgramFiles(x86)")
    if ($programFilesX86) {
        $candidates += Join-Path $programFilesX86 "Git\bin\bash.exe"
    }
    $git = Get-Command git.exe -ErrorAction SilentlyContinue
    if ($git) {
        $candidates += Join-Path (Split-Path -Parent (Split-Path -Parent $git.Source)) "bin\bash.exe"
    }
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return $candidate
        }
    }
    return $null
}

function Read-EnvValues([string]$Path) {
    $values = @{}
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $key = $matches[1]
            if ($values.ContainsKey($key)) {
                throw "O arquivo .env contem a variavel $key mais de uma vez. Corrija antes de continuar."
            }
            $values[$key] = $matches[2].Trim().Trim('"').Trim("'")
        }
    }
    return $values
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = New-Object 'System.Collections.Generic.List[string]'
    $found = $false
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match "^$([regex]::Escape($Key))=") {
            if ($found) { throw "O arquivo .env contem a variavel $Key mais de uma vez. Corrija antes de continuar." }
            $lines.Add("$Key='$Value'")
            $found = $true
        } else {
            $lines.Add([string]$line)
        }
    }
    if (-not $found) { $lines.Add("$Key='$Value'") }
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($Path, $lines, $utf8)
}

function ConvertFrom-SecureStringToPlainText([System.Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function Show-NodeRedPasswordDialog([string]$Username) {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()

    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Proteção do painel industrial'
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false
    $form.ClientSize = New-Object System.Drawing.Size(500, 285)
    $form.Font = New-Object System.Drawing.Font('Segoe UI', 9)

    $title = New-Object System.Windows.Forms.Label
    $title.Text = "Defina a senha do usuário administrativo $Username"
    $title.Font = New-Object System.Drawing.Font('Segoe UI', 10, [System.Drawing.FontStyle]::Bold)
    $title.Location = New-Object System.Drawing.Point(20, 18)
    $title.Size = New-Object System.Drawing.Size(460, 28)
    $form.Controls.Add($title)

    $note = New-Object System.Windows.Forms.Label
    $note.Text = 'Use pelo menos 15 caracteres e até 72 bytes em UTF-8. Guarde-a no gerenciador de credenciais técnico. A senha não será exibida nem registrada.'
    $note.Location = New-Object System.Drawing.Point(20, 51)
    $note.Size = New-Object System.Drawing.Size(460, 44)
    $form.Controls.Add($note)

    $passwordLabel = New-Object System.Windows.Forms.Label
    $passwordLabel.Text = 'Senha'
    $passwordLabel.Location = New-Object System.Drawing.Point(20, 105)
    $passwordLabel.Size = New-Object System.Drawing.Size(440, 20)
    $form.Controls.Add($passwordLabel)
    $passwordField = New-Object System.Windows.Forms.TextBox
    $passwordField.UseSystemPasswordChar = $true
    $passwordField.Location = New-Object System.Drawing.Point(20, 128)
    $passwordField.Size = New-Object System.Drawing.Size(460, 25)
    $form.Controls.Add($passwordField)

    $confirmationLabel = New-Object System.Windows.Forms.Label
    $confirmationLabel.Text = 'Confirme a senha'
    $confirmationLabel.Location = New-Object System.Drawing.Point(20, 165)
    $confirmationLabel.Size = New-Object System.Drawing.Size(440, 20)
    $form.Controls.Add($confirmationLabel)
    $confirmationField = New-Object System.Windows.Forms.TextBox
    $confirmationField.UseSystemPasswordChar = $true
    $confirmationField.Location = New-Object System.Drawing.Point(20, 188)
    $confirmationField.Size = New-Object System.Drawing.Size(460, 25)
    $form.Controls.Add($confirmationField)

    $buttons = New-Object System.Windows.Forms.FlowLayoutPanel
    $buttons.FlowDirection = 'RightToLeft'
    $buttons.Dock = 'Bottom'
    $buttons.Height = 48
    $buttons.Padding = New-Object System.Windows.Forms.Padding(8)
    $form.Controls.Add($buttons)
    $continue = New-Object System.Windows.Forms.Button
    $continue.Text = 'Continuar'
    $continue.Width = 110
    $continue.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $cancel = New-Object System.Windows.Forms.Button
    $cancel.Text = 'Cancelar'
    $cancel.Width = 100
    $cancel.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    [void]$buttons.Controls.Add($continue)
    [void]$buttons.Controls.Add($cancel)
    $form.AcceptButton = $continue
    $form.CancelButton = $cancel

    while ($true) {
        if ($form.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { return $null }
        $password = $passwordField.Text
        $confirmation = $confirmationField.Text
        $errorMessage = ''
        if ($password.Length -lt 15) {
            $errorMessage = 'A senha precisa ter pelo menos 15 caracteres.'
        } elseif ([System.Text.Encoding]::UTF8.GetByteCount($password) -gt 72) {
            $errorMessage = 'A senha deve ter no máximo 72 bytes em UTF-8 para evitar truncamento pelo bcrypt.'
        } elseif (-not [string]::Equals($password, $confirmation, [StringComparison]::Ordinal)) {
            $errorMessage = 'As senhas não conferem. Revise os dois campos.'
        }
        if ($errorMessage) {
            $password = $null
            $confirmation = $null
            [System.Windows.Forms.MessageBox]::Show($errorMessage, 'Confira a senha', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
            $form.DialogResult = [System.Windows.Forms.DialogResult]::None
            continue
        }

        $securePassword = ConvertTo-SecureString -String $password -AsPlainText -Force
        $password = $null
        $confirmation = $null
        $passwordField.Clear()
        $confirmationField.Clear()
        return $securePassword
    }
}

function Ensure-NodeRedAdminAuth([string]$EnvPath, [hashtable]$Values) {
    $username = [string]$Values['NODE_RED_ADMIN_USER']
    if ($username -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$') {
        throw "NODE_RED_ADMIN_USER deve ter de 3 a 64 caracteres seguros."
    }
    $hash = [string]$Values['NODE_RED_ADMIN_PASSWORD_HASH']
    if ($hash -and $hash -notmatch '^\$2[ab]\$\d{2}\$[./A-Za-z0-9]{53}$') {
        throw "NODE_RED_ADMIN_PASSWORD_HASH inválido. Informe um hash bcrypt, nunca a senha em texto puro."
    }
    if ($hash) {
        Set-EnvValue $EnvPath 'NODE_RED_ADMIN_PASSWORD_HASH' $hash
        $Values['NODE_RED_ADMIN_PASSWORD_HASH'] = $hash
        return
    }

    Write-Host "Defina a senha administrativa do Node-RED na janela segura."
    $securePassword = Show-NodeRedPasswordDialog $username
    if ($null -eq $securePassword) { throw 'Instalação cancelada antes de iniciar os serviços industriais.' }
    $plainPassword = $null
    try {
        $plainPassword = ConvertFrom-SecureStringToPlainText $securePassword
        if ([System.Text.Encoding]::UTF8.GetByteCount($plainPassword) -gt 72) {
            throw "A senha do Node-RED deve ter no máximo 72 bytes em UTF-8 para evitar truncamento pelo bcrypt."
        }

        Write-Host "Preparando o gerador bcrypt local; a senha não será gravada nem exibida."
        Invoke-TraceCompose -Arguments @("--profile", "industrial", "build", "node-red")
        Assert-Success "Preparação do Node-RED para gerar o hash" $LASTEXITCODE
        $env:NODE_RED_ADMIN_PASSWORD = $plainPassword
        $hashArguments = @(
            "--profile", "industrial", "run", "--rm", "--no-deps",
            "-e", "NODE_RED_ADMIN_PASSWORD", "--entrypoint", "node", "node-red",
            "-e", "console.log(require('bcryptjs').hashSync(process.env.NODE_RED_ADMIN_PASSWORD, 12))"
        )
        $hashOutput = Invoke-TraceCompose -Arguments $hashArguments 2>&1
        $hashExitCode = $LASTEXITCODE
        if ($hashExitCode -ne 0) {
            $diagnostic = ($hashOutput | Out-String).Trim()
            throw "Não foi possível gerar o hash bcrypt do Node-RED (código $hashExitCode). $diagnostic"
        }
        $hash = [string]($hashOutput | Where-Object { [string]$_ -match '^\$2[ab]\$12\$[./A-Za-z0-9]{53}$' } | Select-Object -Last 1)
        if (-not $hash) {
            throw "O gerador do Node-RED não retornou um hash bcrypt válido. Nenhum serviço industrial foi iniciado."
        }
    } finally {
        Remove-Item Env:NODE_RED_ADMIN_PASSWORD -ErrorAction SilentlyContinue
        $plainPassword = $null
        $securePassword.Dispose()
    }

    Set-EnvValue $EnvPath 'NODE_RED_ADMIN_PASSWORD_HASH' $hash
    $Values['NODE_RED_ADMIN_PASSWORD_HASH'] = $hash
    Write-Host "Hash bcrypt salvo no .env; a senha em texto puro foi descartada."
}

function Ensure-EnvDefaults([string]$Path, [hashtable]$Defaults) {
    $lines = New-Object 'System.Collections.Generic.List[string]'
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        $lines.Add([string]$line)
    }
    foreach ($entry in $Defaults.GetEnumerator()) {
        $key = [string]$entry.Key
        $pattern = "^$([regex]::Escape($key))=(.*)$"
        $matchesForKey = @()
        for ($index = 0; $index -lt $lines.Count; $index++) {
            if ($lines[$index] -match $pattern) {
                $matchesForKey += $index
            }
        }
        if ($matchesForKey.Count -gt 1) {
            throw "O arquivo .env contem a variavel $key mais de uma vez. Corrija antes de continuar."
        }
        if ($matchesForKey.Count -eq 1) {
            $index = $matchesForKey[0]
            $current = ($lines[$index] -split "=", 2)[1].Trim().Trim('"').Trim("'")
            if ([string]::IsNullOrWhiteSpace($current)) {
                $lines[$index] = "$key=$($entry.Value)"
            }
        } else {
            $lines.Add("$key=$($entry.Value)")
        }
    }
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($Path, $lines, $utf8)
}

function New-HexSecret([Security.Cryptography.RandomNumberGenerator]$Generator) {
    $bytes = New-Object byte[] 32
    $Generator.GetBytes($bytes)
    return [System.BitConverter]::ToString($bytes).Replace("-", "")
}

function Ensure-RequiredEnvSecrets([string]$EnvPath, [string]$InstallRoot) {
    $requiredKeys = @("MYSQL_PASSWORD", "MYSQL_ROOT_PASSWORD", "TRACE_DEVICE_TOKEN", "KEYENCE_DEVICE_TOKEN", "KEYENCE_FTP_PASSWORD")
    $values = Read-EnvValues $EnvPath
    $missingKeys = @($requiredKeys | Where-Object {
        -not $values.ContainsKey($_) -or [string]::IsNullOrWhiteSpace([string]$values[$_])
    })
    if ($missingKeys.Count -eq 0) { return }

    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    $generated = @{}
    try {
        foreach ($key in $missingKeys) { $generated[$key] = New-HexSecret $generator }
    } finally {
        $generator.Dispose()
    }

    $missingDatabaseCredentials = @($missingKeys | Where-Object { $_ -in @("MYSQL_PASSWORD", "MYSQL_ROOT_PASSWORD") })
    if ($missingDatabaseCredentials.Count -gt 0) {
        $savedEnvironment = @{}
        $volumeSnapshot = $null
        try {
            foreach ($key in $missingKeys) {
                $savedEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, [EnvironmentVariableTarget]::Process)
                [Environment]::SetEnvironmentVariable($key, $generated[$key], [EnvironmentVariableTarget]::Process)
            }
            $volumeSnapshot = Get-TraceMySqlVolumeSnapshot -InstallRoot $InstallRoot -EnvPath $EnvPath
        } finally {
            foreach ($key in $missingKeys) {
                [Environment]::SetEnvironmentVariable($key, $savedEnvironment[$key], [EnvironmentVariableTarget]::Process)
            }
        }

        $volumeMarker = Read-TraceMySqlVolumeMarker
        $priorInstallEvidence = Test-TraceMySqlPriorInstallEvidence $InstallRoot
        if ($volumeSnapshot.Exists -or $volumeMarker -or $priorInstallEvidence) {
            throw "O .env está sem credenciais obrigatórias do banco, mas há um banco ou evidências de instalação anterior. Para proteger os dados, nenhuma senha foi substituída. Restaure MYSQL_PASSWORD e MYSQL_ROOT_PASSWORD do backup seguro e execute novamente."
        }
    }

    Ensure-EnvDefaults $EnvPath $generated
    Write-Host "Credenciais obrigatórias ausentes geradas com valores aleatórios. Os valores não serão exibidos."
}

function Get-CentralBaseUrl([string]$InputUrl) {
    $parsed = $null
    if (-not [Uri]::TryCreate($InputUrl.Trim(), [UriKind]::Absolute, [ref]$parsed) -or
        $parsed.Scheme -ne "https" -or -not $parsed.Host -or
        $parsed.UserInfo -or $parsed.Query -or $parsed.Fragment) {
        throw "Informe uma URL HTTPS do servidor central, sem usuario, senha, parametros ou fragmento."
    }
    $baseUrl = $parsed.GetLeftPart([UriPartial]::Authority)
    if ($parsed.AbsolutePath -ne "/") {
        Write-Host "Usando apenas a origem do servidor central: $baseUrl"
    }
    return $baseUrl
}

function Test-DockerEngine {
    # A falha nesta sonda e esperada enquanto o Docker Desktop inicia.
    $ErrorActionPreference = "Continue"
    $engineType = & docker.exe info --format '{{.OSType}}' 2> $null
    return $LASTEXITCODE -eq 0 -and $engineType -eq "linux"
}

function Check-DockerReady {
    if (Test-DockerEngine) { return $true }

    $desktopCandidates = @(
        (Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"),
        (Join-Path $env:LOCALAPPDATA "Programs\DockerDesktop\Docker Desktop.exe")
    )
    $programFilesX86 = [Environment]::GetEnvironmentVariable("ProgramFiles(x86)")
    if ($programFilesX86) { $desktopCandidates += Join-Path $programFilesX86 "Docker\Docker\Docker Desktop.exe" }
    $desktop = $desktopCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if ($desktop) {
        Write-Host "Iniciando Docker Desktop e aguardando o mecanismo Linux..."
        Start-Process -FilePath $desktop | Out-Null
        for ($attempt = 1; $attempt -le 24; $attempt++) {
            Start-Sleep -Seconds 5
            if (Test-DockerEngine) { return $true }
        }
    }
    return $false
}

function Initialize-DockerCli {
    if (Get-Command docker.exe -ErrorAction SilentlyContinue) { return }
    $dockerCandidates = @(
        (Join-Path $env:ProgramFiles "Docker\Docker\resources\bin\docker.exe"),
        (Join-Path $env:LOCALAPPDATA "Programs\DockerDesktop\resources\bin\docker.exe")
    )
    $programFilesX86 = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
    if ($programFilesX86) { $dockerCandidates += Join-Path $programFilesX86 'Docker\Docker\resources\bin\docker.exe' }
    $dockerDesktopCandidates = @(
        (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe')
    )
    if ($programFilesX86) { $dockerDesktopCandidates += Join-Path $programFilesX86 'Docker\Docker\Docker Desktop.exe' }

    foreach ($candidate in $dockerCandidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            $env:PATH = "$(Split-Path -Parent $candidate);$env:PATH"
            return
        }
    }

    $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
    if (-not $winget) {
        throw 'Docker Desktop não está instalado e o Windows Package Manager (winget) não está disponível. Instale o Docker Desktop pelo site oficial https://docs.docker.com/desktop/setup/install/windows-install/, abra-o e conclua a configuração do WSL 2; depois execute o TraceSetup novamente.'
    }

    Add-Type -AssemblyName System.Windows.Forms
    $choice = [System.Windows.Forms.MessageBox]::Show(
        "O Dallogix Trace precisa do Docker Desktop para executar o banco e os serviços locais.`r`n`r`nDeseja instalar agora pelo Windows Package Manager? A instalação pode pedir aceite dos termos e uma reinicialização. O responsável da empresa deve confirmar que a licença de uso do Docker Desktop se aplica à organização.",
        'Pré-requisito: Docker Desktop',
        [System.Windows.Forms.MessageBoxButtons]::YesNo,
        [System.Windows.Forms.MessageBoxIcon]::Information
    )
    if ($choice -ne [System.Windows.Forms.DialogResult]::Yes) {
        throw 'A instalação foi pausada porque o Docker Desktop ainda não está instalado. Instale-o e execute o TraceSetup novamente.'
    }

    Write-Host 'Instalando Docker Desktop. Se o instalador pedir aceite de termos, confirme somente após a validação da licença pela empresa.'
    & $winget.Source install --exact --id Docker.DockerDesktop --accept-source-agreements
    if ($LASTEXITCODE -ne 0) {
        throw "O Windows Package Manager não concluiu a instalação do Docker Desktop (código $LASTEXITCODE). Instale-o pelo site oficial e execute o TraceSetup novamente."
    }

    foreach ($candidate in $dockerCandidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            $env:PATH = "$(Split-Path -Parent $candidate);$env:PATH"
            return
        }
    }
    if ($dockerDesktopCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }) {
        throw 'Docker Desktop foi instalado. Reinicie o Windows se solicitado, abra o Docker Desktop, conclua a configuração do WSL 2 e execute o TraceSetup novamente.'
    }
    throw 'O Docker Desktop informou instalação concluída, mas o comando docker não foi localizado. Reinicie o Windows e execute o TraceSetup novamente.'
}

function Wait-MySqlHealthy {
    $containerId = Invoke-TraceCompose -Arguments @("ps", "-q", "mysql")
    Assert-Success "Localizacao do container MySQL" $LASTEXITCODE
    $containerId = ([string]$containerId).Trim()
    if (-not $containerId) {
        throw "O container MySQL nao foi criado pelo Docker Compose."
    }
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $health = & docker.exe inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' $containerId
        if ($LASTEXITCODE -eq 0 -and ([string]$health).Trim() -eq 'healthy') { return }
        $state = & docker.exe inspect --format '{{.State.Status}}' $containerId
        if ($LASTEXITCODE -eq 0 -and ([string]$state).Trim() -in @('exited', 'dead')) { break }
        Start-Sleep -Seconds 3
    }
    $logs = Invoke-TraceCompose -Arguments @("logs", "--no-color", "--tail=100", "mysql") 2>&1
    $diagnostic = ($logs | Out-String).Trim()
    throw "O MySQL nao ficou saudavel antes das migrations. A instalacao foi interrompida. Logs recentes:`n$diagnostic"
}

function Wait-NodeRedHealthy {
    $containerId = Invoke-TraceCompose -Arguments @("ps", "-q", "node-red")
    Assert-Success "Localizacao do container Node-RED" $LASTEXITCODE
    $containerId = ([string]$containerId).Trim()
    if (-not $containerId) {
        throw "O perfil industrial nao iniciou o Node-RED. Confira COMPOSE_PROFILES=industrial no .env."
    }
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        $health = & docker.exe inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' $containerId
        if ($LASTEXITCODE -eq 0 -and ([string]$health).Trim() -eq 'healthy') { return }
        Start-Sleep -Seconds 2
    }
    $logs = Invoke-TraceCompose -Arguments @("logs", "--no-color", "--tail=80", "node-red") 2>&1
    $diagnostic = ($logs | Out-String).Trim()
    throw "O Node-RED nao ficou saudavel; a instalacao foi interrompida. Logs recentes:`n$diagnostic"
}

try {
    if (-not (Test-Path -LiteralPath (Join-Path $PackageRoot "docker-compose.yml") -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path $PackageRoot ".env.example") -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path $PackageRoot "scripts\migrate.sh") -PathType Leaf)) {
        throw "Pacote incompleto. Baixe o TraceSetup.exe da release oficial da empresa e execute o assistente; não instale a máquina industrial diretamente de uma cópia parcial do código-fonte."
    }
    Initialize-DockerCli
    if (-not (Check-DockerReady)) {
        throw "Docker Desktop nao iniciou o mecanismo Linux. Abra o Docker Desktop, conclua a configuracao do WSL/reinicio se solicitado e execute este instalador novamente."
    }
    & docker.exe compose version
    Assert-Success "Docker Compose" $LASTEXITCODE

    $gitBash = Find-GitBash
    if (-not $gitBash) {
        $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
        if (-not $winget) {
            throw "Git for Windows nao encontrado. Instale-o e execute este instalador novamente."
        }
        Write-Host "Instalando Git for Windows para executar as migracoes do banco..."
        & $winget.Source install --exact --id Git.Git --accept-source-agreements --accept-package-agreements
        $gitBash = Find-GitBash
        if (-not $gitBash) {
            throw "Git for Windows nao foi localizado apos a instalacao. Reabra o CMD e execute este instalador novamente."
        }
    }

    $envPath = Join-Path $PackageRoot ".env"
    if (-not (Test-Path -LiteralPath $envPath)) {
        $defaultCentral = "https://trace.dallogix.com"
        $answer = if ($CentralUrl) { $CentralUrl } else { Read-Host "URL HTTPS do servidor central [$defaultCentral]" }
        if ([string]::IsNullOrWhiteSpace($answer)) { $answer = $defaultCentral }
        $centralUrl = Get-CentralBaseUrl $answer

        $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
        try {
            $replacement = @{
                MYSQL_PASSWORD = (New-HexSecret $generator)
                MYSQL_ROOT_PASSWORD = (New-HexSecret $generator)
                TRACE_DEVICE_TOKEN = (New-HexSecret $generator)
                KEYENCE_DEVICE_TOKEN = (New-HexSecret $generator)
                KEYENCE_FTP_PASSWORD = (New-HexSecret $generator)
                KEYENCE_ARCHIVE_ONLY = "0"
                KEYENCE_ARCHIVE_HOST_DIR = "C:/keyence/falhas"
                TRACE_CENTRAL_URL = $centralUrl
                TRACE_INSTALLATION_MODE = "local"
                WEB_BIND_ADDRESS = "127.0.0.1"
                BIND_ADDRESS = "127.0.0.1"
            }
            if ($ProductionMachine) { $replacement.COMPOSE_PROFILES = 'industrial' }
        } finally {
            $generator.Dispose()
        }

        $source = Join-Path $PackageRoot ".env.example"
        $seen = @{}
        $lines = New-Object 'System.Collections.Generic.List[string]'
        foreach ($line in [System.IO.File]::ReadAllLines($source)) {
            $key = ($line -split "=", 2)[0]
            if ($line.StartsWith("$key=") -and $replacement.ContainsKey($key)) {
                if ($seen.ContainsKey($key)) { throw "A configuracao de exemplo repete $key." }
                $seen[$key] = $true
                $lines.Add("$key=$($replacement[$key])")
            } else {
                $lines.Add($line)
            }
        }
        foreach ($key in $replacement.Keys) {
            if (-not $seen.ContainsKey($key)) { throw "A configuracao de exemplo nao contem $key." }
        }
        $utf8 = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllLines($envPath, $lines, $utf8)
        Write-Host "Arquivo .env criado com credenciais aleatorias. Os valores nao serao exibidos."
    } else {
        Write-Host "Arquivo .env existente preservado. Conferindo campos obrigatorios..."
    }

    $script:TraceComposeEnvFile = $envPath
    Ensure-RequiredEnvSecrets -EnvPath $envPath -InstallRoot $PackageRoot

    # O perfil padrão usa somente a leitura do sensor M2052 informado para o
    # INVT TS621. Os valores continuam sobrescrevíveis para outro modelo, mas
    # uma instalação nova nunca inicia com a sonda Modbus vazia.
    Ensure-EnvDefaults $envPath @{
        TRACE_MODBUS_UNIT_ID = '1'
        TRACE_MODBUS_HEARTBEAT_FUNCTION = '1'
        TRACE_MODBUS_HEARTBEAT_REGISTER = '2052'
        NODE_RED_ADMIN_USER = 'trace-admin'
        NODE_RED_ADMIN_PASSWORD_HASH = ''
    }
    $values = Read-EnvValues $envPath
    foreach ($key in @("MYSQL_PASSWORD", "MYSQL_ROOT_PASSWORD", "TRACE_DEVICE_TOKEN", "KEYENCE_DEVICE_TOKEN", "KEYENCE_FTP_PASSWORD", "TRACE_CENTRAL_URL")) {
        if (-not $values.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($values[$key])) {
            throw "O campo $key esta vazio em .env. Preencha-o sem alterar os demais valores e execute o instalador novamente."
        }
    }
    foreach ($key in @("WEB_BIND_ADDRESS", "BIND_ADDRESS")) {
        if (-not $values.ContainsKey($key) -or $values[$key] -ne "127.0.0.1") {
            throw "Mantenha $key=127.0.0.1 em .env antes de continuar."
        }
    }
    if (-not $values.ContainsKey("TRACE_INSTALLATION_MODE") -or $values["TRACE_INSTALLATION_MODE"] -ne "local") {
        throw "Defina TRACE_INSTALLATION_MODE=local em .env antes de continuar."
    }
    if ($values.ContainsKey("TRACE_LOCAL_SIMULATION") -and $values["TRACE_LOCAL_SIMULATION"] -ne "0") {
        throw "TRACE_LOCAL_SIMULATION deve ser 0 no PC industrial de produção. A instalação não continuará em modo simulado."
    }
    if ($values.ContainsKey("TRACE_SIMULATOR_ONLY_COMMANDS") -and $values["TRACE_SIMULATOR_ONLY_COMMANDS"] -ne "0") {
        throw "TRACE_SIMULATOR_ONLY_COMMANDS deve ser 0 no PC industrial de produção. A instalação não continuará com comandos simulados."
    }
    if ($ProductionMachine -and (-not $values.ContainsKey('COMPOSE_PROFILES') -or
        $values['COMPOSE_PROFILES'] -notmatch '(^|,)industrial(,|$)')) {
        throw "Defina COMPOSE_PROFILES=industrial em .env para este PC de producao."
    }
    $modbusUnit = 0
    $modbusFunction = 0
    $modbusRegister = 0
    if (-not [int]::TryParse($values['TRACE_MODBUS_UNIT_ID'], [ref]$modbusUnit) -or
        $modbusUnit -lt 0 -or $modbusUnit -gt 255) {
        throw "TRACE_MODBUS_UNIT_ID deve ser um valor entre 0 e 255."
    }
    if (-not [int]::TryParse($values['TRACE_MODBUS_HEARTBEAT_FUNCTION'], [ref]$modbusFunction) -or
        $modbusFunction -notin @(1, 2, 3, 4)) {
        throw "TRACE_MODBUS_HEARTBEAT_FUNCTION deve ser 1, 2, 3 ou 4."
    }
    if (-not [int]::TryParse($values['TRACE_MODBUS_HEARTBEAT_REGISTER'], [ref]$modbusRegister) -or
        $modbusRegister -lt 0 -or $modbusRegister -gt 65535) {
        throw "TRACE_MODBUS_HEARTBEAT_REGISTER deve ser um valor entre 0 e 65535."
    }
    $centralUrl = Get-CentralBaseUrl $values["TRACE_CENTRAL_URL"]
    if ($centralUrl -eq 'https://trace.santocloud.com.br') {
        $centralUrl = 'https://trace.dallogix.com'
        Set-EnvValue $envPath 'TRACE_CENTRAL_URL' $centralUrl
        $values['TRACE_CENTRAL_URL'] = $centralUrl
        Write-Host 'Servidor Central legado convertido para trace.dallogix.com.'
    }
    if ($centralUrl -ne $values["TRACE_CENTRAL_URL"].TrimEnd('/')) {
        throw "TRACE_CENTRAL_URL deve conter apenas a origem HTTPS do servidor, sem caminho de pagina."
    }
    if ($CentralUrl -and (Get-CentralBaseUrl $CentralUrl) -ne $centralUrl) {
        throw "O .env existente aponta para outro servidor central. Corrija TRACE_CENTRAL_URL conscientemente antes de continuar."
    }

    Set-Location -LiteralPath $PackageRoot
    if ($values.ContainsKey('COMPOSE_PROFILES') -and $values['COMPOSE_PROFILES'] -match '(^|,)industrial(,|$)') {
        Ensure-NodeRedAdminAuth $envPath $values
    }
    Protect-TraceMachineConfiguration -InstallRoot $PackageRoot
    Invoke-TraceCompose -Arguments @("config", "--quiet")
    Assert-Success "Configuracao do Docker Compose" $LASTEXITCODE
    $mysqlVolume = Initialize-TraceMySqlVolumeForInstall -InstallRoot $PackageRoot -AllowFreshDatabaseCreation:$AllowFreshDatabaseCreation

    Write-Host "[1/3] Iniciando banco e PHP..."
    Invoke-TraceCompose -Arguments @("up", "-d", "--build", "mysql", "php")
    Assert-Success "Inicializacao do banco e PHP" $LASTEXITCODE
    Write-Host "Aguardando o MySQL concluir a inicializacao antes das migrations..."
    Wait-MySqlHealthy
    if ($mysqlVolume.NeedsDatabaseValidation) {
        if (-not (Test-TraceMySqlApplicationSchema -InstallRoot $PackageRoot)) {
            throw "O volume MySQL existente nao confirmou a tabela carregamentos. Migrations bloqueadas para proteger os dados existentes."
        }
        Register-TraceMySqlVolumeAfterDatabaseCheck -InstallRoot $PackageRoot | Out-Null
    }

    Write-Host "[2/3] Aplicando migracoes antes de iniciar os demais servicos..."
    & $gitBash "scripts/migrate.sh"
    Assert-Success "Migracoes do banco" $LASTEXITCODE

    Write-Host "[3/3] Iniciando os servicos locais..."
    Invoke-TraceCompose -Arguments @("up", "-d", "--build")
    Assert-Success "Inicializacao do Trace" $LASTEXITCODE
    if ($values.ContainsKey('COMPOSE_PROFILES') -and $values['COMPOSE_PROFILES'] -match '(^|,)industrial(,|$)') {
        Write-Host "Aguardando o gateway Node-RED ficar saudavel..."
        Wait-NodeRedHealthy
    }

    $webPort = 8080
    if ($values.ContainsKey("WEB_PORT") -and $values["WEB_PORT"] -ne "") {
        if (-not [int]::TryParse($values["WEB_PORT"], [ref]$webPort) -or $webPort -lt 1 -or $webPort -gt 65535) {
            throw "WEB_PORT invalida em .env."
        }
    }
    $localUrl = "http://127.0.0.1:$webPort"
    $activationUrl = "$localUrl/api/ativacao_local.php"
    $lastProbeError = "A API de ativacao ainda nao respondeu."
    $ready = $false
    for ($attempt = 1; $attempt -le 18; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $activationUrl -TimeoutSec 5
            $data = $response.Content | ConvertFrom-Json
            if ($response.StatusCode -eq 200 -and $data.data.enabled -eq $true) {
                $ready = $true
                break
            }
            $lastProbeError = "A API respondeu, mas a ativacao local nao esta habilitada."
        } catch {
            $lastProbeError = $_.Exception.Message
        }
        Start-Sleep -Seconds 3
    }
    if (-not $ready) {
        $containerStatus = Invoke-TraceCompose -Arguments @("ps", "--all") 2>&1
        $serviceLogs = Invoke-TraceCompose -Arguments @("logs", "--no-color", "--tail=80", "php", "mysql") 2>&1
        $diagnostic = @(
            "Erro da sonda: $lastProbeError"
            "Estado dos containers:"
            ($containerStatus | Out-String).Trim()
            "Logs recentes de PHP/MySQL:"
            ($serviceLogs | Out-String).Trim()
        ) -join "`n"
        throw "Os containers iniciaram, mas a ativacao local nao ficou pronta. Diagnostico:`n$diagnostic"
    }

    Write-Host "OK: Trace local pronto para ativacao em $localUrl"
    Write-Host "Modo físico: simulação desativada; comandos não serão falsamente confirmados."
    Write-Host "Depois da ativação, vincule a Dala e confirme CLP ONLINE antes de testar Ligar esteira."
    Write-Host "Use o codigo de ativacao e o administrador da empresa cadastrados no servidor central."
    if (-not $NoBrowser) {
        try {
            Start-Process $localUrl | Out-Null
        } catch {
            Write-Host "Nao foi possivel abrir o navegador automaticamente. Abra $localUrl manualmente."
        }
    }
} catch {
    [Console]::Error.WriteLine("ERRO: $($_.Exception.Message)")
    exit 1
}
