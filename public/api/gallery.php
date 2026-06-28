<?php
/**
 * /api/gallery.php — Generation history
 * GET    ?id=N                  -> single with full metadata
 * GET    ?page=&per_page=&...   -> list
 * POST   body: {action:favorite, id, value} | {action:notes, id, value}
 * DELETE ?id=N
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\GalleryManager;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'zip') {
        // ====== 一键打包下载 ======
        $filters = [];
        $favParam = $_GET['favorite'] ?? null;
        if ($favParam === '1' || $favParam === 'true') $filters['favorite'] = true;
        if (!empty($_GET['ids'])) {
            $filters['ids'] = array_filter(array_map('intval', explode(',', $_GET['ids'])));
        }
        if (!empty($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'];
        if (!empty($_GET['to_date']))   $filters['to_date']   = $_GET['to_date'];
        if (!empty($_GET['model']))     $filters['model']     = $_GET['model'];

        $rows = GalleryManager::listForZip($filters, 500);
        if (empty($rows)) error_response('没有可打包的图', 404);

        $zipName = 'nai-studio-' . date('Ymd-His') . '.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'naizip_');
        if (!$tmpFile) error_response('无法创建临时文件', 500);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_response('ZipArchive 创建失败', 500);
        }

        $manifest = [];
        $seenNames = [];
        foreach ($rows as $r) {
            // DB 存的是 /storage/images/xx/yyy.png；paths.storage 已经指向 public/storage
            $rel = preg_replace('#^/?storage/#', '', $r['image_path']);
            $absPath = rtrim(config('paths.storage'), '/\\') . '/' . $rel;
            if (!is_file($absPath)) continue;
            $baseName = sprintf('%s_seed%d_%dx%d', date('Ymd_His', strtotime($r['created_at'])), (int)$r['seed'], (int)$r['width'], (int)$r['height']);
            $name = $baseName . '.png';
            $i = 1;
            while (isset($seenNames[$name])) {
                $name = $baseName . '_' . $i . '.png';
                $i++;
            }
            $seenNames[$name] = true;
            $zip->addFile($absPath, $name);
            $manifest[] = [
                'file'      => $name,
                'id'        => (int)$r['id'],
                'created_at'=> $r['created_at'],
                'model'     => $r['model'],
                'sampler'   => $r['sampler'],
                'steps'     => (int)$r['steps'],
                'scale'     => (float)$r['scale'],
                'seed'      => (int)$r['seed'],
                'width'     => (int)$r['width'],
                'height'    => (int)$r['height'],
                'is_favorite' => (int)$r['is_favorite'],
                'notes'     => $r['notes'],
                'prompt'    => $r['prompt'],
                'negative_prompt' => $r['negative_prompt'],
            ];
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('README.txt', "NAI Studio 出图打包\n生成时间：" . date('Y-m-d H:i:s') . "\n共 " . count($manifest) . " 张\n\nmanifest.json 包含每张图的完整 prompt / seed / 参数。\n");
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-cache');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $row = GalleryManager::get($id);
        if (!$row) error_response('Not found', 404);
        ok_response(['item' => $row]);
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = (int)($_GET['per_page'] ?? 30);
    $filters = [];
    if (!empty($_GET['model']))   $filters['model'] = $_GET['model'];
    // favorite 必须严格为 truthy（不能用 !empty('false')，因为 'false' 是非空字符串）
    $favParam = $_GET['favorite'] ?? null;
    if ($favParam === '1' || $favParam === 'true' || $favParam === true) $filters['favorite'] = true;
    if (!empty($_GET['search']))  $filters['search'] = $_GET['search'];
    $result = GalleryManager::list($page, $per, $filters);
    ok_response($result);
    exit;
}

if ($method === 'POST') {
    $b = read_json_body();
    $action = $b['action'] ?? '';
    // Bulk actions don't need id
    if ($action === 'clear_all') {
        $includeFavorites = !empty($b['include_favorites']);
        $r = GalleryManager::clearAll($includeFavorites);
        ok_response(['deleted' => $r['count'], 'files' => $r['files']]);
        exit;
    }
    $id = (int)($b['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    switch ($action) {
        case 'favorite':
            GalleryManager::setFavorite($id, !empty($b['value']));
            ok_response(['id' => $id, 'is_favorite' => !empty($b['value'])]);
            break;
        case 'notes': {
            \NaiStudio\Db::pdo()->prepare("UPDATE generations SET notes = ? WHERE id = ?")
                ->execute([(string)($b['value'] ?? ''), $id]);
            ok_response(['id' => $id]);
            break;
        }
        default:
            error_response('Unknown action', 400);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) error_response('id required', 400);
    $hard = !empty($_GET['hard']);
    if ($hard) {
        GalleryManager::hardDelete($id);
    } else {
        GalleryManager::softDelete($id);
    }
    ok_response(['id' => $id]);
    exit;
}

error_response('Method not allowed', 405);
