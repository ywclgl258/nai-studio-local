<?php
/**
 * NAI Studio - Configuration
 * Edit paths and secrets as needed. Most settings are overridable via settings table.
 */

declare(strict_types=1);

return [
    // --- Paths ---
    'paths' => [
        'root'         => dirname(__DIR__),                      // D:\anima\nai-studio
        'public'       => dirname(__DIR__) . '/public',          // D:\anima\nai-studio\public
        'storage'      => dirname(__DIR__) . '/public/storage',
        'images'       => dirname(__DIR__) . '/public/storage/images',
        'thumbs'       => dirname(__DIR__) . '/public/storage/thumbs',
        'uploads'      => dirname(__DIR__) . '/public/storage/uploads',
        'cache'        => dirname(__DIR__) . '/public/storage/cache',
        'logs'         => dirname(__DIR__) . '/public/storage/logs',
    ],

    // --- Database ---
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'nai_studio',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // --- Security ---
    'security' => [
        // 32-byte key for API key encryption. CHANGE THIS for production.
        // Generate with: bin2hex(random_bytes(32))
        'encryption_key' => 'naistudio-dev-key-CHANGE-ME-4f8b9c2d1e7a6f5b',
        'session_lifetime' => 86400 * 30,
        'csrf_enabled'    => true,
    ],

    // --- External APIs ---
    'nai' => [
        // NAI Generation API endpoint
        'generate_url' => 'https://image.novelai.net/ai/generate-image',
        // User info / anlas endpoint
        'user_url'     => 'https://api.novelai.net/user/subscription',
        // Tag suggestion via Danbooru
        'tag_suggest_url' => 'https://danbooru.donmai.us/tag.json',
        // Optional proxy (NAI 直连异常时可填 e.g. https://your-mirror.example.com)
        'proxy'        => null,
        'timeout'      => 300,
    ],

    // --- Image generation defaults ---
    'generation' => [
        'default_size'      => '832x1216',
        'allowed_sizes'     => [
            '512x768', '640x640', '640x768', '768x768', '832x1216',
            '1024x1024', '1024x1536', '1024x1920',
            '1216x832', '1280x720', '1536x1024',
            '1472x1472', '1920x1024',
        ],
        'allowed_samplers'  => [
            'k_euler_ancestral', 'k_euler', 'k_dpmpp_2s_ancestral',
            'k_dpmpp_2m', 'k_dpmpp_2m_sde', 'k_dpmpp_sde', 'ddim',
        ],
        'allowed_models'    => [
            'nai-diffusion-4-5-curated'   => 'NAI Diffusion V4.5 Curated',
            'nai-diffusion-4-5-full'      => 'NAI Diffusion V4.5 Full',
            'nai-diffusion-4-curated'     => 'NAI Diffusion V4 Curated',
            'nai-diffusion-4-full'        => 'NAI Diffusion V4 Full',
            'nai-diffusion-3'             => 'NAI Diffusion 3',
            'nai-diffusion-3-furry'       => 'NAI Diffusion Furry 3',
        ],
    ],

    // --- UC presets (negative prompt presets) ---
    'uc_presets' => [
        0 => 'lowres, bad anatomy, bad hands, text, error, missing fingers, extra digit, fewer digits, cropped, worst quality, low quality, normal quality, jpeg artifacts, signature, watermark, username, blurry',
        1 => 'lowres, error, cropped, worst quality, low quality, jpeg artifacts, bad anatomy, bad hands, watermark, username, blurry',
        2 => 'lowres, bad anatomy, bad hands, text, error, missing fingers, extra digit, fewer digits, cropped, worst quality, low quality, jpeg artifacts, signature, watermark, username, blurry, deformed, ugly, duplicate, extra limbs, malformed limbs, poorly drawn hands, poorly drawn face',
        3 => '',
    ],

    // --- Tag library ---
    'tags' => [
        'page_size'         => 60,       // Tags per page in picker
        'search_min_chars'  => 1,        // Min chars before search triggers
        'search_limit'      => 200,      // Max results per search
    ],

    // --- Logging ---
    'logging' => [
        'enabled'    => true,
        'level'      => 'info',        // debug, info, warn, error
        'max_files'  => 7,             // Keep N days of logs
    ],

    // --- Performance ---
    'cache' => [
        'tag_list_ttl'    => 300,       // 5 minutes
        'gallery_ttl'     => 60,        // 1 minute
        'anlas_ttl'       => 60,        // 1 minute
    ],
];
