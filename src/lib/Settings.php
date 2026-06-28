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
