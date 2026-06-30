<?php
/**
 * CLI 端：批量扩充本地标签库（Danbooru + 翻译 + 示例图）
 *
 * Usage:
 *   php tools/expand_tags_cli.php --min-posts=100 --max-pages=50 --with-images=1
 *
 * 状态文件: --state-file=<path>   默认 storage/cache/expand-status.json
 * 日志文件: --log-file=<path>     默认 storage/cache/expand.log
 *
 * v1.1.4+：从 public/api/admin/expand-tags.php 拆出来，避免阻塞 PHP 单线程 server
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/lib/Db.php';

use NaiStudio\Db;
use NaiStudio\TagDict;
use NaiStudio\Translator;

// ===== CLI 参数 =====
$opts = getopt('', ['min-posts::', 'max-pages::', 'with-images::', 'state-file::', 'log-file::', 'root::']);
$minPosts  = max(1, (int)($opts['min-posts'] ?? 100));
$maxPages  = max(1, min(200, (int)($opts['max-pages'] ?? 50)));
$withImages = (bool)(int)($opts['with-images'] ?? 1);
$stateFile = $opts['state-file'] ?? __DIR__ . '/../storage/cache/expand-status.json';
$logFile   = $opts['log-file']   ?? __DIR__ . '/../storage/cache/expand.log';
$root      = $opts['root']       ?? realpath(__DIR__ . '/..');
$doneFile  = $root . '/storage/cache/expand-done.txt';
$previewDir = $root . '/storage/tag-previews';
@mkdir(dirname($stateFile), 0777, true);
@mkdir(dirname($logFile), 0777, true);
@mkdir($previewDir, 0777, true);

set_time_limit(0);

function readState(string $file): array {
    if (!file_exists($file)) return ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
}
function writeState(string $file, array $state): void {
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
}
function httpGet(string $url, int $timeout = 30): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local; cli)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? '' : (string)$body;
}
function downloadExampleImage(string $tag, string $previewDir, array &$state): ?string {
    $hash = substr(md5($tag), 0, 2);
    $subdir = "$previewDir/$hash";
    @mkdir($subdir, 0777, true);
    $fname = "$subdir/" . preg_replace('/[^a-z0-9_]/i', '_', $tag) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);
    if (file_exists($fname) && filesize($fname) > 1000) return $relUrl;
    $url = "https://danbooru.donmai.us/posts.json?tags=" . urlencode($tag) . "&limit=1&random=true";
    $body = httpGet($url, 15);
    if ($body === '') return null;
    $posts = json_decode($body, true);
    if (!is_array($posts) || empty($posts)) return null;
    $previewUrl = $posts[0]['preview_file_url'] ?? null;
    if (!$previewUrl) return null;
    $img = httpGet($previewUrl, 20);
    if (strlen($img) < 500) return null;
    file_put_contents($fname, $img);
    return $relUrl;
}

// ===== 主流程 =====
$log = "[" . date('H:i:s') . "] CLI start min_posts={$minPosts} max_pages={$maxPages} with_images=" . ($withImages?'1':'0') . "\n";
@file_put_contents($logFile, $log, FILE_APPEND);

$done = [];
if (file_exists($doneFile)) {
    $done = array_filter(array_map('trim', file($doneFile) ?: []));
    $done = array_flip($done);
}

$catNames = ['General', 'Artist', 'Copyright', 'Character', 'Meta'];
$catIds = [];
foreach (Db::fetchAll("SELECT id, name, name_cn FROM tag_categories") as $c) {
    $catIds[strtolower($c['name'])] = (int)$c['id'];
    if (!empty($c['name_cn'])) $catIds[strtolower($c['name_cn'])] = (int)$c['id'];
}

$state = [
    'status' => 'running',
    'started_at' => date('Y-m-d H:i:s'),
    'progress' => 0,
    'total' => $maxPages * 1000,
    'added' => 0, 'translated' => 0, 'images' => 0,
    'skipped' => 0, 'errors' => 0,
    'current_tag' => '',
    'message' => '拉取 Danbooru 标签中...',
];
writeState($stateFile, $state);

$page = 1;
$processed = 0;
while ($page <= $maxPages) {
    $url = "https://danbooru.donmai.us/tags.json?limit=1000&page={$page}&search[order]=count";
    $body = httpGet($url, 30);
    if ($body === '') {
        $state['errors']++;
        $state['message'] = "第 {$page} 页拉取失败";
        writeState($stateFile, $state);
        $page++;
        usleep(2_000_000);
        continue;
    }
    @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} got " . strlen($body) . " bytes\n", FILE_APPEND);
    $tags = json_decode($body, true);
    if (!is_array($tags) || empty($tags)) {
        $state['message'] = "第 {$page} 页无数据，结束";
        break;
    }
    usort($tags, fn($a, $b) => ($b['post_count'] ?? 0) <=> ($a['post_count'] ?? 0));

    foreach ($tags as $t) {
        $curState = readState($stateFile);
        if ($curState['status'] === 'stopped') {
            $state['status'] = 'stopped';
            $state['message'] = "已停止（已处理 {$processed} 条）";
            writeState($stateFile, $state);
            @file_put_contents($logFile, "[" . date('H:i:s') . "] stop requested, exit\n", FILE_APPEND);
            exit(0);
        }
        $name = (string)($t['name'] ?? '');
        if ($name === '') continue;
        $postCount = (int)($t['post_count'] ?? 0);
        $cat = (int)($t['category'] ?? 0);
        $nsfw = ($t['is_deprecated'] ?? false) ? 1 : 0;
        if ($postCount < $minPosts) { $state['skipped']++; continue; }
        if (isset($done[$name])) { $state['skipped']++; continue; }
        $state['current_tag'] = $name;
        $state['progress'] = $processed;
        $processed++;

        // 翻译
        $cn = TagDict::lookup($name);
        $translatedThisTag = false;
        if ($cn === null) {
            $existingCn = Db::fetchOne("SELECT cn_name FROM tags WHERE name = ?", [$name])['cn_name'] ?? null;
            if ($existingCn) {
                $cn = $existingCn;
            } else {
                usleep(100_000);
                $r = Translator::enToZh($name);
                $cn = $r['cn'] ?? '';
                if ($cn === '' || stripos($cn, 'WARNING') !== false) $cn = null;
                else $translatedThisTag = true;
            }
        }

        // 分类
        $catName = $catNames[$cat] ?? 'General';
        $catId = $catIds[strtolower($catName)] ?? null;
        if ($catId === null) {
            $catId = (int)Db::fetchOne("SELECT id FROM tag_categories LIMIT 1")['id'];
        }

        // 写 DB
        try {
            $existing = Db::fetchOne("SELECT id FROM tags WHERE name = ?", [$name]);
            if ($existing) {
                Db::update('tags', $existing['id'], [
                    'cn_name' => $cn, 'post_count' => $postCount,
                    'category_id' => $catId, 'is_nsfw' => $nsfw,
                ]);
            } else {
                Db::insert('tags', [
                    'name' => $name, 'category_id' => $catId, 'cn_name' => $cn,
                    'post_count' => $postCount, 'is_nsfw' => $nsfw,
                ]);
                $state['added']++;
            }
            if ($translatedThisTag) $state['translated']++;
        } catch (\Throwable $e) {
            $state['errors']++;
        }

        // 记 done
        @file_put_contents($doneFile, $name . "\n", FILE_APPEND);

        // 示例图
        if ($withImages && $postCount > 0) {
            $imgUrl = downloadExampleImage($name, $previewDir, $state);
            if ($imgUrl) {
                try {
                    $row = Db::fetchOne("SELECT id FROM tags WHERE name = ?", [$name]);
                    if ($row) {
                        Db::update('tags', $row['id'], [
                            'example_image_url' => $imgUrl,
                            'fetched_at' => date('Y-m-d H:i:s'),
                        ]);
                        $state['images']++;
                    }
                } catch (\Throwable $e) {}
            }
        }

        $state['message'] = "已处理 {$processed} 条（当前：{$name}）";
        writeState($stateFile, $state);
    }
    $page++;
    usleep(500_000);
}

$state['status'] = 'finished';
$state['message'] = "完成（共处理 {$processed} 条）";
writeState($stateFile, $state);
@file_put_contents($logFile, "[" . date('H:i:s') . "] CLI done. processed={$processed}\n", FILE_APPEND);
echo "Done. processed={$processed}\n";
