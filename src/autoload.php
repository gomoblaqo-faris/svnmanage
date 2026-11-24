<?php

declare(strict_types=1);

/* Dependency-free autoloader so svnmanage runs without Composer at runtime. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'SvnManage\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';
