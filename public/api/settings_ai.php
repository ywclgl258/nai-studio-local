<?php
/**
 * /api/settings_ai.php - 通用 AI Provider 配置 API
 * GET    → 返回 presets + 当前 config
 * POST   → 保存 config
 * GET ?action=test  → 测试连接
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\AiProvider;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

if ($method === 'GET' && $action === 'test') {
    ok_response(AiProvider::test());
    exit;
}

if ($method === 'GET') {
    ok_response([
        'presets' => AiProvider::presets(),
        'config'  => AiProvider::config(),
    ]);
    exit;
}

if ($method === 'POST') {
    $b = read_json_body();
    $patch = [];
    if (isset($b['provider']))   $patch['provider']   = trim((string)$b['provider']);
    if (isset($b['base_url']))   $patch['base_url']   = trim((string)$b['base_url']);
    if (isset($b['api_key']))    $patch['api_key']    = (string)$b['api_key'];  // 不过滤空，Ollama 不要
    if (isset($b['model']))      $patch['model']      = trim((string)$b['model']);
    if (isset($b['reasoning_effort'])) $patch['reasoning_effort'] = in_array($b['reasoning_effort'], ['low', 'medium', 'high']) ? $b['reasoning_effort'] : null;
    if (isset($b['enabled']))    $patch['enabled']    = !empty($b['enabled']);
    AiProvider::saveConfig($patch);
    ok_response(['config' => AiProvider::config()]);
    exit;
}

error_response('Method not allowed', 405);
