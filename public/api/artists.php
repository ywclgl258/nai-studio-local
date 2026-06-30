<?php
/**
 * /api/artists.php - 画师库 API
 *
 * GET    ?action=list            列表
 * GET    ?action=detail&id=N     详情
 * GET    ?action=search&q=...    搜索
 * GET    ?action=lookup&q=...    按 NAI 名查单条
 * POST   action=create           body: {name_noob, name_nai, name_cn, danbooru_link, notes, style, ...}
 * POST   action=update           body: {id, ...}
 * POST   action=delete           body: {id}
 * POST   action=set_categories   body: {id, category_ids:[]}
 * POST   action=autocomplete     body: {name_noob?, name_nai?}  → {name_noob, name_nai, danbooru_link}
 * POST   action=fetch            body: {id OR name_nai}  → {post_count, example_image, ...}  (调 Danbooru 抓)
 * POST   action=batch_fetch      body: {ids:[]}  → 流式 progress
 * GET    ?action=duplicates
 * GET    ?action=categories      分类列表
 * POST   action=category_create/update/delete
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/DanbooruClient.php';  // 提供 dbFetch()

use NaiStudio\ArtistManager;
use NaiStudio\DanbooruArtistFetcher;
use NaiStudio\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

file_put_contents("php://stderr", "TRACE-IN method=" . $_SERVER["REQUEST_METHOD"] . " action=" . ($_GET["action"] ?? "NULL") . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-IN method=" . $_SERVER["REQUEST_METHOD"] . " action=" . ($_GET["action"] ?? "NULL") . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-IN method=" . $_SERVER["REQUEST_METHOD"] . " action=" . ($_GET["action"] ?? "NULL") . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-IN method=" . $_SERVER["REQUEST_METHOD"] . " action=" . ($_GET["action"] ?? "NULL") . " line=" . __LINE__ . "\n"); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$b = $method === 'POST' ? read_json_body() : [];

error_log("[artists] method=$method action=$action", 0);

// ===== GET =====

file_put_contents("php://stderr", "TRACE-IF checking list\n"); file_put_contents("php://stderr", "TRACE-IF checking list\n"); file_put_contents("php://stderr", "TRACE-IF checking list\n"); file_put_contents("php://stderr", "TRACE-IF checking list\n"); if ($method === 'GET' && $action === 'list') { file_put_contents("php://stderr", "TRACE-MATCH list\n"); file_put_contents("php://stderr", "TRACE-MATCH list\n"); file_put_contents("php://stderr", "TRACE-MATCH list\n"); file_put_contents("php://stderr", "TRACE-MATCH list\n");
    error_log("[artists] matched list", 0);
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $q = trim((string)($_GET['q'] ?? ''));
    $sort = $_GET['sort'] ?? 'post_count';
    $limit = (int)($_GET['limit'] ?? 200);
    ok_response(['rows' => ArtistManager::getArtists($categoryId, $q, $sort, $limit)]);
    exit;
}

file_put_contents("php://stderr", "TRACE-IF checking detail\n"); file_put_contents("php://stderr", "TRACE-IF checking detail\n"); file_put_contents("php://stderr", "TRACE-IF checking detail\n"); file_put_contents("php://stderr", "TRACE-IF checking detail\n"); if ($method === 'GET' && $action === 'detail') { file_put_contents("php://stderr", "TRACE-MATCH detail\n"); file_put_contents("php://stderr", "TRACE-MATCH detail\n"); file_put_contents("php://stderr", "TRACE-MATCH detail\n"); file_put_contents("php://stderr", "TRACE-MATCH detail\n");
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $row = ArtistManager::getArtistById($id);
    if (!$row) error_response('artist not found', 404);
    ok_response(['row' => $row]);
    exit;
}

if ($method === 'GET' && ($action === 'search' || $action === 'lookup')) {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') ok_response(['rows' => []]);
    $rows = ArtistManager::getArtists(null, $q, 'post_count', 50);
    ok_response(['rows' => $rows]);
    exit;
}

file_put_contents("php://stderr", "TRACE-IF checking categories\n"); file_put_contents("php://stderr", "TRACE-IF checking categories\n"); file_put_contents("php://stderr", "TRACE-IF checking categories\n"); file_put_contents("php://stderr", "TRACE-IF checking categories\n"); if ($method === 'GET' && $action === 'categories') { file_put_contents("php://stderr", "TRACE-MATCH categories\n"); file_put_contents("php://stderr", "TRACE-MATCH categories\n"); file_put_contents("php://stderr", "TRACE-MATCH categories\n"); file_put_contents("php://stderr", "TRACE-MATCH categories\n");
    ok_response(['rows' => ArtistManager::getCategories()]);
    exit;
}

file_put_contents("php://stderr", "TRACE-IF checking danbooru_search\n"); file_put_contents("php://stderr", "TRACE-IF checking danbooru_search\n"); file_put_contents("php://stderr", "TRACE-IF checking danbooru_search\n"); file_put_contents("php://stderr", "TRACE-IF checking danbooru_search\n"); if ($method === 'GET' && $action === 'danbooru_search') { file_put_contents("php://stderr", "TRACE-MATCH danbooru_search\n"); file_put_contents("php://stderr", "TRACE-MATCH danbooru_search\n"); file_put_contents("php://stderr", "TRACE-MATCH danbooru_search\n"); file_put_contents("php://stderr", "TRACE-MATCH danbooru_search\n");
    // 画师库 v2：直接从 Danbooru 作者库取
    //  ?q=ciloranko  →  模糊搜索
    //  ?q=           →  热门作者（按 id 倒序的前 N 个）
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 24)));
    if ($q === '') {
        // 空 q：返回热门（按 id 倒序）
        $url = 'https://danbooru.donmai.us/artists.json?limit=' . $limit . '&search[order]=id';
    } else {
        $url = 'https://danbooru.donmai.us/artists.json?search[name_matches]=' . urlencode($q . '*') . '&limit=' . $limit;
    }
    $data = dbFetch($url);
    if ($data === null) {
        ok_response(['rows' => [], 'source' => 'danbooru_offline', 'q' => $q, 'warning' => 'Danbooru 不可达']);
        exit;
    }
    $rows = [];
    foreach ($data as $a) {
        $name = (string)($a['name'] ?? '');
        if ($name === '') continue;
        // 抓 1 张预览图（最热的帖子图）
        $exampleUrl = null;
        try {
            $postData = dbFetch('https://danbooru.donmai.us/posts.json?tags=' . urlencode('artist:' . $name) . '&limit=1&sf=random');
            if (is_array($postData) && !empty($postData)) {
                $exampleUrl = $postData[0]['preview_file_url'] ?? null;
            }
        } catch (\Throwable $e) { /* ignore */ }

        $rows[] = [
            'name'         => $name,
            'name_noob'    => 'artist:' . $name,
            'name_nai'     => $name,
            'tag_count'    => (int)($a['tag_count'] ?? 0),
            'other_names'  => $a['other_names'] ?? [],
            'example_url'  => $exampleUrl,
            'is_banned'    => !empty($a['is_banned']),
            'is_deleted'   => !empty($a['is_deleted']),
        ];
    }
    ok_response(['rows' => $rows, 'source' => 'danbooru', 'q' => $q]);
    exit;
}

file_put_contents("php://stderr", "TRACE-IF checking duplicates\n"); file_put_contents("php://stderr", "TRACE-IF checking duplicates\n"); file_put_contents("php://stderr", "TRACE-IF checking duplicates\n"); file_put_contents("php://stderr", "TRACE-IF checking duplicates\n"); if ($method === 'GET' && $action === 'duplicates') { file_put_contents("php://stderr", "TRACE-MATCH duplicates\n"); file_put_contents("php://stderr", "TRACE-MATCH duplicates\n"); file_put_contents("php://stderr", "TRACE-MATCH duplicates\n"); file_put_contents("php://stderr", "TRACE-MATCH duplicates\n");
    ok_response(['rows' => ArtistManager::findDuplicates()]);
    exit;
}

// ===== POST =====

if ($method !== 'POST') error_response('Method not allowed', 405);

if ($action === 'create') {
    $categoryIds = (array)($b['category_ids'] ?? []);
    try {
        $id = ArtistManager::createArtist($b, $categoryIds);
        ok_response(['id' => $id, 'row' => ArtistManager::getArtistById($id)]);
    } catch (\Throwable $e) {
        error_response($e->getMessage(), 400);
    }
    exit;
}

if ($action === 'update') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $categoryIds = array_key_exists('category_ids', $b) ? (array)$b['category_ids'] : null;
    ArtistManager::updateArtist($id, $b, $categoryIds);
    ok_response(['row' => ArtistManager::getArtistById($id)]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $ok = ArtistManager::deleteArtist($id);
    ok_response(['deleted' => $ok]);
    exit;
}

if ($action === 'set_categories') {
    $id = (int)($b['id'] ?? 0);
    $categoryIds = (array)($b['category_ids'] ?? []);
    if (!$id) error_response('id required', 400);
    ArtistManager::setArtistCategories($id, $categoryIds);
    ok_response(['row' => ArtistManager::getArtistById($id)]);
    exit;
}

if ($action === 'autocomplete') {
    [$noob, $nai] = ArtistManager::normalizeNames($b['name_noob'] ?? null, $b['name_nai'] ?? null);
    $link = !empty($b['danbooru_link']) ? trim($b['danbooru_link']) : ArtistManager::buildDanbooruLink($nai);
    ok_response([
        'name_noob'     => $noob,
        'name_nai'      => $nai,
        'danbooru_link' => $link,
    ]);
    exit;
}

if ($action === 'fetch') {
    $id = (int)($b['id'] ?? 0);
    if ($id) {
        $row = ArtistManager::getArtistById($id);
        if (!$row) error_response('artist not found', 404);
        $name = $row['name_nai'] ?: \NaiStudio\ArtistManager::naiFromNoob($row['name_noob'] ?? '');
    } else {
        $name = trim((string)($b['name_nai'] ?? ''));
    }
    if (!$name) error_response('name_nai or id required', 400);

    try {
        $r = DanbooruArtistFetcher::fetchOne($name);
        // 尝试下载到本地
        $localPath = null;
        if (!empty($r['example_image'])) {
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.jpg';
            $rel = '/storage/artist_images/' . $filename;
            if (DanbooruArtistFetcher::downloadExampleImage($r['example_image'], $rel)) {
                $localPath = $rel;
            }
        }
        // 写回 DB
        if ($id) {
            ArtistManager::updateArtist($id, [
                'post_count'         => $r['post_count'],
                'example_post_id'    => $r['example_post_id'],
                'example_image_url'  => $r['example_image'],
                'example_image_path' => $localPath,
                'fetched_at'         => date('Y-m-d H:i:s'),
            ]);
        }
        ok_response(['data' => $r, 'local_path' => $localPath]);
    } catch (\Throwable $e) {
        error_response($e->getMessage(), 500);
    }
    exit;
}

if ($action === 'category_create') {
    $name = trim((string)($b['name'] ?? ''));
    if (!$name) error_response('name required', 400);
    try {
        $id = ArtistManager::createCategory($name, (int)($b['display_order'] ?? 0));
        ok_response(['id' => $id]);
    } catch (\Throwable $e) {
        error_response($e->getMessage(), 400);
    }
    exit;
}

if ($action === 'category_update') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    ArtistManager::updateCategory($id, $b['name'] ?? null, isset($b['display_order']) ? (int)$b['display_order'] : null);
    ok_response();
    exit;
}

if ($action === 'category_delete') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    ok_response(['deleted' => ArtistManager::deleteCategory($id)]);
    exit;
}

file_put_contents("php://stderr", "TRACE-FALLTHROUGH action=" . $action . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-FALLTHROUGH action=" . $action . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-FALLTHROUGH action=" . $action . " line=" . __LINE__ . "\n"); file_put_contents("php://stderr", "TRACE-FALLTHROUGH action=" . $action . " line=" . __LINE__ . "\n"); error_response('Unknown action: ' . $action, 400);
