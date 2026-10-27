param([string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)))
$ErrorActionPreference = 'Stop'
$configurator = Join-Path $PackageRoot 'implantacao\windows\Configure-TraceIndustrial.ps1'
if (-not (Test-Path -LiteralPath $configurator -PathType Leaf)) {
    throw "Configurador industrial não encontrado: $configurator"
}
& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -STA -File $configurator -PackageRoot $PackageRoot
if ($LASTEXITCODE -ne 0) { throw "O configurador industrial terminou com código $LASTEXITCODE." }
