<?php
/**
 * /api/tags.php  — Tag library
 * GET ?action=categories                 -> all categories with counts
 * GET ?action=search&q=&category=&page=  -> search tags (本地 tags 表 + 分类)
 * GET ?action=local_search&q=            -> 搜本地 danbooru_tag_cache (cn_name + name LIKE)
 * GET ?action=popular&category=N         -> popular in category
 * GET ?action=lookup&names=a,b,c         -> bulk lookup
 * GET ?action=detail&name=X              -> single tag
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\TagManager;
use NaiStudio\Db;

$action = $_GET['action'] ?? 'categories';

switch ($action) {
    case 'categories':
        ok_response(['rows' => TagManager::categories()]);
        break;
    case 'search': {
        $q = trim((string)($_GET['q'] ?? ''));
        $cid = isset($_GET['category']) ? (int)$_GET['category'] : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 60);
        $offset = ($page - 1) * $perPage;
        $rows = TagManager::search($q, $cid, $perPage, $offset);
        ok_response([
            'rows'     => $rows,
            'page'     => $page,
            'per_page' => $perPage,
            'q'        => $q,
        ]);
        break;
    }
    case 'local_search': {
        // 搜本地缓存 danbooru_tag_cache (cn_name + name)
        // 用于标签超市"输入时立即下拉"
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 15)));
        if ($q === '') { ok_response(['rows' => [], 'q' => $q]); break; }
        $like = '%' . $q . '%';
        $rows = Db::fetchAll(
            "SELECT name, cn_name, category, post_count, example_image_url
             FROM danbooru_tag_cache
             WHERE name LIKE ? OR cn_name LIKE ?
             ORDER BY post_count DESC, name ASC
             LIMIT $limit",
            [$like, $like]
        );
        ok_response(['rows' => $rows, 'q' => $q]);
        break;
    }
    case 'popular': {
        $cid = (int)($_GET['category'] ?? 0);
        if (!$cid) error_response('category required', 400);
        $limit = (int)($_GET['limit'] ?? 60);
        $rows = TagManager::popularInCategory($cid, $limit);
        ok_response(['rows' => $rows]);
        break;
    }
    case 'lookup': {
        $names = preg_split('/[,，\s]+/', (string)($_GET['names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $rows = TagManager::lookupByNames($names);
        ok_response(['rows' => $rows]);
        break;
    }
    case 'detail': {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') error_response('name required', 400);
        $row = TagManager::getByName($name);
        if (!$row) error_response('Not found', 404);
        ok_response(['tag' => $row]);
        break;
    }
    default:
        error_response('Unknown action', 400);
}
