<?php
require __DIR__ . '/config.php';

$db = new App\Models\Database();

echo "=== Search by telegram_id 328989898 ===" . PHP_EOL;
$r = $db->query('SELECT id, first_name, last_name, email, telegram_id, username FROM users WHERE telegram_id = :tid LIMIT 1')
    ->bind(':tid', 328989898)
    ->fetch();
var_dump($r);

echo PHP_EOL . "=== All company members ===" . PHP_EOL;
$rows = $db->query('SELECT u.id, u.first_name, u.last_name, u.email, u.telegram_id, u.username, cm.company_id FROM users u JOIN company_members cm ON cm.user_id = u.id ORDER BY u.id')->fetchAll();
foreach ($rows as $u) {
    echo $u['id'] . ' | ' . $u['first_name'] . ' ' . $u['last_name'] . ' | tg_id=' . ($u['telegram_id'] ?? 'NULL') . ' | username=' . ($u['username'] ?? 'NULL') . ' | email=' . ($u['email'] ?? 'NULL') . ' | company=' . $u['company_id'] . PHP_EOL;
}

echo PHP_EOL . "=== Recent interaction logs (last 5) ===" . PHP_EOL;
$logs = $db->query('SELECT id, chat_id, telegram_user_id, app_user_id, processing_status, raw_text, created_at FROM telegram_ai_interaction_logs ORDER BY id DESC LIMIT 5')->fetchAll();
foreach ($logs as $l) {
    echo $l['id'] . ' | chat=' . $l['chat_id'] . ' | tg_user=' . $l['telegram_user_id'] . ' | app_user=' . ($l['app_user_id'] ?? 'NULL') . ' | status=' . ($l['processing_status'] ?? 'NULL') . ' | ' . substr($l['raw_text'] ?? '', 0, 40) . ' | ' . $l['created_at'] . PHP_EOL;
}
