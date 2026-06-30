<?php
/**
 * NAI Studio - Settings manager
 * Singleton row in settings table. Encrypts API key.
 */

declare(strict_types=1);

namespace NaiStudio;

class Settings {
    public static function get(): array {
        $row = Db::fetchOne("SELECT * FROM settings WHERE id = 1");
        if (!$row) {
            Db::insert('settings', ['id' => 1]);
            $row = Db::fetchOne("SELECT * FROM settings WHERE id = 1");
        }
        // Decrypt API key for internal use
        $row['api_key_plain'] = null;
        if (!empty($row['api_key_encrypted'])) {
            $row['api_key_plain'] = Encryption::decrypt($row['api_key_encrypted']);
        }
        // Don't expose encrypted blob via JSON
        $row['api_key_encrypted'] = null;
        // Parse JSON fields
        if (!empty($row['ui_state'])) {
            $row['ui_state'] = json_decode($row['ui_state'], true);
        }
        return $row;
    }

    public static function update(array $data): array {
        // API key: if provided, encrypt before storing
        if (array_key_exists('api_key', $data)) {
            $key = $data['api_key'];
            if ($key === '' || $key === null) {
                $data['api_key_encrypted']   = null;
                $data['api_key_fingerprint'] = null;
            } else {
                $data['api_key_encrypted']   = Encryption::encrypt($key);
                $data['api_key_fingerprint'] = substr($key, -4);
            }
            unset($data['api_key']);
        }
        // UI state: encode JSON
        if (array_key_exists('ui_state', $data) && is_array($data['ui_state'])) {
            $data['ui_state'] = json_encode($data['ui_state'], JSON_UNESCAPED_UNICODE);
        }
        // Whitelist of updatable columns
        $allowed = [
            'api_key_encrypted','api_key_fingerprint',
            'default_model','default_sampler','default_steps','default_scale',
            'default_cfg_rescale','default_noise_schedule','default_size',
            'default_uc_preset','quality_toggle','emphasis_highlight',
            'theme','proxy_enabled','proxy_url','proxy_test_status','proxy_tested_at',
            'local_translate_enabled','local_translate_url',
            'local_translate_status','local_translate_tested_at',
            'translate_source',  // v1.1.4: 'off' | 'fallback' | 'local'
            'aggressive_fallback_enabled',
            'danbooru_username','danbooru_api_key',
            'deepseek_api_key','deepseek_model','deepseek_base_url',
            'deepseek_status','deepseek_tested_at','ai_advisor_enabled',
            'ui_state','anlas_balance','anlas_updated_at',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        if (!empty($data)) {
            Db::update('settings', 1, $data);
        }
        return self::get();
    }

    /** Internal: get the decrypted API key (for NAI requests). */
    public static function getApiKey(): ?string {
        $row = Db::fetchOne("SELECT api_key_encrypted FROM settings WHERE id = 1");
        if (!$row || empty($row['api_key_encrypted'])) return null;
        return Encryption::decrypt($row['api_key_encrypted']);
    }

    /** Get proxy URL if enabled, else null. */
    public static function getProxyUrl(): ?string {
        $row = Db::fetchOne("SELECT proxy_enabled, proxy_url FROM settings WHERE id = 1");
        if (!$row || empty($row['proxy_enabled']) || empty($row['proxy_url'])) return null;
        return trim((string)$row['proxy_url']);
    }

    /** Get local translate URL if enabled, else null. */
    public static function getLocalTranslateUrl(): ?string {
        $row = Db::fetchOne("SELECT local_translate_enabled, local_translate_url FROM settings WHERE id = 1");
        if (!$row || empty($row['local_translate_enabled']) || empty($row['local_translate_url'])) return null;
        return trim((string)$row['local_translate_url']);
    }

    public static function getLocalTranslateEnabled(): bool {
        $row = Db::fetchOne("SELECT local_translate_enabled FROM settings WHERE id = 1");
        return !empty($row) && !empty($row['local_translate_enabled']);
    }

    /**
     * v1.1.4 翻译源选择
     *   'off'      — 不使用本地，只走在线（MyMemory / Google / AI）
     *   'fallback' — 在线优先，本地兜底（推荐：又快又能离线工作）
     *   'local'    — 只用本地，失败报"未翻译"
     */
    public static function getTranslateSource(): string {
        $row = Db::fetchOne("SELECT translate_source FROM settings WHERE id = 1");
        $v = trim((string)($row['translate_source'] ?? 'fallback'));
        if (!in_array($v, ['off', 'fallback', 'local'], true)) return 'fallback';
        return $v;
    }

    /** 是否需要尝试本地翻译（off → false；fallback / local → true） */
    public static function shouldTryLocal(): bool {
        return self::getTranslateSource() !== 'off';
    }

    /** 是否需要兜底到在线（off / fallback → true；local → false） */
    public static function shouldFallbackToOnline(): bool {
        return self::getTranslateSource() !== 'local';
    }

    public static function getAggressiveFallbackEnabled(): bool {
        $row = Db::fetchOne("SELECT aggressive_fallback_enabled FROM settings WHERE id = 1");
        return !empty($row) && !empty($row['aggressive_fallback_enabled']);
    }

    /** Test the proxy URL by attempting to reach NAI user/subscription through it. */
    public static function testProxy(): array {
        $row = Db::fetchOne("SELECT proxy_enabled, proxy_url FROM settings WHERE id = 1");
        $url = trim((string)($row['proxy_url'] ?? ''));
        if ($url === '') return ['ok' => false, 'message' => '代理 URL 为空'];
        $apiKey = self::getApiKey();
        if (!$apiKey) return ['ok' => false, 'message' => '未设置 API Key，无法测试'];

        $target = 'https://api.novelai.net/user/subscription';
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_PROXY          => $url,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        $msg = '';
        if ($body !== false && $code === 200) {
            $msg = '✓ 代理可用，连上 NAI 成功';
            Db::update('settings', 1, ['proxy_test_status' => 'ok:' . $code, 'proxy_tested_at' => date('Y-m-d H:i:s')]);
            return ['ok' => true, 'message' => $msg, 'code' => $code];
        }
        if ($errno === 56 || $errno === 7) {
            $msg = '✗ 连不上代理服务器（errno=' . $errno . '）';
        } elseif ($code > 0) {
            $msg = '✗ 代理连上了，但 NAI 返 ' . $code . '（可能是 Key 或代理本身的问题）';
        } else {
            $msg = '✗ 代理测试失败（errno=' . $errno . '）';
        }
        Db::update('settings', 1, ['proxy_test_status' => 'fail:' . $code, 'proxy_tested_at' => date('Y-m-d H:i:s')]);
        return ['ok' => false, 'message' => $msg, 'code' => $code];
    }

    public static function setAnlas(?int $balance): void {
        Db::pdo()->prepare("UPDATE settings SET anlas_balance = ?, anlas_updated_at = NOW() WHERE id = 1")
            ->execute([$balance]);
    }
}
