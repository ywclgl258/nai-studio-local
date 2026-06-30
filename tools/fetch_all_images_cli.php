<?php
/**
 * CLI 端：批量预下载标签示例图
 *
 * Usage:
 *   php tools/fetch_all_images_cli.php --limit=500
 *
 * 状态: --state-file=<path>  默认 storage/cache/fetch_all_images-status.json
 * 日志: --log-file=<path>    默认 storage/logs/fetch_all_images.log
 *
 * v1.1.4+：从 public/api/admin/fetch_all_images.php 拆出来
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/lib/Db.php';

use NaiStudio\Db;

$opts = getopt('', ['limit::', 'state-file::', 'log-file::', 'root::']);
$limit     = max(1, min(50000, (int)($opts['limit'] ?? 1000)));
$stateFile = $opts['state-file'] ?? __DIR__ . '/../storage/cache/fetch_all_images-status.json';
$logFile   = $opts['log-file']   ?? __DIR__ . '/../storage/logs/fetch_all_images.log';
$root      = $opts['root']       ?? realpath(__DIR__ . '/..');
$previewDir = $root . '/storage/tag-previews';
@mkdir(dirname($stateFile), 0775, true);
@mkdir(dirname($logFile), 0775, true);
@mkdir($previewDir, 0775, true);

set_time_limit(0);

function writeState(string $file, array $s): void { @file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE)); }
function readState(string $file): array {
    if (!file_exists($file)) return ['status' => 'idle'];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : ['status' => 'idle'];
}
function httpGet(string $url, int $timeout = 30): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'NAI-Studio/1.1 (cli)', CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return false;
    return $body;
}

$state = readState($stateFile);
$state['status'] = 'running';
$state['started_at'] = date('Y-m-d H:i:s');
$state['limit'] = $limit;
$state['progress'] = 0;
$state['message'] = '查询待抓 tag';
writeState($stateFile, $state);

$stmt = Db::pdo()->prepare("
    SELECT id, name, post_count FROM tags
    WHERE example_image_url IS NULL OR example_image_url = ''
    ORDER BY post_count DESC LIMIT :lim
");
$stmt->bindValue('lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

$state['total'] = count($tags);
$state['message'] = "开始抓 {$state['total']} 个 tag";
writeState($stateFile, $state);

if (empty($tags)) {
    $state['status'] = 'finished';
    $state['message'] = '没有需要抓的 tag';
    writeState($stateFile, $state);
    exit;
}

$ok = 0; $fail = 0; $noPosts = 0; $skip = 0;
$startTime = time();
foreach ($tags as $i => $t) {
    $curState = readState($stateFile);
    if ($curState['status'] === 'stopped') {
        $state['status'] = 'stopped';
        $state['message'] = "已停止（已处理 " . ($i+1) . "）";
        writeState($stateFile, $state);
        exit(0);
    }
    $tagName = $t['name'];
    $hash = substr(md5($tagName), 0, 2);
    $subdir = "$previewDir/$hash";
    $fname = $subdir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $tagName) . '.jpg';
    $relUrl = "/storage/tag-previews/$hash/" . basename($fname);
    $status = ''; $err = '';
    if (file_exists($fname) && filesize($fname) > 1000) {
        try { Db::execute('UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?', [$relUrl, (int)$t['id']]); } catch (Throwable $e) {}
        $skip++; $status = 'skip';
    } else {
        $apiUrl = 'https://danbooru.donmai.us/posts.json?tags=' . urlencode($tagName) . '&limit=1&random=true';
        $body = httpGet($apiUrl, 15);
        if ($body === false || $body === '') { $fail++; $status = 'fail'; $err = 'network'; }
        else {
            $posts = json_decode($body, true);
            if (!is_array($posts) || empty($posts)) { $noPosts++; $status = 'noPosts'; }
            else {
                $previewUrl = $posts[0]['preview_file_url'] ?? null;
                if (!$previewUrl) { $fail++; $status = 'fail'; $err = 'no_preview_url'; }
                else {
                    $img = httpGet($previewUrl, 20);
                    if (strlen($img) < 500) { $fail++; $status = 'fail'; $err = 'img_too_small'; }
                    else {
                        if (!is_dir($subdir)) @mkdir($subdir, 0775, true);
                        file_put_contents($fname, $img);
                        try { Db::execute('UPDATE tags SET example_image_url = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?', [$relUrl, (int)$t['id']]); } catch (Throwable $e) {}
                        $ok++; $status = 'ok';
                    }
                }
            }
        }
    }
    $state['progress'] = $i + 1;
    $state['last'] = ['name' => $tagName, 'status' => $status, 'err' => $err];
    $state['stats'] = ['ok' => $ok, 'fail' => $fail, 'noPosts' => $noPosts, 'skip' => $skip, 'elapsed' => time() - $startTime];
    writeState($stateFile, $state);
    @file_put_contents($logFile, sprintf("[%s] %d/%d %s %s%s\n",
        date('H:i:s'), $i+1, count($tags), $tagName, $status, $err ? " err=$err" : ''
    ), FILE_APPEND);
    usleep(550_000);
}
$state['status'] = 'finished';
$state['message'] = "完成 ok=$ok fail=$fail noPosts=$noPosts skip=$skip";
writeState($stateFile, $state);
echo "Done. ok=$ok fail=$fail noPosts=$noPosts skip=$skip\n";
