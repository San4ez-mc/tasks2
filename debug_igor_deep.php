<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

echo "=== Users with telegram_id=328989898 or username like igorstrikha ===\n";
$users = $db->query("SELECT id, first_name, last_name, email, telegram_id, username, created_at, updated_at FROM users WHERE telegram_id = 328989898 OR LOWER(username) = 'igorstrikha' ORDER BY id")->fetchAll();
foreach ($users as $u) {
    echo "id={$u['id']} name=[{$u['first_name']} {$u['last_name']}] email=[{$u['email']}] tg_id=[{$u['telegram_id']}] username=[{$u['username']}] created={$u['created_at']} updated={$u['updated_at']}\n";
}


echo "\n=== Any duplicate telegram ids / usernames around Igor ===\n";
$dups = $db->query("SELECT id, first_name, last_name, email, telegram_id, username FROM users WHERE telegram_id IN (328989898,729346519) OR LOWER(username) IN ('igorstrikha','igoractivelife') ORDER BY id")->fetchAll();
foreach ($dups as $u) {
    echo "id={$u['id']} name=[{$u['first_name']} {$u['last_name']}] email=[{$u['email']}] tg_id=[{$u['telegram_id']}] username=[{$u['username']}]\n";
}


echo "\n=== company_members for related users ===\n";
$cms = $db->query("SELECT cm.id, cm.user_id, cm.company_id, cm.role, c.name as company_name FROM company_members cm JOIN companies c ON c.id = cm.company_id WHERE cm.user_id IN (91,93,96) ORDER BY cm.user_id, cm.company_id")->fetchAll();
foreach ($cms as $cm) {
    echo "cm_id={$cm['id']} user_id={$cm['user_id']} company_id={$cm['company_id']} company=[{$cm['company_name']}] role={$cm['role']}\n";
}


echo "\n=== Last 12 interaction logs for telegram_user_id=328989898 ===\n";
$logs = $db->query("SELECT id, telegram_user_id, app_user_id, company_id, raw_text, normalized_text, processing_status, LEFT(bot_reply, 220) as bot_reply, created_at FROM telegram_ai_interaction_logs WHERE telegram_user_id = 328989898 ORDER BY id DESC LIMIT 12")->fetchAll();
foreach ($logs as $l) {
    echo "id={$l['id']} app_user_id=" . var_export($l['app_user_id'], true) . " company_id=" . var_export($l['company_id'], true) . " status=[{$l['processing_status']}] raw=[{$l['raw_text']}] norm=[{$l['normalized_text']}] bot=[{$l['bot_reply']}] at={$l['created_at']}\n";
}


echo "\n=== TG_ACTIVE_COMPANY tokens for related users ===\n";
$tokens = $db->query("SELECT id, user_id, company_id, type, token, expires_at, created_at FROM auth_tokens WHERE token = 'TG_ACTIVE_COMPANY' AND user_id IN (91,93,96) ORDER BY id DESC")->fetchAll();
foreach ($tokens as $t) {
    echo "id={$t['id']} user_id={$t['user_id']} company_id={$t['company_id']} type={$t['type']} expires={$t['expires_at']} created={$t['created_at']}\n";
}


echo "\n=== auth_tokens for user 91 ===\n";
$allTokens = $db->query("SELECT id, token, company_id, type, expires_at, created_at FROM auth_tokens WHERE user_id = 91 ORDER BY id DESC LIMIT 20")->fetchAll();
foreach ($allTokens as $t) {
    echo "id={$t['id']} token=[{$t['token']}] company_id=" . var_export($t['company_id'], true) . " type=[{$t['type']}] expires={$t['expires_at']} created={$t['created_at']}\n";
}
