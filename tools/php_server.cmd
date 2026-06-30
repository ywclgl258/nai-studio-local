@echo off
REM ============================================================
REM  NAI Studio - PHP server actual start (invoked by start.bat via vbs)
REM
REM  Mid-layer exists because wscript.Run's command-line escaping
REM  is hostile to paths with spaces.  .cmd middleman avoids that.
REM ============================================================

setlocal
set PORT=8080
set ROOT=%~dp0..

REM locate PHP
set PHP_EXE=
for /f "delims=" %%p in ('where php 2^>nul') do (
    if not defined PHP_EXE set "PHP_EXE=%%p"
)
if not defined PHP_EXE (
    if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
    if exist "C:\php\php.exe"        set "PHP_EXE=C:\php\php.exe"
    if exist "C:\Program Files\php\php.exe" set "PHP_EXE=C:\Program Files\php\php.exe"
)

REM log dir
if not exist "%ROOT%\storage\logs" mkdir "%ROOT%\storage\logs"

REM run PHP server in foreground (wscript already detached from parent)
"%PHP_EXE%" -S 127.0.0.1:%PORT% -t "%ROOT%\public" "%ROOT%\public\router.php" > "%ROOT%\storage\logs\php-server.log" 2>&1