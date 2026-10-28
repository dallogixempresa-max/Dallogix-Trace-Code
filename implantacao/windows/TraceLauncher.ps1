$ErrorActionPreference = "Stop"

$InstallRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$DatabaseVolumeGuard = Join-Path $PSScriptRoot 'TraceDatabaseVolume.ps1'
if (-not (Test-Path -LiteralPath $DatabaseVolumeGuard -PathType Leaf)) { throw "Protecao do volume MySQL ausente: $DatabaseVolumeGuard" }
. $DatabaseVolumeGuard
$ComposeFile = Join-Path $InstallRoot "docker-compose.yml"
$EnvFile = Join-Path $InstallRoot ".env"
$TraceUrl = if ($env:TRACE_KIOSK_URL) { $env:TRACE_KIOSK_URL } else { "http://127.0.0.1:8080" }
$Browser = $env:TRACE_KIOSK_BROWSER

function Find-Browser {
    param([string]$Configured)
    if ($Configured -and (Get-Command $Configured -ErrorAction SilentlyContinue)) {
        return (Get-Command $Configured).Source
    }
    foreach ($candidate in @("msedge.exe", "chrome.exe", "chromium.exe")) {
        $command = Get-Command $candidate -ErrorAction SilentlyContinue
        if ($command) { return $command.Source }
    }
    throw "Nenhum Edge, Chrome ou Chromium foi encontrado."
}

if (-not (Test-Path $ComposeFile)) { throw "docker-compose.yml não encontrado em $InstallRoot" }
if (-not (Test-Path $EnvFile)) { throw "Crie o arquivo .env em $InstallRoot antes de iniciar o Trace." }
if (-not (Get-Command docker.exe -ErrorAction SilentlyContinue)) { throw "Docker Desktop não está instalado ou não está no PATH." }

Set-Location $InstallRoot
Assert-TraceMySqlVolumeExists -InstallRoot $InstallRoot -RequireMarker | Out-Null
& docker compose --project-directory $InstallRoot --env-file $EnvFile --profile industrial up -d --remove-orphans

$healthy = $false
$lastActivationError = "A API ainda nao respondeu."
for ($attempt = 1; $attempt -le 30; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri "$TraceUrl/api/ativacao_local.php" -UseBasicParsing -TimeoutSec 3
        if ($response.StatusCode -eq 200) { $healthy = $true; break }
        $lastActivationError = "HTTP $($response.StatusCode)"
    } catch { $lastActivationError = $_.Exception.Message }
    Start-Sleep -Seconds 2
}
if (-not $healthy) { throw "A API de ativacao local nao ficou pronta em $TraceUrl. Confira as migrations e os logs do PHP/MySQL. Ultimo erro: $lastActivationError" }

$browserPath = Find-Browser $Browser
$browserArgs = @(
    "--kiosk", $TraceUrl,
    "--incognito",
    "--no-first-run",
    "--no-default-browser-check",
    "--disable-session-crashed-bubble",
    "--disable-infobars",
    "--disable-pinch",
    "--disable-smooth-scrolling",
    "--disable-features=ElasticOverscroll",
    "--overscroll-history-navigation=0",
    "--password-store=basic"
)

while ($true) {
    $process = Start-Process -FilePath $browserPath -ArgumentList $browserArgs -PassThru
    $process.WaitForExit()
    Start-Sleep -Seconds 3
}
