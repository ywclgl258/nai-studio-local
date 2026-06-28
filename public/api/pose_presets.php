<?php
/**
 * /api/pose_presets.php  — Pose prompt presets CRUD
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $row = Db::fetchOne("SELECT * FROM pose_presets WHERE id = ?", [$id]);
        if (!$row) error_response('Not found', 404);
        ok_response(['preset' => $row]);
    }
    $search   = trim($_GET['search'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $fav      = !empty($_GET['favorite']);
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $perPage;
    $where = '1=1'; $params = [];
    if ($search !== '') { $where .= ' AND (name LIKE ? OR prompt LIKE ?)';
        $params = array_merge($params, ["%$search%","%$search%"]); }
    if ($category !== '') { $where .= ' AND category = ?'; $params[] = $category; }
    if ($fav) $where .= ' AND is_favorite = 1';
    $total = (int)Db::fetchScalar("SELECT COUNT(*) FROM pose_presets WHERE $where", $params);
    $rows = Db::fetchAll("SELECT id, name, prompt, category, is_favorite, use_count, created_at, updated_at FROM pose_presets WHERE $where ORDER BY is_favorite DESC, use_count DESC, id DESC LIMIT $offset, $perPage", $params);
    ok_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    exit;
}

if ($method === 'POST') {
    $b = read_json_body();
    $name = trim($b['name'] ?? '');
    $prompt = trim($b['prompt'] ?? '');
    if ($name === '' || $prompt === '') error_response('name and prompt required', 400);
    $id = Db::insert('pose_presets', [
        'name'        => $name,
        'prompt'      => $prompt,
        'category'    => $b['category'] ?? null,
        'is_favorite' => !empty($b['is_favorite']) ? 1 : 0,
    ]);
    ok_response(['id' => $id]);
    exit;
}

if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $b = read_json_body();
    $allowed = ['name','prompt','category','is_favorite'];
    $data = array_intersect_key($b, array_flip($allowed));
    if (isset($data['is_favorite'])) $data['is_favorite'] = !empty($data['is_favorite']) ? 1 : 0;
    if (!empty($data)) Db::update('pose_presets', $id, $data);
    ok_response(['id' => $id]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    Db::delete('pose_presets', $id);
    ok_response(['id' => $id]);
    exit;
}

error_response('Method not allowed', 405);
