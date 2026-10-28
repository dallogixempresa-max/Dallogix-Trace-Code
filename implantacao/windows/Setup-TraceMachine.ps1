param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
)
$ErrorActionPreference = "Stop"

function Show-TraceMachineSetupDialog {
    param(
        [object]$CurrentConfig,
        [string]$DefaultCentralUrl = 'https://trace.dallogix.com',
        [string]$DefaultMachineId = [string]$env:COMPUTERNAME,
        [string]$DefaultTraceUrl = 'http://127.0.0.1:8080'
    )

    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()

    if ($CurrentConfig.central_api_url) { $DefaultCentralUrl = [string]$CurrentConfig.central_api_url }
    if ($CurrentConfig.machine_id) { $DefaultMachineId = [string]$CurrentConfig.machine_id }
    if ($CurrentConfig.trace_url) { $DefaultTraceUrl = [string]$CurrentConfig.trace_url }
    if ($DefaultMachineId -notmatch '\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z') {
        $DefaultMachineId = 'PC-INDUSTRIAL-001'
    }

    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Instalação do Dallogix Trace — identificação da máquina'
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false
    $form.ClientSize = New-Object System.Drawing.Size(720, 510)
    $form.Font = New-Object System.Drawing.Font('Segoe UI', 9)

    $panel = New-Object System.Windows.Forms.Panel
    $panel.Dock = 'Fill'
    $panel.Padding = New-Object System.Windows.Forms.Padding(20, 16, 20, 8)
    $form.Controls.Add($panel)

    $title = New-Object System.Windows.Forms.Label
    $title.Text = 'Etapa 1 de 2 — Dados da instalação'
    $title.Font = New-Object System.Drawing.Font('Segoe UI', 12, [System.Drawing.FontStyle]::Bold)
    $title.Location = New-Object System.Drawing.Point(20, 16)
    $title.Size = New-Object System.Drawing.Size(660, 30)
    $panel.Controls.Add($title)

    $intro = New-Object System.Windows.Forms.Label
    $intro.Text = 'Informe onde este PC será registrado. A configuração do CLP, sensor e leitor vem na próxima etapa. Nenhum banco ou serviço será iniciado antes de você concluir as duas etapas.'
    $intro.Location = New-Object System.Drawing.Point(20, 52)
    $intro.Size = New-Object System.Drawing.Size(660, 42)
    $panel.Controls.Add($intro)

    $centralLabel = New-Object System.Windows.Forms.Label
    $centralLabel.Text = 'Servidor Central (HTTPS)'
    $centralLabel.Location = New-Object System.Drawing.Point(20, 109)
    $centralLabel.Size = New-Object System.Drawing.Size(650, 20)
    $panel.Controls.Add($centralLabel)
    $centralField = New-Object System.Windows.Forms.TextBox
    $centralField.Text = $DefaultCentralUrl
    $centralField.Location = New-Object System.Drawing.Point(20, 132)
    $centralField.Size = New-Object System.Drawing.Size(650, 25)
    $panel.Controls.Add($centralField)

    $machineLabel = New-Object System.Windows.Forms.Label
    $machineLabel.Text = 'Identificação única deste PC'
    $machineLabel.Location = New-Object System.Drawing.Point(20, 178)
    $machineLabel.Size = New-Object System.Drawing.Size(315, 20)
    $panel.Controls.Add($machineLabel)
    $machineField = New-Object System.Windows.Forms.TextBox
    $machineField.Text = $DefaultMachineId
    $machineField.Location = New-Object System.Drawing.Point(20, 201)
    $machineField.Size = New-Object System.Drawing.Size(315, 25)
    $panel.Controls.Add($machineField)

    $traceLabel = New-Object System.Windows.Forms.Label
    $traceLabel.Text = 'Endereço local do Trace'
    $traceLabel.Location = New-Object System.Drawing.Point(355, 178)
    $traceLabel.Size = New-Object System.Drawing.Size(315, 20)
    $panel.Controls.Add($traceLabel)
    $traceField = New-Object System.Windows.Forms.TextBox
    $traceField.Text = $DefaultTraceUrl
    $traceField.Location = New-Object System.Drawing.Point(355, 201)
    $traceField.Size = New-Object System.Drawing.Size(315, 25)
    $panel.Controls.Add($traceField)

    $existingEquipmentId = 0
    if ($null -ne $CurrentConfig.equipment_id) {
        [void][int]::TryParse([string]$CurrentConfig.equipment_id, [ref]$existingEquipmentId)
    }
    $linkCheck = New-Object System.Windows.Forms.CheckBox
    $linkCheck.Text = 'Vincular uma Dala agora (opcional; também pode ser feito depois no Central)'
    $linkCheck.Checked = $existingEquipmentId -gt 0
    $linkCheck.Location = New-Object System.Drawing.Point(20, 252)
    $linkCheck.Size = New-Object System.Drawing.Size(650, 24)
    $panel.Controls.Add($linkCheck)

    $equipmentIdLabel = New-Object System.Windows.Forms.Label
    $equipmentIdLabel.Text = 'ID da Dala'
    $equipmentIdLabel.Location = New-Object System.Drawing.Point(20, 286)
    $equipmentIdLabel.Size = New-Object System.Drawing.Size(110, 20)
    $panel.Controls.Add($equipmentIdLabel)
    $equipmentIdField = New-Object System.Windows.Forms.TextBox
    $equipmentIdField.Text = if ($existingEquipmentId -gt 0) { [string]$existingEquipmentId } else { '' }
    $equipmentIdField.Location = New-Object System.Drawing.Point(20, 309)
    $equipmentIdField.Size = New-Object System.Drawing.Size(110, 25)
    $panel.Controls.Add($equipmentIdField)

    $equipmentCodeLabel = New-Object System.Windows.Forms.Label
    $equipmentCodeLabel.Text = 'Código da Dala'
    $equipmentCodeLabel.Location = New-Object System.Drawing.Point(150, 286)
    $equipmentCodeLabel.Size = New-Object System.Drawing.Size(190, 20)
    $panel.Controls.Add($equipmentCodeLabel)
    $equipmentCodeField = New-Object System.Windows.Forms.TextBox
    $equipmentCodeField.Text = [string]$CurrentConfig.equipment_code
    $equipmentCodeField.Location = New-Object System.Drawing.Point(150, 309)
    $equipmentCodeField.Size = New-Object System.Drawing.Size(190, 25)
    $panel.Controls.Add($equipmentCodeField)

    $tokenLabel = New-Object System.Windows.Forms.Label
    $tokenLabel.Text = 'Token do dispositivo SERVER'
    $tokenLabel.Location = New-Object System.Drawing.Point(360, 286)
    $tokenLabel.Size = New-Object System.Drawing.Size(310, 20)
    $panel.Controls.Add($tokenLabel)
    $tokenField = New-Object System.Windows.Forms.TextBox
    $tokenField.Text = [string]$CurrentConfig.device_token
    $tokenField.UseSystemPasswordChar = $true
    $tokenField.Location = New-Object System.Drawing.Point(360, 309)
    $tokenField.Size = New-Object System.Drawing.Size(310, 25)
    $panel.Controls.Add($tokenField)

    $note = New-Object System.Windows.Forms.Label
    $note.Text = 'Se a Dala ainda não estiver cadastrada, deixe a opção desmarcada. O token é usado pelo agente para enviar o status desta máquina e não será exibido em mensagens ou logs.'
    $note.Location = New-Object System.Drawing.Point(20, 351)
    $note.Size = New-Object System.Drawing.Size(650, 45)
    $panel.Controls.Add($note)

    $linkFields = @($equipmentIdLabel, $equipmentIdField, $equipmentCodeLabel, $equipmentCodeField, $tokenLabel, $tokenField)
    $linkCheck.Add_CheckedChanged({ foreach ($control in $linkFields) { $control.Enabled = $linkCheck.Checked } }.GetNewClosure())
    foreach ($control in $linkFields) { $control.Enabled = $linkCheck.Checked }

    $buttons = New-Object System.Windows.Forms.FlowLayoutPanel
    $buttons.FlowDirection = 'RightToLeft'
    $buttons.Dock = 'Bottom'
    $buttons.Height = 48
    $buttons.Padding = New-Object System.Windows.Forms.Padding(8)
    $form.Controls.Add($buttons)
    $continue = New-Object System.Windows.Forms.Button
    $continue.Text = 'Continuar'
    $continue.Width = 120
    $continue.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $cancel = New-Object System.Windows.Forms.Button
    $cancel.Text = 'Cancelar'
    $cancel.Width = 100
    $cancel.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    [void]$buttons.Controls.Add($continue)
    [void]$buttons.Controls.Add($cancel)
    $form.AcceptButton = $continue
    $form.CancelButton = $cancel

    while ($true) {
        if ($form.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { return $null }
        $centralUri = $null
        $traceUri = $null
        $errorMessage = ''
        if (-not [Uri]::TryCreate($centralField.Text.Trim(), [UriKind]::Absolute, [ref]$centralUri) -or
            $centralUri.Scheme -ne 'https' -or $centralUri.AbsolutePath -ne '/' -or
            $centralUri.Query -or $centralUri.Fragment -or $centralUri.UserInfo) {
            $errorMessage = 'Informe somente a origem HTTPS do servidor Central, por exemplo https://trace.dallogix.com.'
        } elseif ($machineField.Text.Trim() -notmatch '\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z') {
            $errorMessage = 'A identificação deve ter de 3 a 64 caracteres: letras, números, ponto, hífen ou sublinhado.'
        } elseif (-not [Uri]::TryCreate($traceField.Text.Trim(), [UriKind]::Absolute, [ref]$traceUri) -or
            $traceUri.Scheme -ne 'http' -or $traceUri.Host -notin @('127.0.0.1', 'localhost') -or
            $traceUri.UserInfo -or $traceUri.Query -or $traceUri.Fragment -or $traceUri.AbsolutePath -ne '/') {
            $errorMessage = 'O endereço local deve apontar para http://127.0.0.1:PORTA ou http://localhost:PORTA.'
        } elseif ($linkCheck.Checked) {
            $equipmentId = 0
            if (-not [int]::TryParse($equipmentIdField.Text.Trim(), [ref]$equipmentId) -or $equipmentId -lt 1) {
                $errorMessage = 'Informe o ID numérico positivo da Dala ou desmarque a opção para vincular depois.'
            } elseif ([string]::IsNullOrWhiteSpace($equipmentCodeField.Text)) {
                $errorMessage = 'Informe o código da Dala ou desmarque a opção para vincular depois.'
            } elseif ([string]::IsNullOrWhiteSpace($tokenField.Text)) {
                $errorMessage = 'Informe o token SERVER emitido no Central ou desmarque a opção para vincular depois.'
            }
        }
        if ($errorMessage) {
            [System.Windows.Forms.MessageBox]::Show($errorMessage, 'Confira os dados', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
            $form.DialogResult = [System.Windows.Forms.DialogResult]::None
            continue
        }

        return [ordered]@{
            central_url = $centralUri.GetLeftPart([UriPartial]::Authority)
            machine_id = $machineField.Text.Trim()
            trace_url = $traceUri.GetLeftPart([UriPartial]::Authority)
            equipment_id = if ($linkCheck.Checked) { [int]$equipmentIdField.Text.Trim() } else { $null }
            equipment_code = if ($linkCheck.Checked) { $equipmentCodeField.Text.Trim() } else { '' }
            device_token = if ($linkCheck.Checked) { $tokenField.Text.Trim() } else { '' }
        }
    }
}

$resolvedPackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$industrialConfigScript = Join-Path $resolvedPackageRoot "implantacao\windows\TraceIndustrialConfiguration.ps1"
if (-not (Test-Path -LiteralPath $industrialConfigScript -PathType Leaf)) {
    throw "Configurador industrial do Trace não encontrado: $industrialConfigScript"
}
$traceFileSecurityScript = Join-Path $resolvedPackageRoot 'implantacao\windows\TraceFileSecurity.ps1'
if (-not (Test-Path -LiteralPath $traceFileSecurityScript -PathType Leaf)) {
    throw "Proteção da configuração industrial não encontrada: $traceFileSecurityScript"
}
. $industrialConfigScript
. $traceFileSecurityScript
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

function Save-TraceMachineConfiguration {
    param(
        [Parameter(Mandatory = $true)][object]$Configuration,
        [Parameter(Mandatory = $true)][string]$Path
    )
    $temporaryPath = "$Path.$([guid]::NewGuid().ToString('N')).tmp"
    $backupPath = "$Path.$([guid]::NewGuid().ToString('N')).bak"
    $json = ($Configuration | ConvertTo-Json -Depth 8) + [Environment]::NewLine
    try {
        [IO.File]::WriteAllText($temporaryPath, $json, [Text.UTF8Encoding]::new($false))
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            [IO.File]::Replace($temporaryPath, $Path, $backupPath)
        } else {
            [IO.File]::Move($temporaryPath, $Path)
        }
    } finally {
        Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-TraceNetworkRouteHint {
    try {
        $routes = @(Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction Stop |
            Where-Object { $_.NextHop -and $_.NextHop -ne '0.0.0.0' })
        if ($routes.Count -eq 0) {
            return 'O Windows não possui uma rota padrão IPv4. Conecte uma interface de internet por Wi-Fi ou Ethernet com gateway e DNS. As interfaces industriais do CLP e do leitor devem permanecer separadas e sem gateway.'
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

trap {
    $errorMessage = [string]$_.Exception.Message
    $safeMessage = [regex]::Replace(
        $errorMessage,
        '(?i)(password|token|secret)(\s*[:=]\s*)[^,\s;]+',
        '$1$2[redacted]'
    )
    if ($safeMessage.Length -gt 1200) { $safeMessage = $safeMessage.Substring(0, 1200) + '…' }
    $logPath = $null
    try {
        $logRoot = Join-Path $PackageRoot 'armazenamento\logs'
        New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
        $logPath = Join-Path $logRoot 'installation-setup.log'
        [IO.File]::AppendAllText($logPath, "$(Get-Date -Format o) $safeMessage$([Environment]::NewLine)", [Text.UTF8Encoding]::new($false))
    } catch { }
    try {
        Add-Type -AssemblyName System.Windows.Forms
        $logHint = if ($logPath) { "`r`n`r`nO diagnóstico foi salvo em:`r`n$logPath" } else { '' }
        [System.Windows.Forms.MessageBox]::Show(
            "A instalação não foi concluída.`r`n`r`n$safeMessage$logHint`r`n`r`nCorrija o problema e execute o instalador novamente. Bancos existentes não são removidos pelo instalador.",
            'Dallogix Trace — instalação interrompida',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } catch {
        [Console]::Error.WriteLine("Instalação não concluída: $safeMessage")
    }
    exit 1
}

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Execute o instalador como administrador."
}
$configDir = Join-Path $PackageRoot 'config'
$machineConfigPath = Join-Path $configDir 'machine.json'
$priorMachineConfig = $null
if (Test-Path -LiteralPath $machineConfigPath -PathType Leaf) {
    try {
        $priorMachineConfig = Get-Content -LiteralPath $machineConfigPath -Raw | ConvertFrom-Json
    } catch {
        throw 'O arquivo config\machine.json existente está inválido. Corrija ou restaure esse arquivo antes de reinstalar; nenhum dado será apagado.'
    }
}
$setupData = Show-TraceMachineSetupDialog -CurrentConfig $priorMachineConfig
if ($null -eq $setupData) { throw 'Instalação cancelada antes de iniciar os serviços. Nenhuma configuração foi aplicada.' }
$centralUrl = [string]$setupData.central_url
$machineId = [string]$setupData.machine_id
$traceUrl = [string]$setupData.trace_url
$equipmentId = $setupData.equipment_id
$equipmentCode = [string]$setupData.equipment_code
$deviceToken = [string]$setupData.device_token

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

# Reúna a configuração do CLP, sensor e leitor antes de instalar qualquer
# dependência ou iniciar os serviços. Cancelar aqui não deixa um Trace parcial.
$currentIndustrial = if ($priorMachineConfig) {
    $priorMachineConfig
} else {
    [ordered]@{
        equipment_id = 0
        plc_model = ''
        physical_clp_enabled = $false
        io_map_status = 'CONFIRMAR'
        modbus_map = $null
        scanner_usb_pattern = ''
        scanner_model = ''
        keyence_pc_ip = ''
        keyence_reader_ip = ''
        scanner_no_read_timeout_ms = 1500
        modbus_unit_id = 1
        modbus_heartbeat_function = 1
        modbus_heartbeat_register = 2052
        modbus_sensor_register = 2052
        sensor_active_level = '1'
        sensor_signal_mode = 'rising_edge'
    }
}
$currentIndustrial.equipment_id = if ($null -eq $equipmentId) { 0 } else { [int]$equipmentId }
$linkedEquipmentId = if ($null -eq $equipmentId) { 0 } else { [int]$equipmentId }
$industrialSettings = Show-TraceIndustrialSettingsDialog -CurrentConfig $currentIndustrial -EquipmentId $linkedEquipmentId -CentralUrl $centralUrl -InitialSetup
if ($null -eq $industrialSettings) { throw 'Instalação cancelada na configuração industrial. Nenhum serviço foi iniciado.' }
$physicalConnection = $industrialSettings.plc_model -eq 'INVT TS621'
$approvedMapConfirmed = $industrialSettings.io_map_status -eq 'APPROVED'
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
    keyence_pc_ip = $industrialSettings.keyence_pc_ip
    keyence_reader_ip = $industrialSettings.keyence_reader_ip
    scanner_no_read_timeout_ms = $industrialSettings.scanner_no_read_timeout_ms
    operation_mode = if ($enablePhysicalCommands) { 'PHYSICAL' } else { 'SIMULATED' }
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
Protect-TraceMachineConfiguration -InstallRoot $PackageRoot
Save-TraceMachineConfiguration -Configuration $machineConfig -Path $machineConfigPath

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
Write-Host 'Preparando o ambiente local do Trace. O banco existente e a configuração .env serão preservados.'
& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -STA -File (Join-Path $PackageRoot 'implantacao\windows\Instalar-TraceLocal.ps1') -PackageRoot $PackageRoot -CentralUrl $centralUrl -NoBrowser -ProductionMachine -AllowFreshDatabaseCreation
if ($LASTEXITCODE -ne 0) { throw 'A instalação local não terminou. Os dados existentes foram preservados; corrija o problema e execute o instalador novamente.' }

# Let the installer create and validate a complete .env before adding updater settings.
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

& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PackageRoot 'implantacao\windows\Install-TraceMachine.ps1') -PackageRoot $PackageRoot -MachineConfig $machineConfigPath
if ($LASTEXITCODE -ne 0) { throw "A preparacao da maquina falhou." }
if ($enablePhysicalCommands) {
    Write-Output "Configuração concluída com comandos físicos habilitados para o mapa aprovado do INVT TS621 vinculado a esta Dala."
} elseif ($approvedMapConfirmed) {
    Write-Output "Mapa aprovado do INVT TS621 configurado. Escritas físicas permanecem desabilitadas nesta instalação."
} else {
    Write-Output "CLP sem perfil aprovado reconhecido. Escritas físicas permanecem bloqueadas até configurar o mapa correto."
}
Write-Output "O Trace será iniciado automaticamente com o Windows."
