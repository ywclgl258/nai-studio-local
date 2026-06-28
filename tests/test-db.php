<?php
require 'D:\anima\nai-studio\src\bootstrap.php';
echo "Db::fetchScalar: " . (\NaiStudio\Db::fetchScalar("SELECT 1") ?? 'null') . "\n";