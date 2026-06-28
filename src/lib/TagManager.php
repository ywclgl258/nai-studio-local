<?php
/**
 * NAI Studio - Tag manager
 * CRUD + search + suggestions + categories.
 */

declare(strict_types=1);

namespace NaiStudio;

class TagManager {
    /** Return all categories ordered by display_order. */
    public static function categories(): array {
        $cacheFile = config('paths.cache') . '/categories.json';
        $cached = self::readCache($cacheFile, (int)config('cache.tag_list_ttl'));
        if ($cached !== null) return $cached;
        $rows = Db::fetchAll("SELECT id, slug, name, name_cn, description, display_order, tag_count
                              FROM tag_categories ORDER BY display_order, name");
        self::writeCache($cacheFile, $rows);
        return $rows;
    }

    /**
     * Search tags by prefix in name, or substring in cn_name.
     * @return array<int,array{id, name, category_id, cn_name, post_count, category_name, category_name_cn}>
     */
    public static function search(string $query, ?int $categoryId = null, int $limit = 50, int $offset = 0): array {
        $limit = max(1, min((int)config('tags.search_limit'), $limit));
        $offset = max(0, $offset);
        $q = trim($query);
        $params = [];
        $where = [];

        if ($q !== '') {
            // Use positional ? placeholders because named placeholders can't be reused
            // in the same query. We need: contains-match (4x), prefix-match (1x), category (1x)
            $where[] = '(t.name LIKE ? OR t.cn_name LIKE ? OR t.name LIKE ? OR t.cn_name LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', $q . '%', $q . '%');
        }
        if ($categoryId !== null) {
            $where[] = 't.category_id = ?';
            $params[] = $categoryId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $orderBy = "t.post_count DESC, t.name ASC";
        if ($q !== '') {
            $orderBy = "(t.name LIKE ?) DESC, $orderBy";
            array_unshift($params, $q . '%');
        }
        $sql = "SELECT t.id, t.name, t.category_id, t.cn_name, t.post_count, t.aliases, t.is_nsfw,
                       t.example_image_url,
                       c.name AS category_name, c.name_cn AS category_name_cn
                FROM tags t
                LEFT JOIN tag_categories c ON c.id = t.category_id
                $whereSql
                ORDER BY $orderBy
                LIMIT $offset, $limit";
        return Db::fetchAll($sql, $params);
    }

    /** Get popular tags in a category, for default browser. */
    public static function popularInCategory(int $categoryId, int $limit = 60): array {
        return Db::fetchAll(
            "SELECT t.id, t.name, t.cn_name, t.post_count, t.aliases,
                    t.example_image_url,
                    c.name AS category_name, c.name_cn AS category_name_cn
             FROM tags t
             LEFT JOIN tag_categories c ON c.id = t.category_id
             WHERE t.category_id = :cid
             ORDER BY t.post_count DESC, t.name ASC
             LIMIT $limit",
            [':cid' => $categoryId]
        );
    }

    /** Bulk lookup tags by name. */
    public static function lookupByNames(array $names): array {
        $names = array_filter(array_map(fn($n) => strtolower(trim($n)), $names), fn($n) => $n !== '');
        if (empty($names)) return [];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT t.id, t.name, t.cn_name, t.post_count, t.category_id,
                       t.example_image_url,
                       c.name AS category_name, c.name_cn AS category_name_cn
                FROM tags t
                LEFT JOIN tag_categories c ON c.id = t.category_id
                WHERE t.name IN ($placeholders)";
        return Db::fetchAll($sql, $names);
    }

    /** Insert or update a tag. */
    public static function upsert(string $name, ?int $categoryId, ?string $cnName = null, ?int $postCount = null, ?string $aliases = null): int {
        $name = strtolower(trim($name));
        if ($name === '') throw new \InvalidArgumentException('Tag name required');
        $existing = Db::fetchScalar("SELECT id FROM tags WHERE name = ?", [$name]);
        $data = [
            'name'        => $name,
            'category_id' => $categoryId ?? 0,
            'cn_name'     => $cnName,
            'post_count'  => $postCount ?? 0,
            'aliases'     => $aliases,
        ];
        if ($existing) {
            Db::update('tags', (int)$existing, $data);
            return (int)$existing;
        }
        return Db::insert('tags', $data);
    }

    /** Get tag by name. */
    public static function getByName(string $name): ?array {
        return Db::fetchOne(
            "SELECT t.*, c.name AS category_name, c.name_cn AS category_name_cn
             FROM tags t
             LEFT JOIN tag_categories c ON c.id = t.category_id
             WHERE t.name = ?",
            [strtolower(trim($name))]
        );
    }

    /** Update category tag counts (called after bulk imports). */
    public static function refreshCategoryCounts(): void {
        Db::pdo()->exec("
            UPDATE tag_categories c
            LEFT JOIN (
                SELECT category_id, COUNT(*) AS cnt
                FROM tags GROUP BY category_id
            ) t ON t.category_id = c.id
            SET c.tag_count = COALESCE(t.cnt, 0)
        ");
    }

    // Cache helpers
    private static function readCache(string $file, int $ttl): ?array {
        if (!is_file($file)) return null;
        if (time() - filemtime($file) > $ttl) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
    private static function writeCache(string $file, $data): void {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
