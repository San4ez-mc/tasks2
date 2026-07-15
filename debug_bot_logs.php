<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

// 1. Find log tables
echo "=== Log-related tables ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll();
foreach ($tables as $t) {
    $name = array_values($t)[0];
    if (stripos($name, 'log') !== false || stripos($name, 'interaction') !== false || stripos($name, 'error') !== false) {
        echo "  $name\n";
    }
}

// 2. Check telegram_ai_interaction_logs
echo "\n=== telegram_ai_interaction_logs columns ===\n";
try {
    $cols = $db->query("SHOW COLUMNS FROM telegram_ai_interaction_logs")->fetchAll();
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch (Exception $e) {
    echo "  Table not found: " . $e->getMessage() . "\n";
}

echo "\n=== Recent interaction logs (last 40) ===\n";
try {
    $logs = $db->query("SELECT * FROM telegram_ai_interaction_logs ORDER BY id DESC LIMIT 40")->fetchAll();
    foreach ($logs as $l) {
        echo str_repeat('-', 100) . "\n";
        foreach ($l as $k => $v) {
            if ($v !== null && $v !== '') {
                $vShort = mb_substr((string)$v, 0, 300);
                echo "  $k: $vShort\n";
            }
        }
    }
    if (empty($logs)) echo "  No logs found\n";
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

// 2b. error_logs
echo "\n=== Recent error_logs (last 20) ===\n";
try {
    $errs = $db->query("SELECT * FROM error_logs ORDER BY id DESC LIMIT 20")->fetchAll();
    foreach ($errs as $l) {
        echo str_repeat('-', 80) . "\n";
        foreach ($l as $k => $v) {
            if ($v !== null && $v !== '') {
                echo "  $k: " . mb_substr((string)$v, 0, 300) . "\n";
            }
        }
    }
    if (empty($errs)) echo "  No error logs\n";
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

// 3. Igor's user + companies
echo "\n=== Igor's user records ===\n";
$igors = $db->query("SELECT id, first_name, last_name, telegram_id, username, email FROM users WHERE first_name LIKE '%Igor%' OR first_name LIKE '%Ігор%' OR last_name LIKE '%Igor%' OR telegram_id = 328989898")->fetchAll();
foreach ($igors as $u) {
    echo "id={$u['id']} name={$u['first_name']} {$u['last_name']} tg_id={$u['telegram_id']} username={$u['username']} email={$u['email']}\n";
    $memberships = $db->query("SELECT cm.id, cm.company_id, c.name as company_name, cm.role FROM company_members cm JOIN companies c ON c.id = cm.company_id WHERE cm.user_id = :uid")->bind(':uid', $u['id'])->fetchAll();
    foreach ($memberships as $m) {
        echo "  -> company: id={$m['company_id']} name=[{$m['company_name']}] role={$m['role']}\n";
    }
    if (empty($memberships)) echo "  -> NO company memberships\n";
}

// 4. Admin users
echo "\n=== Owner/admin users ===\n";
$admins = $db->query("SELECT u.id, u.first_name, u.last_name, u.telegram_id, u.username, cm.role, cm.company_id, c.name as company_name FROM users u JOIN company_members cm ON cm.user_id = u.id JOIN companies c ON c.id = cm.company_id WHERE cm.role IN ('owner','admin') ORDER BY u.id LIMIT 20")->fetchAll();
foreach ($admins as $u) {
    echo "user={$u['id']} name={$u['first_name']} {$u['last_name']} tg={$u['telegram_id']} role={$u['role']} company={$u['company_id']}({$u['company_name']})\n";
}

echo "\nNOW(PHP/UTC) = " . date('Y-m-d H:i:s') . "\n";
$tz = $db->query("SELECT NOW() as db_now")->fetch();
echo "NOW(DB) = {$tz['db_now']}\n";
