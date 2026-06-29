<?php
/**
 * /api/decompose.php  — Prompt decomposer
 *
 * POST {prompt: '...', translate?: bool}
 *   -> { categories: [...], tags: [...], stats: {...}, untranslated: [...] }
 *
 * GET ?action=sample
 *   -> { prompt: '...' }   // 示例 prompt，方便用户试用
 *
 * GET ?action=test_translate
 *   -> { ok, message, source }   // 测试本地翻译服务
 *
 * GET ?action=lookup&q=foo
 *   -> { name, category, cn, source }   // 单 tag 查 DB（带分类）
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\TagClassifier;
use NaiStudio\ArtistAdvisor;
use NaiStudio\Translator;
use NaiStudio\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? $_POST['action'] ?? 'classify';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ======================= GET: 工具类 =======================
if ($method === 'GET' && $action === 'sample') {
    ok_response([
        'prompt' => 'masterpiece, best_quality, amazing_quality, absurdres, highres, ' .
                    '1girl, solo, hatsune_miku, vocaloid, ' .
                    'long_hair, twintails, blue_eyes, ' .
                    'smile, blush, open_mouth, ' .
                    'standing, hands_on_hips, ' .
                    'school_uniform, pleated_skirt, thighhighs, hair_ribbon, ' .
                    'outdoors, cherry_blossoms, sky, ' .
                    'from_below, sunlight, ' .
                    '{artist:ciloranko}, ' .
                    '{{1.05::detailed_background}}, [depth_of_field:0.9]',
    ]);
    exit;
}

if ($method === 'GET' && $action === 'test_translate') {
    $r = Translator::testLocalTranslate();
    ok_response($r);
    exit;
}

if ($method === 'GET' && $action === 'lookup') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') error_response('q required', 400);
    $row = Db::fetchOne(
        "SELECT name, category, post_count, cn_name, example_image_url
         FROM danbooru_tag_cache WHERE name = ? LIMIT 1",
        [strtolower($q)]
    );
    if (!$row) {
        // 兜底：内置字典
        $cn = \NaiStudio\TagDict::lookup($q);
        ok_response(['name' => strtolower($q), 'cn' => $cn, 'category' => 0, 'source' => $cn ? 'builtin' : 'miss']);
        exit;
    }
    ok_response([
        'name'    => $row['name'],
        'cn'      => $row['cn_name'] ?: \NaiStudio\TagDict::lookup($row['name']),
        'category'=> (int)$row['category'],
        'post_count' => (int)$row['post_count'],
        'example_url' => $row['example_image_url'],
        'source'  => 'danbooru',
    ]);
    exit;
}

// 单独调用画师建议（前端添加/删除画师后用，不重跑整 prompt）
if ($method === 'POST' && $action === 'advise') {
    $b = read_json_body();
    $pairs = is_array($b['pairs'] ?? null) ? $b['pairs'] : [];
    $advice = ArtistAdvisor::analyze($pairs);
    ok_response(['advice' => $advice]);
    exit;
}

// ======================= POST: 拆解 =======================
if ($method !== 'POST') {
    error_response('Method not allowed', 405);
}
if ($action !== 'classify') {
    error_response('Unknown action', 400);
}

$b = read_json_body();
$prompt = (string)($b['prompt'] ?? '');
$autoTranslate = !empty($b['translate']);

if ($prompt === '') {
    error_response('prompt required', 400);
}

$result = TagClassifier::classify($prompt);

// 把所有 tag 拍平为 pairs，传给画师建议器
// 注：name=原文（带 {}），clean=剥前缀的纯名（用于 advisor 查画像库）
$flatPairs = [];
foreach ($result['categories'] as $catKey => $cat) {
    if (empty($cat['tags'])) continue;
    foreach ($cat['tags'] as $t) {
        $flatPairs[] = [
            'name'     => $t['name'] ?? '',
            'clean'    => $t['clean'] ?? $t['name'] ?? '',
            'weight'   => $t['weight'] ?? 1.0,
            'cn'       => $t['cn'] ?? null,
            'category' => $catKey,
        ];
    }
}
$result['artist_advice'] = ArtistAdvisor::analyze($flatPairs);

// 收集未翻译的 tag 列表（用于"补翻译"操作）
$untranslated = [];
foreach ($result['categories'] as $catKey => &$cat) {
    foreach ($cat['tags'] as &$tag) {
        if (empty($tag['cn'])) {
            $untranslated[] = $tag['clean'];
        }
    }
    unset($tag);
}
unset($cat);

// 翻译模式：对未翻译的 tag 调一次翻译
if ($autoTranslate && !empty($untranslated)) {
    $filled = 0;
    foreach ($untranslated as $clean) {
        $r = Translator::enToZh($clean);
        if (!empty($r['cn']) && $r['cn'] !== $clean) {
            // 回填到 categories
            foreach ($result['categories'] as &$cat) {
                foreach ($cat['tags'] as &$tag) {
                    if ($tag['clean'] === $clean && empty($tag['cn'])) {
                        $tag['cn'] = $r['cn'];
                        $tag['source'] = 'translated:' . ($r['source'] ?? 'unknown');
                        $filled++;
                        break 2;
                    }
                }
                unset($tag);
            }
            unset($cat);
        }
    }
    $result['translation_filled'] = $filled;
}

$result['untranslated_count'] = count($untranslated);

ok_response($result);
