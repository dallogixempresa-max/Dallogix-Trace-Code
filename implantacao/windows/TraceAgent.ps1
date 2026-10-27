param(
    [string]$ConfigPath = ""
)
$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) "config\machine.json"
}

function Write-AgentLog([string]$Message) {
    $installRoot = Split-Path -Parent (Split-Path -Parent $ConfigPath)
    $logDir = Join-Path $installRoot "logs"
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    Add-Content -Path (Join-Path $logDir "agent.log") -Value ("{0:u} {1}" -f (Get-Date), $Message)
}

if (-not (Test-Path $ConfigPath)) { throw "Configuração da máquina não encontrada: $ConfigPath" }
$config = Get-Content $ConfigPath -Raw | ConvertFrom-Json
if (-not $config.machine_id -or -not $config.equipment_id -or -not $config.central_api_url -or -not $config.device_token) { throw "machine.json incompleto." }
if ([string]$config.central_api_url -notmatch '^https://') { throw "central_api_url deve usar HTTPS." }
if ($config.physical_clp_enabled -eq $true -and $config.io_map_status -ne "APPROVED") {
    throw "CLP físico bloqueado: o mapa de I/O não está aprovado."
}
$interval = [Math]::Max(5, [int]$config.heartbeat_seconds)
$heartbeatUrl = "$($config.central_api_url.TrimEnd('/'))/api/device_heartbeat.php"

function Get-ScannerPresenceStatus {
    $pattern = [string]$config.scanner_usb_pattern
    if ([string]::IsNullOrWhiteSpace($pattern)) { return $null }
    if ($pattern -notmatch '\AUSB\\VID_[0-9A-F]{4}&PID_[0-9A-F]{4}(?:&MI_[0-9A-F]{2})?\z') {
        return "ERRO"
    }

    try {
        $matchingDevices = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
            $_.Class -in @("Keyboard", "HIDClass") -and
            ([string]$_.InstanceId).StartsWith($pattern, [StringComparison]::OrdinalIgnoreCase)
        })
        if ($matchingDevices.Count -eq 0) { return "OFFLINE" }
        if ($matchingDevices | Where-Object { $_.Status -eq "OK" }) { return "ONLINE" }
        return "ERRO"
    } catch {
        return "ERRO"
    }
}

function Send-DeviceHeartbeat([string]$DeviceType, [string]$Status, [hashtable]$Details) {
    if ($DeviceType -eq "SCANNER" -and $Status -in @("OFFLINE", "ERRO")) {
        $Details.reason_code = if ($Status -eq "OFFLINE") {
            "scanner_usb_not_detected"
        } else {
            "scanner_usb_check_failed"
        }
    }
    $body = @{
        equipment_id = [int]$config.equipment_id
        device_type = $DeviceType
        status = $Status
        details = $Details
    } | ConvertTo-Json -Depth 5
    Invoke-RestMethod -Uri $heartbeatUrl -Method Post `
        -Headers @{ "X-Device-Token" = [string]$config.device_token } `
        -ContentType "application/json" -Body $body -TimeoutSec 10 | Out-Null
}

$nextServerHeartbeat = [datetime]::MinValue
$nextScannerHeartbeat = [datetime]::MinValue
$nextScannerRetry = [datetime]::MinValue
$nextScannerErrorLog = [datetime]::MinValue
$lastReportedScannerStatus = $null

while ($true) {
    $now = Get-Date
    if ($now -ge $nextServerHeartbeat) {
        $traceOnline = $false
        try {
            $health = Invoke-WebRequest -Uri "$($config.trace_url.TrimEnd('/'))/api/health.php" -UseBasicParsing -TimeoutSec 5
            $traceOnline = $health.StatusCode -eq 200
        } catch {
            Write-AgentLog "Trace local indisponível: $($_.Exception.Message)"
        }
        $status = if ($traceOnline) { "ONLINE" } else { "ERRO" }
        try {
            Send-DeviceHeartbeat "SERVER" $status @{
                machine_id = [string]$config.machine_id
                equipment_code = [string]$config.equipment_code
                operation_mode = [string]$config.operation_mode
                physical_clp_enabled = [bool]$config.physical_clp_enabled
                io_map_status = [string]$config.io_map_status
            }
        } catch {
            Write-AgentLog "Servidor central indisponível; operação local preservada: $($_.Exception.Message)"
        }
        $nextServerHeartbeat = $now.AddSeconds($interval)
    }

    $scannerStatus = Get-ScannerPresenceStatus
    if ($null -ne $scannerStatus) {
        $statusChanged = $scannerStatus -ne $lastReportedScannerStatus
        if (($statusChanged -or $now -ge $nextScannerHeartbeat) -and $now -ge $nextScannerRetry) {
            try {
                Send-DeviceHeartbeat "SCANNER" $scannerStatus @{
                    machine_id = [string]$config.machine_id
                    equipment_code = [string]$config.equipment_code
                    scanner_model = if ([string]::IsNullOrWhiteSpace([string]$config.scanner_model)) { "USB HID" } else { [string]$config.scanner_model }
                    signal_source = "WINDOWS_PNP_USB_HID"
                }
                $lastReportedScannerStatus = $scannerStatus
                $nextScannerHeartbeat = $now.AddSeconds($interval)
                $nextScannerRetry = [datetime]::MinValue
            } catch {
                if ($now -ge $nextScannerErrorLog) {
                    Write-AgentLog "Não foi possível enviar o status USB do scanner ao Central: $($_.Exception.Message)"
                    $nextScannerErrorLog = $now.AddSeconds($interval)
                }
                $nextScannerRetry = $now.AddSeconds(5)
            }
        }
    }

    Start-Sleep -Seconds 2
}
