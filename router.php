<?php

$requestPath = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$requestedFile = __DIR__ . $requestPath;

if ($requestPath !== '/' && file_exists($requestedFile) && !is_dir($requestedFile)) {
    return false;
}

require __DIR__ . '/index.php';