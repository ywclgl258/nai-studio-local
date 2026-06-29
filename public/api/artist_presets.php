<?php
/**
 * /api/artist_presets.php - 画师串预设 API
 *
 * GET    ?action=list                列表
 * GET    ?action=detail&id=N         详情（含 items）
 * POST   action=create               body: {name, noob_text, nai_text, description, artist_ids:[]}
 * POST   action=update               body: {id, ...}
 * POST   action=delete               body: {id}
 * POST   action=use                  body: {id}  → use_count++, 返回完整 noob/nai 文本
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\ArtistManager;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$b = $method === 'POST' ? read_json_body() : [];

if ($method === 'GET' && $action === 'list') {
    ok_response(['rows' => ArtistManager::getPresets()]);
    exit;
}

if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $row = ArtistManager::getPresetById($id);
    if (!$row) error_response('preset not found', 404);
    ok_response(['row' => $row]);
    exit;
}

if ($method !== 'POST') error_response('Method not allowed', 405);

if ($action === 'create') {
    try {
        $id = ArtistManager::createPreset(
            $b['name'] ?? '',
            $b['noob_text'] ?? '',
            $b['nai_text'] ?? '',
            $b['description'] ?? null,
            isset($b['category_id']) ? (int)$b['category_id'] : null,
            (array)($b['artist_ids'] ?? [])
        );
        ok_response(['id' => $id, 'row' => ArtistManager::getPresetById($id)]);
    } catch (\Throwable $e) {
        error_response($e->getMessage(), 400);
    }
    exit;
}

if ($action === 'update') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $artistIds = array_key_exists('artist_ids', $b) ? (array)$b['artist_ids'] : null;
    ArtistManager::updatePreset($id, $b, $artistIds);
    ok_response(['row' => ArtistManager::getPresetById($id)]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    ok_response(['deleted' => ArtistManager::deletePreset($id)]);
    exit;
}

if ($action === 'use') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    ArtistManager::incrementPresetUseCount($id);
    $row = ArtistManager::getPresetById($id);
    if (!$row) error_response('preset not found', 404);
    ok_response([
        'noob_text' => $row['noob_text'],
        'nai_text'  => $row['nai_text'],
        'row'       => $row,
    ]);
    exit;
}

error_response('Unknown action: ' . $action, 400);
