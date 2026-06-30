@echo off
REM =============================================================================
REM  NAI Studio - one-click start (background PHP built-in server)
REM
REM  Run this to start.  Server runs in background (detached from this window).
REM  To stop: run stop.bat, or nai-studio settings page -> Stop.
REM =============================================================================
chcp 65001 > nul
setlocal
title NAI Studio - Launcher
cd /d "%~dp0\.."

set PORT=8080
set URL=http://127.0.0.1:%PORT%/nai-studio/
set ROOT=%CD%
set DB=%ROOT%\data\nai-studio.db
set LOG=%ROOT%\storage\logs\php-server.log
set PID=%TEMP%\nai_studio_php_server.pid
set VBS=%TEMP%\nai_start_php_server.vbs

echo.
echo ============================================================
echo   NAI Studio Launcher
echo   PHP built-in server + SQLite (no XAMPP required)
echo ============================================================
echo.

REM --- find PHP (PATH or common locations) ---
set PHP_EXE=
for /f "delims=" %%p in ('where php 2^>nul') do (
    if not defined PHP_EXE set "PHP_EXE=%%p"
)
if not defined PHP_EXE (
    if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
    if exist "C:\php\php.exe"        set "PHP_EXE=C:\php\php.exe"
    if exist "C:\Program Files\php\php.exe" set "PHP_EXE=C:\Program Files\php\php.exe"
)
if not defined PHP_EXE (
    echo [ERROR] PHP not found. Install PHP 8.0+ or add to PATH.
    echo Try:    C:\xampp\php\php.exe
    echo Download: https://windows.php.net/download/
    pause
    exit /b 1
)
echo PHP: %PHP_EXE%

REM --- check DB ---
if not exist "%DB%" (
    echo [ERROR] SQLite DB not found: %DB%
    echo Run first: php tools\migrate_mysql_to_sqlite.php
    pause
    exit /b 1
)

REM --- ensure log dir ---
if not exist "%ROOT%\storage\logs" mkdir "%ROOT%\storage\logs"

REM --- kill old processes on PORT ---
echo [1/4] Cleaning up old processes on port %PORT%...
set /a killed=0
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo       killing PID=%%a
    taskkill /F /PID %%a > nul 2>&1
    if !errorlevel! equ 0 set /a killed+=1
)
if exist "%PID%" del "%PID%" > nul 2>&1
if %killed% gtr 0 echo       killed %killed% process(es)

REM --- write vbs launcher (uses php_server.cmd wrapper) ---
echo [2/4] Writing launcher...
>  "%VBS%" echo Set WshShell = CreateObject("WScript.Shell")
>> "%VBS%" echo WshShell.Run "cmd /c """"%ROOT%\tools\php_server.cmd""""", 0, False
echo       launcher: %VBS%
echo       php:     %PHP_EXE%

REM --- trigger start ---
echo [3/4] Starting PHP server on port %PORT%...
start "" /min wscript "%VBS%"

REM --- wait for port ---
echo [4/4] Waiting for service ready...
set /a attempt=0
:wait_loop
set /a attempt+=1
netstat -aon | findstr ":%PORT% " | findstr "LISTENING" > nul 2>&1
if %errorlevel% equ 0 goto ready
if %attempt% geq 16 (
    echo.
    echo [WARN] Startup timeout. Check log:
    echo        %LOG%
    echo.
    pause
    exit /b 1
)
ping -n 2 -w 500 127.0.0.1 > nul 2>&1
goto wait_loop

:ready
REM write PID file
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo %%a > "%PID%"
    echo       PHP server PID=%%a
)

echo.
echo ============================================================
echo   Service is up
echo   URL:      %URL%
echo   Log:      %LOG%
echo   PID file: %PID%
echo.
echo   Closing this window will NOT stop the server.
echo   To stop: run stop.bat / or use nai-studio settings page.
echo ============================================================
echo.

REM auto-open browser
start "" "%URL%"

REM keep window 3s for visibility
timeout /t 3 /nobreak > nul
exit /b 0