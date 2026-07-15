<?php
require_once __DIR__ . '/config.php';

$db = new App\Models\Database();

echo "=== Execution Paths ===\n";
$summary = $db->query(
    "SELECT COALESCE(execution_path, 'NULL') AS execution_path, COUNT(*) AS cnt
     FROM telegram_ai_interaction_logs
     GROUP BY execution_path
     ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($summary as $row) {
    echo ($row['execution_path'] ?? 'NULL') . ': ' . ($row['cnt'] ?? 0) . "\n";
}

echo "\n=== Problematic Rows ===\n";
$rows = $db->query(
    "SELECT id, created_at, app_user_id, execution_path, command_names, processing_status,
            LEFT(COALESCE(normalized_text, raw_text, ''), 300) AS user_text,
            LEFT(COALESCE(bot_reply, ''), 300) AS bot_reply
     FROM telegram_ai_interaction_logs
     WHERE execution_path IN ('ai_unrecognized', 'fallback_empty', 'fallback_draft', 'fallback_task_clarification', 'needs_task_clarification')
        OR processing_status IN ('ai_failed')
     ORDER BY id DESC
     LIMIT 60"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "---\n";
    foreach ($row as $key => $value) {
        $text = str_replace([PHP_EOL, "\r"], [' ↵ ', ''], (string) $value);
        echo $key . ': ' . $text . "\n";
    }
}