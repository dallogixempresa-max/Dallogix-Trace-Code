$ErrorActionPreference = 'Stop'

$requiredVariables = @(
    'AUTHENTICODE_CERTIFICATE_PFX_BASE64',
    'AUTHENTICODE_CERTIFICATE_PASSWORD',
    'AUTHENTICODE_CERTIFICATE_THUMBPRINT',
    'UPDATE_SIGNING_PRIVATE_KEY'
)
foreach ($name in $requiredVariables) {
    if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) {
        throw "Production environment setting $name is required; deployment will not start."
    }
}

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$tempRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }
$suffix = [Guid]::NewGuid().ToString('N')
$pfxPath = Join-Path $tempRoot "trace-signing-$suffix.pfx"
$privatePath = Join-Path $tempRoot "trace-update-private-$suffix.pem"
$derivedPublic = Join-Path $tempRoot "trace-update-derived-$suffix.der"
$expectedPublic = Join-Path $tempRoot "trace-update-expected-$suffix.der"
$certificate = $null

try {
    $pfxBytes = [Convert]::FromBase64String($env:AUTHENTICODE_CERTIFICATE_PFX_BASE64)
    [IO.File]::WriteAllBytes($pfxPath, $pfxBytes)
    $certificate = [Security.Cryptography.X509Certificates.X509Certificate2]::new(
        $pfxBytes,
        $env:AUTHENTICODE_CERTIFICATE_PASSWORD,
        [Security.Cryptography.X509Certificates.X509KeyStorageFlags]::EphemeralKeySet
    )
    if (-not $certificate.HasPrivateKey) { throw 'The Authenticode PFX does not contain its private key.' }

    $expectedThumbprint = ($env:AUTHENTICODE_CERTIFICATE_THUMBPRINT -replace '\s', '').ToUpperInvariant()
    if ($certificate.Thumbprint -ne $expectedThumbprint) {
        throw 'PFX thumbprint does not match AUTHENTICODE_CERTIFICATE_THUMBPRINT.'
    }
    if ($certificate.NotBefore.ToUniversalTime() -gt [DateTime]::UtcNow -or
        $certificate.NotAfter.ToUniversalTime() -le [DateTime]::UtcNow) {
        throw 'The corporate signing certificate is outside its validity period.'
    }

    $ekuExtension = $certificate.Extensions |
        Where-Object { $_.Oid.Value -eq '2.5.29.37' } |
        Select-Object -First 1
    $hasCodeSigningEku = $false
    if ($ekuExtension) {
        $hasCodeSigningEku = @(
            ([Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension]$ekuExtension).EnhancedKeyUsages |
                Where-Object { $_.Value -eq '1.3.6.1.5.5.7.3.3' }
        ).Count -gt 0
    }
    if (-not $hasCodeSigningEku) { throw 'The configured certificate does not have the Code Signing EKU.' }

    [IO.File]::WriteAllText(
        $privatePath,
        $env:UPDATE_SIGNING_PRIVATE_KEY,
        (New-Object System.Text.UTF8Encoding($false))
    )
    $openssl = Join-Path $env:ProgramFiles 'Git\usr\bin\openssl.exe'
    if (-not (Test-Path -LiteralPath $openssl -PathType Leaf)) { throw 'OpenSSL from Git for Windows was not found.' }
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

    Write-Output 'Production signing materials passed validation.'
} finally {
    if ($certificate) { $certificate.Dispose() }
    foreach ($path in @($pfxPath, $privatePath, $derivedPublic, $expectedPublic)) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}
