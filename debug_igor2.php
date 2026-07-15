<?php
require __DIR__ . '/config.php';

$db = new App\Models\Database();

echo "=== Company members for Igor's user IDs (91, 92, 93) ===\n";
$rows = $db->query("SELECT cm.user_id, cm.company_id, cm.title, cm.role, c.name as company_name FROM company_members cm JOIN companies c ON c.id = cm.company_id WHERE cm.user_id IN (91, 92, 93)")->fetchAll();
if (empty($rows)) {
    echo "No company memberships found!\n";
} else {
    foreach ($rows as $r) {
        echo "user_id={$r['user_id']} | company={$r['company_name']} (id={$r['company_id']}) | role={$r['role']} | title=" . ($r['title'] ?? '') . "\n";
    }
}

echo "\n=== Interaction logs for tg_user=328989898 ===\n";
$logs = $db->query("SELECT id, created_at, telegram_user_id, raw_text, processing_status FROM telegram_ai_interaction_logs WHERE telegram_user_id = 328989898 ORDER BY id DESC LIMIT 10")->fetchAll();
if (empty($logs)) {
    echo "No logs found for telegram_user_id=328989898\n";
} else {
    foreach ($logs as $l) {
        echo "id={$l['id']} | {$l['created_at']} | text=" . substr($l['raw_text'] ?? '', 0, 60) . " | status=" . ($l['processing_status'] ?? 'NULL') . "\n";
    }
}

echo "\n=== All users with username=igorstrikha ===\n";
$igors = $db->query("SELECT id, first_name, last_name, username, telegram_id, email FROM users WHERE LOWER(username) = 'igorstrikha'")->fetchAll();
foreach ($igors as $r) {
    echo "id={$r['id']} | {$r['first_name']} {$r['last_name']} | tg_id=" . ($r['telegram_id'] ?? 'NULL') . " | email=" . ($r['email'] ?? '') . "\n";
}
