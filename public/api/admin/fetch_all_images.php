<?php
/**
 * /api/admin/fetch_all_images.php — HTTP 触发的批量预生成
 *
 * POST 方式（流式输出进度）：
 *   POST /api/admin/fetch_all_images.php?limit=500
 *   Content-Type: application/x-www-form-urlencoded
 *
 * 行为：
 *   - 输出 Content-Type: text/event-stream
 *   - 每个 tag 完成时输出一行 SSE: data: {"done":1,"total":500,"ok":1,"tag":"1girl"}
 *   - 所有完成输出 data: {"done":500,"total":500,"finished":true}
 *   - 浏览器 EventSource 可直接消费
 *
 * GET 方式（仅查询 stats）：
 *   GET ?action=stats  → { total, have, missing, coverage }
 *
 * 复用 tools/fetch_all_tag_images.php 的逻辑（require），不重复代码。
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/config.php';
require_once __DIR__ . '/../../../src/lib/Db.php';

use NaiStudio\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'run';
$limit  = max(1, min(50000, (int)($_GET['limit'] ?? $_POST['limit'] ?? 1000)));

// ======================= STATS =======================
if ($action === 'stats') {
    $total = (int)Db::fetchScalar('SELECT COUNT(*) FROM tags');
    $have  = (int)Db::fetchScalar("SELECT COUNT(*) FROM tags WHERE example_image_url IS NOT NULL AND example_image_url <> ''");
    echo json_encode([
        'total'     => $total,
        'have'      => $have,
        'missing'   => $total - $have,
        'coverage'  => $total > 0 ? round($have * 100 / $total, 1) : 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ======================= RUN (流式 SSE) =======================
// 切到 SSE
header('Content-Type: text/event-stream; charset=utf-8');
header('X-Accel-Buffering: no');  // nginx 不缓冲
header('Cache-Control: no-cache, no-store, must-revalidate');

// 关 PHP 输出缓冲
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
@ob_implicit_flush(true);

// 防超时：HTTP 长时间
set_time_limit(0);
ignore_user_abort(false);  // 客户端断开则终止

// 关 PDO 连接超时
$pdo = Db::pdo();
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 0);

// 检查是否支持 flush
function sse_flush(string $data): void {
    echo "data: $data\n\n";
    @flush();
}

// 准备
$rootStorage = dirname(__DIR__, 3) . '/storage/tag-previews';
if (!is_dir($rootStorage)) @mkdir($rootStorage, 0775, true);

// 1. 找出待抓的 tag（按 post_count DESC 排序）
$stmt = Db::pdo()->prepare("
    SELECT id, name, post_count
    FROM tags
    WHERE example_image_url IS NULL OR example_image_url = ''
    ORDER BY post_count DESC
    LIMIT :lim
");
$stmt->bindValue('lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

sse_flush(json_encode([
    'stage'  => 'start',
    'total'  => count($tags),
    'limit'  => $limit,
    'ts'     => time(),
], JSON_UNESCAPED_UNICODE));

if (empty($tags)) {
    sse_flush(json_encode([
        'stage' => 'done',
        'total' => 0,
        'ok' => 0, 'fail' => 0, 'noPosts' => 0, 'skip' => 0,
        'finished' => true,
    ], JSON_UNESCAPED_UNICODE));
    exit;
}

// 2. 抓
$total = count($tags);
$ok = 0; $fail = 0; $noPosts = 0; $skip = 0;
$startTime = time();

foreach ($tags as $i => $t) {
    $tagName = $t['name'];
    $hash = substr(md5($tagName), 0, 2);
    $subdir = "$rootStorage/$hash";
    $fname = $subdir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $tagName) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);

    $status = ''; $bytes = 0; $err = '';

    // 本地已有 → skip + 同步 DB
    if (file_exists($fname) && filesize($fname) > 1000) {
        try {
            Db::execute('UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$relUrl, (int)$t['id']]);
        } catch (Throwable $e) {}
        $skip++;
        $status = 'skip';
    } else {
        // 调 Danbooru
        $apiUrl = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($tagName) . '&limit=1&random=true';
        $body = httpGet($apiUrl, 15);
        if ($body === false || $body === '') {
            $fail++;
            $status = 'fail';
            $err = 'network';
        } else {
            $posts = json_decode($body, true);
            if (!is_array($posts) || empty($posts)) {
                $noPosts++;
                $status = 'noPosts';
            } else {
                $previewUrl = $posts[0]['preview_file_url'] ?? null;
                if (!$previewUrl) {
                    $fail++;
                    $status = 'fail';
                    $err = 'no_preview_url';
                } else {
                    $img = httpGet($previewUrl, 20);
                    if (strlen($img) < 500) {
                        $fail++;
                        $status = 'fail';
                        $err = 'img_too_small';
                    } else {
                        if (!is_dir($subdir)) @mkdir($subdir, 0775, true);
                        file_put_contents($fname, $img);
                        try {
                            Db::execute('UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?',
                                [$relUrl, (int)$t['id']]);
                        } catch (Throwable $e) {
                            // DB 写失败但文件已存
                            error_log('[fetch_all_images] db write failed for ' . $tagName . ': ' . $e->getMessage());
                        }
                        $ok++;
                        $bytes = strlen($img);
                        $status = 'ok';
                    }
                }
            }
        }
    }

    // 输出进度
    sse_flush(json_encode([
        'stage'    => 'progress',
        'index'    => $i + 1,
        'total'    => $total,
        'name'     => $tagName,
        'status'   => $status,
        'bytes'    => $bytes,
        'err'      => $err,
        'ok'       => $ok,
        'fail'     => $fail,
        'noPosts'  => $noPosts,
        'skip'     => $skip,
        'elapsed'  => time() - $startTime,
    ], JSON_UNESCAPED_UNICODE));

    // 礼貌限速
    usleep(550_000);
}

// 3. 最终统计
$elapsed = time() - $startTime;
$totalRow = Db::fetchOne('SELECT COUNT(*) as t, SUM(CASE WHEN example_image_url IS NOT NULL AND example_image_url <> "" THEN 1 ELSE 0 END) as h FROM tags');
$coverage = $totalRow['t'] > 0 ? round($totalRow['h'] * 100 / $totalRow['t'], 1) : 0;

sse_flush(json_encode([
    'stage'     => 'done',
    'finished'  => true,
    'total'     => $total,
    'ok'        => $ok,
    'fail'      => $fail,
    'noPosts'   => $noPosts,
    'skip'      => $skip,
    'elapsed'   => $elapsed,
    'coverage'  => $coverage,
    'global'    => [
        'total'    => (int)$totalRow['t'],
        'have'     => (int)$totalRow['h'],
        'missing'  => (int)($totalRow['t'] - $totalRow['h']),
    ],
], JSON_UNESCAPED_UNICODE));

// 4. 写日志
$logDir = dirname(__DIR__, 3) . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
file_put_contents(
    $logDir . '/fetch_all_images.log',
    sprintf("[%s] http limit=%d total=%d ok=%d fail=%d noPosts=%d skip=%d elapsed=%ds coverage=%.1f%%\n",
        date('Y-m-d H:i:s'), $limit, $total, $ok, $fail, $noPosts, $skip, $elapsed, $coverage),
    FILE_APPEND
);


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