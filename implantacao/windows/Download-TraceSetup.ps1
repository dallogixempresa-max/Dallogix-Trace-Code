$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$repository = 'dallogixempresa-max/Dallogix-Trace-Code'
$releaseApi = "https://api.github.com/repos/$repository/releases/latest"
$temporaryRoot = $null

try {
    Write-Host 'Localizando a última release estável do Dallogix Trace...'
    $release = Invoke-RestMethod -Uri $releaseApi -Headers @{ 'User-Agent' = 'Dallogix-Trace-Installer' } -TimeoutSec 30
    if ($release.draft -or $release.prerelease -or $release.tag_name -notmatch '^v\d+\.\d+\.\d+$') {
        throw 'A última publicação não é uma release estável válida.'
    }

    $version = [string]$release.tag_name
    $expectedNames = @('TraceSetup.exe', 'TraceSetup.sha256.txt')
    $assets = @{}
    foreach ($name in $expectedNames) {
        $asset = @($release.assets | Where-Object { $_.name -ceq $name }) | Select-Object -First 1
        if (-not $asset -or $asset.state -ne 'uploaded' -or $asset.size -le 0) {
            throw "O instalador da release $version está incompleto (asset $name ausente)."
        }
        $uri = $null
        $expectedPath = "/$repository/releases/download/$version/$name"
        if (-not [Uri]::TryCreate([string]$asset.browser_download_url, [UriKind]::Absolute, [ref]$uri) -or
            $uri.Scheme -ne 'https' -or $uri.Host -ne 'github.com' -or $uri.AbsolutePath -cne $expectedPath) {
            throw "O asset $name da release não aponta para a publicação oficial esperada."
        }
        $assets[$name] = $uri.AbsoluteUri
    }

    $temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ("DallogixTraceSetup-$version-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temporaryRoot | Out-Null
    $installerPath = Join-Path $temporaryRoot 'TraceSetup.exe'
    $checksumPath = Join-Path $temporaryRoot 'TraceSetup.sha256.txt'
    Write-Host "Baixando o instalador oficial $version..."
    Invoke-WebRequest -Uri $assets['TraceSetup.exe'] -OutFile $installerPath -TimeoutSec 600 -UseBasicParsing
    Invoke-WebRequest -Uri $assets['TraceSetup.sha256.txt'] -OutFile $checksumPath -TimeoutSec 30 -UseBasicParsing

    $checksumText = [IO.File]::ReadAllText($checksumPath).Trim()
    if ($checksumText -notmatch '\A([0-9a-fA-F]{64})\s+\*?TraceSetup\.exe\z') {
        throw 'O arquivo SHA-256 da release está em formato inválido.'
    }
    $expectedHash = $Matches[1]
    $actualHash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA256).Hash
    if ($actualHash -ine $expectedHash) {
        throw 'A verificação de integridade do instalador falhou. O arquivo não será executado; baixe novamente a release oficial.'
    }
    if ((Get-Item -LiteralPath $installerPath).Length -lt 10MB) {
        throw 'O instalador baixado está incompleto (arquivo menor que o esperado).'
    }

    Write-Host 'Integridade do instalador conferida.'
    Write-Host "Instalador oficial $version baixado e SHA-256 conferido. Abrindo assistente..."
    $process = Start-Process -FilePath $installerPath -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw "O assistente de instalação terminou com o código $($process.ExitCode)." }
} catch {
    [Console]::Error.WriteLine("Não foi possível baixar e iniciar o instalador oficial: $($_.Exception.Message)")
    exit 1
} finally {
    if ($temporaryRoot -and (Test-Path -LiteralPath $temporaryRoot)) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
