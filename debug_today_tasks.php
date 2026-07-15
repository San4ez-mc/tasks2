<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

echo "=== Tasks today for company 39 / reporter or assignee user 8 ===\n";
$rows = $db->query(
    "SELECT id, company_id, reporter_id, assignee_id, title, status, expected_result, expected_time, actual_time, created_at, due_date
     FROM tasks
     WHERE DATE(created_at) = CURDATE()
       AND company_id = 39
       AND (reporter_id = 8 OR assignee_id = 8)
     ORDER BY id DESC"
)->fetchAll();
foreach ($rows as $r) {
    echo "id={$r['id']} title=[{$r['title']}] status=[{$r['status']}] reporter={$r['reporter_id']} assignee={$r['assignee_id']} due={$r['due_date']} expected_time={$r['expected_time']} created={$r['created_at']}\n";
    echo "expected_result=[{$r['expected_result']}]\n";
}
if (empty($rows)) echo "No tasks found\n";
