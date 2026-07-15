<?php
require_once 'config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ':' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Create projects table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `projects` (
        `id`          INT(11) NOT NULL AUTO_INCREMENT,
        `company_id`  INT(11) NOT NULL,
        `name`        VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_by`  INT(11) NOT NULL,
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_projects_company` (`company_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table 'projects' OK" . PHP_EOL;

// 2. Create project_members table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `project_members` (
        `id`         INT(11) NOT NULL AUTO_INCREMENT,
        `project_id` INT(11) NOT NULL,
        `user_id`    INT(11) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_project_member` (`project_id`, `user_id`),
        KEY `idx_pm_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table 'project_members' OK" . PHP_EOL;

// 3. Add project_id to tasks (optional)
$taskCols = $pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('project_id', $taskCols)) {
    $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `project_id` INT(11) DEFAULT NULL AFTER `company_id`");
    echo "Added tasks.project_id" . PHP_EOL;
} else {
    echo "tasks.project_id already exists" . PHP_EOL;
}

echo "Done." . PHP_EOL;
