<?php
/**
 * NAI Studio - Database connection (PDO)
 */

declare(strict_types=1);

namespace NaiStudio;

use PDO;
use PDOException;

class Db {
    private static ?PDO $instance = null;

    public static function pdo(): PDO {
        if (self::$instance === null) {
            $cfg = config('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
            );
            try {
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'",
                ]);
            } catch (PDOException $e) {
                error_log('[NaiStudio\\Db] ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed', 500, $e);
            }
        }
        return self::$instance;
    }

    /** Run a SELECT and return all rows. */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a SELECT and return one row or null. */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run a fetch returning a single scalar. */
    public static function fetchScalar(string $sql, array $params = []) {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Run an INSERT, return last insert id. */
    public static function insert(string $table, array $data): int {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', $placeholders)
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($data);
        return (int)self::$instance->lastInsertId();
    }

    /** Run an UPDATE by id. */
    public static function update(string $table, int $id, array $data): int {
        $cols = array_keys($data);
        $sets = array_map(fn($c) => "`$c` = :$c", $cols);
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE id = :id LIMIT 1',
            $table,
            implode(',', $sets)
        );
        $stmt = self::pdo()->prepare($sql);
        $data['id'] = $id;
        $stmt->execute($data);
        return $stmt->rowCount();
    }

    /** Delete by id. */
    public static function delete(string $table, int $id): int {
        $stmt = self::pdo()->prepare("DELETE FROM `$table` WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }
}
