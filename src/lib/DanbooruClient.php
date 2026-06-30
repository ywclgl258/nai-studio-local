<?php
/**
 * DanbooruClient.php — 共享的 Danbooru HTTP 客户端
 *
 * 提供 dbFetch() 函数，给 danbooru.php / artists.php / 其他需要调 Danbooru API 的地方用。
 * 放在 src/lib/ 而不是 public/api/，避免被直接通过 HTTP 访问时执行顶级 routing 代码。
 */
declare(strict_types=1);

use NaiStudio\Logger;
use NaiStudio\Settings;

if (!function_exists('dbFetch')) {
    /**
     * Danbooru HTTP GET（支持 settings 里的代理开关）
     *
     * @return array|null 成功返回 JSON 解码的数组；失败返回 null
     */
    function dbFetch(string $url, int $timeout = 8): ?array {
        $proxy = Settings::getProxyUrl();
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if ($proxy) {
            $opts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($body === false) {
            Logger::warn('danbooru.fetch.fail', ['url' => $url, 'errno' => $errno, 'proxy' => $proxy ?: 'none']);
            return null;
        }
        if ($code >= 400) {
            Logger::warn('danbooru.fetch.http', ['url' => $url, 'code' => $code, 'proxy' => $proxy ?: 'none']);
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }
}