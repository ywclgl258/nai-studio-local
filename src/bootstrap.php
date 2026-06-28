<?php
/**
 * NAI Studio - Bootstrap
 * Sets up error handling, autoloading, and global utilities.
 */

declare(strict_types=1);

// --- Error handling ---
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- Timezone (override if needed) ---
date_default_timezone_set('Asia/Shanghai');

// --- Load config ---
$config = require __DIR__ . '/config.php';

// --- Ensure storage dirs exist ---
foreach ([
    $config['paths']['images'],
    $config['paths']['thumbs'],
    $config['paths']['uploads'],
    $config['paths']['cache'],
    $config['paths']['logs'],
] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// --- Autoloader (simple PSR-4-ish) ---
spl_autoload_register(function (string $class) use ($config) {
    // Only autoload our classes
    if (strpos($class, 'NaiStudio\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('NaiStudio\\'));
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// --- Helper functions ---
if (!function_exists('config')) {
    function config(?string $key = null, $default = null) {
        global $config;
        if ($key === null) return $config;
        $parts = explode('.', $key);
        $cur = $config;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
            $cur = $cur[$p];
        }
        return $cur;
    }
}

if (!function_exists('json_response')) {
    function json_response($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('error_response')) {
    function error_response(string $message, int $status = 400, array $extra = []): void {
        json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }
}

if (!function_exists('ok_response')) {
    function ok_response($data = []): void {
        json_response(array_merge(['ok' => true], is_array($data) ? $data : ['data' => $data]));
    }
}

if (!function_exists('read_json_body')) {
    function read_json_body(): array {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_response('Invalid JSON body', 400);
        }
        return $data;
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', $_SERVER[$k])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '127.0.0.1';
    }
}

if (!function_exists('now_iso')) {
    function now_iso(): string {
        return date('c');
    }
}

// --- Session (for CSRF if needed) ---
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    @session_start();
}

// --- CORS / security headers for API responses ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
// CORS (allow same-origin only; if needed from another port, restrict here)
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Return globals for handlers ---
return [
    'config' => $config,
];
