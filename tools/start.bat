@echo off
REM =============================================================================
REM  NAI Studio - One-click start (download-and-run portable)
REM
REM  Behavior:
REM    1. Use runtime\php\php.exe (no PHP install, no PATH)
REM    2. First run: copy data template to user-data
REM    3. Kill old process on 8080
REM    4. Launch PHP server via wscript vbs wrapper
REM    5. Wait for port (up to 8s)
REM    6. Write PID file
REM    7. Open browser
REM
REM  Stop:
REM    Double-click stop.bat / or Settings page >> Stop Service
REM
REM  Port: 8080
REM =============================================================================

chcp 65001 > nul
setlocal EnableExtensions EnableDelayedExpansion
title NAI Studio - Launcher
cd /d "%~dp0\.."

set PORT=8080
set URL=http://127.0.0.1:%PORT%/nai-studio/
set ROOT=%CD%
set RUNTIME=%ROOT%\runtime
set PHP_EXE=%RUNTIME%\php\php.exe
set PHP_INI=%RUNTIME%\php\php.ini
set USERDATA=%ROOT%\user-data
set DB=%USERDATA%\nai-studio.db
set LOG=%USERDATA%\logs\php-server.log
set PID=%TEMP%\nai_studio_php_server.pid
set VBS=%TEMP%\nai_start_php_server.vbs
set FIRST_RUN=0

echo.
echo ============================================================
echo   NAI Studio  v1.1.4  -  Launcher
echo   PHP server + SQLite (port %PORT%)
echo   Zero deps: bundled runtime\php\php.exe
echo ============================================================
echo.

REM --- 1. Check runtime\php\php.exe ---
if not exist "%PHP_EXE%" goto err_no_php
echo PHP: %PHP_EXE%
for /f "tokens=*" %%v in ('"%PHP_EXE%" -v 2^>nul') do (echo       %%v)

REM --- 2. First run: init user-data from data template ---
if exist "%USERDATA%" goto step2_skip
set FIRST_RUN=1
echo.
echo [1/5] First run: initializing user-data ...
if not exist "%ROOT%\data" goto err_no_data
xcopy /E /I /Y /Q "%ROOT%\data" "%USERDATA%\data-tpl" > nul
mkdir "%USERDATA%\data" 2> nul
mkdir "%USERDATA%\storage" 2> nul
mkdir "%USERDATA%\logs" 2> nul
if exist "%USERDATA%\data-tpl\nai-studio.db" copy /Y "%USERDATA%\data-tpl\nai-studio.db" "%DB%" > nul
echo       user-data initialized. Your data (API key, prompts, history) lives here.
goto step2_done
:step2_skip
echo [1/5] user-data exists, skipping init
:step2_done

REM --- 3. DB check + auto-restore from template ---
if exist "%DB%" goto step3_ok
if exist "%USERDATA%\data-tpl\nai-studio.db" goto step3_restore
echo [ERROR] SQLite DB missing: %DB%
echo        Re-extract project or run migration.
goto err_pause
:step3_restore
copy /Y "%USERDATA%\data-tpl\nai-studio.db" "%DB%" > nul
echo [2/5] Restored DB from template
goto step3_done
:step3_ok
echo [2/5] SQLite DB ready
:step3_done

REM --- 4. Kill old processes on port ---
echo [3/5] Cleaning port %PORT% ...
set /a killed=0
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo       killing PID=%%a
    taskkill /F /PID %%a > nul 2>&1
    if !errorlevel! equ 0 set /a killed+=1
)
if exist "%PID%" del "%PID%" > nul 2>&1
if %killed% gtr 0 echo       killed %killed% process(es)

REM --- 5. Launch PHP server via vbs wrapper ---
echo [4/5] Launching PHP server (background) ...
>  "%VBS%" echo Set WshShell = CreateObject("WScript.Shell")
>> "%VBS%" echo WshShell.Run "cmd /c %PHP_EXE% -c %PHP_INI% -S 127.0.0.1:%PORT% -t %ROOT%\public %ROOT%\public\router.php > %LOG% 2>&1", 0, False
start "" /min wscript "%VBS%"
echo       launcher: %VBS%
echo       log:      %LOG%

REM --- 6. Wait for port (LISTENING) ---
echo [5/5] Waiting for service ...
set /a attempt=0
:wait_loop
set /a attempt+=1
netstat -aon | findstr ":%PORT% " | findstr "LISTENING" > nul 2>&1
if %errorlevel% equ 0 goto wait_done
if %attempt% geq 16 goto err_timeout
ping -n 1 -w 500 127.0.0.1 > nul 2>&1
goto wait_loop
:wait_done
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":%PORT% " ^| findstr "LISTENING"') do (
    echo %%a > "%PID%"
    echo       PHP server PID=%%a
)

REM Port LISTENING does not mean HTTP works yet - give PHP 1s to warm up
ping -n 1 -w 1000 127.0.0.1 > nul 2>&1

echo.
echo ============================================================
echo   Service ready
echo   URL:      %URL%
echo   PHP:      runtime\php\php.exe
echo   DB:       %DB%
echo   Log:      %LOG%
echo   PID file: %PID%
echo.
if %FIRST_RUN%==1 goto first_run_msg
echo   Closing this window does NOT stop the server.
echo   Use stop.bat / or nai-studio Settings >> Stop Service
goto status_done
:first_run_msg
echo   [First run]
echo   1. Browser will open automatically
echo   2. Settings >> API Key >> enter your NovelAI Token
echo   3. Start generating!
:status_done
echo ============================================================
echo.

REM --- 7. Open browser (multi-method fallback) ---
powershell -NoProfile -Command "Start-Process '%URL%'" >nul 2>&1
if %errorlevel% neq 0 explorer "%URL%" >nul 2>&1
echo       Opened: %URL%
echo.
echo ============================================================
echo   Service is running in background.
echo   You can close this window safely.
echo   Stop with stop.bat, or Settings page ^>^> Stop Service.
echo ============================================================
echo.
pause
exit /b 0

REM ============== error handlers ==============
:err_no_php
echo [ERROR] runtime\php\php.exe missing!
echo         Make sure runtime/ folder is intact.
echo         Or download PHP 8.2 NTS from https://windows.php.net to runtime\php
pause
exit /b 1

:err_no_data
echo [ERROR] data directory missing - project incomplete?
pause
exit /b 1

:err_timeout
echo.
echo [WARN] startup timeout. Check log: %LOG%
echo.
pause
exit /b 1

:err_pause
pause
exit /b 1
