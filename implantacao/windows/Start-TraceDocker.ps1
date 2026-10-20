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
    if ($probe.ExitCode -ne 0) { return $false }
    return ((Get-Content $stdoutPath -Raw -ErrorAction SilentlyContinue).Trim() -eq 'linux')
}

for ($attempt = 0; $attempt -lt 60; $attempt++) {
    # O teste real do CLI e a evidência de que o motor Linux está utilizável;
    # a existência do pipe, isoladamente, não confirma que o motor responde.
    if (Test-DockerLinuxEngine) {
        Write-DockerStartLog 'Mecanismo Linux do Docker respondeu ao teste de saúde.'
        exit 0
    }
    Start-Sleep -Seconds 2
}

Write-DockerStartLog 'Docker Desktop abriu, mas o mecanismo Linux nao respondeu ao teste de saude em 120 segundos.'
exit 1
