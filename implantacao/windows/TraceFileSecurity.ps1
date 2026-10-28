function Set-TracePrivatePathAcl {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [switch]$Directory
    )

    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force
    if ($Directory -and -not $item.PSIsContainer) { throw "Esperava uma pasta para proteger: $Path" }
    if (-not $Directory -and $item.PSIsContainer) { throw "Esperava um arquivo para proteger: $Path" }

    $acl = Get-Acl -LiteralPath $Path
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.Access)) { [void]$acl.RemoveAccessRuleSpecific($rule) }

    $inheritance = if ($Directory) {
        [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor
        [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    } else {
        [System.Security.AccessControl.InheritanceFlags]::None
    }
    $sids = @(
        [System.Security.Principal.WindowsIdentity]::GetCurrent().User,
        [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18'),
        [System.Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    ) | Sort-Object Value -Unique

    foreach ($sid in $sids) {
        $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Protect-TraceMachineConfiguration {
    param([Parameter(Mandatory = $true)][string]$InstallRoot)

    $configRoot = Join-Path $InstallRoot 'config'
    New-Item -ItemType Directory -Force -Path $configRoot | Out-Null
    Set-TracePrivatePathAcl -Path $configRoot -Directory

    foreach ($name in @('machine.json', 'node-red-clp.env')) {
        Set-TracePrivatePathAcl -Path (Join-Path $configRoot $name)
    }
    Set-TracePrivatePathAcl -Path (Join-Path $InstallRoot '.env')
}
