<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();

$columns = $db->query('SHOW COLUMNS FROM tasks')->fetchAll();
$hasSource = false;
foreach ($columns as $column) {
    if (($column['Field'] ?? '') === 'source') {
        $hasSource = true;
        break;
    }
}

if (!$hasSource) {
    $db->query("ALTER TABLE tasks ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'web' AFTER template_id")->execute();
    echo "tasks.source column created\n";
} else {
    echo "tasks.source already exists\n";
}
