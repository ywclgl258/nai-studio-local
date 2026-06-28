<?php
/**
 * /api/proxy.php  — 代理设置 + 测试
 * GET  ?action=status  → 当前代理配置 + 最近一次测试结果
 * POST ?action=test    → 测试代理是否能通 NAI
 * POST ?action=clear   → 关闭代理
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\Settings;

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

if ($action === 'status') {
    $row = Db::fetchOne("SELECT proxy_enabled, proxy_url, proxy_test_status, proxy_tested_at FROM settings WHERE id = 1");
    ok_response([
        'enabled'    => (bool)($row['proxy_enabled'] ?? 0),
        'url'        => $row['proxy_url'] ?? '',
        'test_status' => $row['proxy_test_status'] ?? null,
        'tested_at'  => $row['proxy_tested_at'] ?? null,
    ]);
    exit;
}

if ($action === 'test') {
    $r = Settings::testProxy();
    ok_response($r, $r['ok'] ? 200 : 502);
    exit;
}

if ($action === 'clear') {
    Db::update('settings', 1, [
        'proxy_enabled' => 0,
        'proxy_url' => null,
        'proxy_test_status' => null,
        'proxy_tested_at' => null,
    ]);
    ok_response(['ok' => true, 'message' => '代理已关闭']);
    exit;
}

error_response('Unknown action', 400);