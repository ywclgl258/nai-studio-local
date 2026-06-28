<?php
/**
 * /api/cleanup.php  — One-click site cleanup
 * POST body: { level: 'all' | 'cache' | 'logs' | 'orphans' }
 *   all:     clears generations (preserves favorites) + cache + logs + orphan files
 *   cache:   only cache files
 *   logs:    only logs (older than N days)
 *   orphans: images/thumbs without DB rows
 *
 * Returns { ok, counts: { rows, files, cache, logs, orphans } }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\GalleryManager;
use NaiStudio\Logger;

$body = read_json_body();
$level = $body['level'] ?? 'all';
$keepFavorites = !empty($body['keep_favorites']);
$logRetentionDays = max(1, (int)($body['log_retention_days'] ?? 7));

$paths = config('paths');
$counts = ['rows' => 0, 'files' => 0, 'cache' => 0, 'logs' => 0, 'orphans' => 0];

function rrmdir($dir) {
    if (!is_dir($dir)) return 0;
    $n = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            if (@unlink($item->getPathname())) $n++;
        }
    }
    @rmdir($dir);
    return $n;
}

function cleanDirContents($dir) {
    if (!is_dir($dir)) return 0;
    $n = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            if (@unlink($item->getPathname())) $n++;
        }
    }
    return $n;
}

function findOrphanFiles(string $dir, array $dbPaths): array {
    if (!is_dir($dir)) return [];
    $orphans = [];
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($items as $item) {
        if ($item->isFile()) {
            $relPath = '/' . str_replace('\\', '/', ltrim(str_replace($dir, '', $item->getPathname()), '/'));
            if (!in_array($relPath, $dbPaths, true)) {
                $orphans[] = $item->getPathname();
            }
        }
    }
    return $orphans;
}

try {
    if ($level === 'all' || $level === 'rows') {
        $r = GalleryManager::clearAll(!$keepFavorites);
        $counts['rows'] = $r['count'];
        $counts['files'] += $r['files'];
    }
    if ($level === 'all' || $level === 'cache') {
        $counts['cache'] = cleanDirContents($paths['cache']);
    }
    if ($level === 'all' || $level === 'logs') {
        $logDir = $paths['logs'];
        if (is_dir($logDir)) {
            $cutoff = time() - $logRetentionDays * 86400;
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($logDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($items as $item) {
                if ($item->isFile() && $item->getMTime() < $cutoff) {
                    if (@unlink($item->getPathname())) $counts['logs']++;
                }
            }
        }
    }
    if ($level === 'all' || $level === 'orphans') {
        // Find image paths referenced in DB
        $rows = Db::fetchAll("SELECT image_path, thumbnail_path FROM generations WHERE image_path IS NOT NULL");
        $referenced = [];
        foreach ($rows as $r) {
            if ($r['image_path']) $referenced[] = $r['image_path'];
            if ($r['thumbnail_path']) $referenced[] = $r['thumbnail_path'];
        }
        // Find orphan files
        $orphans = array_merge(
            findOrphanFiles($paths['images'], $referenced),
            findOrphanFiles($paths['thumbs'], $referenced)
        );
        $counts['orphans'] = count($orphans);
        $counts['files'] += $counts['orphans'];
        foreach ($orphans as $f) @unlink($f);
    }
    Logger::info('cleanup', ['level' => $level, 'counts' => $counts]);
    ok_response(['level' => $level, 'counts' => $counts]);
} catch (Throwable $e) {
    Logger::error('cleanup.failed', ['error' => $e->getMessage()]);
    error_response('清理失败: ' . $e->getMessage(), 500);
}
