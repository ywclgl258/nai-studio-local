<?php
/**
 * NAI Studio - Free translation service
 * Uses MyMemory free API (https://mymemory.translated.net) — no API key required.
 * 1000 words/day free tier, more than enough for tag translation.
 *
 * Usage:
 *   $t = Translator::enToZh('long_hair');
 *   // ['cn' => '长发', 'cached' => false]
 */

declare(strict_types=1);

namespace NaiStudio;

class Translator {
    private const CACHE_TTL = 86400 * 30;   // 30 days
    private const ENDPOINT  = 'https://api.mymemory.translated.net/get';
    private const TIMEOUT   = 6;
    private const MEM_LIMIT = 1024;         // cache size cap

    /**
     * Translate English text to Simplified Chinese.
     * Returns ['cn' => string, 'cached' => bool, 'confidence' => float]
     */
    public static function enToZh(string $text): array {
        $text = trim($text);
        if ($text === '') return ['cn' => '', 'cached' => true, 'confidence' => 0];

        // 1) 内置字典优先（秒回，不消耗 API）
        $builtin = TagDict::lookup($text);
        if ($builtin !== null) {
            return ['cn' => $builtin, 'cached' => true, 'confidence' => 1, 'source' => 'builtin'];
        }

        // 2) 内存缓存
        $key = 'translator:' . md5(strtolower($text));
        $cache = self::apcGet($key);
        if ($cache !== false) {
            return ['cn' => $cache, 'cached' => true, 'confidence' => 1, 'source' => 'memory'];
        }

        // 3) 在线翻译（fallback）
        $url = self::ENDPOINT
            . '?q=' . urlencode(str_replace('_', ' ', $text))
            . '&langpair=en|zh-CN'
            . '&de=nai-studio@example.com';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            Logger::warn('translator.fail', ['q' => $text, 'code' => $code]);
            return ['cn' => '', 'cached' => false, 'confidence' => 0];
        }

        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['responseData']['translatedText'])) {
            Logger::warn('translator.bad_response', ['q' => $text, 'body' => substr((string)$body, 0, 200)]);
            return ['cn' => '', 'cached' => false, 'confidence' => 0];
        }

        $cn = trim((string)$j['responseData']['translatedText']);
        $confidence = (float)($j['responseData']['match'] ?? 0);
        // Strip "MYMEMORY WARNING" or nonsense if present
        $cn = preg_replace('/^(MYMEMORY WARNING[:\s]*|PLEASE.*$)/i', '', $cn);
        $cn = trim((string)$cn);

        if ($cn !== '' && $cn !== $text && stripos($cn, 'WARNING') === false) {
            self::apcSet($key, $cn);
            return ['cn' => $cn, 'cached' => false, 'confidence' => $confidence, 'source' => 'mymemory'];
        }
        return ['cn' => $cn, 'cached' => false, 'confidence' => $confidence, 'source' => 'mymemory'];
    }

    /** Tiny in-memory cache (per-request); DB is the persistent layer. */
    private static array $_mem = [];
    private static function apcGet(string $key) {
        if (function_exists('apcu_fetch')) {
            $v = apcu_fetch($key, $hit);
            if ($hit) return $v;
        }
        return self::$_mem[$key] ?? false;
    }
    private static function apcSet(string $key, string $val): void {
        if (function_exists('apcu_store')) apcu_store($key, $val, self::CACHE_TTL);
        // Bound in-memory size
        if (count(self::$_mem) >= self::MEM_LIMIT) self::$_mem = array_slice(self::$_mem, -500, null, true);
        self::$_mem[$key] = $val;
    }

    /**
     * Batch translate + persist to DB cache for a Danbooru tag.
     * Returns the CN string (or empty).
     */
    public static function translateDanbooruTag(string $enName, int $tagId): string {
        $r = self::enToZh($enName);
        if ($r['cn'] === '' || $r['cn'] === $enName) return '';
        try {
            Db::update('danbooru_tag_cache', $tagId, [
                'cn_name' => mb_substr($r['cn'], 0, 128),
                'translated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // best effort
        }
        return $r['cn'];
    }
}