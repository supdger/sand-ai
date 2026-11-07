<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\sandadmin\app\cache\driver\File;

$root = sys_get_temp_dir() . '/sandadmin-cache-lock-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Unable to create test directory');
}

$cache = new File(['path' => $root . '/runtime/file/']);
$cache->synchronized(static fn (): null => null);

if (!is_file($root . '/runtime/file.lock')) {
    throw new RuntimeException('File cache lock was not created beside the cache directory');
}
if (is_file($root . '/runtime/file/.lock')) {
    throw new RuntimeException('File cache lock was created inside the cache directory');
}

echo "File cache lock path test passed\n";
