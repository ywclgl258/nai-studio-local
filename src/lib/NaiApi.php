<?php
/**
 * NAI Studio - NAI API client
 * Handles image generation, anlas lookup, and proxy fallback.
 */

declare(strict_types=1);

namespace NaiStudio;

class NaiApi {
    private string $apiKey;
    private ?string $proxy;

    public function __construct(string $apiKey, ?string $proxy = null) {
        $this->apiKey = $apiKey;
        // 优先用 settings 里的代理；fallback 到构造参数
        $this->proxy = Settings::getProxyUrl() ?? $proxy;
    }

    /**
     * 判断 proxy 是 HTTP 代理（Clash/v2rayN 7897）还是 NAI 镜像（mmw.ink）。
     *  - HTTP 代理：URL 是 http://127.0.0.1:PORT  /  socks5://...  → 用 CURLOPT_PROXY，URL 仍走 NAI 官方
     *  - NAI 镜像：URL 是 https://mirror.xxx            → 直接拼路径当镜像
     */
    private function isHttpProxy(string $url): bool {
        return (bool)preg_match('#^(http://127\.0\.0\.1|socks5://)#i', $url);
    }

    /** NAI 实际请求的 URL（HTTP 代理模式 → 走 NAI 官方；镜像模式 → 走镜像） */
    private function endpoint(string $path, string $fallbackConfig): string {
        if (!$this->proxy) return config($fallbackConfig);
        if ($this->isHttpProxy($this->proxy)) return config($fallbackConfig);
        // 镜像模式：拼到代理 URL
        return rtrim($this->proxy, '/') . $path;
    }

    /**
     * Generate image(s).
     * @param array $payload Full NAI generate-image payload
     * @return array{ok:bool, status:int, data:?string, error:?string, headers?:array}
     *   data = base64 PNG (single) or array of base64 PNGs
     */
    public function generate(array $payload): array {
        $url = $this->endpoint('/ai/generate-image', 'nai.generate_url');
        $start = microtime(true);
        // post() 内部已加 Accept / Content-Type / Authorization，这里只传业务 headers
        $resp = $this->post($url, $payload);
        $ms = (int)((microtime(true) - $start) * 1000);

        Logger::info('nai.generate', [
            'status'   => $resp['status'],
            'ms'       => $ms,
            'is_err'   => $resp['ok'] ? 0 : 1,
        ]);

        if (!$resp['ok']) {
            return [
                'ok'     => false,
                'status' => $resp['status'],
                'data'   => null,
                'error'  => $resp['error'] ?? 'NAI request failed',
            ];
        }

        // NAI returns raw image bytes (single) or zip with multiple images
        $body = $resp['raw'];
        $ct = strtolower($resp['headers']['content-type'] ?? '');
        $cd = strtolower($resp['headers']['content-disposition'] ?? '');
        // 识别 ZIP：content-type 是 application/zip 或 binary/octet-stream
        // 且 content-disposition 包含 .zip 文件名，或者 body 以 PK\x03\x04 开头
        $isZip = str_contains($ct, 'application/zip')
            || (str_contains($ct, 'binary/octet-stream') && str_contains($cd, '.zip'))
            || (strlen($body) >= 4 && substr($body, 0, 4) === "PK\x03\x04");
        if ($isZip) {
            $images = self::extractZipImages($body);
            if (empty($images)) {
                return [
                    'ok'     => false,
                    'status' => 200,
                    'data'   => null,
                    'error'  => 'NAI returned ZIP but no images found',
                ];
            }
            return [
                'ok'     => true,
                'status' => 200,
                'data'   => $images,
                'ms'     => $ms,
            ];
        }
        if (str_contains($ct, 'image/')) {
            return [
                'ok'     => true,
                'status' => 200,
                'data'   => [base64_encode($body)],
                'ms'     => $ms,
            ];
        }
        // 也尝试：body 是单张 PNG（PK\x03\x04 不匹配，content-type 是 image/png 但被压缩代理改了头）
        if (strlen($body) > 8) {
            $sig = substr($body, 0, 8);
            $isPng = $sig === "\x89PNG\r\n\x1a\n";
            $isJpg = substr($body, 0, 3) === "\xFF\xD8\xFF";
            if ($isPng || $isJpg) {
                return [
                    'ok'     => true,
                    'status' => 200,
                    'data'   => [base64_encode($body)],
                    'ms'     => $ms,
                ];
            }
        }
        // Unknown content type; treat as text error
        $errText = substr($body, 0, 500);
        return [
            'ok'     => false,
            'status' => $resp['status'],
            'data'   => null,
            'error'  => 'Unexpected response (ct=' . $ct . '): ' . $errText,
        ];
    }

    /** Get anlas balance. Returns ['ok'=>bool, 'anlas'=>?int, 'expiresAt'=>?int, 'error'=>?string]. */
    public function getAnlas(): array {
        $url = $this->endpoint('/user/subscription', 'nai.user_url');
        $resp = $this->get($url);
        if (!$resp['ok']) {
            return ['ok' => false, 'anlas' => null, 'error' => $resp['error'] ?? 'Anlas query failed'];
        }
        $data = json_decode($resp['raw'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'anlas' => null, 'error' => 'Invalid anlas response'];
        }
        return [
            'ok'        => true,
            'anlas'     => isset($data['anlas']) ? (int)$data['anlas'] : null,
            'expiresAt' => isset($data['expiresAt']) ? (int)$data['expiresAt'] : null,
            'tier'      => $data['tier'] ?? null,
        ];
    }

    /** Get unauthenticated / public info (no key needed). */
    public function getModels(): array {
        $allowed = config('generation.allowed_models');
        $result = [];
        foreach ($allowed as $id => $name) {
            $result[] = ['id' => $id, 'name' => $name];
        }
        return $result;
    }

    // =======================================================================
    // HTTP helpers (curl)
    // =======================================================================

    private function post(string $url, array $payload, array $headers = []): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: */*',
                'Content-Type: application/json',
            ], $this->authHeader(), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => config('nai.timeout', 300),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
            // 显式 UA 避免 PHP 默认空 UA 被 Cloudflare 拦截（不是 TLS 层，只是 header 检查）
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];
        if ($this->proxy) $opts[CURLOPT_PROXY] = $this->proxy;
        curl_setopt_array($ch, $opts);
        return self::execCurl($ch);
    }

    private function get(string $url, array $headers = []): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $this->authHeader(), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];
        if ($this->proxy) $opts[CURLOPT_PROXY] = $this->proxy;
        curl_setopt_array($ch, $opts);
        return self::execCurl($ch);
    }

    /** Bearer token, sent on every request. Empty if no key set (caller should not invoke). */
    private function authHeader(): array {
        return $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
    }

    private static function execCurl($ch): array {
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = $raw !== false ? substr($raw, 0, $headerSize) : '';
        $body = $raw !== false ? substr($raw, $headerSize) : '';
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'error' => "curl error ($errno): $error", 'raw' => '', 'headers' => []];
        }

        $headers = self::parseHeaders($headerStr);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'status' => $code, 'raw' => $body, 'headers' => $headers];
        }
        return [
            'ok'     => false,
            'status' => $code,
            'error'  => 'HTTP ' . $code . ': ' . substr($body, 0, 500),
            'raw'    => $body,
            'headers'=> $headers,
        ];
    }

    private static function parseHeaders(string $str): array {
        $headers = [];
        $lines = preg_split('/\r?\n/', $str);
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }

    /** Extract image files from a NAI ZIP response. */
    /**
     * 从 NAI 返回的 ZIP bytes 提取内嵌图片（PNG/JPG/WEBP）
     * - 优先用 ZipArchive 扩展
     * - 扩展不存在时用 phar:// stream wrapper（PHP 内置，无需扩展）
     * - 都没有时回退到 raw bytes（理论上不会发生）
     */
    private static function extractZipImages(string $zipBytes): array {
        // 方案 1：ZipArchive 扩展（注意命名空间：用 \ZipArchive）
        if (class_exists('\ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'nai_zip_');
            file_put_contents($tmp, $zipBytes);
            $za = new \ZipArchive();
            $images = [];
            if ($za->open($tmp) === true) {
                for ($i = 0; $i < $za->numFiles; $i++) {
                    $stat = $za->statIndex($i);
                    if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $stat['name'])) {
                        $img = $za->getFromIndex($i);
                        if ($img !== false) $images[] = base64_encode($img);
                    }
                }
                $za->close();
            }
            @unlink($tmp);
            if (!empty($images)) return $images;
        }

        // 方案 2：phar:// stream wrapper（PHP 内置，零依赖）
        // 注意：本类在 NaiStudio 命名空间下，必须用 \ 前缀引用根命名空间类
        $tmp = tempnam(sys_get_temp_dir(), 'nai_zip_') . '.zip';
        file_put_contents($tmp, $zipBytes);
        $images = [];
        try {
            $rpath = realpath($tmp);
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator('phar://' . $rpath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile() && preg_match('/\.(png|jpg|jpeg|webp)$/i', $f->getFilename())) {
                    $img = file_get_contents($f->getPathname());
                    if ($img !== false && strlen($img) > 100) {
                        $images[] = base64_encode($img);
                    }
                }
            }
            error_log('[NaiApi] phar zip extracted ' . count($images) . ' images');
        } catch (\Throwable $e) {
            error_log('[NaiApi] phar zip read failed: ' . $e->getMessage());
        }
        @unlink($tmp);

        if (!empty($images)) return $images;

        // 方案 3：fallback 把整个 ZIP 当单图（不理想但不会崩）
        error_log('[NaiApi] WARNING: ZIP extraction failed both ways, returning raw bytes');
        return [base64_encode($zipBytes)];
    }
}
