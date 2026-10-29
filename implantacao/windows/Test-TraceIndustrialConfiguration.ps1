$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'TraceIndustrialConfiguration.ps1')

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Assert-Throws([scriptblock]$Action, [string]$Message) {
    try { & $Action } catch { return }
    throw $Message
}

$valid = @{
    equipment_id = 0
    plc_model = ''
    physical_clp_enabled = $false
    io_map_status = 'CONFIRMAR'
    modbus_map = $null
    scanner_model = 'USB HID'
    scanner_usb_pattern = 'USB\VID_1234&PID_ABCD'
    keyence_pc_ip = ''
    keyence_reader_ip = ''
    scanner_no_read_timeout_ms = '2500'
    modbus_unit_id = '7'
    modbus_heartbeat_function = '3'
    modbus_heartbeat_register = '2052'
    modbus_sensor_register = '2053'
    sensor_active_level = '0'
    sensor_signal_mode = 'falling_edge'
}
$parsed = ConvertTo-TraceIndustrialSettings $valid
Assert-True ($parsed.modbus_unit_id -eq 7) 'Modbus unit conversion failed.'
Assert-True ($parsed.scanner_no_read_timeout_ms -eq 2500) 'Scanner timeout conversion failed.'
Assert-True (-not $parsed.physical_clp_enabled) 'Physical writes must remain disabled by default.'

$keyence = $valid.Clone()
$keyence.scanner_model = 'KEYENCE SR-X100W'
$keyence.scanner_usb_pattern = ''
$keyence.keyence_pc_ip = '192.168.10.10'
$keyence.keyence_reader_ip = '192.168.10.20'
$parsedKeyence = ConvertTo-TraceIndustrialSettings $keyence
$keyenceEnv = ConvertTo-TraceIndustrialEnvValues $parsedKeyence
Assert-True ($parsedKeyence.scanner_model -eq 'KEYENCE SR-X100W') 'Keyence scanner selection was rejected.'
Assert-True ($keyenceEnv.KEYENCE_ENABLED -eq '1') 'Keyence selection did not enable the Keyence worker.'
Assert-True ($keyenceEnv.KEYENCE_ARCHIVE_ONLY -eq '0') 'Keyence selection must enable scanner reading and sensor association.'
Assert-True ($keyenceEnv.KEYENCE_IMAGE_ARCHIVE_HOST_DIR -eq 'C:/Keyence/Falhas') 'Keyence error-image archive folder was not configured.'
Assert-True ($keyenceEnv.KEYENCE_BIND_ADDRESS -eq '192.168.10.10') 'PC Keyence address was not applied.'
Assert-True ($keyenceEnv.KEYENCE_TCP_ALLOWED_ADDRESS -eq '192.168.10.20') 'Reader allowlist address was not applied.'
$invalidKeyenceIp = $keyence.Clone()
$invalidKeyenceIp.keyence_reader_ip = 'not-an-ip'
Assert-Throws { ConvertTo-TraceIndustrialSettings $invalidKeyenceIp } 'Invalid Keyence reader IPv4 address was accepted.'

$invalidUnit = $valid.Clone()
$invalidUnit.modbus_unit_id = '256'
Assert-Throws { ConvertTo-TraceIndustrialSettings $invalidUnit } 'Unit ID above 255 was accepted.'
$invalidTimeout = $valid.Clone()
$invalidTimeout.scanner_no_read_timeout_ms = '499'
Assert-Throws { ConvertTo-TraceIndustrialSettings $invalidTimeout } 'Scanner timeout below 500 ms was accepted.'
$invalidPattern = $valid.Clone()
$invalidPattern.scanner_usb_pattern = 'USB\VID_NOPE'
Assert-Throws { ConvertTo-TraceIndustrialSettings $invalidPattern } 'Invalid USB HID pattern was accepted.'
$negativeEquipment = $valid.Clone()
$negativeEquipment.equipment_id = -1
Assert-Throws { ConvertTo-TraceIndustrialSettings $negativeEquipment } 'Negative equipment ID was accepted.'

$approved = $valid.Clone()
$approved.equipment_id = 9
$approved.plc_model = 'INVT TS621'
$approved.io_map_status = 'APPROVED'
$approved.physical_clp_enabled = $true
$approved.modbus_map = @{
    conveyor_run_coil = 2049
    reverse_command_coil = 2050
    emergency_command_coil = 2051
    emergency_feedback_coil = 17
}
$parsedApproved = ConvertTo-TraceIndustrialSettings $approved
Assert-True $parsedApproved.physical_clp_enabled 'Approved and linked TS621 map was rejected.'
$wrongMap = $approved.Clone()
$wrongMap.modbus_map = $approved.modbus_map.Clone()
$wrongMap.modbus_map.conveyor_run_coil = 2048
Assert-Throws { ConvertTo-TraceIndustrialSettings $wrongMap } 'Changed approved output address was accepted.'
$unlinked = $approved.Clone()
$unlinked.equipment_id = 0
Assert-Throws { ConvertTo-TraceIndustrialSettings $unlinked } 'Physical writes without a linked Dala were accepted.'

$temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ('trace-industrial-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
try {
    $envPath = Join-Path $temporaryDirectory '.env'
    [IO.File]::WriteAllText($envPath, "MYSQL_PASSWORD=keep-this-value`nTRACE_MODBUS_UNIT_ID=1`n# keep comment`n", (New-Object System.Text.UTF8Encoding($false)))
    Set-TraceEnvValues -Path $envPath -Values ([ordered]@{ TRACE_MODBUS_UNIT_ID = '7'; TRACE_SCANNER_NO_READ_TIMEOUT_MS = '2500' })
    $envText = [IO.File]::ReadAllText($envPath)
    Assert-True ($envText.Contains('MYSQL_PASSWORD=keep-this-value')) 'Unrelated .env value was not preserved.'
    Assert-True ($envText.Contains('# keep comment')) 'Unrelated .env comment was not preserved.'
    Assert-True ($envText.Contains('TRACE_MODBUS_UNIT_ID=7')) 'Modbus value was not updated.'
    Assert-True ($envText.Contains('TRACE_SCANNER_NO_READ_TIMEOUT_MS=2500')) 'Scanner value was not added.'
    [IO.File]::WriteAllText($envPath, "TRACE_MODBUS_UNIT_ID=1`nTRACE_MODBUS_UNIT_ID=2`n", (New-Object System.Text.UTF8Encoding($false)))
    Assert-Throws { Set-TraceEnvValues -Path $envPath -Values ([ordered]@{ TRACE_MODBUS_UNIT_ID = '7' }) } 'Duplicate .env key was accepted.'
} finally {
    Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host 'Industrial configuration validation passed.'
