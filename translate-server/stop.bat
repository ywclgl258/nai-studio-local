@echo off
chcp 65001 > nul
setlocal

REM =============================================================================
REM NAI Studio - Stop local translate server
REM =============================================================================

set PORT=5555

echo.
echo 正在停止翻译服务 (端口 %PORT%) ...

REM 找占用端口的 PID 并杀掉
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":%PORT% " ^| findstr LISTENING') do (
    taskkill /PID %%a /F > nul 2>&1
    if %errorlevel% equ 0 (
        echo       PID %%a 已停止
    )
)

echo.
echo 翻译服务已停止。
timeout /t 2 /nobreak > nul
