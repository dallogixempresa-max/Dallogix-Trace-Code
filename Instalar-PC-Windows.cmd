@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0implantacao\windows\Download-TraceSetup.ps1"
set "TRACE_SETUP_EXIT_CODE=%ERRORLEVEL%"
if not "%TRACE_SETUP_EXIT_CODE%"=="0" (
    echo.
    echo A instalacao nao foi iniciada. Leia o erro acima e tente novamente.
    pause
)
exit /b %TRACE_SETUP_EXIT_CODE%
