<?php
/**
 * /api/character_presets.php  — Character presets CRUD
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

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $row = Db::fetchOne("SELECT * FROM character_presets WHERE id = ?", [$id]);
        if (!$row) error_response('Not found', 404);
        ok_response(['preset' => $row]);
    }
    $search   = trim($_GET['search'] ?? '');
    $fav      = !empty($_GET['favorite']);
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $perPage;
    $where = '1=1'; $params = [];
    if ($search !== '') { $where .= ' AND (name LIKE ? OR prompt LIKE ?)';
        $params = array_merge($params, ["%$search%","%$search%"]); }
    if ($fav) $where .= ' AND is_favorite = 1';
    $total = (int)Db::fetchScalar("SELECT COUNT(*) FROM character_presets WHERE $where", $params);
    $rows = Db::fetchAll("SELECT id, name, gender, position_x, position_y, is_favorite, use_count, created_at, updated_at, prompt FROM character_presets WHERE $where ORDER BY is_favorite DESC, use_count DESC, id DESC LIMIT $offset, $perPage", $params);
    ok_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    exit;
}

if ($method === 'POST') {
    $b = read_json_body();
    $name = trim($b['name'] ?? '');
    if ($name === '') error_response('Name required', 400);
    $id = Db::insert('character_presets', [
        'name'       => $name,
        'gender'     => in_array($b['gender'] ?? '', ['female','male','other']) ? $b['gender'] : 'female',
        'prompt'     => $b['prompt'] ?? '',
        'position_x' => max(0, min(1, (float)($b['position_x'] ?? 0.5))),
        'position_y' => max(0, min(1, (float)($b['position_y'] ?? 0.5))),
        'is_favorite' => !empty($b['is_favorite']) ? 1 : 0,
    ]);
    ok_response(['id' => $id]);
    exit;
}

if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $b = read_json_body();
    $allowed = ['name','gender','prompt','position_x','position_y','is_favorite'];
    $data = array_intersect_key($b, array_flip($allowed));
    if (isset($data['is_favorite'])) $data['is_favorite'] = !empty($data['is_favorite']) ? 1 : 0;
    if (!empty($data)) Db::update('character_presets', $id, $data);
    ok_response(['id' => $id]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    Db::delete('character_presets', $id);
    ok_response(['id' => $id]);
    exit;
}

error_response('Method not allowed', 405);
