$ErrorActionPreference = "Stop"

function Get-TraceConfigProperty {
    param(
        [object]$InputObject,
        [string]$Name,
        [object]$Default = $null
    )
    if ($null -eq $InputObject) { return $Default }
    if ($InputObject -is [System.Collections.IDictionary] -and $InputObject.Contains($Name)) {
        return $InputObject[$Name]
    }
    $property = $InputObject.PSObject.Properties[$Name]
    if ($null -ne $property) { return $property.Value }
    return $Default
}

function ConvertTo-TraceIndustrialSettings {
    param([object]$Configuration)

    $numericValues = @{
        modbus_unit_id = 1
        modbus_heartbeat_function = 1
        modbus_heartbeat_register = 2052
        modbus_sensor_register = 2052
        scanner_no_read_timeout_ms = 1500
    }
    $activeLevel = "1"
    $signalMode = "rising_edge"

    foreach ($entry in @(
        @{ Name = "modbus_unit_id"; Min = 0; Max = 255 },
        @{ Name = "modbus_heartbeat_function"; Min = 1; Max = 4 },
        @{ Name = "modbus_heartbeat_register"; Min = 0; Max = 65535 },
        @{ Name = "modbus_sensor_register"; Min = 0; Max = 65535 },
        @{ Name = "scanner_no_read_timeout_ms"; Min = 500; Max = 10000 }
    )) {
        $value = Get-TraceConfigProperty $Configuration $entry.Name $null
        if ($null -eq $value -or [string]::IsNullOrWhiteSpace([string]$value)) { continue }
        $parsed = 0
        if (-not [int]::TryParse([string]$value, [ref]$parsed) -or
            $parsed -lt $entry.Min -or $parsed -gt $entry.Max) {
            throw "$($entry.Name) deve ser um inteiro entre $($entry.Min) e $($entry.Max)."
        }
        $numericValues[$entry.Name] = $parsed
    }

    $configuredFunction = $numericValues.modbus_heartbeat_function
    if ([int]$configuredFunction -notin @(1, 2, 3, 4)) {
        throw "modbus_heartbeat_function deve ser 1, 2, 3 ou 4."
    }
    $activeLevel = [string](Get-TraceConfigProperty $Configuration "sensor_active_level" "1")
    if ($activeLevel -notin @("0", "1")) { throw "sensor_active_level deve ser 0 ou 1." }
    $signalMode = [string](Get-TraceConfigProperty $Configuration "sensor_signal_mode" "rising_edge")
    if ($signalMode -notin @("rising_edge", "falling_edge", "toggle")) {
        throw "sensor_signal_mode deve ser rising_edge, falling_edge ou toggle."
    }

    $scannerPattern = [string](Get-TraceConfigProperty $Configuration "scanner_usb_pattern" "")
    $scannerModel = [string](Get-TraceConfigProperty $Configuration "scanner_model" "")
    if ($scannerPattern -and $scannerPattern -notmatch '\AUSB\\VID_[0-9A-F]{4}&PID_[0-9A-F]{4}(?:&MI_[0-9A-F]{2})?\z') {
        throw "scanner_usb_pattern não é um identificador USB HID válido."
    }
    if ($scannerPattern -and -not $scannerModel) { $scannerModel = "USB HID" }
    if ($scannerModel -notin @("", "USB HID", "Elgin EL8600", "KEYENCE SR-X100W")) {
        throw "scanner_model deve ser USB HID, Elgin EL8600 ou KEYENCE SR-X100W."
    }
    if ($scannerModel -eq "KEYENCE SR-X100W" -and $scannerPattern) {
        throw "O KEYENCE SR-X100W usa Ethernet/TCP e FTP; não pode ter um vínculo USB HID."
    }
    $keyencePcIp = [string](Get-TraceConfigProperty $Configuration "keyence_pc_ip" "")
    $keyenceReaderIp = [string](Get-TraceConfigProperty $Configuration "keyence_reader_ip" "")
    if ($scannerModel -eq "KEYENCE SR-X100W") {
        foreach ($entry in @(@{ Name = "keyence_pc_ip"; Value = $keyencePcIp }, @{ Name = "keyence_reader_ip"; Value = $keyenceReaderIp })) {
            $parsedIp = $null
            if (-not [System.Net.IPAddress]::TryParse($entry.Value, [ref]$parsedIp) -or
                $parsedIp.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) {
                throw "$($entry.Name) precisa ser um IPv4 fixo da interface isolada do leitor."
            }
        }
    }

    $equipmentIdValue = Get-TraceConfigProperty $Configuration "equipment_id" 0
    $equipmentId = 0
    if ($null -ne $equipmentIdValue -and
        -not [int]::TryParse([string]$equipmentIdValue, [ref]$equipmentId)) {
        throw "equipment_id deve ser um número inteiro positivo."
    }
    if ($equipmentId -lt 0) { throw "equipment_id deve ser zero ou um número inteiro positivo." }
    $plcModel = [string](Get-TraceConfigProperty $Configuration "plc_model" "")
    $ioMapStatus = [string](Get-TraceConfigProperty $Configuration "io_map_status" "CONFIRMAR")
    $physicalEnabled = (Get-TraceConfigProperty $Configuration "physical_clp_enabled" $false) -eq $true
    $map = Get-TraceConfigProperty $Configuration "modbus_map" $null
    $approvedModel = $plcModel -eq "INVT TS621"
    $mapApproved = $ioMapStatus -eq "APPROVED"
    if ($ioMapStatus -notin @("CONFIRMAR", "APPROVED")) {
        throw "io_map_status deve ser CONFIRMAR ou APPROVED."
    }
    if ($mapApproved) {
        if (-not $approvedModel) { throw "Não existe mapa aprovado para o modelo de CLP selecionado." }
        $expectedMap = @{
            conveyor_run_coil = 2049
            reverse_command_coil = 2050
            emergency_command_coil = 2051
            emergency_feedback_coil = 17
        }
        foreach ($key in $expectedMap.Keys) {
            $actual = Get-TraceConfigProperty $map $key $null
            if ($null -eq $actual -or [int]$actual -ne $expectedMap[$key]) {
                throw "O mapa aprovado do INVT TS621 deve manter $key=$($expectedMap[$key])."
            }
        }
    }
    if ($physicalEnabled -and (-not $approvedModel -or -not $mapApproved -or $equipmentId -lt 1)) {
        throw "Escrita física exige o mapa aprovado do INVT TS621 e uma Dala vinculada."
    }

    return [ordered]@{
        scanner_model = $scannerModel
        scanner_usb_pattern = $scannerPattern
        keyence_pc_ip = $keyencePcIp
        keyence_reader_ip = $keyenceReaderIp
        scanner_no_read_timeout_ms = $numericValues.scanner_no_read_timeout_ms
        modbus_unit_id = $numericValues.modbus_unit_id
        modbus_heartbeat_function = $numericValues.modbus_heartbeat_function
        modbus_heartbeat_register = $numericValues.modbus_heartbeat_register
        modbus_sensor_register = $numericValues.modbus_sensor_register
        sensor_active_level = $activeLevel
        sensor_signal_mode = $signalMode
        plc_model = $plcModel
        physical_clp_enabled = $physicalEnabled
        io_map_status = $ioMapStatus
        modbus_map = if ($mapApproved) {
            [ordered]@{
                conveyor_run_coil = 2049
                reverse_command_coil = 2050
                emergency_command_coil = 2051
                emergency_feedback_coil = 17
            }
        } else {
            [ordered]@{
                conveyor_run_coil = $null
                reverse_command_coil = $null
                emergency_command_coil = $null
                emergency_feedback_coil = $null
            }
        }
    }
}

function Get-TraceUsbHidCandidates {
    try {
        $devices = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
            $_.Class -in @("Keyboard", "HIDClass") -and
            ([string]$_.InstanceId) -like "USB\VID_*" -and
            $_.Status -eq "OK"
        })
        $candidates = foreach ($device in $devices) {
            $instanceId = [string]$device.InstanceId
            if ($instanceId -match '\A(USB\\VID_[0-9A-F]{4}&PID_[0-9A-F]{4}(?:&MI_[0-9A-F]{2})?)') {
                [pscustomobject]@{
                    Display = "$($device.FriendlyName) — $($Matches[1])"
                    Pattern = $Matches[1]
                    Model = "USB HID"
                }
            }
        }
        return @($candidates | Sort-Object Pattern -Unique)
    } catch {
        return @()
    }
}

function Show-TraceIndustrialSettingsDialog {
    param(
        [object]$CurrentConfig,
        [int]$EquipmentId = 0,
        [string]$CentralUrl = "https://trace.dallogix.com",
        [switch]$ConfirmNoActiveLoad,
        [switch]$InitialSetup
    )
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()

    $form = New-Object System.Windows.Forms.Form
    $form.Text = if ($InitialSetup) { "Instalação do Dallogix Trace — CLP, sensor e leitor" } else { "Configuração industrial do Dallogix Trace" }
    $form.StartPosition = "CenterScreen"
    $form.FormBorderStyle = "FixedDialog"
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false
    $form.ClientSize = New-Object System.Drawing.Size(700, 760)
    $form.Font = New-Object System.Drawing.Font("Segoe UI", 9)

    $panel = New-Object System.Windows.Forms.Panel
    $panel.Dock = "Fill"
    $panel.AutoScroll = $true
    $form.Controls.Add($panel)

    $label = {
        param([string]$Text, [int]$X, [int]$Y, [int]$Width = 625, [int]$Height = 22)
        $control = New-Object System.Windows.Forms.Label
        $control.Text = $Text
        $control.Location = New-Object System.Drawing.Point($X, $Y)
        $control.Size = New-Object System.Drawing.Size($Width, $Height)
        $panel.Controls.Add($control)
        return $control
    }
    $textbox = {
        param([string]$Name, [string]$Text, [int]$X, [int]$Y, [int]$Width = 120)
        $control = New-Object System.Windows.Forms.TextBox
        $control.Name = $Name
        $control.Text = $Text
        $control.Location = New-Object System.Drawing.Point($X, $Y)
        $control.Size = New-Object System.Drawing.Size($Width, 25)
        $panel.Controls.Add($control)
        return $control
    }
    $combo = {
        param([string]$Name, [string[]]$Items, [int]$SelectedIndex, [int]$X, [int]$Y, [int]$Width = 180)
        $control = New-Object System.Windows.Forms.ComboBox
        $control.Name = $Name
        $control.DropDownStyle = "DropDownList"
        foreach ($item in $Items) { [void]$control.Items.Add($item) }
        $control.SelectedIndex = $SelectedIndex
        $control.Location = New-Object System.Drawing.Point($X, $Y)
        $control.Size = New-Object System.Drawing.Size($Width, 26)
        $panel.Controls.Add($control)
        return $control
    }
    $checkbox = {
        param([string]$Name, [string]$Text, [bool]$Checked, [int]$X, [int]$Y, [int]$Width = 625)
        $control = New-Object System.Windows.Forms.CheckBox
        $control.Name = $Name
        $control.Text = $Text
        $control.Checked = $Checked
        $control.Location = New-Object System.Drawing.Point($X, $Y)
        $control.Size = New-Object System.Drawing.Size($Width, 24)
        $panel.Controls.Add($control)
        return $control
    }

    if ($InitialSetup) {
        & $label "Etapa 2 de 2 — CLP, sensor e leitor" 22 14 640 24
        & $label "Defina o equipamento desta linha. A escrita física começa bloqueada e só pode ser habilitada com Dala vinculada e mapa confirmado." 22 40 640 32
    } else {
        & $label "Central: $CentralUrl" 22 14 640 24
        & $label "IP e porta Modbus TCP são cadastrados no registro da Dala no Central." 22 40 640 24
    }
    & $label "CLP E SENSOR" 22 78 640 24

    $currentModel = [string](Get-TraceConfigProperty $CurrentConfig "plc_model" "")
    $modelIndex = if ($currentModel -eq "INVT TS621") { 1 } else { 0 }
    $modelCombo = & $combo "plc_model" @("Sem mapa físico", "INVT TS621 — mapa aprovado") $modelIndex 22 106 300
    & $label "Saídas homologadas: esteira M2049 · reversão M2050 · emergência M2051 · retorno M17. Endereço do sensor configurável conforme o Ladder desta Dala." 22 138 650 42

    $unitField = & $textbox "modbus_unit_id" ([string](Get-TraceConfigProperty $CurrentConfig "modbus_unit_id" 1)) 22 203 100
    $functionIndex = [array]::IndexOf(@("1", "2", "3", "4"), [string](Get-TraceConfigProperty $CurrentConfig "modbus_heartbeat_function" 1))
    if ($functionIndex -lt 0) { $functionIndex = 0 }
    $functionCombo = & $combo "modbus_heartbeat_function" @("1", "2", "3", "4") $functionIndex 155 203 90
    $heartbeatField = & $textbox "modbus_heartbeat_register" ([string](Get-TraceConfigProperty $CurrentConfig "modbus_heartbeat_register" 2052)) 275 203 110
    $sensorField = & $textbox "modbus_sensor_register" ([string](Get-TraceConfigProperty $CurrentConfig "modbus_sensor_register" 2052)) 415 203 110
    & $label "Unidade Modbus" 22 180 115 20
    & $label "Função heartbeat" 155 180 130 20
    & $label "Endereço heartbeat" 275 180 130 20
    & $label "Endereço do sensor" 415 180 130 20

    $activeIndex = [array]::IndexOf(@("1", "0"), [string](Get-TraceConfigProperty $CurrentConfig "sensor_active_level" "1"))
    if ($activeIndex -lt 0) { $activeIndex = 0 }
    $activeCombo = & $combo "sensor_active_level" @("1 — ativo em nível alto", "0 — ativo em nível baixo") $activeIndex 22 267 245
    $signalItems = @("rising_edge", "falling_edge", "toggle")
    $signalIndex = [array]::IndexOf($signalItems, [string](Get-TraceConfigProperty $CurrentConfig "sensor_signal_mode" "rising_edge"))
    if ($signalIndex -lt 0) { $signalIndex = 0 }
    $signalCombo = & $combo "sensor_signal_mode" $signalItems $signalIndex 300 267 210
    & $label "Nível ativo do sensor" 22 244 200 20
    & $label "Detecção de passagem" 300 244 210 20

    $currentMapStatus = [string](Get-TraceConfigProperty $CurrentConfig "io_map_status" "CONFIRMAR")
    $mapCheck = & $checkbox "confirm_map" "Confirmo que o modelo e o Ladder desta Dala correspondem exatamente ao mapa acima." ($currentMapStatus -eq "APPROVED") 22 309
    $enableCurrent = (Get-TraceConfigProperty $CurrentConfig "physical_clp_enabled" $false) -eq $true
    $enableCheck = & $checkbox "enable_physical" "Habilitar comandos de escrita física (somente após confirmar mapa e vínculo da Dala)." $enableCurrent 22 337
    $enableCheck.Enabled = ($EquipmentId -gt 0 -and $modelIndex -eq 1 -and $mapCheck.Checked)
    $mapCheck.Add_CheckedChanged({
        $enableCheck.Enabled = ($EquipmentId -gt 0 -and $modelCombo.SelectedIndex -eq 1 -and $mapCheck.Checked)
        if (-not $enableCheck.Enabled) { $enableCheck.Checked = $false }
    })
    $modelCombo.Add_SelectedIndexChanged({
        $mapCheck.Enabled = ($modelCombo.SelectedIndex -eq 1)
        if (-not $mapCheck.Enabled) { $mapCheck.Checked = $false }
        $enableCheck.Enabled = ($EquipmentId -gt 0 -and $modelCombo.SelectedIndex -eq 1 -and $mapCheck.Checked)
        if (-not $enableCheck.Enabled) { $enableCheck.Checked = $false }
    })
    if ($EquipmentId -lt 1) {
        & $label "Vincule primeiro uma Dala no Central; comandos físicos ficarão bloqueados até o vínculo." 22 365 640 38
    }

    & $label "LEITOR DE CÓDIGO DE BARRAS" 22 412 640 24
    $scannerCombo = New-Object System.Windows.Forms.ComboBox
    $scannerCombo.Name = "scanner_usb_pattern"
    $scannerCombo.DropDownStyle = "DropDownList"
    $scannerCombo.DisplayMember = "Display"
    $scannerCandidates = @(Get-TraceUsbHidCandidates)
    [void]$scannerCombo.Items.Add([pscustomobject]@{
        Display = "Não configurar agora"
        Pattern = ""
        Model = ""
    })
    [void]$scannerCombo.Items.Add([pscustomobject]@{
        Display = "KEYENCE SR-X100W — Ethernet/TCP + FTP"
        Pattern = ""
        Model = "KEYENCE SR-X100W"
    })
    foreach ($candidate in $scannerCandidates) { [void]$scannerCombo.Items.Add($candidate) }
    $currentPattern = [string](Get-TraceConfigProperty $CurrentConfig "scanner_usb_pattern" "")
    if ($currentPattern -and -not ($scannerCandidates | Where-Object { $_.Pattern -eq $currentPattern })) {
        [void]$scannerCombo.Items.Add([pscustomobject]@{
            Display = "Leitor salvo anteriormente — $currentPattern"
            Pattern = $currentPattern
            Model = "USB HID"
        })
    }
    $scannerCombo.Location = New-Object System.Drawing.Point(22, 443)
    $scannerCombo.Size = New-Object System.Drawing.Size(630, 26)
    [void]$panel.Controls.Add($scannerCombo)
    $selectedScanner = 0
    $currentScannerModel = [string](Get-TraceConfigProperty $CurrentConfig "scanner_model" "")
    for ($index = 0; $index -lt $scannerCombo.Items.Count; $index++) {
        if ($currentScannerModel -eq "KEYENCE SR-X100W" -and $scannerCombo.Items[$index].Model -eq "KEYENCE SR-X100W") {
            $selectedScanner = $index
            break
        }
        if ($scannerCombo.Items[$index].Pattern -eq $currentPattern -and $currentPattern) {
            $selectedScanner = $index
            break
        }
    }
    $scannerCombo.SelectedIndex = $selectedScanner

    $timeoutField = & $textbox "scanner_no_read_timeout_ms" ([string](Get-TraceConfigProperty $CurrentConfig "scanner_no_read_timeout_ms" 1500)) 22 505 120
    & $label "Prazo sem leitura antes de registrar SEM_LEITURA (500–10000 ms)" 22 482 640 20
    & $label "No Keyence, configure o TCP para a porta 19000 e FTP passivo para a porta 2121. O FTP usa a senha KEYENCE_FTP_PASSWORD do .env." 22 538 645 42
    $keyencePcIpField = & $textbox "keyence_pc_ip" ([string](Get-TraceConfigProperty $CurrentConfig "keyence_pc_ip" "")) 22 610 180
    $keyenceReaderIpField = & $textbox "keyence_reader_ip" ([string](Get-TraceConfigProperty $CurrentConfig "keyence_reader_ip" "")) 250 610 180
    & $label "IPv4 fixo do PC na rede isolada do leitor" 22 588 220 20
    & $label "IPv4 fixo do KEYENCE SR-X100W" 250 588 220 20
    & $label "A aplicação restringe TCP e FTP ao IP informado do leitor. Configure e teste o SR-X100W no software KEYENCE antes de ativar a leitura." 22 646 645 38
    & $label "Aplicar uma alteração reinicia a API e a integração por alguns instantes. Faça isso entre carregamentos." 22 690 645 36

    $buttons = New-Object System.Windows.Forms.FlowLayoutPanel
    $buttons.FlowDirection = "RightToLeft"
    $buttons.Dock = "Bottom"
    $buttons.Height = 48
    $buttons.Padding = New-Object System.Windows.Forms.Padding(8)
    $form.Controls.Add($buttons)
    $saveButton = New-Object System.Windows.Forms.Button
    $saveButton.Text = if ($InitialSetup) { "Concluir configuração" } else { "Salvar configuração" }
    $saveButton.Width = 150
    $saveButton.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $cancelButton = New-Object System.Windows.Forms.Button
    $cancelButton.Text = "Cancelar"
    $cancelButton.Width = 100
    $cancelButton.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    [void]$buttons.Controls.Add($saveButton)
    [void]$buttons.Controls.Add($cancelButton)
    $form.AcceptButton = $saveButton
    $form.CancelButton = $cancelButton

    if ($modelIndex -eq 0) { $mapCheck.Enabled = $false; $enableCheck.Enabled = $false }
    while ($true) {
    $dialogResult = $form.ShowDialog()
    if ($dialogResult -ne [System.Windows.Forms.DialogResult]::OK) { return $null }

    $scannerItem = $scannerCombo.SelectedItem
    $physicalEnabled = $modelCombo.SelectedIndex -eq 1 -and $mapCheck.Checked -and $enableCheck.Checked
    $mapStatus = if ($modelCombo.SelectedIndex -eq 1 -and $mapCheck.Checked) { "APPROVED" } else { "CONFIRMAR" }
    $active = if ($activeCombo.SelectedIndex -eq 0) { "1" } else { "0" }
    $values = [ordered]@{
        equipment_id = $EquipmentId
        plc_model = if ($modelCombo.SelectedIndex -eq 1) { "INVT TS621" } else { "" }
        physical_clp_enabled = $physicalEnabled
        io_map_status = $mapStatus
        modbus_map = if ($mapStatus -eq "APPROVED") {
            [ordered]@{
                conveyor_run_coil = 2049
                reverse_command_coil = 2050
                emergency_command_coil = 2051
                emergency_feedback_coil = 17
            }
        } else {
            [ordered]@{
                conveyor_run_coil = $null
                reverse_command_coil = $null
                emergency_command_coil = $null
                emergency_feedback_coil = $null
            }
        }
        modbus_unit_id = $unitField.Text
        modbus_heartbeat_function = $functionCombo.SelectedItem
        modbus_heartbeat_register = $heartbeatField.Text
        modbus_sensor_register = $sensorField.Text
        sensor_active_level = $active
        sensor_signal_mode = $signalCombo.SelectedItem
        scanner_model = [string]$scannerItem.Model
        scanner_usb_pattern = [string]$scannerItem.Pattern
        keyence_pc_ip = $keyencePcIpField.Text.Trim()
        keyence_reader_ip = $keyenceReaderIpField.Text.Trim()
        scanner_no_read_timeout_ms = $timeoutField.Text
    }
    try {
        $settings = ConvertTo-TraceIndustrialSettings $values
    } catch {
        [System.Windows.Forms.MessageBox]::Show(
            $_.Exception.Message,
            'Revise os parâmetros',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
        $form.DialogResult = [System.Windows.Forms.DialogResult]::None
        continue
    }
    if ($ConfirmNoActiveLoad) {
        $confirmation = [System.Windows.Forms.MessageBox]::Show(
            'Aplicar vai reiniciar a API e a integração industrial por alguns instantes. Confirme que não há carregamento ativo.',
            'Confirmar aplicação da configuração',
            [System.Windows.Forms.MessageBoxButtons]::YesNo,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        )
        if ($confirmation -ne [System.Windows.Forms.DialogResult]::Yes) {
            $form.DialogResult = [System.Windows.Forms.DialogResult]::None
            continue
        }
    }
    return $settings
    }
}

function Set-TraceEnvValues {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Values
    )
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Arquivo .env não encontrado: $Path" }
    $lines = [System.Collections.Generic.List[string]]::new()
    $seen = @{}
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$' -and $Values.Contains($Matches[1])) {
            $key = $Matches[1]
            if ($seen.ContainsKey($key)) { throw "O arquivo .env repete a variável $key." }
            $lines.Add("$key=$($Values[$key])")
            $seen[$key] = $true
        } else {
            $lines.Add([string]$line)
        }
    }
    foreach ($key in $Values.Keys) {
        if (-not $seen.ContainsKey([string]$key)) { $lines.Add("$key=$($Values[$key])") }
    }
    $encoding = New-Object System.Text.UTF8Encoding($false)
    $temporaryPath = "$Path.$([guid]::NewGuid().ToString('N')).tmp"
    $backupPath = "$Path.$([guid]::NewGuid().ToString('N')).bak"
    try {
        $originalAcl = Get-Acl -LiteralPath $Path
        [System.IO.File]::WriteAllLines($temporaryPath, $lines, $encoding)
        Set-Acl -LiteralPath $temporaryPath -AclObject $originalAcl
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            [System.IO.File]::Replace($temporaryPath, $Path, $backupPath)
        } else {
            [System.IO.File]::Move($temporaryPath, $Path)
        }
    } finally {
        Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    }
}

function ConvertTo-TraceIndustrialEnvValues {
    param([object]$Settings)
    return [ordered]@{
        TRACE_MODBUS_UNIT_ID = [string]$Settings.modbus_unit_id
        TRACE_MODBUS_HEARTBEAT_FUNCTION = [string]$Settings.modbus_heartbeat_function
        TRACE_MODBUS_HEARTBEAT_REGISTER = [string]$Settings.modbus_heartbeat_register
        TRACE_MODBUS_SENSOR_REGISTER = [string]$Settings.modbus_sensor_register
        TRACE_SENSOR_ACTIVE_LEVEL = [string]$Settings.sensor_active_level
        TRACE_SENSOR_SIGNAL_MODE = [string]$Settings.sensor_signal_mode
        TRACE_SCANNER_NO_READ_TIMEOUT_MS = [string]$Settings.scanner_no_read_timeout_ms
        KEYENCE_ENABLED = if ($Settings.scanner_model -eq "KEYENCE SR-X100W") { "1" } else { "0" }
        KEYENCE_BIND_ADDRESS = [string]$Settings.keyence_pc_ip
        KEYENCE_FTP_PASV_ADDRESS = [string]$Settings.keyence_pc_ip
        KEYENCE_TCP_ALLOWED_ADDRESS = [string]$Settings.keyence_reader_ip
        KEYENCE_FTP_ALLOWED_ADDRESS = [string]$Settings.keyence_reader_ip
    }
}
