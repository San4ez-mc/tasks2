<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();
$rows = $db
    ->query('SELECT id, title, status, due_date, assignee_id, reporter_id FROM tasks WHERE company_id = :company_id AND title LIKE :prefix ORDER BY id ASC')
    ->bind(':company_id', 50)
    ->bind(':prefix', '[BOT TEST]%')
    ->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
