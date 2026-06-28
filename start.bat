@echo off
chcp 65001 > nul
setlocal

REM =============================================================================
REM NAI Studio - Start Apache + MySQL and open the local site
REM =============================================================================

set XAMPP=C:\xampp
set SITE_URL=http://localhost/nai-studio/

echo.
echo ============================================================
echo   NAI Studio - 本地生图工作台
echo   %SITE_URL%
echo ============================================================
echo.

REM --- Check if XAMPP exists ---
if not exist "%XAMPP%\xampp_start.exe" (
    echo [错误] 未找到 XAMPP 安装在 %XAMPP%
    echo        请先安装 XAMPP ^(Apache ^+ MySQL ^+ PHP ^+ Perl^)
    pause
    exit /b 1
)

REM --- Start MySQL ---
echo [1/3] 启动 MySQL ...
start "NAI MySQL" /min "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
timeout /t 2 /nobreak > nul

REM --- Start Apache ---
echo [2/3] 启动 Apache ...
start "NAI Apache" /min "%XAMPP%\apache\bin\httpd.exe"
timeout /t 2 /nobreak > nul

REM --- Wait for services to be ready ---
echo [3/3] 等待服务就绪 ...
set /a attempts=0
:wait_loop
set /a attempts+=1
powershell -Command "$c = Test-NetConnection -ComputerName 127.0.0.1 -Port 80 -InformationLevel Quiet -WarningAction SilentlyContinue; if ($c) { exit 0 } else { exit 1 }" > nul 2>&1
if %errorlevel% equ 0 goto ready
if %attempts% geq 15 (
    echo [警告] Apache 启动超时，但仍在尝试打开浏览器 ...
    goto open_browser
)
timeout /t 1 /nobreak > nul
goto wait_loop

:ready
echo 服务就绪。

:open_browser
start "" "%SITE_URL%"
echo.
echo 网站已打开。关闭此窗口不会停止服务。
echo 停止服务请运行 stop.bat
echo.
timeout /t 3 /nobreak > nul
