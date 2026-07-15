<?php
/**
 * Bootstrap — запускається після config.php.
 * Тут: сесія, часова зона, обробка помилок, автозавантаження, хелпери.
 */

// Сесія
session_start();

// Часовий пояс
date_default_timezone_set('UTC');

// Помилки (задаються константою APP_DEBUG з config.php)
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Автозавантаження класів
spl_autoload_register(function ($class) {
    $class = str_replace('App\\', '', $class);
    $file = APP_PATH . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Підключити хелпери
require_once APP_PATH . '/helpers.php';
