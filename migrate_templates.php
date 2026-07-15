<?php
require_once 'config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ':' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Show current columns
$cols = $pdo->query('SHOW COLUMNS FROM templates')->fetchAll(PDO::FETCH_COLUMN);
echo "Current columns: " . implode(', ', $cols) . PHP_EOL;

// Add columns if not present
$toAdd = [
    'type'            => "ALTER TABLE `templates` ADD COLUMN `type` VARCHAR(60) DEFAULT NULL AFTER `name`",
    'description'     => "ALTER TABLE `templates` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `type`",
    'expected_result' => "ALTER TABLE `templates` ADD COLUMN `expected_result` TEXT DEFAULT NULL AFTER `description`",
    'assignee_id'     => "ALTER TABLE `templates` ADD COLUMN `assignee_id` INT(11) DEFAULT NULL AFTER `expected_result`",
    'reporter_id'     => "ALTER TABLE `templates` ADD COLUMN `reporter_id` INT(11) DEFAULT NULL AFTER `assignee_id`",
    'expected_time'   => "ALTER TABLE `templates` ADD COLUMN `expected_time` INT(11) DEFAULT NULL COMMENT 'minutes' AFTER `reporter_id`",
    'repeat_type'     => "ALTER TABLE `templates` ADD COLUMN `repeat_type` VARCHAR(30) DEFAULT 'none' AFTER `expected_time`",
    'repeat_day'      => "ALTER TABLE `templates` ADD COLUMN `repeat_day` VARCHAR(10) DEFAULT NULL AFTER `repeat_type`",
    'start_time'      => "ALTER TABLE `templates` ADD COLUMN `start_time` VARCHAR(10) DEFAULT NULL AFTER `repeat_day`",
    'created_count'   => "ALTER TABLE `templates` ADD COLUMN `created_count` INT(11) NOT NULL DEFAULT 0 AFTER `start_time`",
    'created_at'      => "ALTER TABLE `templates` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_count`",
    'updated_at'      => "ALTER TABLE `templates` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
];

foreach ($toAdd as $col => $sql) {
    if (!in_array($col, $cols)) {
        $pdo->exec($sql);
        echo "Added column: $col" . PHP_EOL;
    } else {
        echo "Column already exists: $col" . PHP_EOL;
    }
}

echo "Done." . PHP_EOL;
