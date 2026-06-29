<?php
/**
 * /api/ai_analyze.php - DeepSeek AI 分析 API
 *
 * POST {prompt} or {pairs:[...], artist_advice:{...}}
 *   → DeepSeek 深度分析，返回结构化建议
 *
 * POST {action: translate, texts: ['long_hair', 'blue_eyes']}
 *   → 批量 AI 翻译
 *
 * POST {action: expand, description: "girl with sword in forest"}
 *   → AI 扩写 prompt
 *
 * POST {action: test}  → 测试 DeepSeek 连接
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\AiAdvisor;
use NaiStudio\AiProvider;
use NaiStudio\TagClassifier;
use NaiStudio\ArtistAdvisor;
use NaiStudio\Translator;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'analyze';
$b = $method === 'POST' ? read_json_body() : [];

if (!AiProvider::isEnabled() && $action !== 'test') {
    // 没配 DeepSeek key - 让前端走 demo 模式（启发式 mock），不报错
    if ($action === 'analyze' || $action === 'translate' || $action === 'expand') {
        // 继续，但 analyze/translate/expand 内部会走 mock
    } else {
        error_response('未知 action', 400);
    }
}

if ($action === 'test') {
    ok_response(AiProvider::test());
    exit;
}

if ($action === 'translate') {
    $texts = (array)($b['texts'] ?? []);
    if (empty($texts)) error_response('texts array required', 400);
    if (!AiProvider::isEnabled()) {
        ok_response(['results' => [], 'count' => 0, 'mock' => true, 'msg' => '未配 DeepSeek，AI 翻译不可用']);
        exit;
    }
    $results = AiAdvisor::translateBatch($texts);
    ok_response(['results' => $results, 'count' => count($results)]);
    exit;
}

if ($action === 'expand') {
    $desc = trim((string)($b['description'] ?? ''));
    if ($desc === '') error_response('description required', 400);
    if (!AiProvider::isEnabled()) {
        ok_response(['prompt' => '', 'mock' => true, 'msg' => '未配 DeepSeek，AI 扩写不可用']);
        exit;
    }
    $text = AiAdvisor::expandPrompt($desc);
    ok_response(['prompt' => $text]);
    exit;
}

if ($action === 'compose') {
    // AI 写提示词 — 互动式多轮对话（按目标 NAI 模型切换预设）
    $history = (array)($b['history'] ?? []);
    if (empty($history)) error_response('history array required', 400);
    $model = (string)($b['model'] ?? 'curated');   // 'curated' | 'full' | 'auto'
    $r = AiAdvisor::composePrompt($history, $model);
    ok_response($r);
    exit;
}

// 默认 action=analyze
$prompt = (string)($b['prompt'] ?? '');
if ($prompt === '') error_response('prompt required', 400);

// 目标 NAI 模型（影响 AI 建议的方向）
$model = (string)($b['model'] ?? 'auto');

// 如果没传拆解结果，前端可以先用 /api/decompose.php 拆一次，然后传过来
$decomposed = $b['decomposed'] ?? null;
if (!$decomposed) {
    $decomposed = TagClassifier::classify($prompt);
}

$flatPairs = [];
foreach ($decomposed['categories'] ?? [] as $catKey => $cat) {
    foreach ($cat['tags'] ?? [] as $t) {
        $flatPairs[] = [
            'name' => $t['name'] ?? '',
            'clean' => $t['clean'] ?? $t['name'] ?? '',
            'weight' => $t['weight'] ?? 1.0,
            'category' => $catKey,
        ];
    }
}
$artistAdvice = $b['artist_advice'] ?? ArtistAdvisor::analyze($flatPairs);

ok_response(AiAdvisor::analyze($prompt, $decomposed, $artistAdvice, !AiProvider::isEnabled(), $model));
