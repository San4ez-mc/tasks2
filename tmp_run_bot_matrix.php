<?php

require __DIR__ . '/config.php';

putenv('TELEGRAM_SKIP_NETWORK=1');
putenv('TELEGRAM_SKIP_AI=1');

use App\Models\Database;
use App\Services\TelegramBotService;

$db = new Database();
$chatId = 90500050;
$telegramUsername = 'bot_tester_50';
$createdTaskPrefix = '[BOT TEST MATRIX]';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function announce(string $message): void
{
    fwrite(STDERR, '[matrix] ' . $message . PHP_EOL);
}

function getTester(Database $db, string $username): array
{
    $tester = $db
        ->query('SELECT * FROM users WHERE LOWER(username) = :username LIMIT 1')
        ->bind(':username', mb_strtolower($username))
        ->fetch();

    if (!is_array($tester) || empty($tester['id'])) {
        fail('Bot test user not found. Run tmp_seed_bot_test_company50.php first.');
    }

    return $tester;
}

function clearChatArtifacts(int $chatId, int $userId): void
{
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

function latestLogAfter(Database $db, int $afterId, int $chatId): ?array
{
    $row = $db
        ->query('SELECT * FROM telegram_ai_interaction_logs WHERE id > :after_id AND chat_id = :chat_id ORDER BY id DESC LIMIT 1')
        ->bind(':after_id', $afterId)
        ->bind(':chat_id', $chatId)
        ->fetch();

    return is_array($row) ? $row : null;
}

function getMaxLogId(Database $db): int
{
    $row = $db->query('SELECT MAX(id) AS max_id FROM telegram_ai_interaction_logs')->fetch();
    return (int) ($row['max_id'] ?? 0);
}

function simulateMessage(Database $db, int $chatId, int $telegramUserId, string $telegramUsername, string $text): array
{
    $beforeId = getMaxLogId($db);

    $service = new TelegramBotService();
    $service->handleUpdate([
        'update_id' => random_int(100000, 999999),
        'message' => [
            'message_id' => random_int(1000, 9999),
            'date' => time(),
            'chat' => [
                'id' => $chatId,
                'type' => 'private',
            ],
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

    $log = latestLogAfter($db, $beforeId, $chatId);
    if (!$log) {
        fail('No interaction log captured for message: ' . $text);
    }

    return $log;
}

function latestDraftKeyForUser(int $userId): ?string
{
    $draftDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telegram-drafts';
    $latestPath = null;
    $latestTime = 0;

    foreach (glob($draftDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $raw = @file_get_contents($path);
        $payload = json_decode((string) $raw, true);
        if (!is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $userId) {
            continue;
        }

        $mtime = (int) @filemtime($path);
        if ($mtime >= $latestTime) {
            $latestTime = $mtime;
            $latestPath = $path;
        }
    }

    return $latestPath ? pathinfo($latestPath, PATHINFO_FILENAME) : null;
}

function simulateCallback(int $chatId, int $telegramUserId, string $telegramUsername, string $data): void
{
    $service = new TelegramBotService();
    $service->handleUpdate([
        'update_id' => random_int(100000, 999999),
        'callback_query' => [
            'id' => bin2hex(random_bytes(8)),
            'from' => [
                'id' => $telegramUserId,
                'is_bot' => false,
                'first_name' => 'Bot',
                'last_name' => 'Tester',
                'username' => $telegramUsername,
            ],
            'message' => [
                'message_id' => random_int(1000, 9999),
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ],
            'data' => $data,
        ],
    ]);
}

function assertContains(array &$failures, string $scenario, string $haystack, string $needle, string $context): void
{
    if (!str_contains(mb_strtolower($haystack), mb_strtolower($needle))) {
        $failures[] = $scenario . ': expected to find [' . $needle . '] in ' . $context . '. Actual: ' . $haystack;
    }
}

function countTasks(Database $db, string $titleLike): int
{
    $row = $db
        ->query('SELECT COUNT(*) AS cnt FROM tasks WHERE title LIKE :title')
        ->bind(':title', $titleLike)
        ->fetch();

    return (int) ($row['cnt'] ?? 0);
}

function countTemplates(Database $db, string $nameLike): int
{
    $row = $db
        ->query('SELECT COUNT(*) AS cnt FROM templates WHERE name LIKE :name')
        ->bind(':name', $nameLike)
        ->fetch();

    return (int) ($row['cnt'] ?? 0);
}

function ensureCompletedDeleteFixture(Database $db, int $companyId, int $userId, string $title): void
{
    $existing = $db
        ->query('SELECT id FROM tasks WHERE company_id = :company_id AND title = :title LIMIT 1')
        ->bind(':company_id', $companyId)
        ->bind(':title', $title)
        ->fetch();

    if (is_array($existing) && !empty($existing['id'])) {
        return;
    }

    $db->insert('tasks', [
        'title' => $title,
        'company_id' => $companyId,
        'assignee_id' => $userId,
        'reporter_id' => $userId,
        'status' => 'done',
        'due_date' => date('Y-m-d 11:00:00'),
        'description' => 'Delete fixture for bot matrix',
        'expected_result' => 'Delete fixture expected result',
        'actual_result' => 'Delete fixture done',
        'type' => 'important-urgent',
        'expected_time' => 20,
        'actual_time' => 20,
    ]);
}

$tester = getTester($db, $telegramUsername);
$testerId = (int) $tester['id'];
$telegramUserId = (int) ($tester['telegram_id'] ?? 0);
if ($telegramUserId <= 0) {
    fail('Tester telegram_id is not linked. Re-run tmp_seed_bot_test_company50.php.');
}
clearChatArtifacts($chatId, $testerId);

$db->query('DELETE FROM tasks WHERE title LIKE :prefix')->bind(':prefix', $createdTaskPrefix . '%')->execute();
$db->query('DELETE FROM templates WHERE name LIKE :prefix')->bind(':prefix', $createdTaskPrefix . '%')->execute();

$failures = [];
$report = [];

$scenarios = [
    ['name' => 'capabilities', 'text' => 'що ти вмієш', 'expect_route' => null, 'expect_reply' => 'можу'],
    ['name' => 'current_company', 'text' => 'яка зараз компанія', 'expect_route' => 'company_current', 'expect_reply' => 'активна компанія'],
    ['name' => 'employees_company', 'text' => 'покажи працівників', 'expect_route' => 'employee_list', 'expect_reply' => 'люди в компанії'],
    ['name' => 'employees_subordinates', 'text' => 'покажи підлеглих', 'expect_route' => 'employee_list', 'expect_reply' => 'Bot Subordinate'],
    ['name' => 'templates_list', 'text' => 'покажи шаблони', 'expect_route' => 'template_list', 'expect_reply' => '[BOT TEST] Щоденний follow-up'],
    ['name' => 'tasks_my', 'text' => 'покажи мої задачі', 'expect_route' => 'task_list', 'expect_reply' => '[BOT TEST] Моя активна задача'],
    ['name' => 'tasks_delegated', 'text' => 'покажи делеговані задачі', 'expect_route' => 'task_list', 'expect_reply' => '[BOT TEST] Делегована активна задача'],
    ['name' => 'tasks_subordinates', 'text' => 'покажи задачі підлеглих', 'expect_route' => 'task_list', 'expect_reply' => '[BOT TEST] Задача підлеглого'],
    ['name' => 'goals_my', 'text' => 'покажи мої цілі', 'expect_route' => 'goal_list', 'expect_reply' => '[BOT TEST] Моя ціль на тиждень'],
    ['name' => 'multi_route_lists', 'text' => 'покажи мої задачі і мої цілі', 'expect_route' => 'planner', 'expect_reply' => '[BOT TEST] Моя ціль на тиждень'],
    ['name' => 'plan_fact', 'text' => 'покажи план факт по підлеглих', 'expect_route' => 'plan_fact', 'expect_reply' => 'план'],
    ['name' => 'correction_only', 'text' => 'не Тетяна, а Bot Subordinate', 'expect_route' => 'correction_only', 'expect_reply' => 'уточнення'],
];

foreach ($scenarios as $scenario) {
    announce('scenario: ' . $scenario['name']);
    $log = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, $scenario['text']);
    $report[] = [
        'scenario' => $scenario['name'],
        'route' => $log['route_name'] ?? null,
        'execution_path' => $log['execution_path'] ?? null,
        'reply' => $log['bot_reply'] ?? null,
    ];

    if (!empty($scenario['expect_route'])) {
        if (($log['route_name'] ?? null) !== $scenario['expect_route']) {
            $failures[] = $scenario['name'] . ': expected route ' . $scenario['expect_route'] . ', got ' . ($log['route_name'] ?? 'null');
        }
    }

    assertContains($failures, $scenario['name'], (string) ($log['bot_reply'] ?? ''), (string) $scenario['expect_reply'], 'bot_reply');
}

$deleteFixtureTitle = '[BOT TEST] Моя завершена задача';
ensureCompletedDeleteFixture($db, 50, $testerId, $deleteFixtureTitle);
$deleteBefore = countTasks($db, $deleteFixtureTitle);
announce('scenario: delete_request');
$deleteLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'видали мої завершені задачі');
$deleteDraftKey = latestDraftKeyForUser($testerId);
$report[] = ['scenario' => 'delete_request', 'route' => $deleteLog['route_name'] ?? null, 'reply' => $deleteLog['bot_reply'] ?? null, 'draft' => $deleteDraftKey];
if (($deleteLog['route_name'] ?? null) !== 'delete_tasks') {
    $failures[] = 'delete_request: expected route delete_tasks, got ' . ($deleteLog['route_name'] ?? 'null');
}
if ($deleteDraftKey === null) {
    $failures[] = 'delete_request: expected pending draft key, got none';
} else {
    simulateCallback($chatId, $telegramUserId, $telegramUsername, 'tg_confirm_delete:' . $deleteDraftKey);
    $deleteAfter = countTasks($db, $deleteFixtureTitle);
    if (!($deleteBefore >= 1 && $deleteAfter === 0)) {
        $failures[] = 'delete_confirm: expected completed task to be deleted. Before=' . $deleteBefore . ', after=' . $deleteAfter;
    }
}

$createBefore = countTasks($db, $createdTaskPrefix . '%');
announce('scenario: create_full');
$createLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'додай задачу ' . $createdTaskPrefix . ' Підготувати демо звіт на завтра очікуваний результат: готовий демо звіт очікуваний час: 30 хв');
$createAfter = countTasks($db, $createdTaskPrefix . '%');
$report[] = ['scenario' => 'create_full', 'route' => $createLog['route_name'] ?? null, 'execution_path' => $createLog['execution_path'] ?? null, 'reply' => $createLog['bot_reply'] ?? null, 'created_after' => $createAfter];
if ($createAfter <= $createBefore) {
    $failures[] = 'create_full: expected task to be created, count before=' . $createBefore . ', after=' . $createAfter;
}

announce('scenario: create_missing_fields');
$clarifyLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'додай задачу ' . $createdTaskPrefix . ' Узгодити тижневий звіт');
$report[] = ['scenario' => 'create_missing_fields', 'route' => $clarifyLog['route_name'] ?? null, 'execution_path' => $clarifyLog['execution_path'] ?? null, 'reply' => $clarifyLog['bot_reply'] ?? null];
assertContains($failures, 'create_missing_fields', (string) ($clarifyLog['bot_reply'] ?? ''), 'очікуван', 'bot_reply');

$clarifyBefore = countTasks($db, $createdTaskPrefix . ' Узгодити тижневий звіт%');
announce('scenario: clarification_reply');
$clarifyReplyLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'на завтра очікуваний результат: готовий узгоджений звіт очікуваний час: 45 хв');
$clarifyAfter = countTasks($db, $createdTaskPrefix . ' Узгодити тижневий звіт%');
$report[] = ['scenario' => 'clarification_reply', 'route' => $clarifyReplyLog['route_name'] ?? null, 'execution_path' => $clarifyReplyLog['execution_path'] ?? null, 'reply' => $clarifyReplyLog['bot_reply'] ?? null, 'created_after' => $clarifyAfter];
if ($clarifyAfter <= $clarifyBefore) {
    $failures[] = 'clarification_reply: expected pending clarification to create a task. Before=' . $clarifyBefore . ', after=' . $clarifyAfter;
}

$templateBefore = countTemplates($db, $createdTaskPrefix . ' Шаблон%');
announce('scenario: template_missing_time');
$templateLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'створи шаблон ' . $createdTaskPrefix . ' Шаблон щоденного нагадування щодня о 09:30 очікуваний результат: відправлено нагадування');
$templateAfter = countTemplates($db, $createdTaskPrefix . ' Шаблон%');
$report[] = ['scenario' => 'template_missing_time', 'route' => $templateLog['route_name'] ?? null, 'execution_path' => $templateLog['execution_path'] ?? null, 'reply' => $templateLog['bot_reply'] ?? null, 'templates_after' => $templateAfter];
if ($templateAfter > $templateBefore) {
    $failures[] = 'template_missing_time: template was created without required expected_time clarification';
}
assertContains($failures, 'template_missing_time', (string) ($templateLog['bot_reply'] ?? ''), 'час', 'bot_reply');

$multiCreateBefore = countTasks($db, $createdTaskPrefix . ' Multi пакет%');
announce('scenario: multi_task_message');
$multiCreateLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'додай задачу ' . $createdTaskPrefix . ' Multi пакет перша задача на завтра очікуваний результат: готовий перший блок очікуваний час: 30 хв і додай задачу ' . $createdTaskPrefix . ' Multi пакет друга задача на завтра очікуваний результат: готовий другий блок очікуваний час: 40 хв');
$multiCreateAfter = countTasks($db, $createdTaskPrefix . ' Multi пакет%');
$report[] = ['scenario' => 'multi_task_message', 'route' => $multiCreateLog['route_name'] ?? null, 'execution_path' => $multiCreateLog['execution_path'] ?? null, 'reply' => $multiCreateLog['bot_reply'] ?? null, 'created_after' => $multiCreateAfter];
if (($multiCreateAfter - $multiCreateBefore) < 2) {
    $failures[] = 'multi_task_message: expected at least 2 tasks from one message, created delta=' . ($multiCreateAfter - $multiCreateBefore);
}

$multiClarifyBefore = countTasks($db, $createdTaskPrefix . ' Multi clarify%');
announce('scenario: multi_task_clarify');
$multiClarifyLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'додай задачу ' . $createdTaskPrefix . ' Multi clarify перша задача і додай задачу ' . $createdTaskPrefix . ' Multi clarify друга задача');
$report[] = ['scenario' => 'multi_task_clarify', 'route' => $multiClarifyLog['route_name'] ?? null, 'execution_path' => $multiClarifyLog['execution_path'] ?? null, 'reply' => $multiClarifyLog['bot_reply'] ?? null];
assertContains($failures, 'multi_task_clarify', (string) ($multiClarifyLog['bot_reply'] ?? ''), 'очікуван', 'bot_reply');

announce('scenario: multi_task_clarify_reply');
$multiClarifyReplyLog = simulateMessage($db, $chatId, $telegramUserId, $telegramUsername, 'на завтра очікуваний результат: обидва блоки готові очікуваний час: 25 хв');
$multiClarifyAfter = countTasks($db, $createdTaskPrefix . ' Multi clarify%');
$report[] = ['scenario' => 'multi_task_clarify_reply', 'route' => $multiClarifyReplyLog['route_name'] ?? null, 'execution_path' => $multiClarifyReplyLog['execution_path'] ?? null, 'reply' => $multiClarifyReplyLog['bot_reply'] ?? null, 'created_after' => $multiClarifyAfter];
if (($multiClarifyAfter - $multiClarifyBefore) < 2) {
    $failures[] = 'multi_task_clarify_reply: expected clarification to create 2 tasks, created delta=' . ($multiClarifyAfter - $multiClarifyBefore);
}

echo json_encode([
    'ok' => empty($failures),
    'failures' => $failures,
    'report' => $report,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!empty($failures)) {
    exit(1);
}