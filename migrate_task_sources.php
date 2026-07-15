<?php
require_once 'config.php';

$db = new App\Models\Database();
$columns = $db->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_ASSOC);
$existing = array_map(static function ($row) {
    return $row['Field'];
}, $columns);

if (!in_array('result_id', $existing, true)) {
    $db->query('ALTER TABLE tasks ADD COLUMN result_id INT(11) NULL AFTER actual_time')->execute();
    echo "Added column: result_id\n";
} else {
    echo "Column exists: result_id\n";
}

if (!in_array('template_id', $existing, true)) {
    $db->query('ALTER TABLE tasks ADD COLUMN template_id INT(11) NULL AFTER result_id')->execute();
    echo "Added column: template_id\n";
} else {
    echo "Column exists: template_id\n";
}

echo "Done.\n";
