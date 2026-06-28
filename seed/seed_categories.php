<?php
require __DIR__ . '/../src/bootstrap.php';

use NaiStudio\Db;
use NaiStudio\TagManager;

$catRows = [
    ['general',     'General',     '通用',     'General subject tags (1girl, scenery, etc.)', 10],
    ['artist',      'Artist',      '画师',     'Artist tags', 20],
    ['copyright',   'Copyright',   '版权',     'Copyright and franchise tags', 30],
    ['character',   'Character',   '角色',     'Named character tags', 40],
    ['meta',        'Meta',        '元数据',   'Meta tags (rating, source, etc.)', 50],
    ['quality',     'Quality',     '质量',     'Quality tags (masterpiece, best quality, etc.)', 60],
    ['style',       'Style',       '风格',     'Art style tags', 70],
    ['environment', 'Environment', '环境',     'Environment and setting tags', 80],
];
foreach ($catRows as [$slug, $name, $nameCn, $desc, $order]) {
    Db::pdo()->prepare(
        "INSERT INTO tag_categories (slug, name, name_cn, description, display_order)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), name_cn=VALUES(name_cn),
                                 description=VALUES(description), display_order=VALUES(display_order)"
    )->execute([$slug, $name, $nameCn, $desc, $order]);
    echo "  + category: $slug ($nameCn)\n";
}

echo "Categories count: " . count(TagManager::categories()) . "\n";

// Show them
foreach (TagManager::categories() as $c) {
    echo "  [{$c['id']}] {$c['slug']} | {$c['name']} | {$c['name_cn']}\n";
}
