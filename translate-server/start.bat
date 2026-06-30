@echo off
chcp 65001 > nul
setlocal

REM =============================================================================
REM  NAI Studio - Start local translate server
REM   纯 Node.js + OPUS-MT en->zh, 端口 5555
REM   首次启动会下载模型 ~300MB, 之后秒起
REM
REM  v1.1.4+ 自动检测 NAI Studio 配的代理（settings.proxy_url）
REM  如果没配代理，假设你在国内需要代理才能上 HuggingFace，请把
REM  HTTPS_PROXY 写在下面这一行：
REM =============================================================================

set PORT=5555
set URL=http://127.0.0.1:%PORT%

REM --- 读取 NAI Studio 配的代理（如果没装会失败，跳过） ---
for /f "tokens=*" %%P in ('powershell -NoProfile -Command "try { \$db = New-Object System.Data.SQLite.SQLiteConnection('Data Source=D:\anima\nai-studio\data\nai-studio.db'); \$db.Open(); \$cmd = \$db.CreateCommand(); \$cmd.CommandText = 'SELECT proxy_url FROM settings WHERE id=1 AND proxy_enabled=1'; \$r = \$cmd.ExecuteScalar(); if (\$r) { Write-Output \$r } } catch { }" 2^>nul') do set NAISTUDIO_PROXY=%%P
if defined NAISTUDIO_PROXY if not "%NAISTUDIO_PROXY%"=="" (
    echo       检测到 NAI Studio 代理: %NAISTUDIO_PROXY%
    set HTTPS_PROXY=%NAISTUDIO_PROXY%
    set HTTP_PROXY=%NAISTUDIO_PROXY%
)

echo.
echo ============================================================
echo   NAI Studio - 本地翻译服务 (OPUS-MT en -^> zh)
echo   %URL%
echo ============================================================
echo.

REM --- Check Node.js ---
where node > nul 2>&1
if %errorlevel% neq 0 (
    echo [错误] 未检测到 Node.js
    echo        请先安装 Node.js ^(https://nodejs.org/^), 需要 v18 或更高
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('node -v') do echo Node 版本: %%v

REM --- Check if already running ---
netstat -ano | findstr ":%PORT% " | findstr LISTENING > nul 2>&1
if %errorlevel% equ 0 (
    echo [跳过] 端口 %PORT% 已被占用，翻译服务应该已在运行
    echo        直接打开 %URL% 验证
    timeout /t 3 /nobreak > nul
    start "" "%URL%"
    exit /b 0
)

REM --- npm install on first run ---
if not exist "node_modules" (
    echo [1/3] 首次运行，安装依赖 ^(约 200MB^)...
    call npm install --no-audit --no-fund --loglevel=error
    if %errorlevel% neq 0 (
        echo [错误] npm install 失败
        echo        可能是网络问题，请检查后重试
        pause
        exit /b 1
    )
) else (
    echo [1/3] 依赖已安装，跳过
)

REM --- Start server ---
echo.
echo [2/3] 启动翻译服务 ...
echo       (首次会下载模型 ~300MB, 请耐心等待)
echo.

REM 用 start 在新窗口里跑, 不阻塞当前窗口
REM 把 HTTPS_PROXY/HTTP_PROXY 也传给子进程（node fetch 需要）
if defined NAISTUDIO_PROXY if not "%NAISTUDIO_PROXY%"=="" (
    start "NAI Translate" /min cmd /c "set HTTPS_PROXY=%NAISTUDIO_PROXY%&& set HTTP_PROXY=%NAISTUDIO_PROXY%&& node server.js"
) else (
    start "NAI Translate" /min cmd /c "node server.js"
)

REM --- Wait for server ready ---
echo [3/3] 等待服务就绪 ...
set /a attempts=0
:wait_loop
set /a attempts+=1
powershell -Command "$c = Test-NetConnection -ComputerName 127.0.0.1 -Port %PORT% -InformationLevel Quiet -WarningAction SilentlyContinue; if ($c) { exit 0 } else { exit 1 }" > nul 2>&1
if %errorlevel% equ 0 goto ready
if %attempts% geq 30 (
    echo [警告] 等待超时（30秒），但服务可能还在加载模型
    echo        请稍候 1-2 分钟后访问 %URL%
    goto open_browser
)
timeout /t 1 /nobreak > nul
goto wait_loop

:ready
echo       OK
echo.
echo   服务已就绪: %URL%
echo.
echo   NAI Studio 拆解器设置:
echo     1. 打开 nai-studio 设置
echo     2. 网络 -^> 本地翻译 -^> 启用
echo     3. URL 填: %URL%
echo     4. 点"测试连接"
echo.
echo   关闭此窗口不会停止服务，停止请用 stop.bat
echo.
:open_browser
start "" "%URL%"
timeout /t 3 /nobreak > nul
