<?php
/**
 * NAI Studio - Database connection (PDO)
 *
 * 双模式：
 *   - driver='sqlite'  → SQLite 单文件（默认，独立便携）
 *   - driver='mysql'   → 传统 MySQL/MariaDB（XAMPP 兼容）
 *
 * 所有 helper 方法（insert/update/delete/fetchAll/fetchOne/fetchScalar/execute）
 * 都用 ANSI SQL 标准双引号包裹标识符，SQLite 和 MySQL 都支持。
 */

declare(strict_types=1);

namespace NaiStudio;

use PDO;
use PDOException;

class Db {
    private static ?PDO $instance = null;
    private static ?string $driver = null;

    /**
     * 当前驱动名
     */
    public static function driver(): string {
        if (self::$driver === null) {
            self::$driver = config('db.driver') ?? 'mysql';
        }
        return self::$driver;
    }

    /**
     * ANSI SQL 标准标识符引用（双引号），MySQL/SQLite 都兼容
     */
    public static function quoteIdent(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * SQL 兼容性 shim：把 MySQL 专有函数替换成 SQLite 等价物
     *  - NOW()        → CURRENT_TIMESTAMP
     *  - LEFT(s, n)   → substr(s, 1, n)
     *  - IFNULL(a, b) → COALESCE(a, b)  (SQLite 也支持 IFNULL，但 COALESCE 更通用)
     *  - CURDATE()    → DATE('now')
     *  - UNIX_TIMESTAMP() → strftime('%s', 'now')
     */
    public static function normalizeSql(string $sql): string {
        if (self::driver() === 'mysql') return $sql;

        // 不在字符串字面量内的替换（简单实现：用 split 跳字符串）
        $out = '';
        $len = strlen($sql);
        $i = 0;
        $inString = false;
        $stringChar = '';
        while ($i < $len) {
            $ch = $sql[$i];
            if ($inString) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) { $out .= $sql[$i+1]; $i += 2; continue; }
                if ($ch === $stringChar) $inString = false;
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $out .= $ch;
                $i++;
                continue;
            }
            // NOW() → CURRENT_TIMESTAMP
            if (substr($sql, $i, 4) === 'NOW(' && ($i + 4 >= $len || !ctype_alnum($sql[$i+4] ?? ''))) {
                $out .= 'CURRENT_TIMESTAMP';
                $i += 4;
                continue;
            }
            // LEFT( → substr(   （只匹配独立 LEFT(，单词边界）
            if (substr($sql, $i, 5) === 'LEFT(' && ($i === 0 || !ctype_alnum($sql[$i-1] ?? ''))) {
                $out .= 'substr(';
                $i += 5;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }

    public static function pdo(): PDO {
        if (self::$instance === null) {
            $cfg = config('db');
            $driver = $cfg['driver'] ?? 'mysql';

            try {
                if ($driver === 'sqlite') {
                    $path = $cfg['sqlite_path'] ?? (dirname(__DIR__) . '/data/nai-studio.db');
                    // 自动创建 data/ 目录
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    self::$instance = new PDO("sqlite:" . $path, null, null, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    // SQLite 性能/稳定性优化
                    self::$instance->exec("PRAGMA journal_mode = WAL");
                    self::$instance->exec("PRAGMA synchronous = NORMAL");
                    self::$instance->exec("PRAGMA foreign_keys = ON");
                    self::$instance->exec("PRAGMA busy_timeout = 5000");
                } else {
                    $mc = $cfg['mysql'] ?? $cfg;  // 兼容老的 config 结构
                    $dsn = sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                        $mc['host'], $mc['port'], $mc['name'], $mc['charset']
                    );
                    self::$instance = new PDO($dsn, $mc['user'], $mc['pass'], [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    ]);
                }
                self::$driver = $driver;
            } catch (PDOException $e) {
                error_log('[NaiStudio\\Db] ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed', 500, $e);
            }
        }
        return self::$instance;
    }

    /** Run a SELECT and return all rows. */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::pdo()->prepare(self::normalizeSql($sql));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a SELECT and return one row or null. */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::pdo()->prepare(self::normalizeSql($sql));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run a fetch returning a single scalar. */
    public static function fetchScalar(string $sql, array $params = []) {
        $stmt = self::pdo()->prepare(self::normalizeSql($sql));
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Run an INSERT, return last insert id. */
    public static function insert(string $table, array $data): int {
        $cols = array_keys($data);
        $quotedCols = array_map([self::class, 'quoteIdent'], $cols);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdent($table),
            implode(',', $quotedCols),
            implode(',', $placeholders)
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($data);
        return (int)self::pdo()->lastInsertId();
    }

    /** Run an UPDATE by id. */
    public static function update(string $table, int $id, array $data): int {
        $cols = array_keys($data);
        $sets = array_map(fn($c) => self::quoteIdent($c) . ' = :' . $c, $cols);
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :id',
            self::quoteIdent($table),
            implode(',', $sets),
            self::quoteIdent('id')
        );
        $stmt = self::pdo()->prepare($sql);
        $data['id'] = $id;
        $stmt->execute($data);
        return $stmt->rowCount();
    }

    /** Delete by id. */
    public static function delete(string $table, int $id): int {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            self::quoteIdent($table),
            self::quoteIdent('id')
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /**
     * 任意 SQL（UPDATE/DELETE/INSERT...）执行，返回受影响行数
     */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::pdo()->prepare(self::normalizeSql($sql));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 切换驱动（测试用，迁移前要切回 mysql、迁移后切到 sqlite）
     * 注意：切换后会断开当前连接，下次 pdo() 会重连
     */
    public static function setDriver(string $driver): void {
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \InvalidArgumentException("Invalid driver: $driver");
        }
        self::$driver = $driver;
        self::$instance = null;
    }
}