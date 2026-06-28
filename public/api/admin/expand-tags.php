<?php
/**
 * 批量扩充本地标签库
 * - 拉 Danbooru 全量标签（按 post_count 排序）
 * - 翻译成中文（字典 → DB 已有 → MyMemory）
 * - 预下载每个标签的 top 1 示例图到本地
 *
 * 用法：
 *   POST /api/admin/expand-tags.php
 *   { "action": "start", "min_posts": 100, "max_pages": 50, "with_images": true }
 *   { "action": "status" }
 *   { "action": "stop" }
 *
 * 状态文件：storage/cache/expand-status.json（实时进度）
 * 索引：把已处理的 tag 名存到 storage/cache/expand-done.txt
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\TagDict;
use NaiStudio\Translator;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

// 状态文件位置
$stateFile = __DIR__ . '/../../../storage/cache/expand-status.json';
$doneFile  = __DIR__ . '/../../../storage/cache/expand-done.txt';
$previewDir = __DIR__ . '/../../../storage/tag-previews';
@mkdir(dirname($stateFile), 0777, true);
@mkdir($previewDir, 0777, true);

function readState(string $file): array {
    if (!file_exists($file)) return ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
}

function writeState(string $file, array $state): void {
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
}

if ($action === 'status') {
    ok_response(readState($stateFile));
}

if ($action === 'stop') {
    $state = readState($stateFile);
    $state['status'] = 'stopped';
    $state['message'] = '已请求停止';
    writeState($stateFile, $state);
    ok_response($state);
}

if ($action === 'start') {
    // 异步执行（用 ignore_user_abort + fastcgi_finish_request 不行，普通 HTTP 同步）
    $minPosts = max(1, (int)($_POST['min_posts'] ?? 100));
    $maxPages = max(1, min(200, (int)($_POST['max_pages'] ?? 50)));  // 50 页 × 1000 = 50000
    $withImages = (bool)($_POST['with_images'] ?? true);

    // 设置超时（CLI 不受限制；web 请求给 300s）
    set_time_limit(0);

    // 已处理列表
    $done = [];
    if (file_exists($doneFile)) {
        $done = array_filter(array_map('trim', file($doneFile) ?: []));
        $done = array_flip($done);  // O(1) 查
    }

    // DB 中已有
    $existing = Db::fetchAll("SELECT name FROM tags", [], \PDO::FETCH_COLUMN);

    // 分类映射
    $catNames = ['General', 'Artist', 'Copyright', 'Character', 'Meta'];  // index 0/1/3/4/5
    $catIds = []; // Danbooru category → DB category_id（这里只关心 Danbooru 0=通用, 1=画师, 3=版权, 4=角色, 5=元数据）
    foreach (Db::fetchAll("SELECT id, name, name_cn FROM tag_categories") as $c) {
        $catIds[strtolower($c['name'])] = (int)$c['id'];
        if (!empty($c['name_cn'])) $catIds[strtolower($c['name_cn'])] = (int)$c['id'];
    }

    $state = [
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s'),
        'progress' => 0,
        'total' => $maxPages * 1000,
        'added' => 0,
        'translated' => 0,
        'images' => 0,
        'skipped' => 0,
        'errors' => 0,
        'current_tag' => '',
        'message' => '拉取 Danbooru 标签中...',
    ];
    writeState($stateFile, $state);

    // 关闭前端连接，让脚本继续
    if (function_exists('fastcgi_finish_request')) {
        // 输出 + 关闭
        echo json_encode(['ok' => true, 'message' => '已启动后台扩充'], JSON_UNESCAPED_UNICODE);
        fastcgi_finish_request();
    } else {
        echo json_encode(['ok' => true, 'message' => '已启动扩充', 'note' => '请勿关闭浏览器'], JSON_UNESCAPED_UNICODE);
    }

    // 拉取 + 处理
    $page = 1;
    $processed = 0;
    $state['total'] = $maxPages * 1000;
    $logFile = __DIR__ . '/../../../storage/cache/expand.log';
    @file_put_contents($logFile, "[" . date('H:i:s') . "] start min_posts={$minPosts} max_pages={$maxPages}\n", FILE_APPEND);

    while ($page <= $maxPages) {
        // 1) 拉这一页的标签（用 curl，PHP stream 在 Windows 上经常 timeout）
        //    Danbooru: search[order] 接受 date/count/name/similarity，按 post 排要 count
        $url = "https://danbooru.donmai.us/tags.json?limit=1000&page={$page}&search[order]=count";
        $body = httpGet($url, 30);
        if ($body === false) {
            $state['errors']++;
            $state['message'] = "第 {$page} 页拉取失败（网络问题）";
            @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} fail\n", FILE_APPEND);
            writeState($stateFile, $state);
            $page++;
            usleep(2000000);
            continue;
        }
        @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} got " . strlen($body) . " bytes\n", FILE_APPEND);
        // 调试：记第一条
        $firstTag = json_decode($body, true)[0] ?? null;
        if ($firstTag) @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} first: " . $firstTag['name'] . " (" . ($firstTag['post_count'] ?? 0) . " posts)\n", FILE_APPEND);
        $tags = json_decode($body, true);
        if (!is_array($tags) || empty($tags)) {
            $state['message'] = "第 {$page} 页无数据，结束";
            break;
        }
        // 按 post_count 降序（Danbooru 的 search[order] 不稳）
        usort($tags, fn($a, $b) => ($b['post_count'] ?? 0) <=> ($a['post_count'] ?? 0));

        foreach ($tags as $t) {
            // 检查是否停止
            $curState = readState($stateFile);
            if ($curState['status'] === 'stopped') {
                $state['status'] = 'stopped';
                $state['message'] = "已停止（已处理 {$processed} 条）";
                writeState($stateFile, $state);
                exit(0);
            }

            $name = (string)($t['name'] ?? '');
            if ($name === '') continue;
            $postCount = (int)($t['post_count'] ?? 0);
            $cat = (int)($t['category'] ?? 0);
            $nsfw = ($t['is_deprecated'] ?? false) ? 1 : 0;

            if ($postCount < $minPosts) {
                $state['skipped']++;
                continue;
            }

            // 跳过已处理
            if (isset($done[$name])) {
                $state['skipped']++;
                continue;
            }

            $state['current_tag'] = $name;
            $state['progress'] = $processed;
            $processed++;

            // 2) 翻译
            $cn = TagDict::lookup($name);
            $translatedThisTag = false;
            if ($cn === null) {
                // 查 DB
                $existingCn = Db::fetchOne("SELECT cn_name FROM tags WHERE name = ?", [$name])['cn_name'] ?? null;
                if ($existingCn) {
                    $cn = $existingCn;
                } else {
                    // 调 MyMemory（限速 100ms 间隔）
                    usleep(100000);
                    $r = Translator::enToZh($name);
                    $cn = $r['cn'] ?? '';
                    if ($cn === '' || stripos($cn, 'WARNING') !== false) $cn = null;
                    else $translatedThisTag = true;
                }
            }

            // 3) 分类映射
            $catName = $catNames[$cat] ?? 'General';
            $catId = $catIds[strtolower($catName)] ?? null;
            if ($catId === null) {
                // fallback：找第一个
                $catId = (int)Db::fetchOne("SELECT id FROM tag_categories LIMIT 1")['id'];
            }

            // 4) 插入 DB
            try {
                $existing = Db::fetchOne("SELECT id FROM tags WHERE name = ?", [$name]);
                if ($existing) {
                    Db::update('tags', $existing['id'], [
                        'cn_name' => $cn,
                        'post_count' => $postCount,
                        'category_id' => $catId,
                        'is_nsfw' => $nsfw,
                    ]);
                } else {
                    Db::insert('tags', [
                        'name' => $name,
                        'category_id' => $catId,
                        'cn_name' => $cn,
                        'post_count' => $postCount,
                        'is_nsfw' => $nsfw,
                    ]);
                    $state['added']++;
                }
                if ($translatedThisTag) $state['translated']++;
            } catch (\Throwable $e) {
                $state['errors']++;
            }

            // 5) 预下载示例图
            if ($withImages && $postCount > 0) {
                $imgUrl = downloadExampleImage($name, $previewDir, $state);
                if ($imgUrl) {
                    try {
                        $row = Db::fetchOne("SELECT id FROM tags WHERE name = ?", [$name]);
                        if ($row) {
                            Db::update('tags', $row['id'], ['example_image_url' => $imgUrl]);
                            $state['images']++;
                        }
                    } catch (\Throwable $e) {}
                }
            }

            // 6) 记入 done
            file_put_contents($doneFile, $name . "\n", FILE_APPEND);
            $done[$name] = true;

            // 7) 进度（每 50 条写一次）
            if ($processed % 50 === 0) {
                $state['message'] = "处理中：第 {$page} 页，{$state['added']} 新增，{$state['translated']} 翻译，{$state['images']} 图";
                writeState($stateFile, $state);
            }

            // 限速
            usleep(20000); // 50/s
        }

        $page++;
        $state['message'] = "完成第 " . ($page - 1) . " 页，开始第 {$page} 页";
        writeState($stateFile, $state);
        usleep(200000); // 每页间隔
    }

    $state['status'] = 'done';
    $state['message'] = "完成！新增 {$state['added']} 条，翻译 {$state['translated']} 条，下载 {$state['images']} 张图";
    writeState($stateFile, $state);
    exit(0);
}

/**
 * 下载某个 tag 的 1 张示例图
 * @return string|null 相对 URL（存到 DB 用）
 */
function downloadExampleImage(string $tag, string $previewDir, array &$state): ?string {
    // 跳过：已有图
    $hash = substr(md5($tag), 0, 2);
    $subdir = "$previewDir/$hash";
    @mkdir($subdir, 0777, true);
    $fname = "$subdir/" . preg_replace('/[^a-z0-9_]/i', '_', $tag) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);

    if (file_exists($fname) && filesize($fname) > 1000) {
        return $relUrl;
    }

    // 拉 1 个 post（curl）
    $url = "https://danbooru.donmai.us/posts.json?tags=" . urlencode($tag) . "&limit=1&random=true";
    $body = httpGet($url, 15);
    if ($body === false) return null;
    $posts = json_decode($body, true);
    if (!is_array($posts) || empty($posts)) return null;

    $previewUrl = $posts[0]['preview_file_url'] ?? null;
    if (!$previewUrl) return null;

    // 下载（curl）
    $img = httpGet($previewUrl, 20);
    if (strlen($img) < 500) return null;
    file_put_contents($fname, $img);
    return $relUrl;
}

/**
 * 用 curl 拉取 URL（比 file_get_contents 在 Windows 上可靠）
 */
function httpGet(string $url, int $timeout = 30): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('[expand-tags] httpGet error: ' . curl_error($ch) . ' url=' . $url);
    }
    curl_close($ch);
    return $body === false ? '' : (string)$body;
}