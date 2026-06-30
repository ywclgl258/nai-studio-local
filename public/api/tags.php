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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'categories';

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
        $hasCn     = $_GET['has_cn'] ?? null;             // '1' / '0' / null=全部（"已翻译"筛选）
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
        if ($hasCn === '0') {
            // 未翻译：cn_name 为空或 NULL
            $where[] = '(t.cn_name IS NULL OR TRIM(t.cn_name) = "")';
        } elseif ($hasCn === '1') {
            $where[] = '(t.cn_name IS NOT NULL AND TRIM(t.cn_name) <> "")';
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
                'has_cn'    => $hasCn,
                'q'         => $q,
                'sort'      => $sort,
            ],
        ]);
        break;
    }

    // ===== 未翻译 tag 列表（本地缓存里 cn_name 为空） =====
    case 'untranslated_list': {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 60)));
        $offset  = ($page - 1) * $perPage;
        $cid     = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;

        $where = ['(t.cn_name IS NULL OR TRIM(t.cn_name) = "")'];
        $params = [];
        if ($cid !== null) {
            $where[] = 't.category_id = ?';
            $params[] = $cid;
        }
        $whereSql = implode(' AND ', $where);

        $totalRow = Db::fetchOne("SELECT COUNT(*) AS c FROM tags t WHERE $whereSql", $params);
        $total = (int)$totalRow['c'];

        $rows = Db::fetchAll(
            "SELECT t.name, t.category_id, t.post_count, t.fetched_at, c.name_cn AS category_name_cn
             FROM tags t
             LEFT JOIN tag_categories c ON c.id = t.category_id
             WHERE $whereSql
             ORDER BY t.post_count DESC, t.name ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        ok_response([
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => $offset + count($rows) < $total,
        ]);
        break;
    }

    // ===== 重新翻译一个 tag（单条重译） =====
    case 'translate_one': {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $b = read_json_body();
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '') error_response('name required', 400);

        // 中文 → 先 zhToEn 找对应英文 tag，再用英文 tag 走正常翻译流
        $isChinese = preg_match('/[\x{4e00}-\x{9fff}]/u', $name);
        if ($isChinese) {
            $tr = \NaiStudio\Translator::zhToEn($name);
            $enGuess = $tr['en'] ?? '';
            if ($enGuess === '' || $enGuess === $name) {
                ok_response([
                    'name'   => $name,
                    'ok'     => false,
                    'reason' => 'no_english_match',
                    'hint'   => '未找到对应的英文 Danbooru tag，请手动输入英文 tag',
                ]);
                exit;
            }
            // 找到英文 → 用英文继续翻译（en → zh）
            $row = Db::fetchOne('SELECT name, cn_name FROM tags WHERE name = ?', [strtolower($enGuess)]);
            if (!$row) {
                ok_response([
                    'name'      => $name,
                    'en_guess'  => $enGuess,
                    'ok'        => false,
                    'reason'    => 'tag_not_in_local_cache',
                    'hint'      => "已翻译为英文 \"$enGuess\"，但本地标签库没有这个 tag，请先在搜索框搜这个英文名再翻译",
                ]);
                exit;
            }
            $name = $row['name']; // 切到英文名继续
        } else {
            $row = Db::fetchOne('SELECT name, cn_name FROM tags WHERE name = ?', [strtolower($name)]);
            if (!$row) error_response('tag not found', 404);
        }

        // 优先 TagDict 字典
        $cn = \NaiStudio\TagDict::lookup($name);

        // 字典没命中 → fallback 翻译 API（MyMemory / 本地）
        if ($cn === null || $cn === '') {
            try {
                $tr = \NaiStudio\Translator::enToZh($name);
                $cn = $tr['cn'] ?? null;
            } catch (Throwable $e) {
                error_log('[translate_one] translator failed: ' . $e->getMessage());
            }
        }

        if ($cn === null || $cn === '' || $cn === $name) {
            ok_response([
                'name'   => $name,
                'ok'     => false,
                'reason' => 'no_translation',
                'hint'   => '请手动纠正',
            ]);
            exit;
        }

        Db::execute('UPDATE tags SET cn_name = ?, translated_at = CURRENT_TIMESTAMP WHERE name = ?', [$cn, $name]);

        // 同步到 danbooru_tag_cache（让搜索也能命中中文）
        try {
            Db::execute(
                'UPDATE danbooru_tag_cache SET cn_name = ?, translated_at = CURRENT_TIMESTAMP WHERE name = ?',
                [$cn, $name]
            );
        } catch (Throwable $e) {}

        ok_response([
            'name'    => $name,
            'ok'      => true,
            'cn_name' => $cn,
            'source'  => 'auto',
        ]);
        break;
    }

    // ===== 手动纠正翻译（用户输入正确中文，DB 直接写） =====
    case 'manual_translate': {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $name = trim((string)($_POST['name'] ?? ''));
        $cn   = trim((string)($_POST['cn_name'] ?? ''));
        if ($name === '') error_response('name required', 400);
        if ($cn === '') error_response('cn_name required', 400);

        $row = Db::fetchOne('SELECT name FROM tags WHERE name = ?', [$name]);
        if (!$row) error_response('tag not found', 404);

        Db::execute(
            'UPDATE tags SET cn_name = ?, translated_at = CURRENT_TIMESTAMP WHERE name = ?',
            [$cn, $name]
        );

        // 同步到 danbooru_tag_cache
        try {
            Db::execute(
                'UPDATE danbooru_tag_cache SET cn_name = ?, translated_at = CURRENT_TIMESTAMP WHERE name = ?',
                [$cn, $name]
            );
        } catch (Throwable $e) {}

        ok_response([
            'name'    => $name,
            'ok'      => true,
            'cn_name' => $cn,
            'source'  => 'manual',
        ]);
        break;
    }
    default:
        error_response('Unknown action', 400);
}
