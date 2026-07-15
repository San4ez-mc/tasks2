<?php

require __DIR__ . '/config.php';

putenv('TELEGRAM_SKIP_NETWORK=1');
putenv('TELEGRAM_SKIP_AI=1');

use App\Models\Company;
use App\Models\Database;
use App\Services\TelegramIntentCommandService;

$db = new Database();
$tester = $db
    ->query('SELECT * FROM users WHERE LOWER(username) = :username LIMIT 1')
    ->bind(':username', 'bot_tester_50')
    ->fetch();

if (!is_array($tester) || empty($tester['id'])) {
    throw new RuntimeException('Tester not found.');
}

$companyId = 50;
$reporter = $tester;
$service = new TelegramIntentCommandService();
$commands = [
    ['label' => 'my', 'command' => [['name' => 'manage_tasks', 'args' => ['action' => 'list', 'scope' => 'my', 'status' => 'active', 'date' => 'today']]]],
    ['label' => 'delegated', 'command' => [['name' => 'manage_tasks', 'args' => ['action' => 'list', 'scope' => 'delegated', 'status' => 'active', 'date' => 'today']]]],
    ['label' => 'subordinates', 'command' => [['name' => 'manage_tasks', 'args' => ['action' => 'list', 'scope' => 'subordinates', 'status' => 'active', 'date' => 'today']]]],
    ['label' => 'goals_my', 'command' => [['name' => 'list_goals', 'args' => ['status' => 'all']]]],
];

foreach ($commands as $entry) {
    $startedAt = microtime(true);
    $result = $service->executeCommands($companyId, $reporter, $entry['command']);
    $elapsed = round((microtime(true) - $startedAt) * 1000);

    echo json_encode([
        'label' => $entry['label'],
        'elapsed_ms' => $elapsed,
        'reply_preview' => mb_substr((string) ($result['reply'] ?? ''), 0, 500),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
