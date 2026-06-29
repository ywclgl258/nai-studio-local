<?php
/**
 * /api/tags.php  — Tag library
 * GET ?action=categories                 -> all categories with counts
 * GET ?action=search&q=&category=&page=  -> search tags (本地 tags 表 + 分类)
 * GET ?action=local_search&q=            -> 搜本地 danbooru_tag_cache (cn_name + name LIKE)
 * GET ?action=popular&category=N         -> popular in category
 * GET ?action=lookup&names=a,b,c         -> bulk lookup
 * GET ?action=detail&name=X              -> single tag
 * GET ?action=local_list                 -> 列出本地 tags 表里所有 tag（分页，含预览图状态）
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
        // LEFT JOIN tags 拿 example_image_url（图片存在 tags 表里）
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 15)));
        if ($q === '') { ok_response(['rows' => [], 'q' => $q]); break; }
        $like = '%' . $q . '%';
        $rows = Db::fetchAll(
            "SELECT d.name, d.cn_name, d.category, d.post_count,
                    COALESCE(t.example_image_url, d.example_image_url) AS example_image_url
             FROM danbooru_tag_cache d
             LEFT JOIN tags t ON t.name = d.name
             WHERE d.name LIKE ? OR d.cn_name LIKE ?
             ORDER BY d.post_count DESC, d.name ASC
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
    case 'local_list': {
        // 列出本地 tags 表的所有 tag（分页 + 筛选 + 排序）
        // 用于标签超市的"本地缓存"tab
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 60)));
        $offset  = ($page - 1) * $perPage;

        $cid       = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;
        $hasImage  = $_GET['has_image'] ?? null;          // '1' / '0' / null=全部
        $q         = trim((string)($_GET['q'] ?? ''));    // 模糊搜 name/cn_name
        $sort      = $_GET['sort'] ?? 'popular';           // 'popular' | 'recent' | 'name' | 'random'

        $where = ['1=1'];
        $params = [];

        if ($cid !== null) {
            $where[] = 't.category_id = ?';
            $params[] = $cid;
        }
        if ($hasImage === '1') {
            $where[] = 't.example_image_url IS NOT NULL AND t.example_image_url <> ""';
        } elseif ($hasImage === '0') {
            $where[] = '(t.example_image_url IS NULL OR t.example_image_url = "")';
        }
        if ($q !== '') {
            $where[] = '(t.name LIKE ? OR t.cn_name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        // 排序
        switch ($sort) {
            case 'recent':  $orderBy = 'COALESCE(t.fetched_at, t.created_at) DESC, t.post_count DESC'; break;
            case 'name':    $orderBy = 't.name ASC'; break;
            case 'random':  $orderBy = 'RANDOM()'; break;
            case 'popular':
            default:        $orderBy = 't.post_count DESC, t.name ASC';
        }

        // 总数
        $totalRow = Db::fetchOne("SELECT COUNT(*) AS c FROM tags t WHERE $whereSql", $params);
        $total = (int)$totalRow['c'];

        // 列表（JOIN tag_categories 拿 category 中文名）
        $rows = Db::fetchAll(
            "SELECT t.name, t.cn_name, t.post_count, t.example_image_url, t.fetched_at,
                    t.category_id, c.name AS category_name, c.name_cn AS category_name_cn
             FROM tags t
             LEFT JOIN tag_categories c ON c.id = t.category_id
             WHERE $whereSql
             ORDER BY $orderBy
             LIMIT $perPage OFFSET $offset",
            $params
        );

        ok_response([
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => $offset + count($rows) < $total,
            'filters'  => [
                'category'  => $cid,
                'has_image' => $hasImage,
                'q'         => $q,
                'sort'      => $sort,
            ],
        ]);
        break;
    }
    default:
        error_response('Unknown action', 400);
}
