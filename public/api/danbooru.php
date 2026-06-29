<?php
/**
 * /api/danbooru.php  — Danbooru API proxy + 自动翻译
 * GET ?action=tag&q=foo  -> 搜索标签（带中文翻译）
 * GET ?action=post&q=foo -> 搜索示例图
 * GET ?action=translate&q=foo -> 单独触发翻译（取已有缓存或在线翻译）
 *
 * 自动翻译流程：
 *   1. 在线拉取 tag
 *   2. 没翻译过的 → 调 MyMemory API (en→zh-CN) 翻译
 *   3. 写入 danbooru_tag_cache.cn_name
 *   4. 已翻译过的（translated_at < 30 天）直接复用
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\Logger;
use NaiStudio\Settings;
use NaiStudio\Translator;

$action = $_GET['action'] ?? 'tag';
$q      = trim((string)($_GET['q'] ?? ''));
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// 统一 curl helper：支持 settings 里的代理开关
function dbFetch(string $url, int $timeout = 8): ?array {
    $proxy = Settings::getProxyUrl();
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($proxy) {
        $opts[CURLOPT_PROXY] = $proxy;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($body === false) {
        Logger::warn('danbooru.fetch.fail', ['url' => $url, 'errno' => $errno, 'proxy' => $proxy ?: 'none']);
        return null;
    }
    if ($code >= 400) {
        Logger::warn('danbooru.fetch.http', ['url' => $url, 'code' => $code, 'proxy' => $proxy ?: 'none']);
        return null;
    }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

if ($action === 'tag') {
    if ($q === '') {
        ok_response(['rows' => [], 'source' => 'empty', 'q' => $q, 'translated' => 0, 'from_cn' => null, 'to_en' => null]);
        exit;
    }

    // 0) 检测中文 → 翻译为英文（标签超市只支持在线，所以中文必须先转英文）
    $fromCn = null;
    $toEn = null;
    $translateSource = null;
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $q)) {
        // 0.1) 优先 TagDict 反向查表（500+ 词，秒回，比 MyMemory 准）
        $dictHits = \NaiStudio\TagDict::lookupReverse($q);
        if (!empty($dictHits)) {
            // 用第一个英文 tag 做主搜，其他用于"相关推荐"提示
            $toEn = $dictHits[0];
            $translateSource = 'tagdict';
        } else {
            // 0.2) fallback MyMemory / LibreTranslate / Google
            $tr = Translator::zhToEn($q);
            if ($tr === null) {
                ok_response([
                    'rows' => [],
                    'source' => 'translate_fail',
                    'q' => $q,
                    'translated' => 0,
                    'from_cn' => $q,
                    'to_en' => null,
                    'warning' => '中文翻译失败：' . $q . '（可手动输入英文 tag）',
                ]);
                exit;
            }
            $toEn = $tr['en'];
            $translateSource = $tr['source'];
        }
        $fromCn = $q;
        $searchQ = str_replace(' ', '_', $toEn);
    } else {
        $searchQ = $q;
    }

    // 1) 在线拉取 Danbooru（中文→英文 后用前缀模糊搜索）
    //    策略：精确 → 前缀 → 包含，三档合并去重
    $urls = [
        // 精确匹配：name=long_hair（Danbooru 的精确查询）
        'https://danbooru.donmai.us/tags.json?search[name]=' . urlencode($searchQ) . '&limit=5',
        // 前缀匹配：name_matches=long_hair*
        'https://danbooru.donmai.us/tags.json?search[name_matches]=' . urlencode($searchQ . '*') . '&limit=' . $limit,
        // 包含匹配：name_matches=*long_hair*（兜底）
        'https://danbooru.donmai.us/tags.json?search[name_matches]=' . urlencode('*' . $searchQ . '*') . '&limit=' . $limit,
    ];
    $seen = [];
    $data = [];
    foreach ($urls as $u) {
        $batch = dbFetch($u);
        if ($batch === null) continue;
        foreach ($batch as $t) {
            $name = (string)($t['name'] ?? '');
            if ($name === '' || isset($seen[$name])) continue;
            $seen[$name] = true;
            $data[] = $t;
        }
    }
    if (empty($data)) {
        ok_response([
            'rows' => [],
            'source' => 'danbooru_offline',
            'q' => $q,
            'translated' => 0,
            'from_cn' => $fromCn,
            'to_en' => $toEn,
            'translate_source' => $translateSource,
            'warning' => 'Danbooru 不可达（请检查网络/代理）',
        ]);
        exit;
    }

    // 2) 按 post_count 降序（Danbooru 默认按 created_at，不靠谱）
    usort($data, fn($a, $b) => (int)($b['post_count'] <=> (int)$a['post_count']));
    $data = array_slice($data, 0, $limit);

    // 3) 入库 + 翻译
    $rowsOut = [];
    $translatedThisCall = 0;
    foreach ($data as $t) {
        $name = (string)($t['name'] ?? '');
        if ($name === '') continue;
        $existing = Db::fetchOne("SELECT id, cn_name, translated_at, example_image_url FROM danbooru_tag_cache WHERE name = ?", [$name]);
        $cnName = $existing['cn_name'] ?? null;
        $needsTranslate = !$cnName || (strtotime($existing['translated_at'] ?? '1970-01-01') < time() - 86400 * 30);

        $rowId = null;
        if ($existing) {
            $rowId = (int)$existing['id'];
        } else {
            try {
                $rowId = Db::insert('danbooru_tag_cache', [
                    'name'        => $name,
                    'category'    => (int)($t['category'] ?? 0),
                    'post_count'  => (int)($t['post_count'] ?? 0),
                    'fetched_at'  => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // ignore duplicate
            }
        }

        // 翻译：优先内置字典（秒回），否则调 en→zh 翻译
        if ($needsTranslate && $rowId) {
            $builtin = \NaiStudio\TagDict::lookup($name);
            if ($builtin !== null) {
                \NaiStudio\Db::update('danbooru_tag_cache', $rowId, [
                    'cn_name' => mb_substr($builtin, 0, 128),
                    'translated_at' => date('Y-m-d H:i:s'),
                ]);
                $cnName = $builtin;
                $translatedThisCall++;
            } else {
                $cn = Translator::translateDanbooruTag($name, $rowId);
                if ($cn !== '') {
                    $cnName = $cn;
                    $translatedThisCall++;
                }
            }
        }

        $rowsOut[] = [
            'name'        => $name,
            'cn_name'     => $cnName,
            'category'    => (int)($t['category'] ?? 0),
            'post_count'  => (int)($t['post_count'] ?? 0),
            'example_url' => $existing['example_image_url'] ?? null,
        ];
    }

    ok_response([
        'rows' => $rowsOut,
        'source' => 'danbooru',
        'q' => $q,
        'search_q' => $searchQ,
        'translated' => $translatedThisCall,
        'from_cn' => $fromCn,
        'to_en' => $toEn,
        'translate_source' => $translateSource,
    ]);
    exit;
}

if ($action === 'post') {
    if ($q === '') error_response('q required', 400);
    $url = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($q) . '&limit=' . $limit . '&sf=random';
    $data = dbFetch($url);
    if ($data === null) {
        ok_response(['rows' => [], 'source' => 'offline', 'q' => $q, 'warning' => 'Danbooru offline']);
        exit;
    }
    $rows = [];
    foreach ($data as $p) {
        $rows[] = [
            'id' => (int)($p['id'] ?? 0),
            'preview_url' => 'https://cdn.donmai.us/preview/' . ($p['preview_file_url'] ?? ''),
            'sample_url'  => 'https://cdn.donmai.us/sample/'  . ($p['sample_file_url']  ?? ''),
            'file_url'    => 'https://danbooru.donmai.us/data/' . ($p['directory'] ?? '') . '/' . ($p['image'] ?? ''),
            'width'  => (int)($p['image_width']  ?? 0),
            'height' => (int)($p['image_height'] ?? 0),
            'tags'   => (string)($p['tag_string'] ?? ''),
        ];
    }
    ok_response(['rows' => $rows, 'source' => 'danbooru', 'q' => $q]);
    exit;
}

if ($action === 'translate') {
    if ($q === '') error_response('q required', 400);
    // 直接翻译一个词
    $r = Translator::enToZh($q);
    ok_response(['q' => $q, 'cn' => $r['cn'], 'cached' => $r['cached']]);
    exit;
}

error_response('Unknown action', 400);