<?php
/**
 * GET  /api/anlas.php — query anlas balance
 * POST /api/anlas.php — same, force refresh
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Logger;
use NaiStudio\NaiApi;
use NaiStudio\Settings;

$apiKey = Settings::getApiKey();
if (!$apiKey) error_response('API key not configured', 401);

$api = new NaiApi($apiKey, config('nai.proxy'));
$result = $api->getAnlas();
if (!$result['ok']) {
    Logger::warn('nai.anlas.fail', ['error' => $result['error']]);
    error_response($result['error'] ?? 'Failed', 502);
}

Settings::setAnlas($result['anlas']);
ok_response([
    'anlas'     => $result['anlas'],
    'expiresAt' => $result['expiresAt'],
    'tier'      => $result['tier'],
]);
