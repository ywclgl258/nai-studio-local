@echo off
chcp 65001 > nul
setlocal

REM =============================================================================
REM NAI Studio - Stop Apache + MySQL
REM =============================================================================

set XAMPP=C:\xampp

echo.
echo [1/2] 停止 Apache ...
taskkill /IM httpd.exe /F > nul 2>&1
if %errorlevel% equ 0 (echo       OK) else (echo       Apache 未运行)

echo [2/2] 停止 MySQL ...
taskkill /IM mysqld.exe /F > nul 2>&1
if %errorlevel% equ 0 (echo       OK) else (echo       MySQL 未运行)

echo.
echo 服务已停止。
timeout /t 2 /nobreak > nul
