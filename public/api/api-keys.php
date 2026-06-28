<?php
/**
 * /api/api-keys.php — Manage NAI API keys (multi-key rotation)
 * GET    -> list all (with fingerprints, no plain keys)
 * POST   body: {action: "add", key: "sk-...", label: "..."}
 *         body: {action: "delete", id: 1}
 *         body: {action: "set_enabled", id: 1, enabled: true}
 *         body: {action: "set_label", id: 1, label: "..."}
 *         body: {action: "reorder", ids: [3,1,2]}
 *         body: {action: "reset_error", id: 1}
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\ApiKeyManager;
use NaiStudio\Logger;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $method === 'POST' ? read_json_body() : [];

if ($method === 'GET') {
    ok_response(['keys' => ApiKeyManager::list(), 'count' => ApiKeyManager::count()]);
    exit;
}

$action = $body['action'] ?? '';
try {
    switch ($action) {
        case 'add':
            $key = (string)($body['key'] ?? '');
            $label = isset($body['label']) ? trim((string)$body['label']) : null;
            if ($label === '') $label = null;
            $new = ApiKeyManager::add($key, $label);
            Logger::info('api_keys.add', ['id' => $new['id'] ?? null, 'fingerprint' => $new['fingerprint'] ?? null]);
            ok_response(['key' => $new]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) error_response('id required', 400);
            ApiKeyManager::delete($id);
            ok_response(['deleted' => $id]);
            break;

        case 'set_enabled':
            $id = (int)($body['id'] ?? 0);
            $enabled = !empty($body['enabled']);
            if ($id <= 0) error_response('id required', 400);
            ApiKeyManager::setEnabled($id, $enabled);
            ok_response(['id' => $id, 'enabled' => $enabled]);
            break;

        case 'set_label':
            $id = (int)($body['id'] ?? 0);
            $label = isset($body['label']) ? trim((string)$body['label']) : null;
            if ($id <= 0) error_response('id required', 400);
            ApiKeyManager::setLabel($id, $label);
            ok_response(['id' => $id, 'label' => $label]);
            break;

        case 'reorder':
            $ids = $body['ids'] ?? [];
            if (!is_array($ids)) error_response('ids must be array', 400);
            ApiKeyManager::reorder(array_map('intval', $ids));
            ok_response(['reordered' => true]);
            break;

        case 'reset_error':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) error_response('id required', 400);
            ApiKeyManager::resetErrors($id);
            ok_response(['id' => $id]);
            break;

        default:
            error_response('Unknown action: ' . $action, 400);
    }
} catch (\Throwable $e) {
    error_response($e->getMessage(), 400);
}
