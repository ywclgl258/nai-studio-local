<?php
/**
 * NAI Studio - 通用 AI Provider
 *
 * 支持多种 OpenAI 兼容 API：
 *   - deepseek     DeepSeek 官方（V3 / R1）
 *   - openai       OpenAI 官方（GPT-4o / o1 / o3）
 *   - siliconflow  硅基流动（国内，有免费层 + DeepSeek 蒸馏）
 *   - openrouter   OpenRouter（聚合 100+ 模型，部分免费）
 *   - ollama       本地 Ollama（不用 API key，reasoning_effort 不支持）
 *   - custom       自定义 base_url（任何 OpenAI 兼容服务）
 *
 * Usage:
 *   $cfg = AiProvider::config();
 *   $r = AiProvider::chat($messages, ['temperature' => 0.5]);
 */

declare(strict_types=1);

namespace NaiStudio;

class AiProvider {

    /**
     * 预设 provider 元信息
     * @return array<string, array{label: string, base_url: string, models: string[], supports_reasoning: bool, needs_key: bool, free: bool, note: string}>
     */
    public static function presets(): array {
        return [
            'deepseek' => [
                'label'             => 'DeepSeek（官方）',
                'base_url'          => 'https://api.deepseek.com/v1',
                'models'            => [
                    'deepseek-v4-pro',    // 最新旗舰，V4 Pro（默认）
                    'deepseek-v4-flash',  // V4 Flash，速度快、便宜
                ],
                'supports_reasoning' => false,    // V4 Pro / Flash 内部有 thinking 但不支持外部 effort 参数
                'needs_key'         => true,
                'free'              => false,
                'note'              => '注册送 ¥1-5 余额，V4 Pro ≈ V3 价格，V4 Flash 更便宜更快',
            ],
            'openai' => [
                'label'             => 'OpenAI（官方）',
                'base_url'          => 'https://api.openai.com/v1',
                'models'            => ['gpt-4o-mini', 'gpt-4o', 'o1-mini', 'o1', 'o3-mini', 'o3'],
                'supports_reasoning' => true,    // o1/o3 系列支持 reasoning_effort
                'needs_key'         => true,
                'free'              => false,
                'note'              => '需要海外信用卡 · o1/o3 系列支持推理等级',
            ],
            'siliconflow' => [
                'label'             => '硅基流动 SiliconFlow',
                'base_url'          => 'https://api.siliconflow.cn/v1',
                'models'            => [
                    'deepseek-ai/DeepSeek-V3',          // 付费，跟官方
                    'deepseek-ai/DeepSeek-R1',          // 付费，推理
                    'Qwen/Qwen2.5-7B-Instruct',         // 免费
                    'THUDM/glm-4-9b-chat',              // 免费
                    'meta-llama/Meta-Llama-3.1-8B-Instruct', // 免费
                ],
                'supports_reasoning' => false,
                'needs_key'         => true,
                'free'              => true,    // 有 Qwen / GLM / Llama 等免费模型
                'note'              => '国内直连 · 实名后有免费 token 池（Qwen2.5/GLM4/Llama3.1 等）',
            ],
            'openrouter' => [
                'label'             => 'OpenRouter（聚合）',
                'base_url'          => 'https://openrouter.ai/api/v1',
                'models'            => [
                    'deepseek/deepseek-chat:free',     // 免费
                    'deepseek/deepseek-r1:free',       // 免费，推理
                    'meta-llama/llama-3.3-70b-instruct:free',
                    'qwen/qwen-2.5-72b-instruct:free',
                ],
                'supports_reasoning' => false,
                'needs_key'         => true,
                'free'              => true,    // 部分模型 :free 标识免费
                'note'              => '国外 · 100+ 模型 · 找带 :free 后缀的不要钱',
            ],
            'ollama' => [
                'label'             => 'Ollama（本地）',
                'base_url'          => 'http://127.0.0.1:11434/v1',
                'models'            => [
                    'deepseek-r1:7b', 'deepseek-r1:14b', 'deepseek-r1:32b',
                    'qwen2.5:7b', 'qwen2.5:14b',
                    'llama3.1:8b', 'llama3.1:70b',
                ],
                'supports_reasoning' => false,
                'needs_key'         => false,    // 本地不用 key（但 ollama 也接受任意字符串）
                'free'              => true,
                'note'              => '本地跑，免费 · 需要先 `ollama serve` · 需较强 GPU',
            ],
            'custom' => [
                'label'             => '自定义（OpenAI 兼容）',
                'base_url'          => '',
                'models'            => [],
                'supports_reasoning' => true,    // 通用兜底
                'needs_key'         => true,
                'free'              => false,
                'note'              => '填自己的 base_url + 模型名，兼容任何 OpenAI 格式 API',
            ],
        ];
    }

    /**
     * 读配置：优先用新字段（ai_*），回退老字段（deepseek_*）
     * @return array{provider, base_url, api_key, model, reasoning_effort, enabled, status, tested_at}
     */
    public static function config(): array {
        $row = Db::fetchOne("SELECT
                ai_advisor_enabled, ai_provider, ai_base_url, ai_api_key, ai_model, ai_reasoning_effort,
                deepseek_api_key, deepseek_model, deepseek_base_url
            FROM settings WHERE id = 1");
        if (!$row) {
            return self::defaultConfig();
        }

        $provider = $row['ai_provider'] ?? 'deepseek';
        $presets = self::presets();
        $preset = $presets[$provider] ?? $presets['deepseek'];

        // 兼容：老字段没空时优先用新字段
        $baseUrl = $row['ai_base_url'] ?: ($row['deepseek_base_url'] ?? $preset['base_url']);
        $apiKey  = $row['ai_api_key']  ?: ($row['deepseek_api_key']  ?? '');
        $model   = $row['ai_model']    ?: ($row['deepseek_model']    ?? '');

        // DeepSeek 旧模型名 → V4（用户数据库可能存的是 V3 时代的 deepseek-chat / deepseek-reasoner）
        if ($provider === 'deepseek') {
            if ($model === 'deepseek-chat')    $model = 'deepseek-v4-pro';
            if ($model === 'deepseek-reasoner') $model = 'deepseek-v4-pro';
        }
        // 兜底：用 preset 的第一个模型
        if (!$model && !empty($preset['models'])) {
            $model = $preset['models'][0];
        }

        return [
            'provider'         => $provider,
            'base_url'         => $baseUrl,
            'api_key'          => $apiKey,
            'model'            => $model,
            'reasoning_effort' => $row['ai_reasoning_effort'] ?? null,
            'enabled'          => !empty($row['ai_advisor_enabled']),
            'status'           => $row['deepseek_status'] ?? null,    // 复用老字段存状态
            'tested_at'        => $row['deepseek_tested_at'] ?? null,
            'preset'           => $preset,
        ];
    }

    private static function defaultConfig(): array {
        return [
            'provider' => 'deepseek', 'base_url' => 'https://api.deepseek.com/v1',
            'api_key' => '', 'model' => 'deepseek-v4-pro', 'reasoning_effort' => null,
            'enabled' => false, 'status' => null, 'tested_at' => null,
            'preset' => self::presets()['deepseek'],
        ];
    }

    public static function isEnabled(): bool {
        $c = self::config();
        return $c['enabled'] && ($c['api_key'] !== '' || $c['provider'] === 'ollama');
    }

    /**
     * 保存配置到 DB
     */
    public static function saveConfig(array $data): void {
        $allowed = ['provider', 'base_url', 'api_key', 'model', 'reasoning_effort', 'enabled'];
        $patch = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) $patch[$k] = $data[$k];
        }
        // 字段名映射
        $dbPatch = [];
        if (isset($patch['provider']))   $dbPatch['ai_provider'] = $patch['provider'];
        if (isset($patch['base_url']))   $dbPatch['ai_base_url'] = $patch['base_url'];
        if (isset($patch['api_key']))    $dbPatch['ai_api_key']  = $patch['api_key'];
        if (isset($patch['model']))      $dbPatch['ai_model']    = $patch['model'];
        if (isset($patch['reasoning_effort'])) $dbPatch['ai_reasoning_effort'] = $patch['reasoning_effort'] ?: null;
        if (isset($patch['enabled']))    $dbPatch['ai_advisor_enabled'] = !empty($patch['enabled']) ? 1 : 0;
        // 同步到 deepseek_* 字段（向后兼容，model=deepseek-* 时）
        if (!empty($dbPatch['ai_base_url'])) $dbPatch['deepseek_base_url'] = $dbPatch['ai_base_url'];
        if (isset($dbPatch['ai_api_key']))   $dbPatch['deepseek_api_key']  = $dbPatch['ai_api_key'];
        if (!empty($dbPatch['ai_model']) && str_starts_with($dbPatch['ai_model'], 'deepseek')) {
            $dbPatch['deepseek_model'] = $dbPatch['ai_model'];
        }
        if (!empty($dbPatch)) Db::update('settings', 1, $dbPatch);
    }

    /**
     * Chat completion（OpenAI 兼容协议）
     *
     * @param array $messages [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     * @param array $opts {temperature, max_tokens, model, json_mode}
     * @return array{content: string, usage: array, model: string, ms: int}
     */
    public static function chat(array $messages, array $opts = []): array {
        $cfg = self::config();
        if (empty($cfg['api_key']) && $cfg['provider'] !== 'ollama') {
            throw new \RuntimeException('未配置 API key');
        }
        if (empty($messages)) throw new \RuntimeException('messages 不能为空');

        $model = $opts['model'] ?? $cfg['model'];
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.3;
        $maxTokens = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 2000;
        $jsonMode = !empty($opts['json_mode']);

        $body = [
            'model'       => $model,
            'messages'    => array_values($messages),
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];
        // 推理等级（OpenAI o1/o3 系列 + 其他兼容服务）
        if (!empty($cfg['reasoning_effort']) && in_array($cfg['reasoning_effort'], ['low', 'medium', 'high'])) {
            $body['reasoning_effort'] = $cfg['reasoning_effort'];
        }
        if ($jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $url = rtrim($cfg['base_url'], '/') . '/chat/completions';
        $t0 = microtime(true);
        $ch = curl_init($url);
        $proxy = Settings::getProxyUrl();
        $opts2 = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($cfg['api_key'] ?: 'ollama'),
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
            Logger::warn('aiprovider.chat.fail', [
                'provider' => $cfg['provider'], 'code' => $code, 'err' => $err,
                'body' => substr((string)$resp, 0, 300),
            ]);
            throw new \RuntimeException('AI 请求失败: HTTP ' . $code . ($err ? ' / ' . $err : '') . ' · ' . substr((string)$resp, 0, 200));
        }

        $j = json_decode($resp, true);
        if (!is_array($j) || empty($j['choices'][0]['message']['content'])) {
            throw new \RuntimeException('AI 返回格式异常: ' . substr($resp, 0, 200));
        }

        return [
            'content' => (string)$j['choices'][0]['message']['content'],
            'usage'   => $j['usage'] ?? [],
            'model'   => $j['model'] ?? $model,
            'ms'      => $ms,
        ];
    }

    /**
     * 简单 chat：system + user（多轮用 chatWithMessages）
     */
    public static function chatSimple(string $system, string $user, array $opts = []): array {
        return self::chat(array_merge(
            [['role' => 'system', 'content' => $system]],
            [['role' => 'user',   'content' => $user]],
        ), $opts);
    }

    /**
     * 测试连接
     * @return array{ok: bool, message: string, model?: string, ms?: int}
     */
    public static function test(): array {
        try {
            $cfg = self::config();
            $r = self::chat([
                ['role' => 'system', 'content' => 'You are a translator.'],
                ['role' => 'user',   'content' => 'Translate to Chinese: long_hair'],
            ], ['max_tokens' => 50]);
            $cn = trim($r['content']);
            if ($cn === '' || strtolower($cn) === 'long_hair') {
                throw new \RuntimeException('返回内容异常: ' . $cn);
            }
            $label = $cfg['preset']['label'] ?? $cfg['provider'];
            Db::update('settings', 1, [
                'deepseek_status'    => 'ok',
                'deepseek_tested_at' => date('Y-m-d H:i:s'),
            ]);
            return [
                'ok'      => true,
                'message' => "✓ {$label} · {$r['model']} · {$r['ms']}ms → {$cn}",
                'model'   => $r['model'],
                'ms'      => $r['ms'],
            ];
        } catch (\Throwable $e) {
            Db::update('settings', 1, [
                'deepseek_status'    => 'fail',
                'deepseek_tested_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => false, 'message' => '✗ ' . $e->getMessage()];
        }
    }
}
