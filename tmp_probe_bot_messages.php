<?php

require __DIR__ . '/config.php';

putenv('TELEGRAM_SKIP_NETWORK=1');
putenv('TELEGRAM_SKIP_AI=1');

use App\Models\Database;
use App\Services\TelegramBotService;

$db = new Database();
$chatId = 90500052;
$telegramUsername = 'bot_tester_50';

$messages = [
    'покажи мої задачі',
    'покажи делеговані задачі',
    'покажи задачі підлеглих',
    'покажи мої цілі',
    'покажи мої задачі і мої цілі',
    'покажи план факт по підлеглих',
    'не Тетяна, а Bot Subordinate',
];

$tester = $db
    ->query('SELECT * FROM users WHERE LOWER(username) = :username LIMIT 1')
    ->bind(':username', mb_strtolower($telegramUsername))
    ->fetch();

if (!is_array($tester) || empty($tester['id'])) {
    throw new RuntimeException('Tester not found.');
}

$telegramUserId = (int) ($tester['telegram_id'] ?? 0);
if ($telegramUserId <= 0) {
    throw new RuntimeException('Tester telegram_id is not linked.');
}

function getMaxLogId(Database $db): int
{
    $row = $db->query('SELECT MAX(id) AS max_id FROM telegram_ai_interaction_logs')->fetch();
    return (int) ($row['max_id'] ?? 0);
}

function latestLog(Database $db, int $afterId, int $chatId): ?array
{
    $row = $db
        ->query('SELECT * FROM telegram_ai_interaction_logs WHERE id > :after_id AND chat_id = :chat_id ORDER BY id DESC LIMIT 1')
        ->bind(':after_id', $afterId)
        ->bind(':chat_id', $chatId)
        ->fetch();

    return is_array($row) ? $row : null;
}

foreach ($messages as $message) {
    $beforeId = getMaxLogId($db);

    $service = new TelegramBotService();
    $service->handleUpdate([
        'update_id' => random_int(100000, 999999),
        'message' => [
            'message_id' => random_int(1000, 9999),
            'date' => time(),
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => [
                'id' => $telegramUserId,
                'is_bot' => false,
                'first_name' => 'Bot',
                'last_name' => 'Tester',
                'username' => $telegramUsername,
            ],
            'text' => $message,
        ],
    ]);

    $log = latestLog($db, $beforeId, $chatId);
    echo json_encode([
        'message' => $message,
        'route_name' => $log['route_name'] ?? null,
        'execution_path' => $log['execution_path'] ?? null,
        'processing_status' => $log['processing_status'] ?? null,
        'bot_reply' => $log['bot_reply'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
