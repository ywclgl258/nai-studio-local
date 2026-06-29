<?php
/**
 * NAI Studio - SQLite → MySQL/MariaDB 反向迁移脚本
 *
 * 用法：php tools/migrate_sqlite_to_mysql.php
 *
 * 场景：SQLite 跑了一段时间，想回退到 XAMPP + MySQL。
 *
 * 注意：
 *   - 这个脚本假设 MySQL 端已经存在同名库（nai_studio）和全 19 张表（按 db/migrations/ 跑过）
 *   - 只复制数据，不动 schema
 *   - 加密字段（api_key_encrypted）原样复制，加密格式一致
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use NaiStudio\Db;

$cfg = config('db');
$mc = $cfg['mysql'] ?? $cfg;
$sqlitePath = $cfg['sqlite_path'] ?? ($root . '/data/nai-studio.db');

echo "==> 反向迁移：SQLite → MariaDB\n";
echo "    源 SQLite: $sqlitePath\n";
echo "    目标 MySQL: {$mc['host']}:{$mc['port']}/{$mc['name']}\n\n";

// ---- 1. 连接两个 DB ----
echo "[1/5] 连接 SQLite + MySQL...\n";
if (!file_exists($sqlitePath)) {
    fwrite(STDERR, "    [!] SQLite 文件不存在\n");
    exit(1);
}
$sqlite = new PDO("sqlite:$sqlitePath", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sqlite->exec("PRAGMA foreign_keys = OFF");  // 复制期间临时关闭

try {
    $mysql = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $mc['host'], $mc['port'], $mc['name'], $mc['charset']),
        $mc['user'], $mc['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "MySQL 连接失败: " . $e->getMessage() . "\n");
    exit(1);
}
echo "    ✓ 双连接成功\n\n";

// ---- 2. 列出 SQLite 表 ----
echo "[2/5] 列出源库表...\n";
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "    找到 " . count($tables) . " 张表\n\n";

// ---- 3. 清空 MySQL 端（先清后填） ----
echo "[3/5] 清空 MySQL 现有数据（仅 data，保留 schema）...\n";
$mysql->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach ($tables as $t) {
    try {
        $mysql->exec("DELETE FROM `$t`");
        echo "    ✓ 清空 $t\n";
    } catch (PDOException $e) {
        echo "    ⚠️  清空 $t 失败: " . $e->getMessage() . "\n";
    }
}
$mysql->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "\n";

// ---- 4. 复制数据 ----
echo "[4/5] 复制数据 SQLite → MySQL...\n";
$totalRows = 0;
foreach ($tables as $table) {
    try {
        $rows = $sqlite->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "  $table: 0 行 (空表)\n";
            continue;
        }
        $cols = array_keys($rows[0]);
        $quoted = array_map(fn($c) => "`$c`", $cols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(',', $quoted), $placeholders);
        $stmt = $mysql->prepare($sql);
        $mysql->beginTransaction();
        $ok = 0; $fail = 0;
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $row[$c] ?? null;
                if (is_resource($v)) $v = stream_get_contents($v);
                $vals[] = $v;
            }
            try { $stmt->execute($vals); $ok++; } catch (PDOException $e) { $fail++; }
        }
        $mysql->commit();
        $totalRows += $ok;
        echo "  $table: $ok 行 ✓" . ($fail > 0 ? "（失败 $fail）" : '') . "\n";
    } catch (PDOException $e) {
        echo "  $table: ❌ " . $e->getMessage() . "\n";
    }
}
echo "\n    总计成功: $totalRows 行\n\n";

// ---- 5. 校验 ----
echo "[5/5] 校验行数一致性...\n";
$mismatches = 0;
foreach ($tables as $table) {
    $sc = (int)$sqlite->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    $mc = (int)$mysql->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $status = ($sc == $mc) ? '✓' : '⚠️';
    if ($sc != $mc) $mismatches++;
    printf("    %-30s SQLite=%-6s MySQL=%-6s %s\n", $table, $sc, $mc, $status);
}
echo "\n";

if ($mismatches > 0) {
    echo "⚠️  有 $mismatches 张表行数不一致！\n";
    exit(2);
}

echo "==> 反向迁移完成！\n";
echo "    MySQL 数据已更新（$totalRows 行）。\n";
echo "    切回 MySQL：config.php 改 'driver' => 'mysql'\n\n";
exit(0);