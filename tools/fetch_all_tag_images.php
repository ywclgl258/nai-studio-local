<?php
/**
 * tools/fetch_all_tag_images.php — CLI 批量预生成标签示例图
 *
 * 策略（仿 wfjsw/tags.novelai.dev 的构建时预生成）：
 *   1. 找 tags 表里 example_image_url 为空的热门 tag（按 post_count DESC）
 *   2. 对每个 tag 调 Danbooru posts.json?tags=xxx&limit=1&random=true
 *   3. 下载 preview_file_url → 存 storage/tag-previews/<hash[0:2]>/<name>.jpg
 *   4. 写回 tags.example_image_url
 *
 * 用法：
 *   php tools/fetch_all_tag_images.php              # 默认抓 top 1000
 *   php tools/fetch_all_tag_images.php 5000         # 抓 top 5000
 *   php tools/fetch_all_tag_images.php 100 --fresh  # 强制重抓已有的
 *
 * Danbooru 限速 ~2 req/s，所以：
 *   top 1000  ≈ 8 分钟
 *   top 5000  ≈ 40 分钟
 *   top 25000 ≈ 3.5 小时
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/lib/Db.php';

use NaiStudio\Db;

$limit = (int)($argv[1] ?? 1000);
$fresh = in_array('--fresh', $argv, true);

echo "=== Tag Image Pre-fetcher ===\n";
echo "Limit: $limit\n";
echo "Mode:  " . ($fresh ? 'fresh (re-fetch existing)' : 'only missing') . "\n";
echo "Start: " . date('Y-m-d H:i:s') . "\n\n";

// 1. 找出待抓的 tag
$where = $fresh
    ? '1=1'
    : '(example_image_url IS NULL OR example_image_url = "")';

$stmt = Db::pdo()->prepare("
    SELECT id, name, post_count, example_image_url
    FROM tags
    WHERE $where
    ORDER BY post_count DESC
    LIMIT :lim
");
$stmt->bindValue('lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Tags to process: " . count($tags) . "\n\n";

if (empty($tags)) {
    echo "Nothing to do. Done.\n";
    exit(0);
}

// 2. 准备目录
$rootStorage = dirname(__DIR__) . '/storage/tag-previews';
if (!is_dir($rootStorage)) @mkdir($rootStorage, 0775, true);

// 3. 逐个抓
$total = count($tags);
$ok = 0;
$skip = 0;
$fail = 0;
$noPosts = 0;
$startTime = time();

foreach ($tags as $i => $t) {
    $tagName = $t['name'];
    $progress = sprintf('[%d/%d]', $i + 1, $total);
    $pct = round(($i + 1) / $total * 100);

    // 算路径
    $hash = substr(md5($tagName), 0, 2);
    $subdir = "$rootStorage/$hash";
    $fname = $subdir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $tagName) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);

    // 本地已有（除非 --fresh）
    if (!$fresh && file_exists($fname) && filesize($fname) > 1000) {
        // 顺便回填 DB
        try {
            Db::execute(
                'UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$relUrl, (int)$t['id']]
            );
        } catch (Throwable $e) {}
        $skip++;
        echo "\r$progress  $pct%  ⏭  $tagName (already exists)";
        continue;
    }

    // 调 Danbooru
    $apiUrl = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($tagName) . '&limit=1&random=true';
    $body = httpGet($apiUrl, 15);
    if ($body === false || $body === '') {
        $fail++;
        echo "\r$progress  $pct%  ❌  $tagName (network)";
        usleep(500_000);
        continue;
    }
    $posts = json_decode($body, true);
    if (!is_array($posts) || empty($posts)) {
        $noPosts++;
        echo "\r$progress  $pct%  ·   $tagName (no posts)";
        usleep(300_000);  // 0.3s — 没找到也要轻点
        continue;
    }

    $previewUrl = $posts[0]['preview_file_url'] ?? null;
    if (!$previewUrl) {
        $fail++;
        echo "\r$progress  $pct%  ❌  $tagName (no preview_url)";
        continue;
    }

    // 下载图
    $img = httpGet($previewUrl, 20);
    if (strlen($img) < 500) {
        $fail++;
        echo "\r$progress  $pct%  ❌  $tagName (img too small)";
        usleep(300_000);
        continue;
    }

    // 存本地
    if (!is_dir($subdir)) @mkdir($subdir, 0775, true);
    file_put_contents($fname, $img);

    // 写回 DB
    try {
        Db::execute(
            'UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$relUrl, (int)$t['id']]
        );
    } catch (Throwable $e) {
        error_log('[fetch_all] db write failed for ' . $tagName . ': ' . $e->getMessage());
    }

    $ok++;
    $sizeKb = round(strlen($img) / 1024, 1);
    echo "\r$progress  $pct%  ✅  $tagName ($sizeKb KB)";

    // Danbooru 礼貌限速：~2 req/s
    usleep(550_000);  // 0.55s（两次 curl = 1.1s + DB write overhead ≈ 1.5s）
}

// 4. 总结
$elapsed = time() - $startTime;
$rate = $elapsed > 0 ? round(($i + 1) / $elapsed * 60, 1) : 0;

echo "\n\n=== Done ===\n";
echo "Total:    $total\n";
echo "OK:       $ok\n";
echo "Skip:     $skip (already existed)\n";
echo "No posts: $noPosts (cold tags)\n";
echo "Failed:   $fail\n";
echo "Elapsed:  " . gmdate('H:i:s', $elapsed) . " ($rate/min)\n";

// 5. 最终统计
$row = Db::fetchOne("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN example_image_url IS NOT NULL AND example_image_url <> '' THEN 1 ELSE 0 END) as have
    FROM tags
");
$coverage = $row['total'] > 0 ? round($row['have'] * 100 / $row['total'], 1) : 0;
echo "\n=== Coverage now ===\n";
echo "Total:   {$row['total']}\n";
echo "Have:    {$row['have']}\n";
echo "Coverage: {$coverage}%\n";

// 6. 落盘进度日志
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/fetch_all_images.log';
$logLine = sprintf(
    "[%s] limit=%d fresh=%s total=%d ok=%d skip=%d noPosts=%d fail=%d elapsed=%ds coverage=%.1f%%\n",
    date('Y-m-d H:i:s'),
    $limit,
    $fresh ? '1' : '0',
    $total, $ok, $skip, $noPosts, $fail, $elapsed, $coverage
);
file_put_contents($logFile, $logLine, FILE_APPEND);
echo "\nLogged to: $logFile\n";


function httpGet(string $url, int $timeout = 30): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.1 (local; +https://github.com/ywclgl258/nai-studio-local)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err || $code >= 400) {
        return false;
    }
    return $body;
}