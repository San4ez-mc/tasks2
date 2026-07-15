<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

// Check specific token
$target = 'TGONB-StgnnjxEJTNjtQLM';
echo "=== Looking for token: [{$target}] ===\n";
$row = $db->query("SELECT * FROM auth_tokens WHERE token = :t LIMIT 1")->bind(':t', $target)->fetch();
if ($row) {
    echo "FOUND: id={$row['id']} type=[{$row['type']}] user={$row['user_id']} company={$row['company_id']} expires={$row['expires_at']}\n";
} else {
    echo "NOT FOUND by exact match\n";
}

// Check with type filter (as the bot does)
$row2 = $db->query("SELECT * FROM auth_tokens WHERE token = :t AND type = 'tg_onboarding' AND expires_at > NOW() LIMIT 1")->bind(':t', $target)->fetch();
echo "With type+expiry filter: " . ($row2 ? "FOUND id={$row2['id']}" : "NOT FOUND") . "\n";

// Show ALL TGONB tokens
echo "\n=== All TGONB tokens ===\n";
$rows = $db->query("SELECT id, token, user_id, type, expires_at FROM auth_tokens WHERE token LIKE 'TGONB%' ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($rows as $r) {
    echo "id={$r['id']} token=[{$r['token']}] type=[{$r['type']}] expires={$r['expires_at']}\n";
}
if (empty($rows)) echo "No TGONB tokens at all\n";

echo "\nNOW = " . date('Y-m-d H:i:s') . "\n";
echo "DB timezone: ";
$tz = $db->query("SELECT NOW() as now_db, @@session.time_zone as tz")->fetch();
echo "DB NOW={$tz['now_db']} TZ={$tz['tz']}\n";
