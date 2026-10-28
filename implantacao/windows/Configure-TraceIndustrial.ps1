param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [string]$MachineConfig = ''
)

$ErrorActionPreference = 'Stop'
$PackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
if (-not $MachineConfig) { $MachineConfig = Join-Path $PackageRoot 'config\machine.json' }

$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $arguments = @(
        '-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA',
        '-File', ('"' + $PSCommandPath + '"'),
        '-PackageRoot', ('"' + $PackageRoot + '"'),
        '-MachineConfig', ('"' + $MachineConfig + '"')
    )
    $process = Start-Process -FilePath 'powershell.exe' -ArgumentList $arguments -Verb RunAs -Wait -PassThru
    exit $process.ExitCode
}

$settingsScript = Join-Path $PackageRoot 'implantacao\windows\TraceIndustrialConfiguration.ps1'
$installerScript = Join-Path $PackageRoot 'implantacao\windows\Install-TraceMachine.ps1'
if (-not (Test-Path -LiteralPath $settingsScript -PathType Leaf)) { throw "Configurador industrial ausente: $settingsScript" }
if (-not (Test-Path -LiteralPath $installerScript -PathType Leaf)) { throw "Instalador do Trace ausente: $installerScript" }
if (-not (Test-Path -LiteralPath $MachineConfig -PathType Leaf)) {
    throw "Configuração da máquina ausente: $MachineConfig. Execute primeiro a instalação do Trace."
}
. $settingsScript

Add-Type -AssemblyName System.Windows.Forms
$config = Get-Content -LiteralPath $MachineConfig -Raw | ConvertFrom-Json
if (-not $config.machine_id -or -not $config.central_api_url) { throw 'machine.json não contém identidade e servidor Central.' }
$equipmentId = 0
if ($null -ne $config.equipment_id -and
    -not [int]::TryParse([string]$config.equipment_id, [ref]$equipmentId)) {
    throw 'equipment_id em machine.json não é um inteiro válido.'
}

$settings = Show-TraceIndustrialSettingsDialog `
    -CurrentConfig $config `
    -EquipmentId $equipmentId `
    -CentralUrl ([string]$config.central_api_url) `
    -ConfirmNoActiveLoad
if ($null -eq $settings) { exit 0 }

foreach ($name in @(
    'scanner_model', 'scanner_usb_pattern', 'keyence_pc_ip', 'keyence_reader_ip', 'scanner_no_read_timeout_ms',
    'plc_model', 'physical_clp_enabled', 'io_map_status', 'modbus_map',
    'modbus_unit_id', 'modbus_heartbeat_function', 'modbus_heartbeat_register',
    'modbus_sensor_register', 'sensor_active_level', 'sensor_signal_mode'
)) {
    $config | Add-Member -NotePropertyName $name -NotePropertyValue $settings[$name] -Force
}

$temporaryPath = "$MachineConfig.$([guid]::NewGuid().ToString('N')).tmp"
$backupPath = "$MachineConfig.$([guid]::NewGuid().ToString('N')).bak"
try {
    $json = $config | ConvertTo-Json -Depth 8
    [System.IO.File]::WriteAllText($temporaryPath, $json + [Environment]::NewLine, (New-Object System.Text.UTF8Encoding($false)))
    [System.IO.File]::Replace($temporaryPath, $MachineConfig, $backupPath)
} finally {
    Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
}

try {
    & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $installerScript -PackageRoot $PackageRoot -MachineConfig $MachineConfig -IndustrialOnly
    if ($LASTEXITCODE -ne 0) { throw "O Trace não aplicou a configuração industrial (código $LASTEXITCODE)." }
    [System.Windows.Forms.MessageBox]::Show(
        'Configuração salva. O Node-RED e o agente do Trace foram atualizados.',
        'Dallogix Trace',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Information
    ) | Out-Null
} catch {
    [System.Windows.Forms.MessageBox]::Show(
        "A configuração foi salva, mas não foi aplicada. Abra este configurador novamente após corrigir o problema.`r`n`r`n$($_.Exception.Message)",
        'Dallogix Trace — falha ao aplicar',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
    throw
}
