<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();

$rows = $db
    ->query('SELECT id, title, due_date, status, expected_time FROM tasks WHERE company_id = :company_id AND assignee_id = :assignee_id AND title LIKE :prefix ORDER BY due_date ASC')
    ->bind(':company_id', 50)
    ->bind(':assignee_id', 106)
    ->bind(':prefix', '[AUTO KOROBOVA]%')
    ->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}