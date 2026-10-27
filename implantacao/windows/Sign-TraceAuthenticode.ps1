[CmdletBinding(DefaultParameterSetName = 'Sign')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Sign')]
    [string]$PathToSign,

    [Parameter(Mandatory = $true, ParameterSetName = 'Prepare')]
    [switch]$PrepareOnly
)

$ErrorActionPreference = 'Stop'
$requiredVariables = @(
    'ESIGNER_USERNAME',
    'ESIGNER_PASSWORD',
    'ESIGNER_TOTP_SECRET',
    'AUTHENTICODE_CERTIFICATE_THUMBPRINT'
)
foreach ($name in $requiredVariables) {
    if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) {
        throw "Production environment setting $name is required; unsigned installers will not be published."
    }
}

$expectedThumbprint = ($env:AUTHENTICODE_CERTIFICATE_THUMBPRINT -replace '\s', '').ToUpperInvariant()
if ($expectedThumbprint -notmatch '^[A-F0-9]{40}$') {
    throw 'AUTHENTICODE_CERTIFICATE_THUMBPRINT must be a 40-character SHA-1 thumbprint.'
}

if (-not $env:RUNNER_TEMP) { throw 'RUNNER_TEMP is required for temporary signing files.' }

# This is the version and package used by SSL.com’s public eSigner CKA sample workflow.
# Keep the SHA-256 pin in sync only after reviewing a new vendor release.
$ckaUrl = 'https://github.com/SSLcom/eSignerCKA/releases/download/v1.0.8/SSL.COM-eSigner-CKA_1.0.8.zip'
$ckaSha256 = 'c45bcd520a2a77acda150c12a1c233ca2408b3c10c2016b8d0dc8b9aefc0cadb'
$archivePath = Join-Path $env:RUNNER_TEMP 'trace-esigner-cka.zip'
$extractPath = Join-Path $env:RUNNER_TEMP 'trace-esigner-cka-package'
$installPath = Join-Path $env:RUNNER_TEMP 'trace-esigner-cka'
$masterKeyPath = Join-Path $installPath 'master.key'
$toolPath = Join-Path $installPath 'eSignerCKATool.exe'
$certificate = $null
$loaded = $false
$primaryFailure = $false
$unloadFailed = $false

try {
    Invoke-WebRequest -Uri $ckaUrl -OutFile $archivePath
    $downloadHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($downloadHash -ne $ckaSha256) {
        throw 'The downloaded SSL.com eSigner CKA package failed its pinned SHA-256 check.'
    }

    Expand-Archive -LiteralPath $archivePath -DestinationPath $extractPath -Force
    $installers = @(Get-ChildItem -LiteralPath $extractPath -Filter '*.exe' -File -Recurse)
    if ($installers.Count -ne 1) { throw 'The pinned eSigner CKA package has an unexpected installer layout.' }
    $installer = $installers[0]
    $installerSignature = Get-AuthenticodeSignature -FilePath $installer.FullName
    if ($installerSignature.Status -ne [System.Management.Automation.SignatureStatus]::Valid -or
        $installerSignature.SignerCertificate.Subject -notmatch '(?i)SSL\.com|SSL Corporation') {
        throw 'The eSigner CKA installer does not have a valid SSL.com Authenticode signature.'
    }

    New-Item -ItemType Directory -Force -Path $installPath | Out-Null
    $install = Start-Process -FilePath $installer.FullName -ArgumentList @('/CURRENTUSER', '/VERYSILENT', '/SUPPRESSMSGBOXES', "/DIR=$installPath") -Wait -PassThru -NoNewWindow
    if ($install.ExitCode -ne 0) { throw 'The pinned eSigner CKA installer failed.' }
    if (-not (Test-Path -LiteralPath $toolPath -PathType Leaf)) {
        throw 'The eSigner CKA command-line tool was not installed.'
    }

    $configOutput = & $toolPath config -mode product -user $env:ESIGNER_USERNAME -pass $env:ESIGNER_PASSWORD -totp $env:ESIGNER_TOTP_SECRET -key $masterKeyPath -r 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'eSigner CKA could not authenticate the production signing account.' }

    $unloadOutput = & $toolPath unload 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'eSigner CKA could not unload the previous certificate registration.' }
    $loadOutput = & $toolPath load 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'eSigner CKA could not load the code-signing certificate.' }
    $loaded = $true

    $matchingCertificates = @(
        Get-ChildItem Cert:\CurrentUser\My -CodeSigningCert |
            Where-Object { $_.Thumbprint -eq $expectedThumbprint }
    )
    if ($matchingCertificates.Count -ne 1) {
        throw 'The configured thumbprint does not identify exactly one loaded code-signing certificate.'
    }
    $certificate = $matchingCertificates[0]
    if (-not $certificate.HasPrivateKey) {
        throw 'The loaded eSigner certificate does not expose its cloud signing key to Windows.'
    }
    if ($certificate.NotBefore.ToUniversalTime() -gt [DateTime]::UtcNow -or
        $certificate.NotAfter.ToUniversalTime() -le [DateTime]::UtcNow) {
        throw 'The configured code-signing certificate is outside its validity period.'
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
    if (-not $hasCodeSigningEku) {
        throw 'The configured certificate does not have the Code Signing EKU.'
    }

    if ($PrepareOnly) {
        Write-Output 'The eSigner account authenticated and the expected Code Signing certificate is available.'
    } else {
        $signableFile = (Resolve-Path -LiteralPath $PathToSign).Path
        $sdkPath = Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\bin'
        if (-not (Test-Path -LiteralPath $sdkPath -PathType Container)) {
            throw 'The Windows SDK signtool.exe directory was not found.'
        }
        $signTools = @(Get-ChildItem -LiteralPath $sdkPath -Filter signtool.exe -File -Recurse)
        $signtool = $signTools |
            Where-Object { $_.FullName -match '\\x86\\' } |
            Sort-Object { [version]$_.VersionInfo.FileVersion } -Descending |
            Select-Object -First 1
        if (-not $signtool) {
            $signtool = $signTools |
                Where-Object { $_.FullName -match '\\x64\\' } |
                Sort-Object { [version]$_.VersionInfo.FileVersion } -Descending |
                Select-Object -First 1
        }
        if (-not $signtool) { throw 'Windows SDK signtool.exe was not found.' }

        $signOutput = & $signtool.FullName sign /sha1 $certificate.Thumbprint /s My /fd SHA256 /tr http://ts.ssl.com /td SHA256 $signableFile 2>&1
        if ($LASTEXITCODE -ne 0) { throw 'SSL.com eSigner Authenticode signing failed.' }

        $verifyOutput = & $signtool.FullName verify /pa /v $signableFile 2>&1
        if ($LASTEXITCODE -ne 0) { throw 'Authenticode verification failed for the signed installer.' }
        $signature = Get-AuthenticodeSignature -FilePath $signableFile
        if ($signature.Status -ne [System.Management.Automation.SignatureStatus]::Valid -or
            $signature.SignerCertificate.Thumbprint -ne $expectedThumbprint) {
            throw 'The signed installer does not have a valid signature from the configured certificate.'
        }
        Write-Output 'The installer signature and certificate thumbprint were verified.'
    }
} catch {
    $primaryFailure = $true
    throw
} finally {
    if ($loaded -and (Test-Path -LiteralPath $toolPath -PathType Leaf)) {
        $unloadOutput = & $toolPath unload 2>&1
        if ($LASTEXITCODE -ne 0) { $unloadFailed = $true }
    }
    if ($certificate) { $certificate.Dispose() }
    foreach ($path in @($masterKeyPath, $installPath, $extractPath, $archivePath)) {
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Recurse -Force
        }
    }
    if ($unloadFailed) {
        if ($primaryFailure) {
            Write-Warning 'eSigner CKA cleanup also failed; the hosted runner will be discarded.'
        } else {
            throw 'eSigner CKA could not unload its signing certificate after the operation.'
        }
    }
}
