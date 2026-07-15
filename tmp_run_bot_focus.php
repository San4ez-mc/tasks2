<?php

require __DIR__ . '/config.php';

putenv('TELEGRAM_SKIP_NETWORK=1');
putenv('TELEGRAM_SKIP_AI=1');

use App\Models\Database;
use App\Services\TelegramBotService;
use App\Services\TelegramIntentRouterService;

$db = new Database();
$chatId = 90500051;
$telegramUsername = 'bot_tester_50';
$createdTaskPrefix = '[BOT TEST FOCUS]';

function fetchTester(Database $db, string $username): array
{
    $tester = $db
        ->query('SELECT * FROM users WHERE LOWER(username) = :username LIMIT 1')
        ->bind(':username', mb_strtolower($username))
        ->fetch();

    if (!is_array($tester) || empty($tester['id'])) {
        throw new RuntimeException('Tester not found. Run tmp_seed_bot_test_company50.php first.');
    }

    return $tester;
}

function resetFocusArtifacts(Database $db, int $chatId, int $userId, string $prefix): void
{
    $db->query('DELETE FROM tasks WHERE title LIKE :prefix')->bind(':prefix', $prefix . '%')->execute();

    $historyPath = ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telegram-history' . DIRECTORY_SEPARATOR . 'chat-' . $chatId . '.json';
    if (is_file($historyPath)) {
        @unlink($historyPath);
    }

    $draftDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telegram-drafts';
    foreach (glob($draftDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $raw = @file_get_contents($path);
        $payload = json_decode((string) $raw, true);
        if (is_array($payload) && (int) ($payload['user_id'] ?? 0) === $userId) {
            @unlink($path);
        }
    }
}

function getMaxLogId(Database $db): int
{
    $row = $db->query('SELECT MAX(id) AS max_id FROM telegram_ai_interaction_logs')->fetch();
    return (int) ($row['max_id'] ?? 0);
}

function latestLog(Database $db, int $afterId, int $chatId): array
{
    $row = $db
        ->query('SELECT * FROM telegram_ai_interaction_logs WHERE id > :after_id AND chat_id = :chat_id ORDER BY id DESC LIMIT 1')
        ->bind(':after_id', $afterId)
        ->bind(':chat_id', $chatId)
        ->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('No bot log found for chat ' . $chatId);
    }

    return $row;
}

function simulate(Database $db, int $chatId, int $telegramUserId, string $telegramUsername, string $text): array
{
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
            'text' => $text,
        ],
    ]);

    return latestLog($db, $beforeId, $chatId);
}

function countFocusTasks(Database $db, string $prefix): int
{
    $row = $db
        ->query('SELECT COUNT(*) AS cnt FROM tasks WHERE title LIKE :prefix')
        ->bind(':prefix', $prefix . '%')
        ->fetch();

    return (int) ($row['cnt'] ?? 0);
}

$tester = fetchTester($db, $telegramUsername);
$testerId = (int) $tester['id'];
$telegramUserId = (int) ($tester['telegram_id'] ?? 0);
if ($telegramUserId <= 0) {
    throw new RuntimeException('Tester telegram_id is not linked. Run tmp_seed_bot_test_company50.php first.');
}
resetFocusArtifacts($db, $chatId, $testerId, $createdTaskPrefix);

$router = new TelegramIntentRouterService();
$routePreview = $router->routeMessage('покажи мої задачі і мої цілі');

$capLog = simulate($db, $chatId, $telegramUserId, $telegramUsername, 'що ти вмієш');
$multiRouteLog = simulate($db, $chatId, $telegramUserId, $telegramUsername, 'покажи мої задачі і мої цілі');

$beforeCount = countFocusTasks($db, $createdTaskPrefix);
$multiTaskLog = simulate(
    $db,
    $chatId,
    $telegramUserId,
    $telegramUsername,
    'додай задачу ' . $createdTaskPrefix . ' Перша задача на завтра очікуваний результат: готовий перший блок очікуваний час: 30 хв і додай задачу ' . $createdTaskPrefix . ' Друга задача на завтра очікуваний результат: готовий другий блок очікуваний час: 40 хв'
);
$afterCount = countFocusTasks($db, $createdTaskPrefix);

echo json_encode([
    'route_preview' => $routePreview,
    'capabilities' => [
        'route_name' => $capLog['route_name'] ?? null,
        'execution_path' => $capLog['execution_path'] ?? null,
        'bot_reply' => $capLog['bot_reply'] ?? null,
    ],
    'multi_route' => [
        'route_name' => $multiRouteLog['route_name'] ?? null,
        'execution_path' => $multiRouteLog['execution_path'] ?? null,
        'bot_reply' => $multiRouteLog['bot_reply'] ?? null,
    ],
    'multi_task_message' => [
        'route_name' => $multiTaskLog['route_name'] ?? null,
        'execution_path' => $multiTaskLog['execution_path'] ?? null,
        'bot_reply' => $multiTaskLog['bot_reply'] ?? null,
        'created_delta' => $afterCount - $beforeCount,
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;