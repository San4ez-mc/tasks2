<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();
$rows = $db
    ->query('SELECT id, chat_id, raw_text, route_name, execution_path, processing_status, LEFT(bot_reply, 220) AS bot_reply, created_at FROM telegram_ai_interaction_logs ORDER BY id DESC LIMIT 12')
    ->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}