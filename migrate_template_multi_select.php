<?php
require_once 'config.php';

use App\Models\Database;

$db = new Database();
$columns = $db->query('SHOW COLUMNS FROM templates')->fetchAll();
$columnsByName = [];
foreach ($columns as $column) {
    $name = (string) ($column['Field'] ?? '');
    if ($name !== '') {
        $columnsByName[$name] = $column;
    }
}

if (!isset($columnsByName['assignee_ids'])) {
    $db->query('ALTER TABLE templates ADD COLUMN assignee_ids VARCHAR(255) NULL AFTER assignee_id')->execute();
    echo "Added templates.assignee_ids\n";
}

if (isset($columnsByName['repeat_day'])) {
    $db->query('ALTER TABLE templates MODIFY COLUMN repeat_day VARCHAR(128) NULL')->execute();
    echo "Updated templates.repeat_day length to 128\n";
}

// Backfill assignee_ids from assignee_id for existing rows.
$db->query('UPDATE templates SET assignee_ids = CAST(assignee_id AS CHAR) WHERE assignee_id IS NOT NULL AND (assignee_ids IS NULL OR TRIM(assignee_ids) = "")')->execute();
echo "Backfilled templates.assignee_ids from assignee_id\n";
