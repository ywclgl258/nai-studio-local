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
$limit  = max(1, min(50, (int)($_GET['limit'] ?? 24)));

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
        ok_response(['rows' => [], 'source' => 'empty', 'q' => $q, 'translated' => 0]);
        exit;
    }

    // 1) 优先本地缓存（包含翻译）
    $cached = Db::fetchAll(
        "SELECT id, name, cn_name, category, post_count, example_image_url, translated_at
         FROM danbooru_tag_cache
         WHERE name LIKE ?
         ORDER BY post_count DESC LIMIT $limit",
        [$q . '%']
    );
    $cachedCn = 0;
    foreach ($cached as &$c) {
        if (!empty($c['cn_name'])) $cachedCn++;
        unset($c['id']);
    }
    unset($c);
    if (count($cached) >= 5 && $cachedCn >= max(1, count($cached) * 0.6)) {
        // 缓存命中率够高，直接返回
        ok_response(['rows' => $cached, 'source' => 'cache', 'q' => $q, 'translated' => $cachedCn]);
        exit;
    }

    // 2) 在线拉取
    $url = 'https://danbooru.donmai.us/tags.json?search[name_matches]=' . urlencode($q) . '&limit=' . ($limit * 2);
    $data = dbFetch($url);
    if ($data === null) {
        ok_response([
            'rows' => $cached,
            'source' => 'cache_fallback',
            'q' => $q,
            'translated' => $cachedCn,
            'warning' => 'Danbooru offline（已用本地缓存）',
        ]);
        exit;
    }

    // 3) 合并：在线 + 已缓存的；入库 + 翻译
    // 按 post_count 降序（Danbooru 默认按创建时间，不靠谱）
    usort($data, fn($a, $b) => (int)($b['post_count'] <=> (int)$a['post_count']));
    $data = array_slice($data, 0, $limit);

    $rowsOut = [];
    $translatedThisCall = 0;
    foreach ($data as $t) {
        $name = (string)($t['name'] ?? '');
        if ($name === '') continue;
        // 查 DB 是否已有翻译
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

        // 翻译：优先内置字典（秒回，不调用 API），否则调 MyMemory
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

    // 把剩余未翻译的缓存行也补进去
    foreach ($cached as $c) {
        $exists = false;
        foreach ($rowsOut as $r) { if ($r['name'] === $c['name']) { $exists = true; break; } }
        if (!$exists) $rowsOut[] = $c;
    }

    ok_response([
        'rows' => $rowsOut,
        'source' => 'danbooru',
        'q' => $q,
        'translated' => $translatedThisCall,
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