<?php
/**
 * NAI Studio - Danbooru Artist Fetcher
 *
 * 借鉴 Monxia：批量抓取画师的 post_count + example image
 * 用法：
 *   $r = DanbooruArtistFetcher::fetchOne('ciloranko');
 *   $r = ['post_count' => 1234, 'example_image' => 'https://...', 'example_post_id' => 5678]
 *
 * 需要 Danbooru API key（可选）—— 匿名请求会被限流
 */

declare(strict_types=1);

namespace NaiStudio;

class DanbooruArtistFetcher {

    private const ENDPOINT = 'https://danbooru.donmai.us';
    private const TIMEOUT  = 10;

    /**
     * 抓单个画师
     * @return array{post_count:?int, example_post_id:?int, example_image:?string}
     */
    public static function fetchOne(string $nameNai): array {
        $nameNai = trim($nameNai);
        if ($nameNai === '') return ['post_count' => null, 'example_post_id' => null, 'example_image' => null];

        $proxy = Settings::getProxyUrl();
        $auth  = self::authHeader();

        // 1) 查 artist tag 信息
        $tagUrl = self::ENDPOINT . '/tags.json?search[name_matches]=' . urlencode($nameNai) . '*&limit=5&search[category]=1';
        $tags = self::httpGet($tagUrl, $proxy, $auth);
        if (!is_array($tags) || empty($tags)) {
            return ['post_count' => null, 'example_post_id' => null, 'example_image' => null];
        }

        // 找完全匹配的
        $matched = null;
        foreach ($tags as $t) {
            if (strcasecmp((string)($t['name'] ?? ''), $nameNai) === 0) {
                $matched = $t;
                break;
            }
        }
        $matched = $matched ?: $tags[0];

        $postCount = (int)($matched['post_count'] ?? 0);

        // 2) 查最新一张图作为示例
        $postsUrl = self::ENDPOINT . '/posts.json?tags=' . urlencode('artist:' . $nameNai) . '&limit=1&sf=random';
        $posts = self::httpGet($postsUrl, $proxy, $auth);
        $example = null;
        $examplePostId = null;
        if (is_array($posts) && !empty($posts)) {
            $p = $posts[0];
            $examplePostId = (int)($p['id'] ?? 0) ?: null;
            $example = self::buildPreviewUrl($p);
        }

        return [
            'post_count'     => $postCount,
            'example_post_id'=> $examplePostId,
            'example_image'  => $example,
        ];
    }

    /**
     * 批量抓取（顺序请求，Danbooru 限速不要并发）
     * @param array $names 画师 NAI 名列表
     * @param callable|null $onProgress 进度回调 fn($name, $current, $total) => void
     * @return array<string, array{post_count, example_image, example_post_id, error:?string}>
     */
    public static function fetchBatch(array $names, ?callable $onProgress = null): array {
        $out = [];
        $total = count($names);
        $i = 0;
        foreach ($names as $name) {
            $i++;
            try {
                $r = self::fetchOne($name);
                $out[$name] = $r + ['error' => null];
            } catch (\Throwable $e) {
                $out[$name] = ['post_count' => null, 'example_post_id' => null, 'example_image' => null, 'error' => $e->getMessage()];
            }
            if ($onProgress) $onProgress($name, $i, $total);
            // Danbooru 限流 2 req/s，加 sleep
            usleep(550000);   // 0.55s
        }
        return $out;
    }

    /**
     * 下载示例图到本地
     * @param string $remoteUrl 远程 URL（通常是 https://cdn.donmai.us/.../xxx.jpg）
     * @param string $localPath 本地存储相对路径（如 /storage/artist_images/abc.jpg）
     * @return bool 成功
     */
    public static function downloadExampleImage(string $remoteUrl, string $localPath): bool {
        $abs = self::resolveStoragePath($localPath);
        if (!$abs) return false;
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $ch = curl_init($remoteUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            Logger::warn('danbooru.artist.image.fetch_fail', ['url' => $remoteUrl, 'code' => $code]);
            return false;
        }
        return file_put_contents($abs, $body) !== false;
    }

    // ===== 内部 =====

    private static function authHeader(): ?string {
        $s = Db::fetchOne("SELECT danbooru_username, danbooru_api_key FROM settings WHERE id = 1");
        if ($s && !empty($s['danbooru_username']) && !empty($s['danbooru_api_key'])) {
            return 'Authorization: Basic ' . base64_encode($s['danbooru_username'] . ':' . $s['danbooru_api_key']);
        }
        return null;
    }

    private static function httpGet(string $url, ?string $proxy, ?string $auth): ?array {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'User-Agent: NAI-Studio/1.0 (local)'];
        if ($auth) $headers[] = $auth;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($proxy) $opts[CURLOPT_PROXY] = $proxy;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            Logger::warn('danbooru.artist.fetch_fail', ['url' => $url, 'code' => $code]);
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private static function buildPreviewUrl(array $post): ?string {
        $fileUrl = (string)($post['file_url'] ?? '');
        if ($fileUrl !== '') return $fileUrl;
        $sample = (string)($post['sample_file_url'] ?? '');
        if ($sample !== '') {
            return 'https://cdn.donmai.us/sample/' . $sample;
        }
        $preview = (string)($post['preview_file_url'] ?? '');
        if ($preview !== '') {
            return 'https://cdn.donmai.us/preview/' . $preview;
        }
        return null;
    }

    private static function resolveStoragePath(string $rel): ?string {
        // rel 像 /storage/artist_images/xxx.jpg
        if (!str_starts_with($rel, '/storage/')) return null;
        $cfg = config('paths');
        $publicDir = $cfg['public'] ?? null;
        if (!$publicDir) return null;
        $rel = substr($rel, strlen('/storage/'));
        return rtrim($publicDir, '/') . '/' . $rel;
    }
}
