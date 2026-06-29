<?php
/**
 * NAI Studio - DeepSeek API client
 *
 * OpenAI 兼容协议的 chat completions 封装
 * 默认 base_url: https://api.deepseek.com/v1
 * 默认 model: deepseek-chat
 *
 * 价格：~1-4 元/百万 token（极便宜）
 * 注册：https://platform.deepseek.com/
 */

declare(strict_types=1);

namespace NaiStudio;

class DeepSeekHelper {

    private const DEFAULT_BASE = 'https://api.deepseek.com/v1';
    private const DEFAULT_MODEL = 'deepseek-chat';
    private const TIMEOUT = 30;

    /**
     * Chat completion
     *
     * @param string $system system prompt
     * @param string $user   user prompt
     * @param array $opts {temperature, max_tokens, model, json_mode}
     * @return array{content: string, usage: array, model: string, ms: int}
     * @throws \RuntimeException on failure
     */
    public static function chat(string $system, string $user, array $opts = []): array {
        return self::chatWithMessages(array_merge(
            [['role' => 'system', 'content' => $system]],
            [['role' => 'user',   'content' => $user]],
        ), $opts);
    }

    /**
     * Multi-turn chat completion (used by AI prompt composer 等多轮对话场景)
     *
     * @param array $messages [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     * @param array $opts {temperature, max_tokens, model, json_mode}
     * @return array{content: string, usage: array, model: string, ms: int}
     */
    public static function chatWithMessages(array $messages, array $opts = []): array {
        $cfg = self::config();
        if (empty($cfg['api_key'])) {
            throw new \RuntimeException('DeepSeek API key 未配置');
        }
        if (empty($messages)) throw new \RuntimeException('messages 不能为空');

        $model = $opts['model'] ?? $cfg['model'] ?? self::DEFAULT_MODEL;
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.3;
        $maxTokens = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 2000;
        $jsonMode = !empty($opts['json_mode']);

        $body = [
            'model'       => $model,
            'messages'    => array_values($messages),
            'temperature' => $temperature,
            'max_tokens'   => $maxTokens,
        ];
        if ($jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $url = rtrim($cfg['base_url'] ?? self::DEFAULT_BASE, '/') . '/chat/completions';
        $t0 = microtime(true);
        $ch = curl_init($url);
        $proxy = Settings::getProxyUrl();
        $opts2 = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_USERAGENT      => 'NAI-Studio/1.0 (local)',
        ];
        if ($proxy) $opts2[CURLOPT_PROXY] = $proxy;
        curl_setopt_array($ch, $opts2);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);

        if ($resp === false || $code >= 400) {
            Logger::warn('deepseek.chat.fail', ['code' => $code, 'err' => $err, 'body' => substr((string)$resp, 0, 300)]);
            throw new \RuntimeException('DeepSeek 请求失败: HTTP ' . $code . ($err ? ' / ' . $err : ''));
        }

        $j = json_decode($resp, true);
        if (!is_array($j) || empty($j['choices'][0]['message']['content'])) {
            throw new \RuntimeException('DeepSeek 返回格式异常');
        }

        return [
            'content' => (string)$j['choices'][0]['message']['content'],
            'usage'   => $j['usage'] ?? [],
            'model'   => $j['model'] ?? $model,
            'ms'      => $ms,
        ];
    }

    /**
     * Test connection
     * @return array{ok: bool, message: string}
     */
    public static function test(): array {
        try {
            $r = self::chat(
                'You are a translator.',
                'Translate to Chinese: long_hair',
                ['max_tokens' => 50]
            );
            $cn = trim($r['content']);
            if ($cn === '' || $cn === 'long_hair') {
                throw new \RuntimeException('返回内容异常: ' . $cn);
            }
            Db::update('settings', 1, [
                'deepseek_status' => 'ok',
                'deepseek_tested_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => '✓ DeepSeek 可用：' . $cn . '  (' . $r['ms'] . 'ms)'];
        } catch (\Throwable $e) {
            Db::update('settings', 1, [
                'deepseek_status' => 'fail',
                'deepseek_tested_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => false, 'message' => '✗ ' . $e->getMessage()];
        }
    }

    /**
     * Get config from DB
     */
    public static function config(): array {
        $row = Db::fetchOne("SELECT deepseek_api_key, deepseek_model, deepseek_base_url, ai_advisor_enabled FROM settings WHERE id = 1");
        return [
            'api_key' => $row['deepseek_api_key'] ?? null,
            'model'   => $row['deepseek_model']   ?? self::DEFAULT_MODEL,
            'base_url'=> $row['deepseek_base_url']?? self::DEFAULT_BASE,
            'enabled' => !empty($row['ai_advisor_enabled']),
        ];
    }

    public static function isEnabled(): bool {
        $cfg = self::config();
        return $cfg['enabled'] && !empty($cfg['api_key']);
    }
}
