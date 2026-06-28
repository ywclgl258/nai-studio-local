<?php
require 'D:\anima\nai-studio\src\bootstrap.php';
$all = \NaiStudio\PoseDict::all();
$total = 0;
foreach ($all as $cat => $items) {
    $n = count($items);
    $total += $n;
    echo str_pad($cat, 12) . ": " . $n . " 词\n";
    foreach ($items as $it) {
        echo "    " . $it['en'] . " = " . $it['cn'] . "\n";
    }
}
echo "---\n总计: $total 词 / " . count($all) . " 类\n";

echo "\n=== 搜 '手' ===\n";
$hits = \NaiStudio\PoseDict::search('手');
$n = 0;
foreach ($hits as $cat => $its) {
    $n += count($its);
    echo "$cat: " . count($its) . "\n";
}
echo "总计 $n 匹配\n";

echo "\n=== 搜 'kiss' ===\n";
$hits = \NaiStudio\PoseDict::search('kiss');
$n = 0;
foreach ($hits as $cat => $its) {
    $n += count($its);
    echo "$cat:\n";
    foreach ($its as $it) echo "  " . $it['en'] . " = " . $it['cn'] . "\n";
}
echo "总计 $n 匹配\n";
