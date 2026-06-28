<?php
/**
 * /api/upload.php — Upload an image (for vibe/precise/base image)
 * POST multipart/form-data, field "file" (image/*)
 * Returns {path, url, info}
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Logger;
use NaiStudio\MetadataExtractor;

if (empty($_FILES['file'])) error_response('No file uploaded', 400);
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) error_response('Upload error code ' . $f['error'], 400);
if ($f['size'] > 20 * 1024 * 1024) error_response('File too large (max 20MB)', 400);

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$allowed = ['png','jpg','jpeg','webp'];
if (!in_array($ext, $allowed, true)) error_response('Unsupported file type', 400);

$uploadDir = config('paths.uploads');
$hashDir = substr(md5($f['name'] . microtime()), 0, 2);
$dir = $uploadDir . '/' . $hashDir;
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
$fileName = time() . '_' . substr(md5($f['name']), 0, 6) . '_' . $baseName . '.' . $ext;
$absPath = $dir . '/' . $fileName;

if (!move_uploaded_file($f['tmp_name'], $absPath)) error_response('Failed to save upload', 500);

$relPath = '/storage/uploads/' . $hashDir . '/' . $fileName;
$info = MetadataExtractor::fromFile($absPath);

Logger::info('upload.image', ['path' => $relPath, 'size' => filesize($absPath)]);

ok_response([
    'path' => $relPath,
    'url'  => $relPath,
    'info' => $info,
]);
