param(
    [string]$PackageRoot = (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))),
    [string]$MachineConfig = (Join-Path $PSScriptRoot "machine-config.json"),
    [switch]$IndustrialOnly
)
$ErrorActionPreference = "Stop"

$databaseVolumeGuard = Join-Path $PSScriptRoot 'TraceDatabaseVolume.ps1'
if (-not (Test-Path -LiteralPath $databaseVolumeGuard -PathType Leaf)) { throw "Protecao do volume MySQL ausente: $databaseVolumeGuard" }
. $databaseVolumeGuard
$industrialConfigScript = Join-Path $PSScriptRoot 'TraceIndustrialConfiguration.ps1'
if (-not (Test-Path -LiteralPath $industrialConfigScript -PathType Leaf)) { throw "Configurador industrial ausente: $industrialConfigScript" }
. $industrialConfigScript
$traceFileSecurity = Join-Path $PSScriptRoot 'TraceFileSecurity.ps1'
if (-not (Test-Path -LiteralPath $traceFileSecurity -PathType Leaf)) { throw "Proteção da configuração industrial ausente: $traceFileSecurity" }
. $traceFileSecurity

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Execute a preparação inicial com uma conta técnica administradora."
}
$compose = Join-Path $PackageRoot "docker-compose.yml"
$envFile = Join-Path $PackageRoot ".env"
if (-not (Test-Path $compose)) { throw "Pacote sem docker-compose.yml: $PackageRoot" }
if (-not (Test-Path $envFile)) { throw "O .env deve ser entregue previamente pela Dallogix." }
if (-not (Test-Path $MachineConfig)) { throw "machine.json deve ser criado pela Dallogix antes da instalação." }
if (-not (Get-Command docker.exe -ErrorAction SilentlyContinue)) { throw "Docker Desktop não está instalado." }

$config = Get-Content $MachineConfig -Raw | ConvertFrom-Json
if (-not $config.machine_id -or -not $config.central_api_url) { throw "machine.json sem identidade ou servidor central." }
$industrialSettings = ConvertTo-TraceIndustrialSettings -Configuration $config
$equipmentId = if ($null -eq $config.equipment_id) { 0 } else { [int]$config.equipment_id }
$equipmentCode = if ($null -eq $config.equipment_code) { "" } else { [string]$config.equipment_code }
$deviceToken = if ($null -eq $config.device_token) { "" } else { [string]$config.device_token }
$hasEquipment = $equipmentId -gt 0
if ($hasEquipment -and ([string]::IsNullOrWhiteSpace($equipmentCode) -or [string]::IsNullOrWhiteSpace($deviceToken))) {
    throw "machine.json tem uma Dala, mas falta o código ou o token do dispositivo SERVER."
}
if (-not $hasEquipment -and (-not [string]::IsNullOrWhiteSpace($equipmentCode) -or -not [string]::IsNullOrWhiteSpace($deviceToken))) {
    throw "machine.json sem Dala não pode conter código ou token de dispositivo."
}
if ($null -ne $config.physical_clp_enabled -and $config.physical_clp_enabled -isnot [bool]) {
    throw "physical_clp_enabled deve ser booleano (true ou false)."
}
$physicalClpEnabled = $industrialSettings.physical_clp_enabled
$ioMapStatus = $industrialSettings.io_map_status
if ($ioMapStatus -notin @("CONFIRMAR", "APPROVED")) { throw "io_map_status deve ser CONFIRMAR ou APPROVED." }
$modbusMap = $industrialSettings.modbus_map
$modbusSignals = @("conveyor_run_coil", "reverse_command_coil", "emergency_command_coil", "emergency_feedback_coil")
$modbusAddresses = @{}
if ($physicalClpEnabled) {
    if ($ioMapStatus -ne "APPROVED") { throw "Instalação física bloqueada sem mapa de I/O aprovado." }
    if ($equipmentId -lt 1) { throw "Instalação física bloqueada sem equipment_id vinculado ao mapa desta Dala." }
    if ($null -eq $modbusMap) { throw "Instalação física bloqueada sem modbus_map no machine.json." }
    foreach ($signal in $modbusSignals) {
        $address = 0
        if (-not [int]::TryParse([string]$modbusMap.$signal, [ref]$address) -or $address -lt 0 -or $address -gt 65535) {
            throw "Instalação física bloqueada: modbus_map.$signal deve ser um endereço inteiro entre 0 e 65535."
        }
        $modbusAddresses[$signal] = $address
    }
    $outputAddresses = @($modbusAddresses.conveyor_run_coil, $modbusAddresses.reverse_command_coil, $modbusAddresses.emergency_command_coil)
    $uniqueOutputAddresses = @($outputAddresses | Select-Object -Unique)
    if ($uniqueOutputAddresses.Count -ne 3) {
        throw "Instalação física bloqueada: bobinas de saída devem ter endereços distintos."
    }
    $uniqueAddresses = @($modbusAddresses.Values | Select-Object -Unique)
    if ($uniqueAddresses.Count -ne 4) {
        throw "Instalação física bloqueada: a bobina de retorno deve ser distinta das bobinas de saída."
    }
}

$installRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$configDir = Join-Path $installRoot "config"
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
if ((Resolve-Path -LiteralPath $MachineConfig).Path -ne (Join-Path $configDir "machine.json")) {
    Copy-Item $MachineConfig (Join-Path $configDir "machine.json") -Force
}
Protect-TraceMachineConfiguration -InstallRoot $PackageRoot
$nodeRedEnvPath = Join-Path $configDir "node-red-clp.env"
$nodeRedEnvValues = [ordered]@{
    TRACE_PHYSICAL_CLP_ENABLED = if ($physicalClpEnabled) { "1" } else { "0" }
    TRACE_IO_MAP_STATUS = $ioMapStatus
    TRACE_MODBUS_MAP_EQUIPMENT_ID = if ($physicalClpEnabled) { [string]$equipmentId } else { "0" }
    TRACE_MODBUS_COIL_CONVEYOR_RUN = if ($physicalClpEnabled) { [string]$modbusAddresses.conveyor_run_coil } else { "" }
    TRACE_MODBUS_COIL_REVERSAL = if ($physicalClpEnabled) { [string]$modbusAddresses.reverse_command_coil } else { "" }
    TRACE_MODBUS_COIL_EMERGENCY = if ($physicalClpEnabled) { [string]$modbusAddresses.emergency_command_coil } else { "" }
    TRACE_MODBUS_COIL_EMERGENCY_FEEDBACK = if ($physicalClpEnabled) { [string]$modbusAddresses.emergency_feedback_coil } else { "" }
}
$nodeRedEnvLines = @($nodeRedEnvValues.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" })
$keyenceSecretValues = [ordered]@{}
if ($industrialSettings.scanner_model -eq 'KEYENCE SR-X100W') {
    $existingEnvValues = @{}
    foreach ($line in [System.IO.File]::ReadAllLines($envFile)) {
        if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $key = $matches[1]
            if ($existingEnvValues.ContainsKey($key)) { throw "O arquivo .env contem a variavel $key mais de uma vez. Corrija antes de continuar." }
            $existingEnvValues[$key] = $matches[2].Trim().Trim('"').Trim("'")
        }
    }
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        foreach ($key in @('KEYENCE_DEVICE_TOKEN', 'KEYENCE_FTP_PASSWORD')) {
            if (-not $existingEnvValues.ContainsKey($key) -or [string]::IsNullOrWhiteSpace([string]$existingEnvValues[$key])) {
                $bytes = New-Object byte[] 32
                $random.GetBytes($bytes)
                $keyenceSecretValues[$key] = [System.BitConverter]::ToString($bytes).Replace('-', '')
            }
        }
    } finally {
        $random.Dispose()
    }
}
$environmentValues = ConvertTo-TraceIndustrialEnvValues -Settings $industrialSettings
foreach ($key in $keyenceSecretValues.Keys) { $environmentValues[$key] = $keyenceSecretValues[$key] }
Set-TraceEnvValues -Path $envFile -Values $environmentValues
Set-Location $PackageRoot

[System.IO.File]::WriteAllText(
    $nodeRedEnvPath,
    (($nodeRedEnvLines -join [Environment]::NewLine) + [Environment]::NewLine),
    ([System.Text.UTF8Encoding]::new($false))
)
Set-Location $PackageRoot
Protect-TraceMachineConfiguration -InstallRoot $PackageRoot

& docker.exe compose --profile industrial config --quiet
if ($LASTEXITCODE -ne 0) { throw "A configuração validada do Docker Compose não passou. Nenhum container foi reiniciado." }

if ($IndustrialOnly) {
    & docker.exe compose --profile industrial up -d --no-deps --wait --wait-timeout 120 php node-red keyence-worker
    if ($LASTEXITCODE -ne 0) { throw "A API, o Node-RED e o leitor não ficaram saudáveis com a configuração industrial salva." }
    if ($hasEquipment -and (Get-ScheduledTask -TaskName "Dallogix Trace Agent" -ErrorAction SilentlyContinue)) {
        Stop-ScheduledTask -TaskName "Dallogix Trace Agent" -ErrorAction SilentlyContinue
        Start-ScheduledTask -TaskName "Dallogix Trace Agent"
    }
    Write-Output "Configuração industrial aplicada. API, Node-RED, leitor e agente atualizados; banco e volumes não foram alterados."
    return
}

# O Docker Desktop precisa ser iniciado na sessão interativa do operador. A
# tarefa antiga apontava para um .cmd em Downloads, que pode ser apagado ou
# mover-se entre instalações. Registre o executável real no logon da conta
# técnica usada para preparar a máquina.
$dockerDesktopCandidates = @(
    (Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"),
    (Join-Path $env:LOCALAPPDATA "Programs\DockerDesktop\Docker Desktop.exe")
)
$programFilesX86 = [Environment]::GetEnvironmentVariable("ProgramFiles(x86)")
if ($programFilesX86) { $dockerDesktopCandidates += Join-Path $programFilesX86 "Docker\Docker\Docker Desktop.exe" }
$dockerDesktop = $dockerDesktopCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $dockerDesktop) { throw "Docker Desktop não foi encontrado para configurar a inicialização automática." }
$dockerStartScript = Join-Path $PackageRoot "implantacao\windows\Start-TraceDocker.ps1"
if (-not (Test-Path -LiteralPath $dockerStartScript -PathType Leaf)) { throw "Script de inicialização do Docker não encontrado." }
$interactiveUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$dockerTaskPrincipal = New-ScheduledTaskPrincipal -UserId $interactiveUser -LogonType Interactive -RunLevel Limited
$dockerTaskAction = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$dockerStartScript`" -InstallRoot `"$PackageRoot`"" -WorkingDirectory $PackageRoot
$dockerTaskTrigger = New-ScheduledTaskTrigger -AtLogOn -User $interactiveUser
$dockerTaskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2) -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName "Trace-Docker-Start" -Action $dockerTaskAction -Trigger $dockerTaskTrigger -Principal $dockerTaskPrincipal -Settings $dockerTaskSettings -Description "Inicia o Docker Desktop do Trace após o login da conta técnica." -Force | Out-Null

Assert-TraceMySqlVolumeExists -InstallRoot $PackageRoot -RequireMarker | Out-Null
& docker.exe compose --project-directory $PackageRoot --env-file $envFile --profile industrial up -d --remove-orphans
if ($LASTEXITCODE -ne 0) { throw "Nao foi possivel iniciar os containers do Trace." }

& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PackageRoot "implantacao\windows\Register-TraceUpdater.ps1") -PackageRoot $PackageRoot
if ($LASTEXITCODE -ne 0) { throw "Nao foi possivel registrar a atualizacao automatica de producao." }
$taskPrincipal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
if ($hasEquipment) {
    $agentScript = Join-Path $PackageRoot "implantacao\windows\TraceAgent.ps1"
    $taskAction = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument "-NoLogo -NoProfile -ExecutionPolicy Bypass -File `"$agentScript`" -ConfigPath `"$configDir\machine.json`""
    $taskTrigger = New-ScheduledTaskTrigger -AtStartup
    Register-ScheduledTask -TaskName "Dallogix Trace Agent" -Action $taskAction -Trigger $taskTrigger -Principal $taskPrincipal -Force | Out-Null
    Start-ScheduledTask -TaskName "Dallogix Trace Agent"
    Write-Output "Agente do PC industrial registrado para a Dala $equipmentCode."
} else {
    Unregister-ScheduledTask -TaskName "Dallogix Trace Agent" -Confirm:$false -ErrorAction SilentlyContinue
    Write-Output "PC industrial instalado sem Dala; o agente será registrado após o vínculo da primeira Dala."
}
Write-Output "Máquina preparada: $($config.machine_id). Modo: $($config.operation_mode). CLP físico habilitado: $($config.physical_clp_enabled)."
Write-Output "Atualizacao automatica de releases assinadas configurada pela tarefa estavel."
