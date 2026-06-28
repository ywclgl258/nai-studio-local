<?php
require 'D:\anima\nai-studio\src\bootstrap.php';
$rows = \NaiStudio\GalleryManager::listForZip([], 5);
echo "Got " . count($rows) . " rows\n";
foreach ($rows as $r) {
    $abs = rtrim(config('paths.storage'), '/') . '/' . ltrim($r['image_path'], '/');
    echo sprintf("id=%d image_path=%s abs=%s exists=%s\n",
        $r['id'], $r['image_path'], $abs, is_file($abs) ? 'YES' : 'NO');
}