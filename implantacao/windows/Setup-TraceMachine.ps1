param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
)
$ErrorActionPreference = "Stop"

$resolvedPackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$industrialConfigScript = Join-Path $resolvedPackageRoot "implantacao\windows\TraceIndustrialConfiguration.ps1"
if (-not (Test-Path -LiteralPath $industrialConfigScript -PathType Leaf)) {
    throw "Configurador industrial do Trace não encontrado: $industrialConfigScript"
}
. $industrialConfigScript
$legacyRoot = Join-Path $env:ProgramData "DallogixTrace"
if ((Test-Path -LiteralPath (Join-Path $legacyRoot "docker-compose.yml") -PathType Leaf) -and
    ($resolvedPackageRoot -ne (Resolve-Path -LiteralPath $legacyRoot).Path)) {
    throw "Foi encontrada uma instalação antiga em C:\ProgramData\DallogixTrace. Pare e remova essa instalação antes de continuar; nenhum dado será apagado automaticamente."
}

function Test-WebView2Runtime {
    $clientId = '{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}'
    $registryKeys = @(
        "Registry::HKEY_LOCAL_MACHINE\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\$clientId",
        "Registry::HKEY_CURRENT_USER\Software\Microsoft\EdgeUpdate\Clients\$clientId"
    )
    foreach ($key in $registryKeys) {
        $versionText = (Get-ItemProperty -LiteralPath $key -Name pv -ErrorAction SilentlyContinue).pv
        $runtimeVersion = $null
        if ([version]::TryParse([string]$versionText, [ref]$runtimeVersion) -and
            $runtimeVersion -gt [version]'0.0.0.0') {
            return $true
        }
    }
    return $false
}

function Get-TraceNetworkRouteHint {
    try {
        $routes = @(Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction Stop |
            Where-Object { $_.NextHop -and $_.NextHop -ne '0.0.0.0' })
        if ($routes.Count -eq 0) {
            return 'O Windows não possui uma rota padrão IPv4. Conecte uma interface de internet por Wi-Fi ou Ethernet com gateway e DNS. As interfaces do CLP e da câmera devem permanecer separadas e sem gateway.'
        }
        return 'Há uma rota padrão IPv4, mas o destino HTTPS não respondeu. Verifique DNS, proxy e firewall para a conta que executará o atualizador.'
    } catch {
        return 'Não foi possível consultar a rota padrão. Verifique a conexão Wi-Fi ou Ethernet, o gateway e o DNS.'
    }
}

function Get-TraceEnvValue([string]$Name) {
    $envPath = Join-Path $resolvedPackageRoot '.env'
    if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { return '' }
    $pattern = '^\s*' + [regex]::Escape($Name) + '=(.*)$'
    foreach ($line in Get-Content -LiteralPath $envPath) {
        if ($line -match $pattern) {
            return $Matches[1].Trim().Trim('"').Trim("'")
        }
    }
    return ''
}

function Test-TraceReleaseAccess([string]$ManifestUrl, [string]$MinimumVersion, [hashtable]$Headers = @{}) {
    $manifestUri = $null
    if (-not [Uri]::TryCreate($ManifestUrl, [UriKind]::Absolute, [ref]$manifestUri) -or
        $manifestUri.Scheme -ne 'https' -or $manifestUri.UserInfo -or
        $manifestUri.Query -or $manifestUri.Fragment) {
        throw 'A URL do manifesto precisa usar HTTPS e não pode conter credenciais ou parâmetros na URL. Use UPDATE_MANIFEST_TOKEN para autenticação.'
    }

    try {
        $manifest = Invoke-RestMethod -Uri $manifestUri.AbsoluteUri -Headers $Headers -TimeoutSec 20
    } catch {
        throw "A instalação não pode continuar: o manifesto de atualização não está acessível por HTTPS. $((Get-TraceNetworkRouteHint)) Detalhe: $($_.Exception.Message)"
    }

    foreach ($field in @('version', 'artifact_url', 'sha256', 'signature')) {
        if (-not $manifest.$field) {
            throw "A instalação não pode continuar: o manifesto de atualização está sem o campo $field."
        }
    }
    if ($manifest.version -notmatch '^v\d+\.\d+\.\d+$' -or
        ($manifest.channel -and $manifest.channel -ne 'stable') -or
        $manifest.sha256 -notmatch '^[0-9a-fA-F]{64}$') {
        throw 'A instalação não pode continuar: o manifesto de atualização tem versão, canal ou SHA-256 inválido.'
    }
    if ([version]($manifest.version.Substring(1)) -lt [version]($MinimumVersion.Substring(1))) {
        throw "A instalação não pode continuar: a release estável $($manifest.version) é anterior ao instalador $MinimumVersion."
    }

    $artifactUri = $null
    $versionPath = [regex]::Escape([string]$manifest.version)
    $artifactPathPattern = "^/dallogixempresa-max/Dallogix-Trace-Code/releases/download/$versionPath/trace-$versionPath\.tar\.gz$"
    if (-not [Uri]::TryCreate([string]$manifest.artifact_url, [UriKind]::Absolute, [ref]$artifactUri) -or
        $artifactUri.Scheme -ne 'https' -or
        $artifactUri.Host -ne 'github.com' -or
        $artifactUri.AbsolutePath -notmatch $artifactPathPattern) {
        throw 'A instalação não pode continuar: o pacote não aponta para uma release HTTPS aprovada do Trace no GitHub.'
    }

    try {
        $artifactResponse = Invoke-WebRequest -Uri $artifactUri.AbsoluteUri -Method Head -MaximumRedirection 5 -TimeoutSec 20 -UseBasicParsing
        if ([int]$artifactResponse.StatusCode -lt 200 -or [int]$artifactResponse.StatusCode -ge 300) {
            throw "HTTP $([int]$artifactResponse.StatusCode)"
        }
    } catch {
        throw "A instalação não pode continuar: o pacote assinado não está acessível por HTTPS. $((Get-TraceNetworkRouteHint)) Detalhe: $($_.Exception.Message)"
    }

    return $manifest
}

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Execute o instalador como administrador."
}
function Ask([string]$Label, [string]$Default = "") {
    $suffix = if ($Default) { " [$Default]" } else { "" }
    $value = Read-Host "$Label$suffix"
    if (-not $value -and $Default) { return $Default }
    return $value
}

$centralUrl = Ask "URL HTTPS do servidor central" "https://trace.dallogix.com"
$centralUri = $null
if (-not [Uri]::TryCreate($centralUrl, [UriKind]::Absolute, [ref]$centralUri) -or
    $centralUri.Scheme -ne 'https' -or $centralUri.AbsolutePath -ne '/' -or
    $centralUri.Query -or $centralUri.Fragment -or $centralUri.UserInfo) {
    throw 'Informe apenas a origem HTTPS do servidor Central.'
}
$expectedVersion = [IO.File]::ReadAllText((Join-Path $PackageRoot 'trace-build-version.txt')).Trim()
$expectedCommit = [IO.File]::ReadAllText((Join-Path $PackageRoot 'trace-build-commit.txt')).Trim()
$legacyManifestUrl = 'https://github.com/VItoraaraujoo/Dallogix-Trace-System/releases/latest/download/manifest.json'
$updateManifestUrl = Get-TraceEnvValue 'UPDATE_MANIFEST_URL'
if ([string]::IsNullOrWhiteSpace($updateManifestUrl) -or $updateManifestUrl -eq $legacyManifestUrl) {
    $updateManifestUrl = 'https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest/download/manifest.json'
}
$manifestHeaders = @{}
$manifestToken = Get-TraceEnvValue 'UPDATE_MANIFEST_TOKEN'
if (-not [string]::IsNullOrWhiteSpace($manifestToken)) {
    $manifestHeaders.Authorization = "Bearer $manifestToken"
}
$centralHealth = $null
try {
    $centralHealth = Invoke-RestMethod -Uri ($centralUrl.TrimEnd('/') + '/api/health.php') -TimeoutSec 15
} catch {
    throw "A instalação não pode continuar: o servidor Central não está acessível por HTTPS. $((Get-TraceNetworkRouteHint)) Detalhe: $($_.Exception.Message)"
}
if ($centralHealth.status -ne 'ok' -or $centralHealth.installation_mode -ne 'central' -or
    $centralHealth.version -ne $expectedVersion -or $centralHealth.commit -ne $expectedCommit) {
    throw "O servidor Central ainda nao serve $expectedVersion no commit $expectedCommit. A instalacao local nao sera iniciada."
}
$releaseManifest = Test-TraceReleaseAccess $updateManifestUrl $expectedVersion $manifestHeaders
Write-Host "Rede validada: Central, manifesto $($releaseManifest.version) e pacote assinado acessíveis por HTTPS."
if (-not (Test-WebView2Runtime)) {
    $webViewBootstrapper = Join-Path $PackageRoot 'Dependencies\MicrosoftEdgeWebView2Setup.exe'
    if (-not (Test-Path -LiteralPath $webViewBootstrapper -PathType Leaf)) {
        throw 'WebView2 Runtime ausente e instalador nao encontrado no pacote. Gere um TraceSetup.exe atualizado.'
    }
    $webViewSignature = Get-AuthenticodeSignature -FilePath $webViewBootstrapper
    if ($webViewSignature.Status -ne 'Valid' -or
        -not $webViewSignature.SignerCertificate.Subject.Contains('Microsoft Corporation')) {
        throw 'O instalador do WebView2 nao possui assinatura digital valida da Microsoft.'
    }
    Write-Host 'Instalando o WebView2 Runtime oficial da Microsoft...'
    $webViewInstall = Start-Process -FilePath $webViewBootstrapper -ArgumentList '/silent', '/install' -Wait -PassThru
    if ($webViewInstall.ExitCode -notin @(0, 3010) -or -not (Test-WebView2Runtime)) {
        throw "O WebView2 Runtime nao foi instalado corretamente (codigo $($webViewInstall.ExitCode))."
    }
}
& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PackageRoot "implantacao\windows\Instalar-TraceLocal.ps1") -PackageRoot $PackageRoot -CentralUrl $centralUrl -NoBrowser -ProductionMachine -AllowFreshDatabaseCreation
if ($LASTEXITCODE -ne 0) { throw "A instalacao local falhou; a maquina nao foi vinculada." }

# Let the installer create and validate a complete .env before adding updater settings.
$configDir = Join-Path $PackageRoot "config"
$envPath = Join-Path $PackageRoot ".env"
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
$updateDefaults = [ordered]@{
    UPDATE_MANIFEST_URL = $updateManifestUrl
    UPDATE_PUBLIC_KEY_FILE = (Join-Path $PackageRoot "servidor\configuracao\trace-update-public.pem")
    UPDATE_CHANNEL = "stable"
}
$envLines = [System.Collections.Generic.List[string]]::new()
Get-Content $envPath | ForEach-Object { $envLines.Add([string]$_) }
foreach ($entry in $updateDefaults.GetEnumerator()) {
    $pattern = "^\s*$([regex]::Escape($entry.Key))=(.*)$"
    $found = $false
    for ($index = 0; $index -lt $envLines.Count; $index++) {
        if ($envLines[$index] -match $pattern) {
            $found = $true
            $configuredValue = $Matches[1].Trim().Trim('"').Trim("'")
            if (-not $configuredValue -or
                ($entry.Key -eq 'UPDATE_MANIFEST_URL' -and $configuredValue -eq $legacyManifestUrl)) {
                $envLines[$index] = "$($entry.Key)=$($entry.Value)"
            }
            break
        }
    }
    if (-not $found) { $envLines.Add("$($entry.Key)=$($entry.Value)") }
}
[System.IO.File]::WriteAllText(
    $envPath,
    (($envLines -join [Environment]::NewLine) + [Environment]::NewLine),
    ([System.Text.UTF8Encoding]::new($false))
)

$machineId = Ask "Identificação da máquina" "EST-001"
if ([string]::IsNullOrWhiteSpace($machineId)) {
    throw "Informe uma identificação para o PC industrial."
}

# A Dala pode ser vinculada na Master depois que o PC for ativado. Não force
# um ID ou token inventado nessa etapa: a sincronização local continua pronta
# e o vínculo será concluído quando a primeira Dala for cadastrada.
$equipmentIdText = Ask "ID do equipamento no cadastro central (opcional; deixe vazio para vincular depois)" ""
$equipmentId = $null
$equipmentCode = ""
$deviceToken = ""
if (-not [string]::IsNullOrWhiteSpace($equipmentIdText)) {
    $parsedEquipmentId = 0
    if (-not [int]::TryParse($equipmentIdText.Trim(), [ref]$parsedEquipmentId) -or $parsedEquipmentId -lt 1) {
        throw "O ID do equipamento deve ser um número inteiro positivo."
    }
    $equipmentId = $parsedEquipmentId
    $equipmentCode = Ask "Código do equipamento" ""
    if ([string]::IsNullOrWhiteSpace($equipmentCode)) {
        throw "Informe o código do equipamento ou deixe o ID vazio para vincular a Dala depois."
    }
    $deviceToken = Read-Host "Token do dispositivo SERVER da máquina"
    if ([string]::IsNullOrWhiteSpace($deviceToken)) {
        throw "Informe o token real do dispositivo SERVER."
    }
}
$traceUrl = Ask "URL local do Trace" "http://127.0.0.1:8080"
$currentIndustrial = [ordered]@{
    equipment_id = if ($null -eq $equipmentId) { 0 } else { $equipmentId }
    plc_model = ""
    physical_clp_enabled = $false
    io_map_status = "CONFIRMAR"
    modbus_map = $null
    scanner_usb_pattern = ""
    scanner_model = ""
    scanner_no_read_timeout_ms = 1500
    modbus_unit_id = 1
    modbus_heartbeat_function = 1
    modbus_heartbeat_register = 2052
    modbus_sensor_register = 2052
    sensor_active_level = "1"
    sensor_signal_mode = "rising_edge"
}
$linkedEquipmentId = if ($null -eq $equipmentId) { 0 } else { [int]$equipmentId }
$industrialSettings = Show-TraceIndustrialSettingsDialog -CurrentConfig $currentIndustrial -EquipmentId $linkedEquipmentId -CentralUrl $centralUrl
if ($null -eq $industrialSettings) { throw "A instalação foi cancelada na configuração industrial." }
$physicalConnection = $industrialSettings.plc_model -eq "INVT TS621"
$approvedMapConfirmed = $industrialSettings.io_map_status -eq "APPROVED"
$enablePhysicalCommands = $industrialSettings.physical_clp_enabled

$machineConfig = [ordered]@{
    machine_id = $machineId
    equipment_id = $equipmentId
    equipment_code = $equipmentCode
    central_api_url = $centralUrl
    device_token = $deviceToken
    trace_url = $traceUrl
    heartbeat_seconds = 30
    scanner_model = $industrialSettings.scanner_model
    scanner_usb_pattern = $industrialSettings.scanner_usb_pattern
    scanner_no_read_timeout_ms = $industrialSettings.scanner_no_read_timeout_ms
    operation_mode = if ($physicalConnection) { "PHYSICAL" } else { "SIMULATED" }
    physical_clp_enabled = $enablePhysicalCommands
    plc_model = $industrialSettings.plc_model
    io_map_status = $industrialSettings.io_map_status
    modbus_unit_id = $industrialSettings.modbus_unit_id
    modbus_heartbeat_function = $industrialSettings.modbus_heartbeat_function
    modbus_heartbeat_register = $industrialSettings.modbus_heartbeat_register
    modbus_sensor_register = $industrialSettings.modbus_sensor_register
    sensor_active_level = $industrialSettings.sensor_active_level
    sensor_signal_mode = $industrialSettings.sensor_signal_mode
    modbus_map = $industrialSettings.modbus_map
}
$machineConfig | ConvertTo-Json -Depth 5 | Set-Content -Path (Join-Path $configDir "machine.json") -Encoding UTF8

& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PackageRoot "implantacao\windows\Install-TraceMachine.ps1") -PackageRoot $PackageRoot -MachineConfig (Join-Path $configDir "machine.json")
if ($LASTEXITCODE -ne 0) { throw "A preparacao da maquina falhou." }
if ($enablePhysicalCommands) {
    Write-Output "Configuração concluída com comandos físicos habilitados para o mapa aprovado do INVT TS621 vinculado a esta Dala."
} elseif ($approvedMapConfirmed) {
    Write-Output "Mapa aprovado do INVT TS621 configurado. Escritas físicas permanecem desabilitadas nesta instalação."
} else {
    Write-Output "CLP sem perfil aprovado reconhecido. Escritas físicas permanecem bloqueadas até configurar o mapa correto."
}
Write-Output "O Trace será iniciado automaticamente com o Windows."
