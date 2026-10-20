param(
    [string]$ConfigPath = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) "config\machine.json")
)
$ErrorActionPreference = "Stop"

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Execute este utilitário como administrador."
}
if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "Configuração da máquina não encontrada: $ConfigPath"
}

$config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
if ([int]$config.equipment_id -lt 1 -or [string]::IsNullOrWhiteSpace([string]$config.device_token)) {
    throw "Vincule o PC a uma Dala e configure o token SERVER antes de monitorar o scanner."
}
$agentTask = Get-ScheduledTask -TaskName "Dallogix Trace Agent" -ErrorAction SilentlyContinue
if (-not $agentTask) {
    throw "Tarefa Dallogix Trace Agent não encontrada. Nenhuma configuração foi alterada."
}

$devices = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
    $_.Class -in @("Keyboard", "HIDClass") -and
    ([string]$_.InstanceId) -like "USB\VID_*" -and
    $_.Status -eq "OK"
})
$candidates = foreach ($device in $devices) {
    if ([string]$device.InstanceId -match '\A(USB\\VID_[0-9A-F]{4}&PID_[0-9A-F]{4}(?:&MI_[0-9A-F]{2})?)') {
        [pscustomobject]@{
            Name = [string]$device.FriendlyName
            Pattern = $Matches[1]
        }
    }
}
$candidates = @($candidates | Sort-Object Pattern -Unique)
if ($candidates.Count -eq 0) {
    throw "Nenhum dispositivo USB HID elegível foi encontrado. Nenhuma configuração foi alterada."
}

Write-Output "Selecione somente o Elgin EL8600. Se não conseguir identificá-lo, interrompa e desconecte outros dispositivos USB HID antes de tentar novamente."
for ($index = 0; $index -lt $candidates.Count; $index++) {
    Write-Output ("{0}. {1} — {2}" -f ($index + 1), $candidates[$index].Name, $candidates[$index].Pattern)
}
$selectionText = Read-Host "Número do Elgin EL8600 (0 para cancelar)"
$selection = 0
if (-not [int]::TryParse($selectionText, [ref]$selection) -or $selection -lt 0 -or $selection -gt $candidates.Count) {
    throw "Seleção USB inválida. Nenhuma configuração foi alterada."
}
if ($selection -eq 0) {
    Write-Output "Configuração cancelada; machine.json não foi alterado."
    exit 0
}

$config | Add-Member -NotePropertyName scanner_usb_pattern -NotePropertyValue $candidates[$selection - 1].Pattern -Force
$backupPath = "$ConfigPath.bak.$(Get-Date -Format 'yyyyMMdd-HHmmss-fff')"
$temporaryPath = "$ConfigPath.tmp"
Copy-Item -LiteralPath $ConfigPath -Destination $backupPath
try {
    $json = $config | ConvertTo-Json -Depth 10
    [System.IO.File]::WriteAllText($temporaryPath, $json, [System.Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $ConfigPath -Force
} finally {
    if (Test-Path -LiteralPath $temporaryPath) {
        Remove-Item -LiteralPath $temporaryPath -Force
    }
}

try {
    if ($agentTask.State -eq "Running") {
        Stop-ScheduledTask -TaskName "Dallogix Trace Agent"
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            if ((Get-ScheduledTask -TaskName "Dallogix Trace Agent").State -ne "Running") { break }
            Start-Sleep -Milliseconds 250
        }
        if ((Get-ScheduledTask -TaskName "Dallogix Trace Agent").State -eq "Running") {
            throw "O agente não encerrou no prazo esperado."
        }
    }
    Start-ScheduledTask -TaskName "Dallogix Trace Agent"
} catch {
    throw "Identificação USB salva, mas o agente não reiniciou. Cópia de segurança: $backupPath. Detalhe: $($_.Exception.Message)"
}

Write-Output "Scanner configurado: $($config.scanner_usb_pattern)"
Write-Output "Agente reiniciado. Cópia anterior: $backupPath"
