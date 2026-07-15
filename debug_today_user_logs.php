<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

echo "=== Today logs for Oleksandr / user 8 / tg 345126254 ===\n";
$logs = $db->query(
    "SELECT id, chat_id, telegram_user_id, app_user_id, company_id, message_kind, raw_text, transcribed_text, normalized_text,
            route_name, route_confidence, route_reason, execution_path, command_names, LEFT(bot_reply, 300) AS bot_reply,
            LEFT(ai_raw_response, 300) AS ai_raw_response, LEFT(audio_error, 300) AS audio_error, processing_status, created_at
     FROM telegram_ai_interaction_logs
     WHERE DATE(created_at) = CURDATE()
       AND (telegram_user_id = 345126254 OR app_user_id = 8)
     ORDER BY id DESC"
)->fetchAll();

foreach ($logs as $l) {
    echo str_repeat('-', 100) . "\n";
    echo "id={$l['id']} created_at={$l['created_at']} kind={$l['message_kind']} status={$l['processing_status']} route={$l['route_name']} exec={$l['execution_path']} company_id=" . var_export($l['company_id'], true) . "\n";
    echo "raw=" . trim((string)$l['raw_text']) . "\n";
    if (!empty($l['transcribed_text'])) echo "transcribed={$l['transcribed_text']}\n";
    if (!empty($l['normalized_text'])) echo "normalized={$l['normalized_text']}\n";
    if (!empty($l['command_names'])) echo "commands={$l['command_names']}\n";
    if (!empty($l['bot_reply'])) echo "bot_reply={$l['bot_reply']}\n";
    if (!empty($l['ai_raw_response'])) echo "ai_raw_response={$l['ai_raw_response']}\n";
    if (!empty($l['audio_error'])) echo "audio_error={$l['audio_error']}\n";
}

echo "\n=== Summary counts today ===\n";
$summary = $db->query(
    "SELECT execution_path, route_name, processing_status, COUNT(*) AS cnt
     FROM telegram_ai_interaction_logs
     WHERE DATE(created_at) = CURDATE()
       AND (telegram_user_id = 345126254 OR app_user_id = 8)
     GROUP BY execution_path, route_name, processing_status
     ORDER BY cnt DESC, execution_path ASC"
)->fetchAll();
foreach ($summary as $row) {
    echo "execution_path=" . ($row['execution_path'] ?? '') . " route=" . ($row['route_name'] ?? '') . " status=" . ($row['processing_status'] ?? '') . " cnt={$row['cnt']}\n";
}
