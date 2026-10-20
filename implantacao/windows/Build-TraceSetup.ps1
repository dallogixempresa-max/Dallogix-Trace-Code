$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$app = Join-Path $root "desktop\DallogixTrace\DallogixTrace.csproj"
if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) { throw "Instale o .NET SDK 8 antes de compilar o aplicativo." }
$inno = Get-Command ISCC.exe -ErrorAction SilentlyContinue
if (-not $inno) {
    $programFilesX86 = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
    if ($programFilesX86) {
        $candidate = Join-Path $programFilesX86 'Inno Setup 6\ISCC.exe'
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { $inno = $candidate }
    }
}
if (-not $inno) { throw "Instale o Inno Setup antes de gerar o TraceSetup.exe." }
if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) { throw "Git for Windows e necessario para identificar a release." }
$versionOutput = & git.exe -C $root describe --tags --exact-match HEAD
$version = ([string]$versionOutput).Trim()
if ($LASTEXITCODE -ne 0 -or $version -notmatch '^v\d+\.\d+\.\d+$') { throw "Compile o instalador a partir de uma tag de release aprovada (vN.N.N)." }
$changed = @(& git.exe -C $root status --porcelain --untracked-files=no)
if ($LASTEXITCODE -ne 0 -or $changed.Count -gt 0) { throw "O codigo rastreado possui alteracoes locais; use a tag de release limpa." }
$versionFile = Join-Path $root 'trace-build-version.txt'
if (Test-Path -LiteralPath $versionFile) { throw "Remova o marcador gerado de uma compilacao anterior: $versionFile" }
$commitFile = Join-Path $root 'trace-build-commit.txt'
if (Test-Path -LiteralPath $commitFile) { throw "Remova o marcador gerado de uma compilacao anterior: $commitFile" }
$commit = ([string](& git.exe -C $root rev-parse HEAD)).Trim()
if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[0-9a-fA-F]{40}$') { throw 'Nao foi possivel identificar o commit da release.' }
$webViewBootstrapper = Join-Path $PSScriptRoot 'Dependencies\MicrosoftEdgeWebView2Setup.exe'
$webViewDependencyDirectory = Split-Path -Parent $webViewBootstrapper
New-Item -ItemType Directory -Force -Path $webViewDependencyDirectory | Out-Null
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -UseBasicParsing -Uri 'https://go.microsoft.com/fwlink/p/?LinkId=2124703' -OutFile $webViewBootstrapper
} catch {
    throw "Nao foi possivel obter o instalador oficial do WebView2 Runtime: $($_.Exception.Message)"
}
$webViewSignature = Get-AuthenticodeSignature -FilePath $webViewBootstrapper
if ($webViewSignature.Status -ne 'Valid' -or
    -not $webViewSignature.SignerCertificate.Subject.Contains('Microsoft Corporation')) {
    Remove-Item -LiteralPath $webViewBootstrapper -Force -ErrorAction SilentlyContinue
    throw 'O instalador do WebView2 nao possui assinatura digital valida da Microsoft.'
}
$env:TRACE_INSTALL_VERSION = $version.TrimStart('v')
try {
    [IO.File]::WriteAllText($versionFile, "$version`n", (New-Object System.Text.UTF8Encoding($false)))
    [IO.File]::WriteAllText($commitFile, "$commit`n", (New-Object System.Text.UTF8Encoding($false)))
    & dotnet publish $app -c Release -r win-x64 --self-contained true
    if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar o aplicativo Windows." }
    $innoExe = if ($inno -is [string]) { $inno } else { $inno.Source }
    & $innoExe (Join-Path $PSScriptRoot "TraceSetup.iss")
    if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar o instalador Windows." }
} finally {
    Remove-Item -LiteralPath $versionFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $commitFile -Force -ErrorAction SilentlyContinue
    Remove-Item Env:TRACE_INSTALL_VERSION -ErrorAction SilentlyContinue
}
