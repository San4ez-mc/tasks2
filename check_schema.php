<?php
require_once 'config.php';
$db = new App\Models\Database();

// Check user 92 - orphaned duplicate?
echo "=== User 92 ===\n";
$u92 = $db->query("SELECT * FROM users WHERE id = 92")->fetch();
if ($u92) {
    echo "name: {$u92['first_name']} {$u92['last_name']}\n";
    echo "email: " . ($u92['email'] ?: 'NULL') . "\n";
    echo "telegram_id: " . ($u92['telegram_id'] ?: 'NULL') . "\n";

    $m92 = $db->query("SELECT cm.*, c.name as company_name FROM company_members cm JOIN companies c ON cm.company_id = c.id WHERE cm.user_id = 92")->fetchAll();
    echo "Companies: " . count($m92) . "\n";
    foreach ($m92 as $m) {
        echo "  company_id={$m['company_id']} ({$m['company_name']}) role={$m['role']}\n";
    }
}

echo "\n=== User 90 (Demo User) ===\n";
$u90 = $db->query("SELECT * FROM users WHERE id = 90")->fetch();
if ($u90) {
    echo "name: {$u90['first_name']} {$u90['last_name']}\n";
    echo "telegram_id: " . ($u90['telegram_id'] ?: 'NULL') . "\n";
    $m90 = $db->query("SELECT cm.*, c.name as company_name FROM company_members cm JOIN companies c ON cm.company_id = c.id WHERE cm.user_id = 90")->fetchAll();
    echo "Companies: " . count($m90) . "\n";
    foreach ($m90 as $m) {
        echo "  company_id={$m['company_id']} ({$m['company_name']}) role={$m['role']}\n";
    }
}

// Check tasks table schema
echo "\n=== Tasks Table Schema ===\n";
$result = $db->query("SHOW COLUMNS FROM tasks");
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['Field']}: {$col['Type']}\n";
}

// Count tasks
$result = $db->query("SELECT COUNT(*) as count FROM tasks");
$row = $result->fetch(PDO::FETCH_ASSOC);
echo "\nTotal tasks: {$row['count']}\n";

// Sample tasks
echo "\n=== First 5 Tasks ===\n";
$result = $db->query("SELECT id, title, status FROM tasks LIMIT 5");
$tasks = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($tasks as $task) {
    echo "ID {$task['id']}: {$task['title']} ({$task['status']})\n";
}
?>