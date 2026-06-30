<?php
/**
 * NAI Studio - PHP 内置服务器路由脚本
 *
 * 用法：php -S 127.0.0.1:8080 -t public public/router.php
 *
 * 作用：把 /nai-studio/* 路径映射到 public/* 文件（兼容 XAMPP 时的 URL）
 *  - /nai-studio/api/foo.php → 引入 public/api/foo.php
 *  - /nai-studio/index.php   → 引入 public/index.php
 *  - /nai-studio/            → 引入 public/index.php
 *  - /nai-studio/storage/... → 服务 public/storage/...
 *  - /nai-studio/assets/...  → 服务 public/assets/...
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 去掉 /nai-studio 前缀
if (strpos($uri, '/nai-studio') === 0) {
    $uri = substr($uri, strlen('/nai-studio'));
    if ($uri === '' || $uri === false) $uri = '/';
    $_SERVER['REQUEST_URI'] = $uri;
}

// 真实文件路径
$file = __DIR__ . $uri;

// /storage/... 特殊处理
//   v1.1.4 之前：项目根 storage/ 目录
//   v1.1.4+ portable 模式：user-data/storage/（用户数据隔离）
//   优先用 user-data，fallback 到老路径（兼容老 user）
if (strpos($uri, '/storage/') === 0) {
    $userDataStorage = dirname(__DIR__) . '/user-data' . $uri;  // v1.1.4+ 新位置
    $rootStorage     = dirname(__DIR__) . $uri;                // 老位置
    $candidates = file_exists($userDataStorage) ? [$userDataStorage, $rootStorage] : [$rootStorage, $userDataStorage];
    foreach ($candidates as $storage) {
        if (file_exists($storage) && !is_dir($storage)) {
            $ext = strtolower(pathinfo($storage, PATHINFO_EXTENSION));
            $mime = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'svg'  => 'image/svg+xml',
            ];
            if (isset($mime[$ext])) {
                header('Content-Type: ' . $mime[$ext]);
                header('Content-Length: ' . filesize($storage));
                header('Cache-Control: public, max-age=86400');
                readfile($storage);
                exit;
            }
            readfile($storage);
            exit;
        }
    }
    // 文件不存在：让 API 处理（见 api/tag_image.php?action=fetch）
}

// 静态文件（图片/css/js/字体等）：router 自己服务（不能用 return false，
//   否则内置 server 会按原始 /nai-studio/... 找不到）
//   但 .php 必须走 require，不能 readfile 源码
if ($uri !== '/' && file_exists($file) && !is_dir($file) && !preg_match('/\.php$/', $uri)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'txt'   => 'text/plain',
        'html'  => 'text/html; charset=utf-8',
        'map'   => 'application/json',
        'webmanifest' => 'application/manifest+json',
    ];
    if (isset($mime[$ext])) {
        header('Content-Type: ' . $mime[$ext]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    // 其他静态文件直接输出
    readfile($file);
    exit;
}

// 根路径 / → index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

// PHP 文件 → 引入
if (preg_match('/\.php$/', $uri)) {
    if (file_exists($file)) {
        require $file;
        exit;
    }
    http_response_code(404);
    echo "404 Not Found: $uri";
    exit;
}

// 其他找不到
http_response_code(404);
echo "404 Not Found: $uri";
exit;