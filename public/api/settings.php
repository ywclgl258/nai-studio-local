<?php
/**
 * /api/settings.php  — Get / update settings
 * GET    -> all settings (api_key_plain included; remove for non-local)
 * POST   body: any updatable fields
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Settings;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    ok_response(['settings' => Settings::get()]);
    exit;
}

if ($method === 'POST') {
    $b = read_json_body();
    // Strip out api_key_plain to avoid storing it
    $updated = Settings::update($b);
    ok_response(['settings' => $updated]);
    exit;
}

error_response('Method not allowed', 405);
