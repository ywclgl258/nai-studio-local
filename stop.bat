@echo off
REM =============================================================================
REM  NAI Studio - One-click stop
REM =============================================================================
chcp 65001 > nul
setlocal
title NAI Studio - Stopper

set PORT=8080
set PID=%TEMP%\nai_studio_php_server.pid

echo.
echo Stopping NAI Studio (port %PORT%) ...

set /a killed=0
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo   killing PID=%%a ...
    taskkill /F /PID %%a > nul 2>&1
    if !errorlevel! equ 0 set /a killed+=1
)
if exist "%PID%" del "%PID%" > nul 2>&1

if %killed% gtr 0 (
    echo.
    echo Stopped %killed% process(es).
) else (
    echo Port %PORT% not in use (server not running).
)
echo.
timeout /t 2 /nobreak > nul
exit /b 0