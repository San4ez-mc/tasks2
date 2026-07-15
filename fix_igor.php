<?php
require __DIR__ . '/config.php';

$db = new App\Models\Database();

// 1. Check if users 92, 93 have tasks/results assigned
echo "=== Checking tasks for users 92, 93 ===\n";
$tasks = $db->query("SELECT id, title, assignee_id, reporter_id FROM tasks WHERE assignee_id IN (92, 93) OR reporter_id IN (92, 93)")->fetchAll();
echo count($tasks) . " tasks found\n";
foreach ($tasks as $t) {
    echo "  task id={$t['id']} | title=" . substr($t['title'], 0, 50) . " | assignee={$t['assignee_id']} | reporter={$t['reporter_id']}\n";
}

$results = $db->query("SELECT id, title, assignee_id, reporter_id FROM results WHERE assignee_id IN (92, 93) OR reporter_id IN (92, 93)")->fetchAll();
echo count($results) . " results/goals found\n";

// 2. Move telegram_id to user 91
echo "\n=== Moving telegram_id=328989898 to user 91 ===\n";
$db->query("UPDATE users SET telegram_id = NULL WHERE id IN (92, 93)")->execute();
$db->query("UPDATE users SET telegram_id = 328989898 WHERE id = 91")->execute();
echo "Done: telegram_id moved to user 91\n";

// 3. Reassign any tasks from 92/93 to 91
if (!empty($tasks)) {
    $db->query("UPDATE tasks SET assignee_id = 91 WHERE assignee_id IN (92, 93)")->execute();
    $db->query("UPDATE tasks SET reporter_id = 91 WHERE reporter_id IN (92, 93)")->execute();
    echo "Tasks reassigned to user 91\n";
}
if (!empty($results)) {
    $db->query("UPDATE results SET assignee_id = 91 WHERE assignee_id IN (92, 93)")->execute();
    $db->query("UPDATE results SET reporter_id = 91 WHERE reporter_id IN (92, 93)")->execute();
    echo "Results reassigned to user 91\n";
}

// 4. Delete company_members for 92, 93
$db->query("DELETE FROM company_members WHERE user_id IN (92, 93)")->execute();
echo "Company memberships for 92, 93 removed\n";

// 5. Delete companies created by onboarding (40=Active Life, 42=ActiveLife) if empty
foreach ([40, 42] as $cid) {
    $members = $db->query("SELECT COUNT(*) as cnt FROM company_members WHERE company_id = {$cid}")->fetch();
    if ((int) ($members['cnt'] ?? 0) === 0) {
        $db->query("DELETE FROM companies WHERE id = {$cid}")->execute();
        echo "Deleted empty company id={$cid}\n";
    } else {
        echo "Company id={$cid} still has members, skipped\n";
    }
}

// 6. Delete duplicate users 92, 93
$db->query("DELETE FROM users WHERE id IN (92, 93)")->execute();
echo "Deleted duplicate users 92, 93\n";

// 7. Delete onboarding drafts
$draftDir = __DIR__ . '/storage/telegram-drafts';
foreach ([92, 93] as $uid) {
    $path = $draftDir . '/onboarding-' . $uid . '.json';
    if (is_file($path)) {
        unlink($path);
        echo "Deleted onboarding draft for user {$uid}\n";
    }
}

// 8. Verify
echo "\n=== Verification ===\n";
$igor = $db->query("SELECT id, first_name, last_name, username, telegram_id, email FROM users WHERE id = 91")->fetch();
echo "User 91: {$igor['first_name']} {$igor['last_name']} | tg_id=" . ($igor['telegram_id'] ?? 'NULL') . " | username=" . ($igor['username'] ?? 'NULL') . "\n";

$membership = $db->query("SELECT cm.company_id, c.name FROM company_members cm JOIN companies c ON c.id = cm.company_id WHERE cm.user_id = 91")->fetchAll();
foreach ($membership as $m) {
    echo "Company: {$m['name']} (id={$m['company_id']})\n";
}

echo "\nDone! Igor should now work correctly with the bot.\n";
