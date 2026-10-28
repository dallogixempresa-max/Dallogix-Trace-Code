; Compilar com Build-TraceSetup.ps1 a partir de uma tag aprovada.
; O pacote não contém credenciais reais; o .env e o machine.json são fornecidos
; durante a instalação homologada.
#define TraceInstallVersion GetEnv("TRACE_INSTALL_VERSION")
[Setup]
AppId={{8E47F2D6-7E85-4A2E-9A74-000000000001}}
AppName=Dallogix Trace
AppVersion={#TraceInstallVersion}
DefaultDirName=C:\DallogixTrace
UsePreviousAppDir=no
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64
OutputBaseFilename=TraceSetup
Compression=lzma2
SolidCompression=yes

[Files]
Source: "..\..\docker-compose.yml"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\..\interface\*"; DestDir: "{app}\interface"; Flags: recursesubdirs ignoreversion
Source: "..\..\servidor\*"; DestDir: "{app}\servidor"; Flags: recursesubdirs ignoreversion
Source: "..\..\integracoes\*"; DestDir: "{app}\integracoes"; Flags: recursesubdirs ignoreversion
Source: "Dependencies\MicrosoftEdgeWebView2Setup.exe"; DestDir: "{app}\Dependencies"; Flags: ignoreversion
Source: "..\..\docker\*"; DestDir: "{app}\docker"; Flags: recursesubdirs ignoreversion
Source: "..\..\nginx\*"; DestDir: "{app}\nginx"; Flags: recursesubdirs ignoreversion
Source: "..\..\banco-de-dados\*"; DestDir: "{app}\banco-de-dados"; Flags: recursesubdirs ignoreversion
Source: "..\..\scripts\*"; DestDir: "{app}\scripts"; Flags: recursesubdirs ignoreversion
Source: "..\..\.env.example"; DestDir: "{app}"; DestName: ".env.example"; Flags: ignoreversion
Source: "..\..\trace-build-version.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\..\trace-build-commit.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "TraceLauncher.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "TraceDatabaseVolume.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "TraceDatabaseBackup.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "TraceUpdater.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Register-TraceUpdater.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Instalar-TraceLocal.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "TraceAgent.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Build-TraceSetup.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "..\..\desktop\DallogixTrace\bin\Release\net8.0-windows\win-x64\publish\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion
Source: "TraceLauncher.cmd"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Install-TraceMachine.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Start-TraceDocker.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Setup-TraceMachine.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "TraceIndustrialConfiguration.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "Configure-TraceIndustrial.ps1"; DestDir: "{app}\implantacao\windows"; Flags: ignoreversion
Source: "..\..\desktop\DallogixTrace\trace.ico"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{app}\armazenamento"
Name: "{app}\config"
Name: "{app}\logs"

[Icons]
Name: "{commondesktop}\Dallogix Trace"; Filename: "{app}\DallogixTrace.exe"; WorkingDir: "{app}"; IconFilename: "{app}\trace.ico"
Name: "{autoprograms}\Dallogix Trace"; Filename: "{app}\DallogixTrace.exe"; WorkingDir: "{app}"; IconFilename: "{app}\trace.ico"
Name: "{autoprograms}\Configurar CLP e leitor do Dallogix Trace"; Filename: "powershell.exe"; Parameters: "-NoLogo -NoProfile -ExecutionPolicy Bypass -STA -File ""{app}\implantacao\windows\Configure-TraceIndustrial.ps1"" -PackageRoot ""{app}"""; WorkingDir: "{app}"; IconFilename: "{app}\trace.ico"; Comment: "Configura o CLP e o leitor USB HID ou KEYENCE SR-X100W por Ethernet/TCP"

[Code]
function ParseTraceVersion(Value: String; var Packed: Int64): Boolean;
var
  FirstDot, SecondDot, Major, Minor, Patch: Integer;
begin
  Result := False;
  Value := Trim(Value);
  if Length(Value) = 0 then Exit;
  if Value[1] = 'v' then
    Delete(Value, 1, 1);
  FirstDot := Pos('.', Value);
  if FirstDot = 0 then Exit;
  SecondDot := Pos('.', Copy(Value, FirstDot + 1, Length(Value)));
  if SecondDot = 0 then Exit;
  SecondDot := SecondDot + FirstDot;
  Major := StrToIntDef(Copy(Value, 1, FirstDot - 1), -1);
  Minor := StrToIntDef(Copy(Value, FirstDot + 1, SecondDot - FirstDot - 1), -1);
  Patch := StrToIntDef(Copy(Value, SecondDot + 1, Length(Value)), -1);
  if (Major < 0) or (Major > 65535) or
     (Minor < 0) or (Minor > 65535) or
     (Patch < 0) or (Patch > 65535) then Exit;
  Packed := PackVersionComponents(Major, Minor, Patch, 0);
  Result := True;
end;

function InitializeSetup: Boolean;
var
  VersionFile: String;
  LegacyRoot: String;
  LegacyCompose: String;
  Lines: TArrayOfString;
  InstalledVersion, PackageVersion: Int64;
begin
  Result := False;
  LegacyRoot := ExpandConstant('{commonappdata}\DallogixTrace');
  LegacyCompose := AddBackslash(LegacyRoot) + 'docker-compose.yml';
  if FileExists(LegacyCompose) and
     (CompareText('C:\DallogixTrace', LegacyRoot) <> 0) then begin
    MsgBox('Foi encontrada uma instalação anterior em C:\ProgramData\DallogixTrace.' + #13#10#13#10 +
      'Pare e remova essa instalação antiga antes de instalar a versão nova em C:\DallogixTrace.' + #13#10 +
      'Nenhum arquivo antigo será apagado automaticamente.', mbError, MB_OK);
    Exit;
  end;
  VersionFile := ExpandConstant('C:\DallogixTrace\armazenamento\updates\current_version');
  if FileExists(VersionFile) then begin
    if not LoadStringsFromFile(VersionFile, Lines) then begin
      MsgBox('A versão instalada não pôde ser identificada. Corrija o registro da instalação antes de continuar.', mbError, MB_OK);
      Exit;
    end;
    if GetArrayLength(Lines) = 0 then begin
      MsgBox('A versão instalada não pôde ser identificada. Corrija o registro da instalação antes de continuar.', mbError, MB_OK);
      Exit;
    end;
    if not ParseTraceVersion(Lines[0], InstalledVersion) then begin
      MsgBox('A versão instalada não pôde ser identificada. Corrija o registro da instalação antes de continuar.', mbError, MB_OK);
      Exit;
    end;
    if not ParseTraceVersion('v{#TraceInstallVersion}', PackageVersion) then begin
      MsgBox('A versão deste instalador é inválida.', mbError, MB_OK);
      Exit;
    end;
    if ComparePackedVersion(PackageVersion, InstalledVersion) < 0 then begin
      MsgBox('Este instalador é mais antigo que a versão já instalada. Use uma release mais recente.', mbError, MB_OK);
      Exit;
    end;
  end;
  Result := True;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  Parameters: String;
begin
  if CurStep <> ssPostInstall then Exit;
  Parameters := '-NoLogo -NoProfile -ExecutionPolicy Bypass -STA -File "' +
    ExpandConstant('{app}\implantacao\windows\Setup-TraceMachine.ps1') +
    '" -PackageRoot "' + ExpandConstant('{app}') + '"';
  if not Exec('powershell.exe', Parameters, ExpandConstant('{app}'),
    SW_SHOWNORMAL, ewWaitUntilTerminated, ResultCode) then
    RaiseException('Não foi possível iniciar a configuração do Trace: ' + SysErrorMessage(ResultCode));
  if ResultCode <> 0 then
    RaiseException('A configuração do Trace falhou. Código: ' + IntToStr(ResultCode) + '. Veja o erro exibido na janela de configuração.');
  if not RegWriteStringValue(HKLM, 'Software\Microsoft\Windows\CurrentVersion\Run',
    'Dallogix Trace', '"' + ExpandConstant('{app}\DallogixTrace.exe') + '"') then
    RaiseException('A configuração foi concluída, mas não foi possível registrar o início automático do Trace.');
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usPostUninstall then
    RegDeleteValue(HKLM, 'Software\Microsoft\Windows\CurrentVersion\Run', 'Dallogix Trace');
end;
