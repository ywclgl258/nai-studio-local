<?php
namespace Foo;
echo "class_exists(ZipArchive): " . (class_exists('ZipArchive') ? "yes" : "no") . "\n";
try {
    $z = new ZipArchive();
    echo "new ZipArchive OK\n";
} catch (\Throwable $e) {
    echo "new ZipArchive ERR: " . $e->getMessage() . "\n";
}
try {
    $p = new PDO('sqlite::memory:');
    echo "new PDO OK\n";
} catch (\Throwable $e) {
    echo "new PDO ERR: " . $e->getMessage() . "\n";
}
try {
    $c = new stdClass();
    echo "new stdClass OK\n";
} catch (\Throwable $e) {
    echo "new stdClass ERR: " . $e->getMessage() . "\n";
}