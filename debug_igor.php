<?php
require __DIR__ . '/config.php';

$db = new App\Models\Database();

echo "=== Users with username or telegram_id 328989898 ===\n";
$rows = $db->query("SELECT id, first_name, last_name, username, telegram_id, email FROM users WHERE telegram_id = 328989898 OR username IS NOT NULL ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "id={$r['id']} | {$r['first_name']} {$r['last_name']} | tg_id=" . ($r['telegram_id'] ?? 'NULL') . " | username=" . ($r['username'] ?? 'NULL') . " | email=" . ($r['email'] ?? '') . "\n";
}

echo "\n=== Interaction logs for telegram_user_id=328989898 ===\n";
$logs = $db->query("SELECT id, created_at, raw_text, processing_status, error_message FROM telegram_ai_interaction_logs WHERE telegram_user_id = 328989898 ORDER BY id DESC LIMIT 10")->fetchAll();
if (empty($logs)) {
    echo "No logs found for this telegram_user_id\n";
} else {
    foreach ($logs as $l) {
        echo "id={$l['id']} | {$l['created_at']} | text=" . substr($l['raw_text'] ?? '', 0, 60) . " | status=" . ($l['processing_status'] ?? 'NULL') . " | err=" . substr($l['error_message'] ?? '', 0, 100) . "\n";
    }
}

echo "\n=== Latest 5 interaction logs (any user) ===\n";
$latest = $db->query("SELECT id, created_at, telegram_user_id, raw_text, processing_status FROM telegram_ai_interaction_logs ORDER BY id DESC LIMIT 5")->fetchAll();
foreach ($latest as $l) {
    echo "id={$l['id']} | {$l['created_at']} | tg_user={$l['telegram_user_id']} | text=" . substr($l['raw_text'] ?? '', 0, 50) . " | status=" . ($l['processing_status'] ?? 'NULL') . "\n";
}
