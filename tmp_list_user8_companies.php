<?php
require __DIR__ . '/config.php';

$db = new App\Models\Database();
$rows = $db
    ->query('SELECT c.id, c.name, cm.role FROM company_members cm JOIN companies c ON c.id = cm.company_id WHERE cm.user_id = :user_id ORDER BY c.name ASC')
    ->bind(':user_id', 8)
    ->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
