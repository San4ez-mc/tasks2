<?php
require_once 'config.php';

use App\Models\Database;

$db = new Database();

$columns = $db->query('SHOW COLUMNS FROM tasks')->fetchAll();
$hasAcceptedAt = false;

foreach ($columns as $column) {
    if (($column['Field'] ?? '') === 'accepted_at') {
        $hasAcceptedAt = true;
        break;
    }
}

if (!$hasAcceptedAt) {
    $db->query('ALTER TABLE tasks ADD COLUMN accepted_at DATETIME NULL AFTER reporter_id')->execute();

    // Existing tasks should remain visible in current lists after migration.
    $db->query('UPDATE tasks SET accepted_at = COALESCE(created_at, NOW()) WHERE accepted_at IS NULL')->execute();
    echo "Added tasks.accepted_at and backfilled existing tasks.\n";
} else {
    echo "Column tasks.accepted_at already exists.\n";
}
