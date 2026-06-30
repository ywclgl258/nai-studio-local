<?php
/**
 * /api/tag_image.php  — 自动从 Danbooru 抓标签示例图
 *
 * GET ?action=fetch&name=xxx
 *   - 先查本地 <storage>/tag-previews/<hash>/<name>.jpg
 *   - 有就返回 { name, url, source: 'local' }
 *   - 没有就调 Danbooru posts.json?tags=name&limit=1&random=true
 *   - 下载 preview_file_url → 存本地 → 写回 tags 表 example_image_url
 *   - 返回 { name, url, source: 'danbooru', saved_to: '/storage/...' }
 *
 * GET ?action=batch&names=tag1,tag2,tag3
 *   - 批量抓图（最多 20 个），返回数组
 *
 * GET ?action=stats
 *   - { total, with_image, missing } — 看覆盖率
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'fetch';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * 抓一张图（核心逻辑）
 * 流程：本地 → DB → Danbooru → 下载 → 写回 DB → 返回 URL
 *
 * 保底策略（按顺序 fallback）：
 *   1. posts.json?tags=name&limit=1&random=true（最常见的）
 *   2. limit=20 不 random，遍历找一个有 preview_file_url 的 post
 *   3. 如果都只有 file_url / large_file_url（受限 post 没 preview），用大图代替
 */
function fetchOne(string $tag): array {
    $tag = trim($tag);
    if ($tag === '') return ['name' => '', 'ok' => false, 'error' => 'empty'];

    // 1. 算 hash 路径（跟 admin/expand-tags.php 一致）
    $hash = substr(md5($tag), 0, 2);
    $rootStorage = dirname(__DIR__, 2) . '/storage/tag-previews';
    $subdir = "$rootStorage/$hash";
    $fname = $subdir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $tag) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);

    // 2. 本地已有（≥1KB 算有效）
    if (file_exists($fname) && filesize($fname) > 1000) {
        syncDb($tag, $relUrl);
        return ['name' => $tag, 'ok' => true, 'url' => $relUrl, 'source' => 'local'];
    }

    // 3. 调 Danbooru 抓第一个 post
    $post = danbooruPickFirstPost($tag);
    if ($post === null) {
        markAttempted($tag);
        return ['name' => $tag, 'ok' => false, 'error' => 'no_posts'];
    }

    // 4. 选图源：preview > large > file（按可用性 fallback）
    $imgUrl = $post['preview_file_url'] ?? $post['large_file_url'] ?? $post['file_url'] ?? null;
    if (!$imgUrl) {
        markAttempted($tag);
        return ['name' => $tag, 'ok' => false, 'error' => 'no_image_url'];
    }

    // 5. 下载图片
    $img = httpGet($imgUrl, 20);
    if (strlen($img) < 500) {
        markAttempted($tag);
        return ['name' => $tag, 'ok' => false, 'error' => 'download_too_small'];
    }

    // 6. 写本地 + 建目录
    if (!is_dir($subdir)) @mkdir($subdir, 0775, true);
    file_put_contents($fname, $img);

    // 7. 写回 DB
    syncDb($tag, $relUrl);

    return ['name' => $tag, 'ok' => true, 'url' => $relUrl, 'source' => 'danbooru', 'bytes' => strlen($img)];
}

/**
 * 从 Danbooru 找一个有图的 post
 *
 * Fallback 链：
 *   1. limit=1&random=true（绝大多数场景）
 *   2. limit=20 找第一个有 preview_file_url 的（解决 random 命中受限 post）
 *   3. 用 file_url 兜底（即使 preview 缺失也能用大图）
 */
function danbooruPickFirstPost(string $tag): ?array {
    // Step 1: 随机抽 1 个
    $url = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($tag) . '&limit=1&random=true';
    $body = httpGet($url, 15);
    if ($body !== false && $body !== '') {
        $posts = json_decode($body, true);
        if (is_array($posts) && !empty($posts)) {
            return $posts[0];
        }
    }

    // Step 2: 取 20 个，遍历找第一个有 preview_file_url 的（解决受限 post 兜底）
    $url = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($tag) . '&limit=20';
    $body = httpGet($url, 15);
    if ($body !== false && $body !== '') {
        $posts = json_decode($body, true);
        if (is_array($posts) && !empty($posts)) {
            foreach ($posts as $p) {
                if (!empty($p['preview_file_url'])) return $p;
            }
            // 都没 preview，但有 large / file URL — 退回第一个（用大图）
            return $posts[0];
        }
    }

    // Step 3: 整个没 post
    return null;
}

/**
 * 同步 DB（写回 tags.example_image_url）
 */
function syncDb(string $tag, string $relUrl): void {
    try {
        Db::execute(
            'UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE name = ?',
            [$relUrl, $tag]
        );
    } catch (Throwable $e) {
        // 静默失败：本地文件已经存好，DB 同步失败不影响功能
        error_log('[tag_image] syncDb failed: ' . $e->getMessage());
    }
}

/**
 * 标记已尝试（避免冷门 tag 反复请求）
 * 用 danbooru_tag_cache.fetched_at 字段（已有）
 */
function markAttempted(string $tag): void {
    try {
        Db::execute(
            'UPDATE danbooru_tag_cache SET fetched_at = CURRENT_TIMESTAMP WHERE name = ?',
            [$tag]
        );
    } catch (Throwable $e) {}
}

/**
 * HTTP GET（curl 包装，Windows 上比 file_get_contents 可靠）
 */
function httpGet(string $url, int $timeout = 30): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local; +https://github.com/ywclgl258/nai-studio-local)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err || $code >= 400) {
        error_log("[tag_image] httpGet $url → code=$code err=$err");
        return false;
    }
    return $body;
}

// ======================= ROUTING =======================
if ($method !== 'GET') {
    error_response('Method not allowed', 405);
}

if ($action === 'stats') {
    $total = (int)Db::fetchScalar('SELECT COUNT(*) FROM tags');
    $haveImg = (int)Db::fetchScalar("SELECT COUNT(*) FROM tags WHERE example_image_url IS NOT NULL AND example_image_url <> ''");
    ok_response([
        'total' => $total,
        'with_image' => $haveImg,
        'missing' => $total - $haveImg,
        'coverage' => $total > 0 ? round($haveImg * 100 / $total, 1) : 0,
    ]);
    exit;
}

if ($action === 'batch') {
    $names = array_filter(array_map('trim', explode(',', (string)($_GET['names'] ?? ''))));
    if (empty($names)) error_response('names required', 400);
    $names = array_slice($names, 0, 20);  // 限 20 个
    $results = [];
    foreach ($names as $name) {
        $results[] = fetchOne($name);
        // 串行，不要并发（Danbooru 会限流）
        usleep(100_000);  // 100ms 间隔
    }
    ok_response(['results' => $results]);
    exit;
}

if ($action === 'fetch') {
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') error_response('name required', 400);
    $result = fetchOne($name);
    ok_response($result);
    exit;
}

error_response('Unknown action', 400);