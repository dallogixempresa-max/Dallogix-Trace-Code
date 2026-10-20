@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0implantacao\windows\Instalar-TraceLocal.ps1"
if errorlevel 1 exit /b %errorlevel%
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; New-Item -ItemType Directory -Force -Path '.\armazenamento\updates\master-homologacao' | Out-Null; Copy-Item -Path '.\implantacao\windows\auto-master-homologacao\*' -Destination '.\armazenamento\updates\master-homologacao' -Force; Copy-Item -LiteralPath '.\scripts\sync_github.sh' -Destination '.\armazenamento\updates\master-homologacao\sync_github.sh' -Force"
if errorlevel 1 exit /b %errorlevel%
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0armazenamento\updates\master-homologacao\Configurar.ps1"
exit /b %errorlevel%
