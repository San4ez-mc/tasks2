<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();
$rows = $db
    ->query('SELECT id, title, due_date, status, assignee_id, reporter_id FROM tasks WHERE title LIKE :prefix ORDER BY id DESC')
    ->bind(':prefix', '[BOT TEST MATRIX]%')
    ->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}