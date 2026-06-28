<?php
/**
 * NAI Studio - Simple logger
 */

declare(strict_types=1);

namespace NaiStudio;

class Logger {
    private static ?string $logFile = null;

    public static function init(): void {
        if (self::$logFile === null) {
            $logDir = config('paths.logs');
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            self::$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
        }
    }

    public static function debug(string $msg, array $ctx = []): void { self::write('DEBUG', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void  { self::write('INFO',  $msg, $ctx); }
    public static function warn(string $msg, array $ctx = []): void  { self::write('WARN',  $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::write('ERROR', $msg, $ctx); }

    private static function write(string $level, string $msg, array $ctx = []): void {
        if (!config('logging.enabled')) return;
        self::init();
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
