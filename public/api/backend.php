<?php
/**
 * NAI Studio - 后端服务控制（PHP 内置服务器 + SQLite）
 *
 * GET  ?action=status  → 返回 server / db 状态
 * POST action=start   → 启动 PHP 内置服务器（8080）
 * POST action=stop    → 停止 PHP 内置服务器（杀 8080 端口）
 *
 * 之前这个文件假设用 XAMPP（Apache 80 + MySQL 3306），但 nai-studio 实际是
 * PHP 内置 server 8080 + SQLite 单文件，不需要任何外部服务。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Logger;

header('Content-Type: application/json; charset=utf-8');
header('X-Backend-Action: ' . ($_GET['action'] ?? $_POST['action'] ?? 'status'));

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

// 端口和路径配置
$port     = 8080;  // NAI Studio 固定端口（要改就改这里 + router.php）
$root     = realpath(__DIR__ . '/../..');
$phpExe   = trim((string)shell_exec('where php 2>nul')) ?: 'php';
$dbFile   = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'nai-studio.db';
$logFile  = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-server.log';
$pidFile  = sys_get_temp_dir() . '\\nai_studio_php_server.pid';
$startVbs = sys_get_temp_dir() . '\\nai_start_php_server.vbs';
$siteUrl  = 'http://127.0.0.1:' . $port . '/nai-studio/';

function checkPort(int $port): bool {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($conn) { fclose($conn); return true; }
    return false;
}

function killPort(int $port): array {
    $log = [];
    // netstat 输出格式：TCP    0.0.0.0:8080    0.0.0.0:0    LISTENING    1234
    $out = shell_exec('netstat -aon 2>nul | findstr ":' . $port . ' " | findstr "LISTENING"');
    if (!$out) { $log[] = "端口 {$port} 未被占用"; return $log; }
    $pids = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/LISTENING\s+(\d+)/', $line, $m)) $pids[] = (int)$m[1];
    }
    $pids = array_unique($pids);
    foreach ($pids as $pid) {
        if ($pid <= 0) continue;
        $rc = 0;
        exec("taskkill /F /PID $pid 2>&1", $dummy, $rc);
        $log[] = "PID {$pid} " . ($rc === 0 ? '已停止' : '停止失败 (rc=' . $rc . ')');
    }
    return $log;
}

function readPidFile(string $path): ?int {
    if (!file_exists($path)) return null;
    $pid = (int)trim((string)@file_get_contents($path));
    return $pid > 0 ? $pid : null;
}

function isProcessAlive(int $pid): bool {
    if ($pid <= 0) return false;
    $out = shell_exec("tasklist /FI \"PID eq $pid\" 2>nul");
    return $out && stripos($out, (string)$pid) !== false;
}

function getStatus(int $port, string $dbFile, string $pidFile, string $logFile): array {
    $portUp   = checkPort($port);
    $dbExists = file_exists($dbFile);
    $dbSize   = $dbExists ? filesize($dbFile) : 0;
    $pid      = readPidFile($pidFile);
    $procUp   = $pid ? isProcessAlive($pid) : false;
    return [
        'port'        => $port,
        'server'      => $portUp,                   // 主开关：端口可达 = 跑起来了
        'pid'         => $pid,
        'process'     => $procUp,                   // PID 文件记录的进程是否还在
        'db_exists'   => $dbExists,
        'db_size_kb'  => $dbSize ? (int)round($dbSize / 1024) : 0,
        'db_ok'       => $dbExists && $dbSize > 0,  // DB 文件存在且非空
        'site_url'    => $portUp ? ('http://127.0.0.1:' . $port . '/nai-studio/') : null,
        'log_file'    => file_exists($logFile) ? $logFile : null,
        'timestamp'   => date('c'),
    ];
}

if ($action === 'status') {
    echo json_encode(['ok' => true] + getStatus($port, $dbFile, $pidFile, $logFile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'start') {
    $log = [];
    $st  = getStatus($port, $dbFile, $pidFile, $logFile);

    if ($st['server']) {
        $log[] = "PHP 内置服务器已在运行（端口 {$port}）";
    } else {
        if (!file_exists($dbFile)) {
            $log[] = '⚠ SQLite 数据库不存在：' . $dbFile;
            $log[] = '请先运行迁移：php tools/migrate_mysql_to_sqlite.php';
            echo json_encode(['ok' => false, 'error' => 'db_missing', 'log' => $log] + $st, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 确保日志目录存在
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);

        // 用 wscript vbs 包裹启动（脱离 PHP 进程生命周期）
        $vbsContent = "Set WshShell = CreateObject(\"WScript.Shell\")\r\n"
            . "WshShell.Run \"\"\"$phpExe\"\" -S 127.0.0.1:$port -t \"\"" . str_replace('/', '\\', $root . '/public') . "\" \"\"" . str_replace('/', '\\', $root . '/public/index.php') . "\" > \"\"" . str_replace('/', '\\', $logFile) . "\" 2>&1\", 0, False\r\n";
        file_put_contents($startVbs, $vbsContent);

        pclose(popen('cmd /c start /min "" wscript "' . $startVbs . '"', 'r'));
        $log[] = "PHP 内置服务器启动请求已发送（端口 {$port}）";
        $log[] = "日志：" . $logFile;
    }

    // 等端口就绪（最多 8 秒）
    $ready = false;
    for ($i = 0; $i < 8; $i++) {
        usleep(500_000);  // 500ms
        if (checkPort($port)) { $ready = true; break; }
    }
    if ($ready) {
        $log[] = "✓ 端口 {$port} 已就绪";
        // 写 PID 文件
        $out = shell_exec("netstat -aon 2>nul | findstr \":{$port} \" | findstr \"LISTENING\"");
        if ($out && preg_match('/LISTENING\s+(\d+)/', $out, $m)) {
            @file_put_contents($pidFile, $m[1]);
            $log[] = "PHP server PID = " . $m[1];
        }
    } else {
        $log[] = "⚠ 端口 {$port} 启动超时（8 秒），请查看日志";
    }

    Logger::info('backend.start', $log);
    echo json_encode(['ok' => true, 'ready' => $ready, 'log' => $log] + getStatus($port, $dbFile, $pidFile, $logFile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'stop') {
    // 自杀防护：不能在 8080 端口调用 stop，会把当前 PHP server 进程杀掉
    // 实际想停服务请用 stop.bat 或 taskkill
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === $port) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'cannot_stop_self',
            'message' => "不能在 8080 端口调用 stop（自杀风险）。请用 tools/stop.bat 或 taskkill /F /FI \"WINDOWTITLE eq *nai-studio*\"" . '。',
            'log' => ['拒绝执行：调用来自 PHP server 自己所在的端口'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $log = killPort($port);
    @unlink($pidFile);
    Logger::info('backend.stop', $log);
    echo json_encode(['ok' => true, 'log' => $log] + getStatus($port, $dbFile, $pidFile, $logFile), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action], JSON_UNESCAPED_UNICODE);