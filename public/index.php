<?php

declare(strict_types=1);

/*
 * svnmanage front controller.
 * With PHP's built-in server: php -S localhost:8080 -t public public/index.php
 */
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    echo 'svnmanage requires PHP 8.1 or newer (running ' . PHP_VERSION . ').';
    exit(1);
}

require dirname(__DIR__) . '/src/autoload.php';

SvnManage\App::run(dirname(__DIR__));
