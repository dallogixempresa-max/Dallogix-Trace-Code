$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($env:UPDATE_SIGNING_PRIVATE_KEY)) {
    throw 'UPDATE_SIGNING_PRIVATE_KEY is required; deployment will not start.'
}

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$tempRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }
$suffix = [Guid]::NewGuid().ToString('N')
$privatePath = Join-Path $tempRoot "trace-update-private-$suffix.pem"
$derivedPublic = Join-Path $tempRoot "trace-update-derived-$suffix.der"
$expectedPublic = Join-Path $tempRoot "trace-update-expected-$suffix.der"

try {
    [IO.File]::WriteAllText(
        $privatePath,
        $env:UPDATE_SIGNING_PRIVATE_KEY,
        (New-Object System.Text.UTF8Encoding($false))
    )
    $openssl = Join-Path $env:ProgramFiles 'Git\usr\bin\openssl.exe'
    if (-not (Test-Path -LiteralPath $openssl -PathType Leaf)) {
        throw 'OpenSSL from Git for Windows was not found.'
    }

    & $openssl pkey -in $privatePath -pubout -outform DER -out $derivedPublic
    if ($LASTEXITCODE -ne 0) { throw 'The updater private key could not be parsed.' }
    $publicKey = Join-Path $root 'servidor\configuracao\trace-update-public.pem'
    & $openssl pkey -pubin -in $publicKey -outform DER -out $expectedPublic
    if ($LASTEXITCODE -ne 0) { throw 'The tracked updater public key could not be parsed.' }

    $derivedHash = (Get-FileHash -LiteralPath $derivedPublic -Algorithm SHA256).Hash
    $expectedHash = (Get-FileHash -LiteralPath $expectedPublic -Algorithm SHA256).Hash
    if ($derivedHash -ne $expectedHash) {
        throw 'UPDATE_SIGNING_PRIVATE_KEY does not match the tracked updater public key.'
    }

    Write-Output 'Production updater signing key passed validation.'
} finally {
    foreach ($path in @($privatePath, $derivedPublic, $expectedPublic)) {
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Force
        }
    }
}
