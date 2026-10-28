$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$installerPath = Join-Path $PSScriptRoot 'Instalar-TraceLocal.ps1'
$tokens = $null
$parseErrors = $null
$installerAst = [System.Management.Automation.Language.Parser]::ParseFile(
    $installerPath,
    [ref]$tokens,
    [ref]$parseErrors
)
if ($parseErrors.Count -gt 0) {
    $parseErrors | Format-List | Out-String | Write-Error
}

foreach ($name in @('Read-EnvValues', 'Ensure-EnvDefaults', 'New-HexSecret', 'Ensure-RequiredEnvSecrets', 'Invoke-TraceCompose')) {
    $definition = $installerAst.Find({
        param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name
    }, $true)
    if (-not $definition) { throw "Required installer function is missing: $name" }
    . ([scriptblock]::Create($definition.Extent.Text))
}

$script:TestDatabaseVolumeExists = $false
$script:TestVolumeMarkerExists = $false
$script:TestPriorInstallExists = $false
$script:CapturedDockerArguments = @()

function Get-TraceMySqlVolumeSnapshot {
    param([string]$InstallRoot, [string]$EnvPath)
    return [pscustomobject]@{ Exists = $script:TestDatabaseVolumeExists }
}

function Read-TraceMySqlVolumeMarker { return $script:TestVolumeMarkerExists }

function Test-TraceMySqlPriorInstallEvidence {
    param([string]$InstallRoot)
    return $script:TestPriorInstallExists
}

function docker.exe {
    $script:CapturedDockerArguments = @($args)
    return 0
}

$testRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("trace-installer-env-test-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $envPath = Join-Path $testRoot '.env'
    $blankEnv = @(
        'MYSQL_PASSWORD=',
        'MYSQL_ROOT_PASSWORD=',
        'TRACE_DEVICE_TOKEN=',
        'KEYENCE_DEVICE_TOKEN=',
        'KEYENCE_FTP_PASSWORD='
    )
    [System.IO.File]::WriteAllLines($envPath, $blankEnv, (New-Object System.Text.UTF8Encoding($false)))

    $script:TestDatabaseVolumeExists = $false
    Ensure-RequiredEnvSecrets -EnvPath $envPath -InstallRoot $testRoot
    $generated = Read-EnvValues $envPath
    foreach ($key in @('MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'TRACE_DEVICE_TOKEN', 'KEYENCE_DEVICE_TOKEN', 'KEYENCE_FTP_PASSWORD')) {
        if ($generated[$key] -notmatch '^[A-F0-9]{64}$') {
            throw "Fresh installation did not generate a valid value for $key."
        }
    }
    if (@($generated.Values | Sort-Object -Unique).Count -ne 5) {
        throw 'Fresh installation generated duplicate secret values.'
    }

    [System.IO.File]::WriteAllLines($envPath, $blankEnv, (New-Object System.Text.UTF8Encoding($false)))
    $before = [System.IO.File]::ReadAllText($envPath)
    $script:TestDatabaseVolumeExists = $true
    $refusedExistingDatabase = $false
    try {
        Ensure-RequiredEnvSecrets -EnvPath $envPath -InstallRoot $testRoot
    } catch {
        if ($_.Exception.Message -notmatch 'preservar os dados|proteger os dados') { throw }
        $refusedExistingDatabase = $true
    }
    if (-not $refusedExistingDatabase) { throw 'Existing database without credentials was not refused.' }
    if ([System.IO.File]::ReadAllText($envPath) -cne $before) {
        throw 'Installer modified .env while refusing an existing database.'
    }

    $script:TraceComposeRoot = $testRoot
    $script:TraceComposeEnvFile = $envPath
    $script:CapturedDockerArguments = @()
    Invoke-TraceCompose -Arguments @('config', '--quiet') | Out-Null
    $argumentText = $script:CapturedDockerArguments -join ' '
    if ($argumentText -notmatch '--project-directory' -or $argumentText -notmatch '--env-file' -or $argumentText -notmatch [regex]::Escape($envPath)) {
        throw 'Installer did not pass the project directory and generated .env to Docker Compose.'
    }

    Write-Host 'Installer environment regression checks passed.'
} finally {
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
