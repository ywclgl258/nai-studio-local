<?php
/**
 * NAI Studio - Configuration
 * Edit paths and secrets as needed. Most settings are overridable via settings table.
 */

declare(strict_types=1);

// ============================================================
//  Portable 模式：用户数据写到 user-data\（首次启动由 start.bat 创建）
//  这样 git pull / 重新解压项目时不会丢失 API key / 提示词 / 历史
// ============================================================
$rootPath    = dirname(__DIR__);
$userData    = $rootPath . '/user-data';
$useUserData = is_dir($userData);

// 第一次启动时从 data-tpl 复制模板
if ($useUserData && !file_exists($userData . '/nai-studio.db') && file_exists($userData . '/data-tpl/nai-studio.db')) {
    @copy($userData . '/data-tpl/nai-studio.db', $userData . '/nai-studio.db');
}

$dbFile     = $useUserData ? ($userData . '/nai-studio.db') : ($rootPath . '/data/nai-studio.db');
$storageDir = $useUserData ? ($userData . '/storage') : ($rootPath . '/public/storage');
$logDir     = $useUserData ? ($userData . '/logs') : ($rootPath . '/public/storage/logs');

// 确保目录存在
foreach ([$storageDir, $storageDir . '/images', $storageDir . '/thumbs', $storageDir . '/uploads', $storageDir . '/cache', $logDir] as $d) {
    if ($useUserData && !is_dir($d)) @mkdir($d, 0777, true);
}

return [
    // --- Paths ---
    'paths' => [
        'root'         => $rootPath,
        'public'       => $rootPath . '/public',
        'storage'      => $storageDir,
        'images'       => $storageDir . '/images',
        'thumbs'       => $storageDir . '/thumbs',
        'uploads'      => $storageDir . '/uploads',
        'cache'        => $storageDir . '/cache',
        'logs'         => $logDir,
        'userdata'     => $useUserData ? $userData : null,
    ],

    // --- Database ---
    'db' => [
        // 驱动选择：'sqlite'（默认，独立单文件） 或 'mysql'（传统 XAMPP）
        'driver' => 'sqlite',

        // SQLite 单文件路径
        'sqlite_path' => $dbFile,

        // MySQL 配置（仅当 driver='mysql' 时生效）
        'mysql' => [
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'name'    => 'nai_studio',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ],
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
