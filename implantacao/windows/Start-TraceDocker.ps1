param(
    [string]$InstallRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

$ErrorActionPreference = 'Stop'
$logRoot = Join-Path $InstallRoot 'armazenamento\logs'
New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
$logPath = Join-Path $logRoot 'docker-start.log'

function Write-DockerStartLog([string]$Message) {
    Add-Content -LiteralPath $logPath -Value ("{0:o} {1}" -f (Get-Date), $Message)
}

$desktopCandidates = @(
    (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'),
    (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe')
)
$programFilesX86 = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
if ($programFilesX86) { $desktopCandidates += Join-Path $programFilesX86 'Docker\Docker\Docker Desktop.exe' }
$desktop = $desktopCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $desktop) {
    Write-DockerStartLog 'Docker Desktop nao encontrado.'
    exit 1
}

# O serviço privilegiado é instalado como automático. Tentar iniciá-lo aqui
# evita que uma sessão gráfica recém-aberta fique presa em "Engine starting".
try {
    $service = Get-Service -Name 'com.docker.service' -ErrorAction Stop
    if ($service.StartType -ne 'Automatic') {
        Set-Service -Name 'com.docker.service' -StartupType Automatic -ErrorAction Stop
    }
    if ($service.Status -ne 'Running') {
        Start-Service -Name 'com.docker.service' -ErrorAction Stop
    }
} catch {
    Write-DockerStartLog ("Servico privilegiado nao iniciado: " + $_.Exception.Message)
}

# O agendador pode executar este script em uma sessão diferente da sessão
# gráfica (por exemplo, durante uma manutenção remota). Não abra uma segunda
# instância só porque o processo existente pertence a outra sessão.
$existing = @(Get-Process -Name 'Docker Desktop' -ErrorAction SilentlyContinue)
if ($existing.Count -eq 0) {
    Start-Process -FilePath $desktop -WorkingDirectory (Split-Path -Parent $desktop) | Out-Null
    Write-DockerStartLog 'Docker Desktop iniciado na sessao grafica.'
} else {
    Write-DockerStartLog 'Docker Desktop ja estava em execucao em uma sessao grafica.'
}

$dockerCandidates = @(
    (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe'),
    (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe')
)
if ($programFilesX86) { $dockerCandidates += Join-Path $programFilesX86 'Docker\Docker\resources\bin\docker.exe' }
$docker = $dockerCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $docker) {
    Write-DockerStartLog 'Docker CLI nao encontrado para validar o motor Linux.'
    exit 1
}

function Test-DockerLinuxEngine {
    $stdoutPath = Join-Path $env:TEMP 'trace-docker-start-out.txt'
    $stderrPath = Join-Path $env:TEMP 'trace-docker-start-err.txt'
    Remove-Item $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    $probe = Start-Process -FilePath $docker -ArgumentList @('info', '--format', '{{.OSType}}') -PassThru -WindowStyle Hidden -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
    if (-not $probe.WaitForExit(5000)) {
        & taskkill.exe /PID $probe.Id /T /F 2>$null | Out-Null
        return $false
    }
    # Get-Content -Raw returns $null for a zero-byte file in Windows
    # PowerShell 5.1, so calling .Trim() directly aborts the startup task.
    # ReadAllText consistently returns an empty string for the redirected
    # stderr file when docker info succeeds without diagnostics.
    $engineType = [System.IO.File]::ReadAllText($stdoutPath).Trim()
    $probeError = [System.IO.File]::ReadAllText($stderrPath).Trim()
    # Em tarefas do Windows PowerShell 5.1 o ExitCode do objeto retornado por
    # Start-Process pode permanecer nulo mesmo depois de WaitForExit concluir.
    # O tipo de engine esperado e stderr vazio são sinais estáveis do processo.
    return ($engineType -eq 'linux' -and [string]::IsNullOrWhiteSpace($probeError))
}

function Read-TraceEnvFile([string]$Path) {
    $values = @{}
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $key = $matches[1]
            if ($values.ContainsKey($key)) { throw "A configuração local contém a chave duplicada $key." }
            $values[$key] = $matches[2].Trim().Trim('"').Trim("'")
        }
    }
    return $values
}

function Test-TraceKeyenceFirewall([string]$LocalAddress, [string]$RemoteAddress, [string[]]$Ports) {
    $rules = @(Get-NetFirewallRule -Name 'DallogixTraceKeyenceScanner' -ErrorAction Stop)
    foreach ($rule in $rules) {
        if (-not $rule.Enabled -or $rule.Action -ne 'Allow' -or $rule.Direction -ne 'Inbound') { continue }
        $address = $rule | Get-NetFirewallAddressFilter
        $port = $rule | Get-NetFirewallPortFilter
        $localValues = @($address.LocalAddress | ForEach-Object { [string]$_ })
        $remoteValues = @($address.RemoteAddress | ForEach-Object { [string]$_ })
        $portValues = @($port.LocalPort | ForEach-Object { [string]$_ })
        $localMatches = $localValues -contains $LocalAddress -or $localValues -contains "$LocalAddress/32"
        $remoteMatches = $remoteValues -contains $RemoteAddress -or $remoteValues -contains "$RemoteAddress/32"
        $portsMatch = @($Ports | Where-Object { $portValues -notcontains $_ }).Count -eq 0
        if ($localMatches -and $remoteMatches -and $port.Protocol -eq 'TCP' -and $portsMatch) { return $true }
    }
    throw 'A regra do Firewall do leitor não está ativa ou não limita IP e portas conforme a configuração.'
}

function Test-TracePublishedPort([object]$PortMappings, [string]$ContainerPort, [string]$HostAddress, [string]$HostPort) {
    $property = $PortMappings.PSObject.Properties[$ContainerPort]
    if ($null -eq $property) { throw "A porta $HostPort do FTP não está publicada pelo contêiner." }
    $matchingBinding = @($property.Value | Where-Object {
        [string]$_.HostIp -eq $HostAddress -and [string]$_.HostPort -eq $HostPort
    })
    if ($matchingBinding.Count -eq 0) { throw "A porta $HostPort do FTP não está publicada no IP industrial configurado." }
}

$engineReady = $false
for ($attempt = 0; $attempt -lt 60; $attempt++) {
    # O teste real do CLI e a evidência de que o motor Linux está utilizável;
    # a existência do pipe, isoladamente, não confirma que o motor responde.
    if (Test-DockerLinuxEngine) {
        Write-DockerStartLog 'Mecanismo Linux do Docker respondeu ao teste de saúde.'
        $engineReady = $true
        break
    }
    Start-Sleep -Seconds 2
}

if (-not $engineReady) {
    Write-DockerStartLog 'Docker Desktop abriu, mas o mecanismo Linux nao respondeu ao teste de saude em 120 segundos.'
    exit 1
}

$composeFile = Join-Path $InstallRoot 'docker-compose.yml'
$envFile = Join-Path $InstallRoot '.env'
if (-not (Test-Path -LiteralPath $composeFile -PathType Leaf) -or -not (Test-Path -LiteralPath $envFile -PathType Leaf)) {
    Write-DockerStartLog 'Inicializacao interrompida: docker-compose.yml ou .env ausente.'
    exit 1
}

try {
    $environment = Read-TraceEnvFile $envFile
    $machineConfigPath = Join-Path $InstallRoot 'config\machine.json'
    $keyenceSelected = $false
    if (Test-Path -LiteralPath $machineConfigPath -PathType Leaf) {
        $machineConfig = Get-Content -LiteralPath $machineConfigPath -Raw | ConvertFrom-Json
        $keyenceSelected = [string]$machineConfig.scanner_model -eq 'KEYENCE SR-X100W'
    }
    $keyenceEnabled = [string]$environment['KEYENCE_ENABLED'] -eq '1'
    if ($keyenceSelected -and -not $keyenceEnabled) {
        throw 'O PC está configurado para o SR-X100W, mas KEYENCE_ENABLED não está ativo.'
    }
    if ($keyenceEnabled -and -not $keyenceSelected) {
        throw 'KEYENCE_ENABLED está ativo, mas o machine.json não seleciona o SR-X100W.'
    }
    if ($keyenceEnabled) {
        $requiredKeyenceKeys = @(
            'KEYENCE_DEVICE_TOKEN', 'KEYENCE_FTP_PASSWORD', 'KEYENCE_FTP_PASV_ADDRESS',
            'KEYENCE_FTP_ALLOWED_ADDRESS', 'KEYENCE_TCP_ALLOWED_ADDRESS', 'KEYENCE_BIND_ADDRESS'
        )
        $missingKeyenceKeys = @($requiredKeyenceKeys | Where-Object {
            -not $environment.ContainsKey($_) -or [string]::IsNullOrWhiteSpace([string]$environment[$_])
        })
        if ($missingKeyenceKeys.Count -gt 0) {
            throw ('Configuração Keyence incompleta; chaves ausentes: ' + ($missingKeyenceKeys -join ', '))
        }
        if ([string]$environment['KEYENCE_BIND_ADDRESS'] -ne [string]$environment['KEYENCE_FTP_PASV_ADDRESS']) {
            throw 'KEYENCE_BIND_ADDRESS e KEYENCE_FTP_PASV_ADDRESS precisam apontar para o IP industrial deste PC.'
        }
        foreach ($addressKey in @('KEYENCE_BIND_ADDRESS', 'KEYENCE_FTP_ALLOWED_ADDRESS', 'KEYENCE_TCP_ALLOWED_ADDRESS')) {
            $parsedAddress = $null
            if (-not [System.Net.IPAddress]::TryParse([string]$environment[$addressKey], [ref]$parsedAddress) -or
                $parsedAddress.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) {
                throw "$addressKey precisa conter um IPv4 válido."
            }
        }
        if ([string]$environment['KEYENCE_FTP_ALLOWED_ADDRESS'] -ne [string]$environment['KEYENCE_TCP_ALLOWED_ADDRESS']) {
            throw 'As listas de origem FTP e TCP precisam apontar para o mesmo leitor Keyence.'
        }
        if ([string]$environment['KEYENCE_FTP_ALLOWED_ADDRESS'] -ne [string]$machineConfig.keyence_reader_ip -or
            [string]$environment['KEYENCE_BIND_ADDRESS'] -ne [string]$machineConfig.keyence_pc_ip) {
            throw 'Os IPs do .env e do machine.json divergem; revise a configuração industrial.'
        }

        $interfaceReady = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            $configuredAddress = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
                Where-Object { $_.IPAddress -eq [string]$environment['KEYENCE_BIND_ADDRESS'] })
            if ($configuredAddress.Count -gt 0) {
                $interfaceReady = $true
                break
            }
            Start-Sleep -Seconds 2
        }
        if (-not $interfaceReady) { throw 'A interface industrial ainda não possui o IP configurado para o leitor Keyence.' }
    }

    $composeArguments = @('--project-directory', $InstallRoot, '--env-file', $envFile, '--profile', 'industrial')
    & $docker compose @composeArguments config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'A configuração do Docker Compose não passou na validação.' }

    & $docker compose @composeArguments up -d
    if ($LASTEXITCODE -ne 0) { throw 'O Docker Compose não conseguiu iniciar os serviços do Trace.' }
    Write-DockerStartLog 'Servicos do Trace solicitados ao Docker Compose apos o login.'

    if ($keyenceEnabled) {
        # Capture the full native-process output before selecting a line.
        # Select-Object -First 1 directly in the pipeline can close stdout
        # early and make Docker Compose report exit code -1 in PowerShell 5.1.
        $containerOutput = @(& $docker compose @composeArguments ps -q keyence-worker)
        $composeExitCode = $LASTEXITCODE
        $containerId = $containerOutput |
            Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } |
            Select-Object -First 1
        if ($composeExitCode -ne 0 -or [string]::IsNullOrWhiteSpace([string]$containerId)) {
            throw 'O serviço do leitor Keyence não possui um contêiner iniciado.'
        }
        $workerHealthy = $false
        for ($attempt = 0; $attempt -lt 90; $attempt++) {
            $workerHealth = (& $docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' ([string]$containerId) 2>$null).Trim()
            if ($LASTEXITCODE -eq 0 -and $workerHealth -eq 'healthy') {
                $workerHealthy = $true
                break
            }
            if ($LASTEXITCODE -eq 0 -and $workerHealth -in @('exited', 'dead')) {
                throw 'O contêiner do leitor Keyence encerrou durante a inicialização.'
            }
            Start-Sleep -Seconds 2
        }
        if (-not $workerHealthy) { throw 'O worker Keyence não confirmou saúde em 180 segundos.' }

        $ftpPort = 2121
        if ($environment.ContainsKey('KEYENCE_FTP_PORT') -and -not [int]::TryParse([string]$environment['KEYENCE_FTP_PORT'], [ref]$ftpPort)) {
            throw 'KEYENCE_FTP_PORT precisa ser um número de porta válido.'
        }
        if ($ftpPort -lt 1 -or $ftpPort -gt 65535) { throw 'KEYENCE_FTP_PORT precisa estar entre 1 e 65535.' }
        $passiveStart = 21100
        $passiveEnd = 21109
        if ($environment.ContainsKey('KEYENCE_FTP_PASSIVE_START') -and -not [int]::TryParse([string]$environment['KEYENCE_FTP_PASSIVE_START'], [ref]$passiveStart)) {
            throw 'KEYENCE_FTP_PASSIVE_START precisa ser uma porta válida.'
        }
        if ($environment.ContainsKey('KEYENCE_FTP_PASSIVE_END') -and -not [int]::TryParse([string]$environment['KEYENCE_FTP_PASSIVE_END'], [ref]$passiveEnd)) {
            throw 'KEYENCE_FTP_PASSIVE_END precisa ser uma porta válida.'
        }
        if ($passiveStart -lt 1 -or $passiveEnd -gt 65535 -or $passiveStart -gt $passiveEnd) {
            throw 'A faixa passiva FTP está fora do intervalo ou invertida.'
        }

        $firewallPorts = @('9004', '19000', [string]$ftpPort, "$passiveStart-$passiveEnd")
        Test-TraceKeyenceFirewall `
            -LocalAddress ([string]$environment['KEYENCE_BIND_ADDRESS']) `
            -RemoteAddress ([string]$environment['KEYENCE_FTP_ALLOWED_ADDRESS']) `
            -Ports $firewallPorts

        $portMappingsJson = & $docker inspect --format '{{json .NetworkSettings.Ports}}' ([string]$containerId) 2>$null
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace([string]$portMappingsJson)) {
            throw 'Não foi possível confirmar as portas publicadas do contêiner Keyence.'
        }
        $portMappings = $portMappingsJson | ConvertFrom-Json
        Test-TracePublishedPort $portMappings '2121/tcp' ([string]$environment['KEYENCE_BIND_ADDRESS']) ([string]$ftpPort)
        for ($portNumber = $passiveStart; $portNumber -le $passiveEnd; $portNumber++) {
            Test-TracePublishedPort $portMappings "$portNumber/tcp" ([string]$environment['KEYENCE_BIND_ADDRESS']) ([string]$portNumber)
        }
        Write-DockerStartLog 'Keyence saudável; FTP ativo, portas de controle e passivas publicadas no IP industrial, e Firewall restrito ao leitor. Envio de imagem real ainda depende do leitor.'
    }

    Write-DockerStartLog 'Inicializacao do Trace concluida.'
    exit 0
} catch {
    Write-DockerStartLog ("Inicializacao do Trace nao concluida: " + $_.Exception.Message)
    exit 1
}
