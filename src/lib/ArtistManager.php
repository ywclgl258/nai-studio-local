<?php
/**
 * NAI Studio - Artist Library Manager
 *
 * 画师库 CRUD + 分类管理 + Danbooru 抓取 + NOOB/NAI 格式互转
 *
 * 数据模型参考 Monxia (MIT)：
 *   - artists: 主表
 *   - artist_categories: 分类
 *   - artist_category_map: 画师-分类多对多
 *   - artist_presets: 画师串预设
 *   - artist_preset_items: 预设里的画师
 *
 * 借鉴功能：
 *   - NOOB/NAI 格式自动补全
 *   - Danbooru 链接生成
 *   - 批量抓取作品数 + 示例图
 *   - 找重复
 */

declare(strict_types=1);

namespace NaiStudio;

class ArtistManager {

    // ===== 类别管理 =====

    public static function getCategories(): array {
        return Db::fetchAll("
            SELECT c.*, COUNT(DISTINCT m.artist_id) AS artist_count
            FROM artist_categories c
            LEFT JOIN artist_category_map m ON c.id = m.category_id
            GROUP BY c.id
            ORDER BY c.display_order, c.id
        ");
    }

    public static function createCategory(string $name, int $order = 0): int {
        return Db::insert('artist_categories', [
            'name'          => trim($name),
            'display_order' => $order,
        ]);
    }

    public static function updateCategory(int $id, ?string $name = null, ?int $order = null): bool {
        $data = [];
        if ($name !== null) $data['name'] = trim($name);
        if ($order !== null) $data['display_order'] = $order;
        if (empty($data)) return false;
        return Db::update('artist_categories', $id, $data) >= 0;
    }

    public static function deleteCategory(int $id): bool {
        // 分类下画师自动归到 "未分类" (id=1)
        Db::execute("UPDATE artist_category_map SET category_id = 1 WHERE category_id = ?", [$id]);
        return Db::delete('artist_categories', $id) > 0;
    }

    // ===== 画师 CRUD =====

    /**
     * 获取画师列表
     * @param int|null $categoryId 按分类筛（null=全部）
     * @param string $q 搜索关键字（name_noob/name_nai/name_cn/notes）
     * @param string $sort post_count|name|fetched_at
     * @param int $limit
     */
    public static function getArtists(?int $categoryId = null, string $q = '', string $sort = 'post_count', int $limit = 200): array {
        $where = ['1=1'];
        $params = [];

        if ($categoryId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM artist_category_map m WHERE m.artist_id = a.id AND m.category_id = ?)';
            $params[] = $categoryId;
        }
        if ($q !== '') {
            $where[] = '(a.name_noob LIKE ? OR a.name_nai LIKE ? OR a.name_cn LIKE ? OR a.notes LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $orderCol = match ($sort) {
            'name'     => 'COALESCE(a.name_cn, a.name_nai, a.name_noob)',
            'recent'   => 'a.fetched_at DESC',
            'updated'  => 'a.updated_at DESC',
            default    => 'a.post_count IS NULL, a.post_count DESC',
        };

        $sql = "SELECT a.*,
                    GROUP_CONCAT(c.name ORDER BY c.display_order SEPARATOR ',') AS category_names,
                    GROUP_CONCAT(c.id ORDER BY c.display_order) AS category_ids
                FROM artists a
                LEFT JOIN artist_category_map m ON a.id = m.artist_id
                LEFT JOIN artist_categories c ON m.category_id = c.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY a.id
                ORDER BY $orderCol
                LIMIT " . max(1, min(2000, $limit));

        $rows = Db::fetchAll($sql, $params);
        foreach ($rows as &$r) {
            $r['category_names'] = $r['category_names'] ?: '';
            $r['category_ids']   = $r['category_ids'] ? array_map('intval', explode(',', $r['category_ids'])) : [];
        }
        return $rows;
    }

    public static function getArtistById(int $id): ?array {
        $row = Db::fetchOne("SELECT * FROM artists WHERE id = ?", [$id]);
        if (!$row) return null;
        $row['categories'] = Db::fetchAll(
            "SELECT c.* FROM artist_categories c
             JOIN artist_category_map m ON c.id = m.category_id
             WHERE m.artist_id = ? ORDER BY c.display_order",
            [$id]
        );
        return $row;
    }

    public static function getArtistByName(string $name): ?array {
        $name = trim($name);
        if ($name === '') return null;
        // 去掉可能的 artist: 前缀
        $bare = preg_replace('/^artist:/i', '', $name);
        $row = Db::fetchOne(
            "SELECT * FROM artists
             WHERE LOWER(name_noob) = LOWER(?)
                OR LOWER(name_nai) = LOWER(?)
                OR LOWER(REPLACE(name_noob, 'artist:', '')) = LOWER(?)
                OR LOWER(REPLACE(name_nai,  'artist:', '')) = LOWER(?)
             LIMIT 1",
            [$name, $name, $bare, $bare]
        );
        return $row ?: null;
    }

    /**
     * 创建画师
     * @param array $data 含 name_noob / name_nai / name_cn / danbooru_link / post_count / notes / tags / style / skip_danbooru
     * @param array $categoryIds 分类 ID 列表
     */
    public static function createArtist(array $data, array $categoryIds = []): int {
        // 自动补全 name_noob / name_nai
        [$nameNoob, $nameNai] = self::normalizeNames($data['name_noob'] ?? null, $data['name_nai'] ?? null);

        if (empty($nameNoob) && empty($nameNai)) {
            throw new \InvalidArgumentException('name_noob 或 name_nai 必须填一个');
        }

        // 查重（按 NAI 名）
        if ($nameNai && ($existing = self::getArtistByName($nameNai))) {
            throw new \RuntimeException(
                "画师已存在: {$nameNai} (ID: {$existing['id']})"
            );
        }

        // 自动生成 danbooru_link
        $danbooruLink = trim((string)($data['danbooru_link'] ?? ''));
        if (empty($danbooruLink) && !empty($nameNai)) {
            $danbooruLink = self::buildDanbooruLink($nameNai);
        }

        $uuid = self::uuid();
        $id = Db::insert('artists', [
            'uuid'            => $uuid,
            'name_noob'       => $nameNoob,
            'name_nai'        => $nameNai,
            'name_cn'         => !empty($data['name_cn']) ? trim($data['name_cn']) : null,
            'danbooru_link'   => $danbooruLink ?: null,
            'post_count'      => isset($data['post_count']) ? (int)$data['post_count'] : null,
            'example_post_id' => isset($data['example_post_id']) ? (int)$data['example_post_id'] : null,
            'example_image_url' => !empty($data['example_image_url']) ? trim($data['example_image_url']) : null,
            'example_image_path' => !empty($data['example_image_path']) ? trim($data['example_image_path']) : null,
            'notes'           => !empty($data['notes']) ? trim($data['notes']) : null,
            'tags'            => !empty($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
            'style'           => !empty($data['style']) ? trim($data['style']) : null,
            'skip_danbooru'   => !empty($data['skip_danbooru']) ? 1 : 0,
        ]);

        self::setArtistCategories($id, $categoryIds);
        return $id;
    }

    public static function updateArtist(int $id, array $data, ?array $categoryIds = null): bool {
        $update = [];
        if (array_key_exists('name_noob', $data)) $update['name_noob'] = $data['name_noob'] !== null ? trim($data['name_noob']) : null;
        if (array_key_exists('name_nai',  $data)) $update['name_nai']  = $data['name_nai']  !== null ? trim($data['name_nai'])  : null;
        if (array_key_exists('name_cn',   $data)) $update['name_cn']   = $data['name_cn']   !== null ? trim($data['name_cn'])   : null;
        if (array_key_exists('danbooru_link', $data)) $update['danbooru_link'] = !empty($data['danbooru_link']) ? trim($data['danbooru_link']) : null;
        if (array_key_exists('post_count', $data)) $update['post_count'] = $data['post_count'] !== null ? (int)$data['post_count'] : null;
        if (array_key_exists('example_post_id', $data)) $update['example_post_id'] = $data['example_post_id'] !== null ? (int)$data['example_post_id'] : null;
        if (array_key_exists('example_image_url',  $data)) $update['example_image_url']  = !empty($data['example_image_url'])  ? trim($data['example_image_url'])  : null;
        if (array_key_exists('example_image_path', $data)) $update['example_image_path'] = !empty($data['example_image_path']) ? trim($data['example_image_path']) : null;
        if (array_key_exists('notes',  $data)) $update['notes']  = !empty($data['notes'])  ? trim($data['notes'])  : null;
        if (array_key_exists('tags',   $data)) $update['tags']   = !empty($data['tags'])   ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null;
        if (array_key_exists('style',  $data)) $update['style']  = !empty($data['style'])  ? trim($data['style'])  : null;
        if (array_key_exists('skip_danbooru', $data)) $update['skip_danbooru'] = !empty($data['skip_danbooru']) ? 1 : 0;

        if (!empty($update)) {
            Db::update('artists', $id, $update);
        }
        if ($categoryIds !== null) {
            self::setArtistCategories($id, $categoryIds);
        }
        return true;
    }

    public static function deleteArtist(int $id): bool {
        return Db::delete('artists', $id) > 0;
    }

    public static function setArtistCategories(int $artistId, array $categoryIds): void {
        Db::execute("DELETE FROM artist_category_map WHERE artist_id = ?", [$artistId]);
        foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
            if ($cid > 0) {
                Db::insert('artist_category_map', ['artist_id' => $artistId, 'category_id' => $cid]);
            }
        }
    }

    // ===== 工具方法 =====

    /**
     * 规范化 NOOB / NAI 两种格式
     * NOOB: 必须带 artist: 前缀（括号转义也算）
     * NAI:  纯名（不带前缀）
     */
    public static function normalizeNames(?string $noob, ?string $nai): array {
        $noob = trim((string)$noob);
        $nai  = trim((string)$nai);

        // NOOB: 括号转义 + 加前缀
        if ($noob !== '') {
            if (!str_starts_with(strtolower($noob), 'artist:')) {
                $noob = 'artist:' . $noob;
            }
            // 解括号转义
            $noob = str_replace(['\\(', '\\)'], ['(', ')'], $noob);
            $nai  = self::naiFromNoob($noob);
        } elseif ($nai !== '') {
            // 给了 NAI 但没给 NOOB → 自动补
            $nai = str_replace(['\\(', '\\)'], ['(', ')'], $nai);
            $noob = 'artist:' . $nai;
        }

        return [$noob, $nai];
    }

    public static function naiFromNoob(string $noob): string {
        $name = preg_replace('/^artist:/i', '', $noob);
        return str_replace(['\\(', '\\)'], ['(', ')'], $name);
    }

    public static function noobFromNai(string $nai): string {
        $nai = trim($nai);
        if ($nai === '') return '';
        if (str_contains($nai, '(') || str_contains($nai, ')')) {
            // 含括号需要转义
            $nai = str_replace(['(', ')'], ['\\(', '\\)'], $nai);
        }
        return 'artist:' . $nai;
    }

    public static function buildDanbooruLink(string $nameNai): string {
        $nameNai = trim($nameNai);
        if ($nameNai === '') return '';
        return 'https://danbooru.donmai.us/posts?tags=artist%3A' . urlencode($nameNai);
    }

    // ===== 找重复 =====

    public static function findDuplicates(): array {
        // 名字或 danbooru_link 相同
        $rows = Db::fetchAll("
            SELECT a1.id AS id1, a1.name_nai AS name1, a1.name_noob AS noob1, a1.danbooru_link AS link1,
                   a2.id AS id2, a2.name_nai AS name2, a2.name_noob AS noob2, a2.danbooru_link AS link2
            FROM artists a1
            JOIN artists a2
              ON a1.id < a2.id
             AND (LOWER(COALESCE(a1.name_nai, '')) = LOWER(COALESCE(a2.name_nai, '')) AND a1.name_nai IS NOT NULL
               OR LOWER(COALESCE(a1.danbooru_link, '')) = LOWER(COALESCE(a2.danbooru_link, '')) AND a1.danbooru_link IS NOT NULL)
            ORDER BY a1.id
        ");
        return $rows;
    }

    // ===== 画师串预设 =====

    public static function getPresets(int $limit = 200): array {
        return Db::fetchAll("
            SELECT p.*, COUNT(i.artist_id) AS artist_count
            FROM artist_presets p
            LEFT JOIN artist_preset_items i ON p.id = i.preset_id
            GROUP BY p.id
            ORDER BY p.is_favorite DESC, p.use_count DESC, p.updated_at DESC
            LIMIT " . max(1, min(2000, $limit))
        );
    }

    public static function getPresetById(int $id): ?array {
        $row = Db::fetchOne("SELECT * FROM artist_presets WHERE id = ?", [$id]);
        if (!$row) return null;
        $row['items'] = Db::fetchAll(
            "SELECT i.*, a.name_noob, a.name_nai, a.name_cn, a.style, a.post_count
             FROM artist_preset_items i
             JOIN artists a ON i.artist_id = a.id
             WHERE i.preset_id = ?
             ORDER BY i.position",
            [$id]
        );
        return $row;
    }

    public static function createPreset(string $name, string $noobText = '', string $naiText = '', ?string $description = null, ?int $categoryId = null, array $artistIds = []): int {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('画师串名称不能为空');
        }
        $id = Db::insert('artist_presets', [
            'name'        => trim($name),
            'description' => $description ? trim($description) : null,
            'noob_text'   => $noobText,
            'nai_text'    => $naiText,
            'category_id' => $categoryId,
        ]);
        self::setPresetItems($id, $artistIds);
        return $id;
    }

    public static function updatePreset(int $id, array $data, ?array $artistIds = null): bool {
        $update = [];
        if (array_key_exists('name', $data)) $update['name'] = trim($data['name']);
        if (array_key_exists('description', $data)) $update['description'] = $data['description'] ? trim($data['description']) : null;
        if (array_key_exists('noob_text', $data)) $update['noob_text'] = $data['noob_text'];
        if (array_key_exists('nai_text',  $data)) $update['nai_text']  = $data['nai_text'];
        if (array_key_exists('category_id', $data)) $update['category_id'] = $data['category_id'] ? (int)$data['category_id'] : null;
        if (array_key_exists('is_favorite', $data)) $update['is_favorite'] = !empty($data['is_favorite']) ? 1 : 0;
        if (!empty($update)) Db::update('artist_presets', $id, $update);
        if ($artistIds !== null) self::setPresetItems($id, $artistIds);
        return true;
    }

    public static function deletePreset(int $id): bool {
        return Db::delete('artist_presets', $id) > 0;
    }

    public static function setPresetItems(int $presetId, array $artistIds): void {
        Db::execute("DELETE FROM artist_preset_items WHERE preset_id = ?", [$presetId]);
        $pos = 0;
        foreach (array_unique(array_map('intval', $artistIds)) as $aid) {
            if ($aid > 0) {
                Db::insert('artist_preset_items', [
                    'preset_id' => $presetId,
                    'artist_id' => $aid,
                    'position'  => $pos++,
                ]);
            }
        }
    }

    public static function incrementPresetUseCount(int $id): void {
        Db::execute("UPDATE artist_presets SET use_count = use_count + 1 WHERE id = ?", [$id]);
    }

    public static function presetToNaiText(int $presetId): string {
        $preset = self::getPresetById($presetId);
        if (!$preset) return '';
        $names = array_map(fn($i) => $i['name_nai'] ?: $i['name_noob'], $preset['items']);
        return implode(', ', array_filter($names));
    }

    public static function presetToNoobText(int $presetId): string {
        $preset = self::getPresetById($presetId);
        if (!$preset) return '';
        $names = array_map(fn($i) => self::noobFromNai($i['name_nai'] ?: self::naiFromNoob($i['name_noob'])), $preset['items']);
        return implode(', ');
    }

    // ===== UUID 生成 =====
    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
