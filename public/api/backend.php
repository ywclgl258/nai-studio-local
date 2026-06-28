<?php
/**
 * NAI Studio - 后端服务控制
 * GET ?action=status  → 返回 apache / mysql 运行状态
 * POST action=start  → 启动 Apache + MySQL
 * POST action=stop   → 停止 Apache + MySQL
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Logger;

header('Content-Type: application/json; charset=utf-8');
header('X-Backend-Action: ' . ($_GET['action'] ?? $_POST['action'] ?? 'status'));

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$logger = null; // Logger::init() is called automatically on first log()

$xampp = 'C:\\xampp';
$apacheExe = $xampp . '\\apache\\bin\\httpd.exe';
$mysqlExe  = $xampp . '\\mysql\\bin\\mysqld.exe';
$mysqlIni  = $xampp . '\\mysql\\bin\\my.ini';

function checkPort($port) {
    // Win-compatible: use fsockopen
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($conn) { fclose($conn); return true; }
    return false;
}

function checkProcess($name) {
    // Use tasklist to see if process exists
    $output = [];
    exec('tasklist /FI "IMAGENAME eq ' . escapeshellarg($name) . '"', $output);
    foreach ($output as $line) {
        if (stripos($line, $name) !== false && stripos($line, 'No tasks') === false) {
            return true;
        }
    }
    return false;
}

function getStatus() {
    return [
        'apache' => checkPort(80),
        'mysql'  => checkPort(3306),
        'httpd_process'  => checkProcess('httpd.exe'),
        'mysqld_process' => checkProcess('mysqld.exe'),
        'site_url' => 'http://localhost/nai-studio/',
        'timestamp' => date('c'),
    ];
}

if ($action === 'status') {
    echo json_encode(['ok' => true] + getStatus());
    exit;
}

if ($action === 'start') {
    $log = [];
    $apacheOk = checkProcess('httpd.exe');
    $mysqlOk  = checkProcess('mysqld.exe');

    if (!$mysqlOk && file_exists($mysqlExe)) {
        // Start MySQL: use a .vbs wrapper to detach the process from PHP's lifetime
        $vbs = sys_get_temp_dir() . '\\nai_start_mysql.vbs';
        file_put_contents($vbs, "Set WshShell = CreateObject(\"WScript.Shell\")\r\nWshShell.Run \"\"\"$mysqlExe\"\" --defaults-file=\"\"$mysqlIni\"\" --standalone\", 0, False\r\n");
        pclose(popen('cmd /c start /min "" wscript "' . $vbs . '"', 'r'));
        $log[] = 'MySQL 启动请求已发送';
    } else if ($mysqlOk) {
        $log[] = 'MySQL 已在运行';
    } else {
        $log[] = 'MySQL 可执行文件不存在: ' . $mysqlExe;
    }

    if (!$apacheOk && file_exists($apacheExe)) {
        // Start Apache: use a .vbs wrapper for proper detachment
        $vbs = sys_get_temp_dir() . '\\nai_start_apache.vbs';
        file_put_contents($vbs, "Set WshShell = CreateObject(\"WScript.Shell\")\r\nWshShell.Run \"\"\"$apacheExe\"\"\", 0, False\r\n");
        pclose(popen('cmd /c start /min "" wscript "' . $vbs . '"', 'r'));
        $log[] = 'Apache 启动请求已发送';
    } else if ($apacheOk) {
        $log[] = 'Apache 已在运行';
    } else {
        $log[] = 'Apache 可执行文件不存在: ' . $apacheExe;
    }

    // Wait up to 10 seconds for ports to be ready
    $ready = false;
    for ($i = 0; $i < 10; $i++) {
        sleep(1);
        if (checkPort(80) && checkPort(3306)) {
            $ready = true;
            break;
        }
    }

    Logger::info('backend.start', $log);
    echo json_encode([
        'ok' => true,
        'log' => $log,
        'ready' => $ready,
    ] + getStatus());
    exit;
}

if ($action === 'stop') {
    $log = [];
    if (checkProcess('httpd.exe')) {
        exec('taskkill /IM httpd.exe /F 2>&1', $out, $rc);
        $log[] = 'Apache 已停止 (rc=' . $rc . ')';
    } else {
        $log[] = 'Apache 未运行';
    }
    if (checkProcess('mysqld.exe')) {
        exec('taskkill /IM mysqld.exe /F 2>&1', $out, $rc);
        $log[] = 'MySQL 已停止 (rc=' . $rc . ')';
    } else {
        $log[] = 'MySQL 未运行';
    }

    Logger::info('backend.stop', $log);
    echo json_encode(['ok' => true, 'log' => $log] + getStatus());
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action]);