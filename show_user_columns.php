<?php
require_once __DIR__ . '/config.php';
$db = new App\Models\Database();

$users = $db->query("SHOW COLUMNS FROM users")->fetchAll();
echo "users table columns:\n";
foreach ($users as $col) {
    echo "  {$col['Field']} ({$col['Type']})\n";
}
