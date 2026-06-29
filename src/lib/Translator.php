<?php
/**
 * NAI Studio - Translation service
 *
 * 三档翻译来源（按优先级）：
 *   1. 内置 TagDict（500+ 词，秒回）
 *   2. 本地 LibreTranslate / OPUS-MT 服务（用户自配，无每日限额）
 *   3. MyMemory 在线 API（fallback，1000 词/天免费）
 *
 * Usage:
 *   $t = Translator::enToZh('long_hair');
 *   // ['cn' => '长发', 'source' => 'builtin'|'local'|'mymemory', 'cached' => bool]
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
    public static function enToZh(string $text, bool $autoTranslate = true): array {
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

        // 2.5) AI 翻译（DeepSeek，最后兜底 - 比 MyMemory/Google 准但要花 token）
        if (DeepSeekHelper::isEnabled() && $autoTranslate) {
            $cn = AiAdvisor::translateTag($text);
            if ($cn !== '' && $cn !== $text) {
                self::apcSet($key, $cn);
                return ['cn' => $cn, 'cached' => false, 'confidence' => 0.95, 'source' => 'deepseek'];
            }
        }

        // 3) 本地翻译（用户自配 LibreTranslate / OPUS-MT，可选）
        if (Settings::getLocalTranslateEnabled()) {
            $localUrl = Settings::getLocalTranslateUrl();
            if ($localUrl) {
                $cn = self::callLocalTranslate($localUrl, $text);
                if ($cn !== null && $cn !== '' && $cn !== $text) {
                    self::apcSet($key, $cn);
                    return ['cn' => $cn, 'cached' => false, 'confidence' => 1, 'source' => 'local'];
                }
            }
        }

        // 4) 在线翻译（多源 fallback：MyMemory → LibreTranslate 公共实例）
        $online = self::callOnlineTranslate($text);
        if ($online !== null) {
            self::apcSet($key, $online['cn']);
            return [
                'cn'         => $online['cn'],
                'cached'     => false,
                'confidence' => $online['confidence'],
                'source'     => $online['source'],
            ];
        }

        // 全失败
        Logger::warn('translator.all_fail', ['q' => $text]);
        return ['cn' => '', 'cached' => false, 'confidence' => 0, 'source' => 'fail'];

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

    /**
     * Call a local LibreTranslate-compatible endpoint.
     * POST {url}/translate  body: {q, source:'en', target:'zh', format:'text'}
     * Returns CN string, or null on failure.
     */
    public static function callLocalTranslate(string $baseUrl, string $text, int $timeout = 4): ?string {
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = $baseUrl . '/translate';
        $payload = json_encode([
            'q'      => str_replace('_', ' ', $text),
            'source' => 'en',
            'target' => 'zh',
            'format' => 'text',
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            return null;
        }
        $j = json_decode($body, true);
        $cn = is_array($j) ? (string)($j['translatedText'] ?? '') : '';
        $cn = trim($cn);
        return $cn !== '' ? $cn : null;
    }

    /**
     * Test the local translate endpoint. Returns ['ok'=>bool, 'message'=>string].
     */
    public static function testLocalTranslate(): array {
        $url = Settings::getLocalTranslateUrl();
        if (!$url) return ['ok' => false, 'message' => '本地翻译 URL 为空'];
        $cn = self::callLocalTranslate($url, 'long_hair blue_eyes', 6);
        if ($cn === null) {
            Db::update('settings', 1, [
                'local_translate_status' => 'fail',
                'local_translate_tested_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => false, 'message' => '✗ 本地翻译服务无响应（请检查 URL 和 LibreTranslate 是否运行）'];
        }
        Db::update('settings', 1, [
            'local_translate_status' => 'ok',
            'local_translate_tested_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => '✓ 本地翻译可用：' . $cn];
    }

    /**
     * 多源在线翻译，按顺序尝试：
     *   1. MyMemory (anonymous, 1000 词/天)
     *   2. Google translate_a/single (非官方网页版，需用户开启"非官方 fallback")
     *   3. LibreTranslate 公共实例 (备用)
     *
     * 全部失败返回 null
     * @return array{cn:string, source:string, confidence:float}|null
     */
    public static function callOnlineTranslate(string $text): ?array {
        $text = trim($text);
        $translated = str_replace('_', ' ', $text);

        // 1) MyMemory（主，1000 词/天，匿名）
        $r = self::callMyMemory($translated);
        if ($r !== null) return $r;

        // 2) Google translate_a/single（非官方，需用户明确开启）
        if (Settings::getAggressiveFallbackEnabled()) {
            $r = self::callGoogleTranslateUnofficial($translated);
            if ($r !== null) return $r;
        }

        // 3) LibreTranslate 公共实例（兜底）
        foreach (self::LIBRETRANSLATE_PUBLIC as $endpoint) {
            $r = self::callLibreTranslatePublic($endpoint, $translated);
            if ($r !== null) return $r;
        }

        return null;
    }

    /**
     * 反向翻译入口：中文 → 英文（标签搜索用）
     * 优先级：内存缓存 → 在线 MyMemory → LibreTranslate → Google
     *
     * @return array{en:string, source:string, confidence:float}|null
     */
    public static function zhToEn(string $text): ?array {
        $text = trim($text);
        if ($text === '') return null;

        // 内存缓存（同 enToZh 模式）
        $key = 'translator:z2e:' . md5(strtolower($text));
        $cache = self::apcGet($key);
        if ($cache !== false) {
            return ['en' => $cache, 'source' => 'memory', 'confidence' => 1];
        }

        $r = self::callOnlineTranslateZhToEn($text);
        if ($r !== null) {
            self::apcSet($key, $r['en']);
        }
        return $r;
    }

    /**
     * 反向翻译：中文 → 英文（标签搜索用）
     * 优先级：MyMemory (zh-CN|en) → LibreTranslate → Google 非官方
     *
     * @return array{en:string, source:string, confidence:float}|null
     */
    public static function callOnlineTranslateZhToEn(string $text): ?array {
        $text = trim($text);
        if ($text === '') return null;

        // 1) MyMemory 反向（langpair=zh-CN|en）
        $r = self::callMyMemoryZhToEn($text);
        if ($r !== null) return $r;

        // 2) LibreTranslate 反向
        foreach (self::LIBRETRANSLATE_PUBLIC as $endpoint) {
            $r = self::callLibreTranslatePublicZhToEn($endpoint, $text);
            if ($r !== null) return $r;
        }

        // 3) Google 非官方反向（如果用户开启）
        if (Settings::getAggressiveFallbackEnabled()) {
            $r = self::callGoogleTranslateUnofficialZhToEn($text);
            if ($r !== null) return $r;
        }

        return null;
    }

    /** MyMemory zh-CN → en */
    private static function callMyMemoryZhToEn(string $text): ?array {
        $url = self::ENDPOINT
            . '?q=' . urlencode($text)
            . '&langpair=zh-CN|en'
            . '&de=nai-studio@example.com';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            Logger::warn('translator.mymemory.zh2en.fail', ['code' => $code]);
            return null;
        }

        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['responseData']['translatedText'])) return null;

        $en = trim((string)$j['responseData']['translatedText']);
        $en = preg_replace('/^(MYMEMORY WARNING[:\s]*|PLEASE.*$)/i', '', $en);
        $en = trim($en);
        $confidence = (float)($j['responseData']['match'] ?? 0);

        if ($en === '' || stripos($en, 'WARNING') !== false) return null;
        // 避免翻译后还是中文（说明没翻成）
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $en)) return null;

        return ['en' => $en, 'source' => 'mymemory', 'confidence' => $confidence];
    }

    /** LibreTranslate zh → en */
    private static function callLibreTranslatePublicZhToEn(string $base, string $text): ?array {
        $payload = json_encode([
            'q'      => $text,
            'source' => 'zh',
            'target' => 'en',
            'format' => 'text',
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($base . '/translate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) return null;
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['translatedText'])) return null;

        $en = trim((string)$j['translatedText']);
        if ($en === '' || preg_match('/[\x{4e00}-\x{9fff}]/u', $en)) return null;

        return ['en' => $en, 'source' => 'libretranslate', 'confidence' => 0.8];
    }

    /** Google translate_a/single zh → en (非官方) */
    private static function callGoogleTranslateUnofficialZhToEn(string $text): ?array {
        $url = 'https://translate.googleapis.com/translate_a/single'
             . '?client=dict-chrome-ex'
             . '&sl=zh-CN&tl=en'
             . '&dt=t&q=' . urlencode($text);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) return null;
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j[0])) return null;

        $en = '';
        foreach ($j[0] as $chunk) {
            if (isset($chunk[0])) $en .= (string)$chunk[0];
        }
        $en = trim($en);
        if ($en === '' || preg_match('/[\x{4e00}-\x{9fff}]/u', $en)) return null;

        return ['en' => $en, 'source' => 'google_unofficial', 'confidence' => 0.9];
    }

    /** MyMemory anonymous */
    private static function callMyMemory(string $translated): ?array {
        $url = self::ENDPOINT
            . '?q=' . urlencode($translated)
            . '&langpair=en|zh-CN'
            . '&de=nai-studio@example.com';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            Logger::warn('translator.mymemory.fail', ['code' => $code]);
            return null;
        }

        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['responseData']['translatedText'])) {
            return null;
        }

        $cn = trim((string)$j['responseData']['translatedText']);
        $cn = preg_replace('/^(MYMEMORY WARNING[:\s]*|PLEASE.*$)/i', '', $cn);
        $cn = trim($cn);
        $confidence = (float)($j['responseData']['match'] ?? 0);

        if ($cn === '' || stripos($cn, 'WARNING') !== false) return null;
        // 避免和原文一样（说明没翻译）
        if (strtolower($cn) === strtolower($translated)) return null;

        return ['cn' => $cn, 'source' => 'mymemory', 'confidence' => $confidence];
    }

    /** LibreTranslate 公共实例（免费、免 key） */
    private const LIBRETRANSLATE_PUBLIC = [
        'https://libretranslate.com',
        'https://libretranslate.de',
        'https://translate.argosopentech.com',
        'https://translate.terraprint.co',
    ];

    private static function callLibreTranslatePublic(string $base, string $translated): ?array {
        $payload = json_encode([
            'q'      => $translated,
            'source' => 'en',
            'target' => 'zh',
            'format' => 'text',
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($base . '/translate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) return null;

        $j = json_decode($body, true);
        $cn = is_array($j) ? trim((string)($j['translatedText'] ?? '')) : '';
        if ($cn === '' || strtolower($cn) === strtolower($translated)) return null;

        Logger::info('translator.libretranslate.ok', ['endpoint' => $base]);
        return ['cn' => $cn, 'source' => 'libretranslate', 'confidence' => 0.8];
    }

    /**
     * Google 翻译 - 模拟 Chrome 扩展端点
     *
     * 端点：https://translate.googleapis.com/translate_a/single
     * 参数：client=dict-chrome-ex&sl=en&tl=zh-CN&dt=t&q=...
     *
     * 风险：
     *   - 这是 Google 翻译网页版的"内部"端点，没明文公开
     *   - 高频调用可能触发 Google 反爬（429/403）
     *   - 违反 Google TOS（个人小用风险低）
     *   - Google 改接口随时会挂
     *
     * 默认关闭，需要用户在 settings 明确开启"非官方 fallback"
     */
    private static function callGoogleTranslateUnofficial(string $translated): ?array {
        $url = 'https://translate.googleapis.com/translate_a/single'
            . '?client=dict-chrome-ex'
            . '&sl=en&tl=zh-CN'
            . '&dt=t'              // 翻译模式
            . '&q=' . urlencode($translated);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            Logger::warn('translator.google.fail', ['code' => $code, 'q_len' => strlen($translated)]);
            return null;
        }

        // 返回格式：嵌套数组 [[ ["翻译结果","原文",null,null,1], ... ], null, ...]
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j[0]) || !is_array($j[0])) {
            return null;
        }

        $cn = '';
        foreach ($j[0] as $part) {
            if (is_array($part) && isset($part[0]) && is_string($part[0])) {
                $cn .= $part[0];
            }
        }
        $cn = trim($cn);
        if ($cn === '' || strtolower($cn) === strtolower($translated)) return null;

        Logger::info('translator.google.ok', ['q_len' => strlen($translated), 'cn_len' => strlen($cn)]);
        return ['cn' => $cn, 'source' => 'google-unofficial', 'confidence' => 0.9];
    }
}