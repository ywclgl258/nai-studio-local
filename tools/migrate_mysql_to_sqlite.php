<?php
/**
 * NAI Studio - MySQL/MariaDB → SQLite 迁移脚本
 *
 * 用法：
 *   1) 先确认 config.php 里 db.driver='mysql'（连源库）
 *   2) php tools/migrate_mysql_to_sqlite.php
 *   3) 自动备份原库 → 创建 SQLite → 复制 19 张表 → 验证条数
 *   4) 成功后切 db.driver='sqlite' 即可
 *
 * 反向（SQLite → MySQL）工具：tools/migrate_sqlite_to_mysql.php
 */

declare(strict_types=1);

// 强制 MySQL 驱动跑迁移（即使 config 改了也能跑）
$_SERVER['MIGRATION_MODE'] = 'mysql';

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use NaiStudio\Db;

$cfg = config('db');
// 无论 config 写的什么 driver，迁移脚本都直接用 mysql 子配置连源库
$mc = $cfg['mysql'] ?? $cfg;
if (empty($mc['host']) || empty($mc['name'])) {
    echo "[!] config.db.mysql 配置缺失，请补全 host/name/user/pass\n";
    exit(1);
}
$sqlitePath = $cfg['sqlite_path'] ?? ($root . '/data/nai-studio.db');
$sqliteDir = dirname($sqlitePath);

echo "==> 迁移：MariaDB → SQLite\n";
echo "    源 MySQL: {$mc['host']}:{$mc['port']}/{$mc['name']}\n";
echo "    目标 SQLite: $sqlitePath\n\n";

// ---- 1. 连接 MySQL ----
echo "[1/6] 连接 MySQL...\n";
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
echo "    ✓ OK\n\n";

// ---- 2. 列出要迁移的表 ----
echo "[2/6] 列出源库表...\n";
$tables = $mysql->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);
echo "    找到 " . count($tables) . " 张表：\n";
foreach ($tables as $t) echo "      - $t\n";
echo "\n";

// ---- 3. 备份（mysqldump） ----
echo "[3/6] 备份源库...\n";
$dumpDir = $root . '/data/backups';
if (!is_dir($dumpDir)) mkdir($dumpDir, 0755, true);
$dumpFile = $dumpDir . '/pre-migration-' . date('Y-m-d_His') . '.sql';
$cmd = sprintf(
    '"%s" -h %s -P %s -u %s %s --default-character-set=utf8mb4 --single-transaction --quick --routines --triggers %s > %s 2>&1',
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    escapeshellarg($mc['host']),
    escapeshellarg((string)$mc['port']),
    escapeshellarg($mc['user']),
    $mc['pass'] ? '-p' . escapeshellarg($mc['pass']) : '',
    escapeshellarg($mc['name']),
    escapeshellarg($dumpFile)
);
exec($cmd, $out, $ret);
if ($ret !== 0) {
    fwrite(STDERR, "    [!] mysqldump 失败 (exit=$ret)\n");
    echo implode("\n", $out) . "\n";
} else {
    $size = filesize($dumpFile);
    echo "    ✓ 备份完成：$dumpFile (" . round($size/1024/1024, 2) . " MB)\n";
}
echo "\n";

// ---- 4. 删除旧 SQLite 文件（如有），创建新 SQLite ----
echo "[4/6] 创建 SQLite 文件...\n";
if (!is_dir($sqliteDir)) mkdir($sqliteDir, 0755, true);
if (file_exists($sqlitePath)) {
    // 备份旧 SQLite（如果存在）
    $bak = $sqlitePath . '.old-' . date('Ymd-His');
    copy($sqlitePath, $bak);
    echo "    [!] 旧 SQLite 已备份到 $bak\n";
    unlink($sqlitePath);
}
try {
    $sqlite = new PDO("sqlite:$sqlitePath", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $sqlite->exec("PRAGMA journal_mode = WAL");
    $sqlite->exec("PRAGMA synchronous = NORMAL");
    $sqlite->exec("PRAGMA foreign_keys = OFF");  // 迁移期间临时关闭，迁移完再开
} catch (PDOException $e) {
    fwrite(STDERR, "SQLite 创建失败: " . $e->getMessage() . "\n");
    exit(1);
}
echo "    ✓ SQLite 文件创建成功\n\n";

// ---- 5. 应用 schema ----
echo "[5/6] 应用 SQLite schema...\n";
$schemaFile = $root . '/data/schema_sqlite.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "    [!] Schema 文件不存在: $schemaFile\n");
    exit(1);
}
$schema = file_get_contents($schemaFile);
// 去掉 BOM
if (substr($schema, 0, 3) === "\xEF\xBB\xBF") {
    $schema = substr($schema, 3);
}
// 把整段注释行（-- 开头）清掉
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
$statements = array_filter(array_map('trim', explode(';', $schema)));
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $sqlite->exec($stmt);
    } catch (PDOException $e) {
        fwrite(STDERR, "    Schema 执行失败:\n      SQL: " . substr($stmt, 0, 200) . "\n      Err: " . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "    ✓ " . count($statements) . " 条 SQL 应用成功\n\n";

// ---- 6. 复制数据 ----
echo "[6/6] 复制数据...\n";
$totalRows = 0;
$totalFail = 0;
$report = [];
foreach ($tables as $table) {
    try {
        $rows = $mysql->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            $report[] = "  $table: 0 行 (空表)";
            continue;
        }
        // 拿目标表实际列（SQLite schema 可能与 MySQL 略有差异）
        $targetCols = [];
        $colStmt = $sqlite->query("PRAGMA table_info(\"$table\")");
        foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $targetCols[] = $col['name'];
        }
        if (empty($targetCols)) {
            $report[] = "  $table: ⚠️ 目标表不存在（已跳过）";
            continue;
        }

        // 找两表共有的列
        $srcCols = array_keys($rows[0]);
        $common = array_values(array_intersect($targetCols, $srcCols));
        if (empty($common)) {
            $report[] = "  $table: ⚠️ 没有共同列（已跳过）";
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($common), '?'));
        $quoted = array_map(fn($c) => '"' . $c . '"', $common);
        $sql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', $table, implode(',', $quoted), $placeholders);
        $stmt = $sqlite->prepare($sql);

        $sqlite->beginTransaction();
        $ok = 0; $fail = 0;
        foreach ($rows as $row) {
            $vals = [];
            foreach ($common as $c) {
                $v = $row[$c] ?? null;
                // 处理二进制 BLOB：MySQL 的 PDO 可能返回 resource，需用 mysql 读出来
                if (is_resource($v)) {
                    $v = stream_get_contents($v);
                }
                $vals[] = $v;
            }
            try {
                $stmt->execute($vals);
                $ok++;
            } catch (PDOException $e) {
                $fail++;
                if ($fail <= 3) {
                    echo "    ⚠️  $table 行插入失败: " . $e->getMessage() . "\n";
                }
            }
        }
        $sqlite->commit();
        $totalRows += $ok;
        $totalFail += $fail;
        $report[] = "  $table: $ok 行 ✓" . ($fail > 0 ? "（失败 $fail 行）" : '');
    } catch (PDOException $e) {
        $report[] = "  $table: ❌ 失败: " . $e->getMessage();
    }
}
echo implode("\n", $report) . "\n";
echo "\n    总计成功: $totalRows 行";
if ($totalFail > 0) echo "，失败: $totalFail 行";
echo "\n\n";

// ---- 7. 重新开启外键 + VACUUM ----
echo "[验证] 重新开启外键 + 优化...\n";
$sqlite->exec("PRAGMA foreign_keys = ON");
$sqlite->exec("VACUUM");
echo "    ✓ 完成\n\n";

// ---- 8. 条数对比 ----
echo "[校验] MySQL vs SQLite 行数对比：\n";
$mismatches = 0;
foreach ($tables as $table) {
    $mc = $mysql->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $sc = (int)$sqlite->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    $status = ($mc == $sc) ? '✓' : '⚠️';
    if ($mc != $sc) $mismatches++;
    printf("    %-30s MySQL=%-6s SQLite=%-6s %s\n", $table, $mc, $sc, $status);
}
echo "\n";

$sqliteSize = filesize($sqlitePath);
echo "==> 迁移完成！\n";
echo "    SQLite 文件: $sqlitePath (" . round($sqliteSize/1024/1024, 2) . " MB)\n";
echo "    行数不一致表: $mismatches 张\n\n";

echo "下一步：\n";
echo "  1) 打开 src/config.php\n";
echo "  2) 找到 'driver' => 'mysql'\n";
echo "  3) 改成 'driver' => 'sqlite'\n";
echo "  4) 打开浏览器访问项目，验证一切正常\n\n";

if ($mismatches > 0) {
    echo "⚠️  有 $mismatches 张表行数不一致，请检查日志后再切驱动！\n";
    exit(2);
}
exit(0);