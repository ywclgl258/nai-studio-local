<?php
/**
 * 后台导入 Danbooru 全部标签
 *  - 拉全量 (默认 500 页 × 1000 = 50 万 tag 上限)
 *  - 翻译: TagDict 内置字典秒级命中; 其余留英文 (MyMemory 免费额度翻译 30 万 tag 不现实)
 *  - 示例图: 默认关闭 (拉图占 90% 时间)
 *
 * 用法:
 *   POST /api/admin/import-all-tags.php
 *   { "action": "start", "min_posts": 1, "max_pages": 500 }
 *   { "action": "status" }
 *   { "action": "stop" }
 *
 * 也支持 CLI:  php import-all-tags.php --min_posts=1 --max_pages=500
 *
 * 状态: storage/cache/import-status.json (实时)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\TagDict;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$stateFile  = __DIR__ . '/../../../storage/cache/import-status.json';
$doneFile   = __DIR__ . '/../../../storage/cache/import-done.txt';
$logFile    = __DIR__ . '/../../../storage/cache/import.log';
$previewDir = __DIR__ . '/../../../storage/tag-previews';
@mkdir(dirname($stateFile), 0777, true);
@mkdir($previewDir, 0777, true);

function readState(string $file): array {
    if (!file_exists($file)) return ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : ['status' => 'idle', 'progress' => 0, 'total' => 0, 'message' => ''];
}
function writeState(string $file, array $state): void {
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function httpGet(string $url, int $timeout = 30): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local-tag-import)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    if (curl_errno($ch)) {
        @error_log('[import-all-tags] ' . curl_error($ch) . ' ' . $url);
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return $body === false ? null : (string)$body;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;

// CLI 模式：默认直接跑 job (除非显式传 status/stop)
if (PHP_SAPI === 'cli') {
    // CLI 参数解析
    $cliArgs = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) $cliArgs[$m[1]] = $m[2];
    }
    $cliAction = $cliArgs['action'] ?? 'start';
    if ($cliAction === 'status') { echo json_encode(readState($stateFile), JSON_UNESCAPED_UNICODE); exit(0); }
    if ($cliAction === 'stop') {
        $s = readState($stateFile);
        $s['status'] = 'stopped'; $s['message'] = '已请求停止';
        writeState($stateFile, $s);
        echo json_encode($s, JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    // 默认 start: 跑
    $minPosts   = max(1, (int)($cliArgs['min_posts'] ?? 1));
    $maxPages   = max(1, min(2000, (int)($cliArgs['max_pages'] ?? 500)));
    $withImages = (bool)($cliArgs['with_images'] ?? false);
    $pageDelayMs = 600;
    set_time_limit(0);
    runJob($minPosts, $maxPages, $withImages, $pageDelayMs);
    exit(0);
}

if (!$action) { error_response('Missing action', 400); exit; }
if ($action === 'status') { ok_response(readState($stateFile)); exit; }
if ($action === 'stop') {
    $s = readState($stateFile);
    $s['status'] = 'stopped'; $s['message'] = '已请求停止';
    writeState($stateFile, $s);
    ok_response($s); exit;
}

if ($action !== 'start') { error_response('Unknown action: ' . $action, 400); exit; }

// ===== 解析参数（web + cli 都用） =====
$cliArgs = [];
if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) $cliArgs[$m[1]] = $m[2];
    }
}
$minPosts   = max(1, (int)($cliArgs['min_posts'] ?? $_POST['min_posts'] ?? 1));
$maxPages   = max(1, min(2000, (int)($cliArgs['max_pages'] ?? $_POST['max_pages'] ?? 500)));
$withImages = (bool)($cliArgs['with_images'] ?? ($_POST['with_images'] ?? false));
$pageDelayMs = 600;

// ===== CLI 模式：直接跑 job =====
if (PHP_SAPI === 'cli') {
    set_time_limit(0);
    runJob($minPosts, $maxPages, $withImages, $pageDelayMs);
    exit(0);
}

// ===== Web 模式 =====
set_time_limit(30);
$self = __FILE__;
$php = PHP_BINARY ?: 'php';
$cliLog = __DIR__ . '/../../../storage/cache/import-cli.log';

$sapi = PHP_SAPI ?? '';
$canSpawn = in_array($sapi, ['cli-server', 'cli'], true);

if ($canSpawn) {
    // PHP built-in server / CLI: 用 vbs 包裹启动 CLI 进程，HTTP 立即返回
    $stateFileEsc = '"' . str_replace('/', '\\', $stateFile) . '"';
    $cliLogEsc    = '"' . str_replace('/', '\\', $cliLog) . '"';
    $cmd = sprintf(
        '"%s" "%s" --min_posts=%d --max_pages=%d --with_images=%d --cli',
        str_replace('/', '\\', $php),
        str_replace('/', '\\', $self),
        $minPosts, $maxPages, $withImages ? 1 : 0
    );
    // 写状态
    $s = readState($stateFile);
    $s['status'] = 'running'; $s['started_at'] = date('Y-m-d H:i:s');
    $s['message'] = '已 spawn 后台进程';
    writeState($stateFile, $s);

    $vbs = sys_get_temp_dir() . '\\nai_import_cli.vbs';
    file_put_contents($vbs, "Set WshShell = CreateObject(\"WScript.Shell\")\r\nWshShell.Run \"\"\"cmd.exe\"\" /c \"\"\"$cmd > $cliLogEsc 2>&1\"\"\"\", 0, False\r\n");
    pclose(popen('cmd /c start /min "" wscript "' . $vbs . '"', 'r'));

    ok_response([
        'ok'      => true,
        'method'  => 'detached_cli',
        'message' => '已启动后台进程（独立 PHP 进程，不阻塞 server）',
        'log'     => $cliLog,
        'state'   => $stateFile,
        'state_now' => readState($stateFile),
    ]);
    exit;
}

// 兜底：mod_php / Apache（不能 spawn），让用户手动跑
$cmd = sprintf(
    'cd /d "%s" && "%s" "%s" --min_posts=%d --max_pages=%d --cli',
    str_replace('/', '\\', dirname($self, 3)),
    $php,
    $self,
    $minPosts,
    $maxPages
);

ok_response([
    'ok'      => true,
    'method'  => 'manual_cli',
    'reason'  => 'mod_php (Apache) 不支持 spawn 后台进程',
    'command' => $cmd,
    'log'     => $cliLog,
    'state'   => $stateFile,
    'message' => '请在终端跑下面命令（独立进程, 可关浏览器）',
    'note'    => '进度查 status，或在终端看 ' . $cliLog,
]);
exit;

// =================================================================
// CLI 实际跑的工作
// =================================================================
function runJob(int $minPosts, int $maxPages, bool $withImages, int $pageDelayMs): void {
    global $stateFile, $doneFile, $logFile;

    // 已处理 (跨进程跳过)
    $done = [];
    if (file_exists($doneFile)) {
        $done = array_flip(array_filter(array_map('trim', file($doneFile) ?: [])));
    }

    // 分类映射
    $catNames = ['General', 'Artist', 'Copyright', 'Character', 'Meta'];
    $catMap = [];
    foreach (Db::fetchAll("SELECT id, name FROM tag_categories") as $c) {
        $catMap[strtolower($c['name'])] = (int)$c['id'];
    }
    $defaultCat = $catMap['general'] ?? (int)Db::fetchOne("SELECT id FROM tag_categories LIMIT 1")['id'];

    $state = [
        'status'        => 'running',
        'started_at'    => date('Y-m-d H:i:s'),
        'progress'      => 0,
        'pages_done'     => 0,
        'pages_total'    => $maxPages,
        'fetched'        => 0,
        'added'          => 0,
        'updated'        => 0,
        'translated'     => 0,
        'skipped'        => 0,
        'images'         => 0,
        'errors'         => 0,
        'rate_per_sec'   => 0,
        'current_page'   => 0,
        'current_tag'    => '',
        'last_error'     => '',
        'message'        => "启动：拉 {$maxPages} 页，每页间隔 {$pageDelayMs}ms",
    ];
    writeState($stateFile, $state);

    @file_put_contents($logFile, "[" . date('H:i:s') . "] start min_posts={$minPosts} max_pages={$maxPages} with_images=" . ($withImages ? '1' : '0') . "\n", FILE_APPEND);

    $startTime = microtime(true);
    $page = 1;
    $totalProcessed = 0;

    while ($page <= $maxPages) {
        // 检查停止
        $cur = readState($stateFile);
        if ($cur['status'] === 'stopped') {
            $state['status'] = 'stopped';
            $state['message'] = "已停止（已处理 {$totalProcessed}）";
            writeState($stateFile, $state);
            @file_put_contents($logFile, "[" . date('H:i:s') . "] stopped\n", FILE_APPEND);
            return;
        }

        $url = "https://danbooru.donmai.us/tags.json?limit=1000&page={$page}";
        $body = httpGet($url, 30);
        if ($body === '' || $body === null) {
            $state['errors']++;
            $state['last_error'] = "page {$page} fetch fail";
            writeState($stateFile, $state);
            @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} fail\n", FILE_APPEND);
            $page++;
            usleep($pageDelayMs * 1000);
            continue;
        }
        $tags = json_decode($body, true);
        if (!is_array($tags) || empty($tags)) {
            $state['message'] = "第 {$page} 页无数据（已到末尾），完成";
            writeState($stateFile, $state);
            @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} empty → end\n", FILE_APPEND);
            break;
        }

        @file_put_contents($logFile, "[" . date('H:i:s') . "] page {$page} got " . count($tags) . " tags\n", FILE_APPEND);

        foreach ($tags as $t) {
            $name = (string)($t['name'] ?? '');
            if ($name === '') continue;
            // 截断超长 name (字段 VARCHAR(128))
            if (strlen($name) > 128) {
                $state['skipped']++;
                continue;
            }
            $postCount = (int)($t['post_count'] ?? 0);
            $catIdx = (int)($t['category'] ?? 0);
            $catName = $catNames[$catIdx] ?? 'General';
            $catId = $catMap[strtolower($catName)] ?? $defaultCat;
            $isNsfw = !empty($t['is_deprecated']) ? 1 : 0;

            if ($postCount < $minPosts) { $state['skipped']++; continue; }
            if (isset($done[$name])) { $state['skipped']++; continue; }

            // 翻译：先查内置 dict (~500+ 常见), miss 留英文 (cn=null)
            $cn = TagDict::lookup($name);
            if ($cn !== null) $state['translated']++;

            $state['fetched']++;
            $state['current_tag'] = $name;
            $totalProcessed++;

            // 一次性 upsert
            $sql = "INSERT INTO tags (name, category_id, cn_name, post_count, is_nsfw, fetched_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        category_id = VALUES(category_id),
                        cn_name = COALESCE(NULLIF(cn_name, ''), VALUES(cn_name)),
                        post_count = VALUES(post_count),
                        is_nsfw = VALUES(is_nsfw),
                        fetched_at = NOW()";
            try {
                Db::pdo()->prepare($sql)->execute([$name, $catId, $cn, $postCount, $isNsfw]);
                $state['added']++;
            } catch (\Throwable $e) {
                $state['errors']++;
                $state['last_error'] = $e->getMessage();
            }
            file_put_contents($doneFile, $name . "\n", FILE_APPEND);
            $done[$name] = true;

            // 进度写盘 (每 500 条)
            if ($totalProcessed % 500 === 0) {
                $elapsed = microtime(true) - $startTime;
                $rate = $elapsed > 0 ? $totalProcessed / $elapsed : 0;
                $state['progress'] = $totalProcessed;
                $state['pages_done'] = $page;
                $state['rate_per_sec'] = round($rate, 1);
                $state['current_page'] = $page;
                $state['message'] = "第 {$page}/{$maxPages} 页 · {$state['added']} 条已存 · {$state['translated']} 已翻译 · " . round($rate) . ' 条/s';
                writeState($stateFile, $state);
                @file_put_contents($logFile, "[" . date('H:i:s') . "] progress page={$page} added={$state['added']} rate=" . round($rate) . "/s\n", FILE_APPEND);
            }
        }

        $page++;
        usleep($pageDelayMs * 1000);
    }

    $totalInDb = (int)Db::pdo()->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    $translatedInDb = (int)Db::pdo()->query("SELECT COUNT(*) FROM tags WHERE cn_name IS NOT NULL AND cn_name <> ''")->fetchColumn();
    $state['status'] = 'done';
    $state['message'] = "完成！拉取 {$state['fetched']} 条，DB 总 {$totalInDb} 条，已翻译 {$translatedInDb} 条";
    writeState($stateFile, $state);
    @file_put_contents($logFile, "[" . date('H:i:s') . "] done. total_in_db={$totalInDb}\n", FILE_APPEND);
}
