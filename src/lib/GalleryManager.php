<?php
/**
 * NAI Studio - Gallery manager
 * Handles generation history: insert, list, favorite, delete, restore.
 */

declare(strict_types=1);

namespace NaiStudio;

class GalleryManager {
    public static function insert(array $data): int {
        // JSON-encode structured fields
        foreach (['characters_json', 'vibe_refs_json', 'precise_refs_json', 'meta_json'] as $j) {
            if (isset($data[$j]) && !is_string($data[$j])) {
                $data[$j] = json_encode($data[$j], JSON_UNESCAPED_UNICODE);
            }
        }
        return Db::insert('generations', $data);
    }

    public static function get(int $id): ?array {
        $row = Db::fetchOne("SELECT * FROM generations WHERE id = ? AND is_deleted = 0", [$id]);
        if (!$row) return null;
        foreach (['characters_json','vibe_refs_json','precise_refs_json','meta_json'] as $j) {
            if (!empty($row[$j])) {
                $row[$j] = json_decode($row[$j], true);
            }
        }
        return $row;
    }

    public static function list(int $page = 1, int $perPage = 30, array $filters = []): array {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;
        $where   = ['is_deleted = 0'];
        $params  = [];

        if (!empty($filters['model'])) { $where[] = 'model = ?'; $params[] = $filters['model']; }
        if (!empty($filters['favorite'])) { $where[] = 'is_favorite = 1'; }
        if (!empty($filters['search'])) {
            $where[] = '(prompt LIKE ? OR negative_prompt LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['from_date'])) { $where[] = 'created_at >= ?'; $params[] = $filters['from_date']; }
        if (!empty($filters['to_date']))   { $where[] = 'created_at <= ?'; $params[] = $filters['to_date']; }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $total = (int)Db::fetchScalar("SELECT COUNT(*) FROM generations $whereSql", $params);
        $rows = Db::fetchAll(
            "SELECT id, batch_id, operation, model, sampler, steps, scale, seed, width, height,
                    cfg_rescale, noise_schedule, uc_preset, image_path, thumbnail_path,
                    is_favorite, notes, created_at,
                    LEFT(prompt, 120) AS prompt_preview
             FROM generations
             $whereSql
             ORDER BY created_at DESC, id DESC
             LIMIT $offset, $perPage",
            $params
        );
        return [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => (int)ceil($total / $perPage),
        ];
    }

    /**
     * 不分页拿完整数据（用于打包下载）
     * 上限 500 张，超过截断
     */
    public static function listForZip(array $filters = [], int $maxRows = 500): array {
        $where   = ['is_deleted = 0'];
        $params  = [];
        if (!empty($filters['favorite'])) $where[] = 'is_favorite = 1';
        if (!empty($filters['model'])) { $where[] = 'model = ?'; $params[] = $filters['model']; }
        if (!empty($filters['from_date'])) { $where[] = 'created_at >= ?'; $params[] = $filters['from_date']; }
        if (!empty($filters['to_date']))   { $where[] = 'created_at <= ?'; $params[] = $filters['to_date']; }
        if (!empty($filters['ids'])) {
            $ids = array_filter(array_map('intval', (array)$filters['ids']));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "id IN ($placeholders)";
                foreach ($ids as $id) $params[] = $id;
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        return Db::fetchAll(
            "SELECT id, batch_id, operation, model, sampler, steps, scale, seed, width, height,
                    cfg_rescale, noise_schedule, uc_preset, image_path, thumbnail_path,
                    is_favorite, notes, created_at, prompt, negative_prompt
             FROM generations
             $whereSql
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, min(2000, $maxRows)),
            $params
        );
    }

    public static function setFavorite(int $id, bool $fav): void {
        Db::pdo()->prepare("UPDATE generations SET is_favorite = ? WHERE id = ?")
            ->execute([$fav ? 1 : 0, $id]);
    }

    public static function softDelete(int $id): void {
        Db::pdo()->prepare("UPDATE generations SET is_deleted = 1 WHERE id = ?")
            ->execute([$id]);
    }

    public static function hardDelete(int $id): bool {
        $row = Db::fetchOne("SELECT image_path, thumbnail_path FROM generations WHERE id = ?", [$id]);
        if (!$row) return false;
        // Delete files
        foreach ([$row['image_path'], $row['thumbnail_path']] as $f) {
            if ($f) {
                $abs = config('paths.public') . $f;
                if (is_file($abs)) @unlink($abs);
            }
        }
        Db::pdo()->prepare("DELETE FROM generations WHERE id = ?")->execute([$id]);
        return true;
    }

    /**
     * Clear all history. By default preserves favorites.
     * Returns ['count' => deleted_rows, 'files' => deleted_files_count]
     */
    public static function clearAll(bool $includeFavorites = false): array {
        $where = $includeFavorites ? '' : 'WHERE is_favorite = 0';
        $rows = Db::fetchAll("SELECT id, image_path, thumbnail_path FROM generations $where");
        $files = 0;
        foreach ($rows as $r) {
            foreach ([$r['image_path'], $r['thumbnail_path']] as $f) {
                if ($f) {
                    $abs = config('paths.public') . $f;
                    if (is_file($abs)) { @unlink($abs); $files++; }
                }
            }
        }
        $sql = $includeFavorites
            ? "DELETE FROM generations"
            : "DELETE FROM generations WHERE is_favorite = 0";
        $count = Db::pdo()->exec($sql);
        return ['count' => (int)$count, 'files' => $files];
    }

    /** Save a generated image to disk and DB. */
    public static function saveImage(string $base64Png, array $params, ?string $batchId = null): array {
        $id = self::insert(array_merge($params, [
            'batch_id'  => $batchId,
            'operation' => $params['operation'] ?? 'generate',
            'image_size_bytes' => strlen($base64Png),
        ]));
        $dir = config('paths.images') . '/' . substr(md5((string)$id), 0, 2);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $filename = $id . '_' . substr(md5($base64Png), 0, 8) . '.png';
        $absPath = $dir . '/' . $filename;
        file_put_contents($absPath, base64_decode($base64Png));
        $relPath = '/storage/images/' . substr(md5((string)$id), 0, 2) . '/' . $filename;

        // Get actual image dimensions
        [$w, $h] = getimagesize($absPath) ?: [0, 0];

        // Generate thumbnail
        $thumbRel = self::makeThumbnail($absPath, $relPath);

        Db::update('generations', $id, [
            'image_path'     => $relPath,
            'thumbnail_path' => $thumbRel,
            'image_width'    => $w,
            'image_height'   => $h,
            'image_size_bytes' => filesize($absPath),
        ]);
        return ['id' => $id, 'image_path' => $relPath, 'thumbnail_path' => $thumbRel];
    }

    /** Generate a thumbnail (max 320px wide) using GD. */
    public static function makeThumbnail(string $absPath, string $relPath): ?string {
        if (!function_exists('imagecreatefromstring')) {
            // GD 不可用：回退到原图（这样至少能显示）
            error_log('[GalleryManager] GD 扩展未安装，缩略图回退到原图');
            return $relPath;
        }
        $img = @imagecreatefromstring(file_get_contents($absPath));
        if (!$img) return null;
        $w = imagesx($img); $h = imagesy($img);
        $max = 320;
        if ($w <= $max) {
            imagedestroy($img);
            return $relPath; // 缩略图跟原图一样大，直接用原图
        }
        $newW = $max;
        $newH = (int)($h * $max / $w);
        $thumb = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $thumbRel = preg_replace('#/images/#', '/thumbs/', $relPath);
        $thumbAbs = config('paths.public') . $thumbRel;
        @mkdir(dirname($thumbAbs), 0775, true);
        imagepng($thumb, $thumbAbs, 6);
        imagedestroy($img); imagedestroy($thumb);
        return $thumbRel;
    }
}
