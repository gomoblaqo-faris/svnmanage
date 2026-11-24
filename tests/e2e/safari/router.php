<?php
// Test-only router: serves the Safari QA runner next to the unchanged app.
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/__qa/lib.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/runner.js');
    return true;
}
if ($uri !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $uri)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
