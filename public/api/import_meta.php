<?php
/**
 * /api/import_meta.php
 * POST body: {path:'/storage/uploads/.../foo.png'} or {base64:'...'}
 * Extracts NAI / SD-style metadata and returns parsed fields.
 * Optional: also creates a generation record (action=save_as_generation).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\GalleryManager;
use NaiStudio\Logger;
use NaiStudio\MetadataExtractor;

$b = read_json_body();
$path = $b['path'] ?? null;
$base64 = $b['base64'] ?? null;
$save = !empty($b['save_as_generation']);

$abs = null;
if ($path) {
    $abs = config('paths.public') . $path;
    if (!is_file($abs)) error_response('File not found: ' . $path, 404);
} elseif ($base64) {
    if (preg_match('#^data:image/\w+;base64,(.+)$#', $base64, $m)) $base64 = $m[1];
    $uploadDir = config('paths.uploads');
    $hashDir = substr(md5($base64), 0, 2);
    $dir = $uploadDir . '/' . $hashDir;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fileName = 'imported_' . time() . '.png';
    $abs = $dir . '/' . $fileName;
    file_put_contents($abs, base64_decode($base64));
    $path = '/storage/uploads/' . $hashDir . '/' . $fileName;
} else {
    error_response('path or base64 required', 400);
}

$info = MetadataExtractor::fromFile($abs);

$genId = null;
if ($save) {
    $b64 = base64_encode(file_get_contents($abs));
    $params = [
        'prompt'           => $info['prompt'] ?? '(imported)',
        'negative_prompt'  => $info['negative'] ?? null,
        'model'            => $info['model'] ?? 'unknown',
        'sampler'          => $info['sampler'] ?? 'unknown',
        'steps'            => $info['steps'] ?? 28,
        'scale'            => $info['scale'] ?? 5.0,
        'seed'             => $info['seed'] ?? 0,
        'width'            => $info['width'] ?? 0,
        'height'           => $info['height'] ?? 0,
        'cfg_rescale'      => $info['cfg_rescale'] ?? 0.0,
        'noise_schedule'   => $info['noise_schedule'] ?? 'karras',
        'uc_preset'        => 0,
        'quality_toggle'   => 1,
        'operation'        => 'img2img',
        'meta_json'        => ['imported' => true, 'source_path' => $path],
    ];
    $r = GalleryManager::saveImage($b64, $params);
    $genId = $r['id'];
}

Logger::info('import.meta', ['path' => $path, 'prompt_len' => strlen($info['prompt'] ?? '')]);

ok_response([
    'info' => $info,
    'path' => $path,
    'generation_id' => $genId,
]);
