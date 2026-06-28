<?php
/**
 * POST /api/generate.php
 * Body: {
 *   prompt, negative_prompt, model, sampler, steps, scale, seed, width, height,
 *   cfg_rescale, noise_schedule, uc_preset, quality_toggle, n_samples,
 *   characters: [{prompt, position, gender}],
 *   vibe_refs: [{path, strength, info_extracted}],
 *   precise_refs: [{path, type, strength, info_extracted}],
 *   strength, noise, base_image (base64), mask (base64),
 *   parent_id, batch_id
 * }
 *
 * Response: {ok, id, image_path, thumbnail_path, ms, anlas, meta}
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\GalleryManager;
use NaiStudio\Logger;
use NaiStudio\NaiApi;
use NaiStudio\Settings;

$body = read_json_body();
$apiKey = Settings::getApiKey();
if (!$apiKey) error_response('API key not configured. Set it in Settings.', 401);

$prompt = trim((string)($body['prompt'] ?? ''));
$posePrompt   = trim((string)($body['pose_prompt']   ?? ''));

// 兼容旧版 character_prompt（单 string）和新版 character_prompts（数组）
$charPromptsArr = [];
if (isset($body['character_prompts']) && is_array($body['character_prompts'])) {
    $charPromptsArr = array_values(array_filter(
        array_map('trim', $body['character_prompts']),
        fn($v) => $v !== ''
    ));
} elseif (isset($body['character_prompt'])) {
    $legacy = trim((string)$body['character_prompt']);
    if ($legacy !== '') $charPromptsArr = [$legacy];
}
$charPrompt = implode(', ', $charPromptsArr);

if ($prompt === '' && $charPrompt === '' && $posePrompt === '') {
    error_response('Prompt / 角色 / 姿势 至少填一个', 400);
}

/** 拼接：主提示词 + 角色提示词 + 姿势提示词 */
$parts = array_filter([$prompt, $charPrompt, $posePrompt], fn($v) => $v !== '');
$prompt = implode(', ', $parts);

// Normalize fields
$model        = $body['model']        ?? config('generation.default_size');
$model        = $body['model']        ?? 'nai-diffusion-4-5-curated';
$allowedModels = config('generation.allowed_models');
if (!isset($allowedModels[$model])) error_response('Invalid model', 400);
$sampler      = $body['sampler']      ?? 'k_euler_ancestral';
$allowedSamplers = config('generation.allowed_samplers');
if (!in_array($sampler, $allowedSamplers, true)) error_response('Invalid sampler', 400);

$steps        = max(1, min(50, (int)($body['steps'] ?? 28)));
$scale        = max(0, min(20, (float)($body['scale'] ?? 5.0)));
$seed         = $body['seed'] ?? random_int(0, 4294967295);
$seed         = max(0, min(4294967295, (int)$seed));
$size         = $body['size'] ?? '832x1216';
if (!preg_match('/^(\d+)x(\d+)$/', $size, $m)) error_response('Invalid size', 400);
$width        = (int)$m[1];
$height       = (int)$m[2];
$cfgRescale   = max(0, min(1, (float)($body['cfg_rescale'] ?? 0)));
$noiseSched   = $body['noise_schedule'] ?? 'karras';
$ucPreset     = (int)($body['uc_preset'] ?? 0);
$quality      = (bool)($body['quality_toggle'] ?? true);
$nSamples     = max(1, min(4, (int)($body['n_samples'] ?? 1)));
$negative     = $body['negative_prompt'] ?? '';
$operation    = $body['operation'] ?? 'generate';
$strength     = isset($body['strength']) ? max(0, min(1, (float)$body['strength'])) : null;
$noise        = isset($body['noise']) ? max(0, min(1, (float)$body['noise'])) : null;
$batchId      = $body['batch_id'] ?? null;

// Build NAI payload
$isV4 = (str_contains($model, 'nai-diffusion-4') && !str_contains($model, 'nai-diffusion-3'));

$baseParams = [
    'params_version'   => 3,
    'width'            => $width,
    'height'           => $height,
    'scale'            => $scale,
    'sampler'          => $sampler,
    'steps'            => $steps,
    'n_samples'        => $nSamples,
    'ucPreset'         => $ucPreset,
    'qualityToggle'    => $quality,
    'cfg_rescale'      => $cfgRescale,
    'noise_schedule'   => $noiseSched,
    'seed'             => $seed,
];

if ($isV4) {
    // V4 / V4.5 系列必须用新的 v4_prompt 结构（否则 NAI 返回 500）
    // 参考 LittleWhiteBox (RT15548) 的实现
    $baseParams = array_merge($baseParams, [
        'autoSmea'             => false,
        'dynamic_thresholding' => false,
        'controlnet_strength'  => 1,
        'legacy'               => false,
        'legacy_v3_extend'     => false,
        'use_coords'           => false,
        'legacy_uc'            => false,
        'normalize_reference_strength_multiple' => true,
        'deliberate_euler_ancestral_bug'         => false,
        'prefer_brownian'      => true,
        'image_format'         => 'png',
        'characterPrompts'     => [],   // V4 角色提示词（暂未启用 UI 字段）
        'v4_prompt' => [
            'caption' => [
                'base_caption'  => $prompt,
                'char_captions' => [],
            ],
            'use_coords' => false,
            'use_order'   => true,
        ],
        'v4_negative_prompt' => [
            'caption' => [
                'base_caption'  => $negative,
                'char_captions' => [],
            ],
            'legacy_uc' => false,
        ],
    ]);
} else {
    // V3 / Furue 3 等老模型
    $baseParams = array_merge($baseParams, [
        'sm'                  => $cfgRescale > 0,
        'sm_dyn'              => false,
        'dynamic_threshold'   => $cfgRescale,
        'controlnet_model'    => 'none',
        'add_original_image'  => true,
        'legacy'              => false,
        'reference_image_multiple' => [],
        'reference_information_extracted_multiple' => [],
        'reference_strength_multiple' => [],
    ]);
    if ($negative !== '') $baseParams['negative_prompt'] = $negative;
}

$payload = [
    'input'             => $prompt,
    'model'             => $model,
    'action'            => 'generate',
    'parameters'        => $baseParams,
];

// V4+ characters: characters[] is added when present
if (!empty($body['characters']) && is_array($body['characters'])) {
    $payload['parameters']['characters'] = array_map(function ($c) {
        return [
            'prompt'    => $c['prompt'] ?? '',
            'uc'        => $c['negative'] ?? '',
            'center'    => $c['position'] ?? ['x'=>0.5,'y'=>0.5],
            'enabled'   => true,
        ];
    }, $body['characters']);
}

// V4+ Vibe transfer
if (!empty($body['vibe_refs']) && is_array($body['vibe_refs'])) {
    foreach ($body['vibe_refs'] as $vr) {
        if (empty($vr['path']) || !is_file(config('paths.public') . $vr['path'])) continue;
        $b64 = base64_encode(file_get_contents(config('paths.public') . $vr['path']));
        $payload['parameters']['reference_image_multiple'][] = $b64;
        $payload['parameters']['reference_information_extracted_multiple'][] = $vr['info_extracted'] ?? null;
        $payload['parameters']['reference_strength_multiple'][] = $vr['strength'] ?? 0.6;
    }
}
// V4+ Precise references
if (!empty($body['precise_refs']) && is_array($body['precise_refs'])) {
    foreach ($body['precise_refs'] as $pr) {
        if (empty($pr['path']) || !is_file(config('paths.public') . $pr['path'])) continue;
        $b64 = base64_encode(file_get_contents(config('paths.public') . $pr['path']));
        $payload['parameters']['reference_image_multiple'][] = $b64;
        $payload['parameters']['reference_information_extracted_multiple'][] = $pr['info_extracted'] ?? null;
        $payload['parameters']['reference_strength_multiple'][] = $pr['strength'] ?? 0.6;
    }
}

// Img2Img / Inpaint: include image
if (!empty($body['base_image'])) {
    $b64 = $body['base_image'];
    if (preg_match('#^data:image/\w+;base64,(.+)$#', $b64, $m)) $b64 = $m[1];
    $payload['parameters']['image'] = $b64;
    if ($strength !== null) $payload['parameters']['strength'] = $strength;
    if ($noise !== null) $payload['parameters']['noise'] = $noise;
    if (!empty($body['mask'])) {
        $mask = $body['mask'];
        if (preg_match('#^data:image/\w+;base64,(.+)$#', $mask, $m)) $mask = $m[1];
        $payload['parameters']['mask'] = $mask;
    }
}

$api = new NaiApi($apiKey, config('nai.proxy'));
$result = $api->generate($payload);

// 自动重试：
//   5xx / 网络断：固定 2s，最多 2 次（NAI 服务瞬时波动）
//   429 限流：按 NAI 的 Retry-After 头 sleep（默认 5s），最多 3 次
$retries = 0;
while (!$result['ok'] && $retries < 3) {
    $status = (int)($result['status']);
    $isSoftFail = in_array($status, [0, 500, 502, 503, 504]);
    $isRateLimit = $status === 429;
    if (!$isSoftFail && !$isRateLimit) break;
    $retries++;
    if ($isRateLimit) {
        // NAI 返 Retry-After: <秒数>（或 HTTP-date，这里只接秒数）
        $retryAfter = (int)($result['headers']['retry-after'] ?? 0);
        $wait = $retryAfter > 0 ? min($retryAfter, 60) : 5;   // 上限 60s 防止被 NAI 拖太久
        Logger::warn('nai.generate.429', ['attempt' => $retries, 'retry_after' => $wait, 'model' => $model]);
    } else {
        $wait = 2;
        Logger::warn('nai.generate.retry', ['attempt' => $retries, 'prev_status' => $status, 'model' => $model]);
    }
    sleep($wait);
    $result = $api->generate($payload);
}

if (!$result['ok']) {
    Logger::error('nai.generate.failed', ['error' => $result['error'], 'status' => $result['status'], 'retries' => $retries, 'model' => $model]);
    // 5xx 用 502 而不是原状态，避免前端以为是我们自己代码崩了
    $httpStatus = ($result['status'] >= 500 && $result['status'] < 600) ? 502 : ($result['status'] ?: 502);
    $hint = '';
    if ($result['status'] === 0) {
        $hint = '（连不上 NovelAI 服务器 — 可能是网络/代理问题。检查设置 → 网络代理）';
    } elseif ($result['status'] >= 500) {
        // V4/V4.5 当前 NAI 官方炸了（已知问题），提示切 V3
        $isV4 = (str_contains($model, 'nai-diffusion-4') && !str_contains($model, 'nai-diffusion-3'));
        if ($isV4) {
            $hint = sprintf('（%s 官方当前 V4 系列模型服务异常。建议临时切换到 nai-diffusion-3，等 NAI 修复）', 'NovelAI');
        } else {
            $hint = '（已自动重试 ' . $retries . ' 次仍失败。可稍后再试或在设置 → 网络代理里加代理）';
        }
    } elseif ($result['status'] === 401) {
        $hint = '（API Key 无效或过期。检查设置里的 API 密钥）';
    } elseif ($result['status'] === 402) {
        $hint = '（Anlas 余额不足。检查账户或等下月重置）';
    } elseif ($result['status'] === 429) {
        $hint = '（触发 NAI 速率限制。等几分钟再试）';
    }
    error_response(($result['error'] ?? 'Generation failed') . $hint, $httpStatus);
}

$images = $result['data'];
if (!is_array($images) || empty($images)) error_response('No images returned', 502);

// Persist to DB
$saved = [];
$common = [
    'prompt'           => $prompt,
    'negative_prompt'  => $negative,
    'model'            => $model,
    'sampler'          => $sampler,
    'steps'            => $steps,
    'scale'            => $scale,
    'seed'             => $seed,
    'width'            => $width,
    'height'           => $height,
    'cfg_rescale'      => $cfgRescale,
    'noise_schedule'   => $noiseSched,
    'uc_preset'        => $ucPreset,
    'quality_toggle'   => $quality ? 1 : 0,
    'characters_json'  => $body['characters'] ?? null,
    'vibe_refs_json'   => $body['vibe_refs'] ?? null,
    'precise_refs_json'=> $body['precise_refs'] ?? null,
    'strength'         => $strength,
    'noise'            => $noise,
    'operation'        => $operation,
    'meta_json'        => ['ms' => $result['ms'] ?? null, 'status' => 200],
];
foreach ($images as $idx => $b64) {
    $rowParams = $common;
    if ($idx > 0) {
        $rowParams['seed'] = ($seed + $idx) & 0xFFFFFFFF; // slight variation
    }
    $rowParams['seed'] = (int)$rowParams['seed'];
    $savedRow = GalleryManager::saveImage($b64, $rowParams, $batchId);
    $saved[] = $savedRow;
}

ok_response([
    'batch_id'   => $batchId,
    'ms'         => $result['ms'] ?? null,
    'items'      => $saved,
]);
