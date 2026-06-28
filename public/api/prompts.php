<?php
/**
 * /api/prompts.php  — Saved prompt presets CRUD
 * GET    ?id=N       -> single
 * GET    ?search=&favorite=&page=  -> list
 * POST                create
 * PUT    ?id=N        update
 * DELETE ?id=N        delete
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id) {
            $row = Db::fetchOne("SELECT * FROM prompts WHERE id = ?", [$id]);
            if (!$row) error_response('Not found', 404);
            if (!empty($row['tags_json'])) $row['tags_json'] = json_decode($row['tags_json'], true);
            ok_response(['prompt' => $row]);
        }
        $search   = trim($_GET['search'] ?? '');
        $fav      = !empty($_GET['favorite']);
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 30)));
        $offset   = ($page - 1) * $perPage;
        $where = '1=1'; $params = [];
        if ($search !== '') { $where .= ' AND (title LIKE ? OR positive LIKE ? OR negative LIKE ?)';
            $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
        if ($fav) $where .= ' AND is_favorite = 1';
        $total = (int)Db::fetchScalar("SELECT COUNT(*) FROM prompts WHERE $where", $params);
        $rows = Db::fetchAll("SELECT id, title, description, model, size, uc_preset, is_favorite, use_count, last_used_at, created_at, updated_at, LEFT(positive, 200) AS positive_preview FROM prompts WHERE $where ORDER BY is_favorite DESC, last_used_at DESC, id DESC LIMIT $offset, $perPage", $params);
        ok_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }
    case 'POST': {
        $b = read_json_body();
        $title = trim($b['title'] ?? '');
        if ($title === '') error_response('Title required', 400);
        $id = Db::insert('prompts', [
            'title'       => $title,
            'description' => $b['description'] ?? null,
            'positive'    => $b['positive']    ?? '',
            'negative'    => $b['negative']    ?? null,
            'tags_json'   => isset($b['tags_json']) ? json_encode($b['tags_json'], JSON_UNESCAPED_UNICODE) : null,
            'model'       => $b['model']       ?? null,
            'size'        => $b['size']        ?? null,
            'uc_preset'   => $b['uc_preset']   ?? null,
            'is_favorite' => !empty($b['is_favorite']) ? 1 : 0,
        ]);
        ok_response(['id' => $id]);
    }
    case 'PUT': {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) error_response('id required', 400);
        $b = read_json_body();
        $allowed = ['title','description','positive','negative','model','size','uc_preset','is_favorite','tags_json'];
        $data = array_intersect_key($b, array_flip($allowed));
        if (isset($data['tags_json']) && is_array($data['tags_json'])) {
            $data['tags_json'] = json_encode($data['tags_json'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['is_favorite'])) $data['is_favorite'] = !empty($data['is_favorite']) ? 1 : 0;
        if (!empty($data)) Db::update('prompts', $id, $data);
        ok_response(['id' => $id]);
    }
    case 'DELETE': {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) error_response('id required', 400);
        Db::delete('prompts', $id);
        ok_response(['id' => $id]);
    }
    default:
        error_response('Method not allowed', 405);
}
