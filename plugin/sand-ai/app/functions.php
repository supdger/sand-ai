<?php

declare(strict_types=1);

/**
 * SandAI management plugin helper functions.
 */


// SandPackage installs plugin files without regenerating the host Composer map.
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\SandAi\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
