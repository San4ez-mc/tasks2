<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();
$companyId = 50;
$prefix = '[BOT TEST]';

function ensureBotTestUser(Database $db, string $username, string $firstName, string $lastName, string $email, int $telegramId): array
{
    $db
        ->query('UPDATE users SET telegram_id = NULL WHERE telegram_id = :telegram_id AND LOWER(username) <> :username')
        ->bind(':telegram_id', $telegramId)
        ->bind(':username', mb_strtolower($username))
        ->execute();

    $user = $db
        ->query('SELECT * FROM users WHERE LOWER(username) = :username LIMIT 1')
        ->bind(':username', mb_strtolower($username))
        ->fetch();

    if (!$user) {
        $db->insert('users', [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => null,
            'phone_number' => null,
            'photo_url' => null,
            'telegram_id' => $telegramId,
            'username' => $username,
        ]);

        $user = $db
            ->query('SELECT * FROM users WHERE id = :id LIMIT 1')
            ->bind(':id', (int) $db->lastInsertId())
            ->fetch();
    } else {
        $db
            ->query('UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, username = :username, telegram_id = :telegram_id WHERE id = :id')
            ->bind(':first_name', $firstName)
            ->bind(':last_name', $lastName)
            ->bind(':email', $email)
            ->bind(':username', $username)
            ->bind(':telegram_id', $telegramId)
            ->bind(':id', (int) ($user['id'] ?? 0))
            ->execute();

        $user = $db
            ->query('SELECT * FROM users WHERE id = :id LIMIT 1')
            ->bind(':id', (int) ($user['id'] ?? 0))
            ->fetch();
    }

    return is_array($user) ? $user : [];
}

function ensureCompanyMembership(Database $db, int $companyId, int $userId, string $role, ?int $reportsTo = null, ?string $title = null): int
{
    $membership = $db
        ->query('SELECT id FROM company_members WHERE company_id = :company_id AND user_id = :user_id LIMIT 1')
        ->bind(':company_id', $companyId)
        ->bind(':user_id', $userId)
        ->fetch();

    if ($membership) {
        $db
            ->query('UPDATE company_members SET role = :role, reports_to = :reports_to, title = :title WHERE id = :id')
            ->bind(':role', $role)
            ->bind(':reports_to', $reportsTo)
            ->bind(':title', $title)
            ->bind(':id', (int) ($membership['id'] ?? 0))
            ->execute();
        return (int) ($membership['id'] ?? 0);
    }

    $db->insert('company_members', [
        'company_id' => $companyId,
        'user_id' => $userId,
        'title' => $title,
        'role' => $role,
        'reports_to' => $reportsTo,
    ]);

    return (int) $db->lastInsertId();
}

$company = $db
    ->query('SELECT id, name FROM companies WHERE id = :id LIMIT 1')
    ->bind(':id', $companyId)
    ->fetch();

if (!$company) {
    fwrite(STDERR, "Company 50 not found.\n");
    exit(1);
}

$tetiana = $db
    ->query('SELECT u.* FROM users u JOIN company_members cm ON cm.user_id = u.id WHERE cm.company_id = :company_id AND LOWER(u.first_name) = :first_name AND LOWER(u.last_name) = :last_name LIMIT 1')
    ->bind(':company_id', $companyId)
    ->bind(':first_name', 'тетяна')
    ->bind(':last_name', 'коробова')
    ->fetch();

if (!$tetiana) {
    fwrite(STDERR, "Tetiana Korobova not found in company 50.\n");
    exit(1);
}

$tester = ensureBotTestUser($db, 'bot_tester_50', 'Bot', 'Tester', 'bot.tester.50@example.test', 90505050);
$subordinate = ensureBotTestUser($db, 'bot_subordinate_50', 'Bot', 'Subordinate', 'bot.subordinate.50@example.test', 90505051);

if (empty($tester['id']) || empty($subordinate['id'])) {
    fwrite(STDERR, "Unable to create isolated bot test users.\n");
    exit(1);
}

$testerId = (int) $tester['id'];
$subordinateId = (int) $subordinate['id'];
$tetianaId = (int) ($tetiana['id'] ?? 0);

$db->beginTransaction();

try {
    $testerMembershipId = ensureCompanyMembership($db, $companyId, $testerId, 'owner', null, 'Bot tester');
    ensureCompanyMembership($db, $companyId, $subordinateId, 'member', $testerMembershipId, 'Bot subordinate');

    $db->query('DELETE FROM tasks WHERE company_id = :company_id AND title LIKE :prefix')->bind(':company_id', $companyId)->bind(':prefix', $prefix . '%')->execute();
    $db->query('DELETE FROM templates WHERE company_id = :company_id AND name LIKE :prefix')->bind(':company_id', $companyId)->bind(':prefix', $prefix . '%')->execute();
    $db->query('DELETE FROM results WHERE company_id = :company_id AND title LIKE :prefix')->bind(':company_id', $companyId)->bind(':prefix', $prefix . '%')->execute();

    $today = new DateTimeImmutable('today');

    $db->insert('results', [
        'title' => $prefix . ' Моя ціль на тиждень',
        'company_id' => $companyId,
        'assignee_id' => $testerId,
        'reporter_id' => $testerId,
        'description' => 'Тестова ціль для Telegram-бота',
        'expected_result' => 'Є прогрес по тестовій цілі',
        'deadline' => $today->modify('+6 days')->format('Y-m-d'),
        'status' => 'in-progress',
        'completed' => 0,
    ]);
    $myGoalId = (int) $db->lastInsertId();

    $db->insert('results', [
        'title' => $prefix . ' Делегована ціль Тетяні',
        'company_id' => $companyId,
        'assignee_id' => $tetianaId,
        'reporter_id' => $testerId,
        'description' => 'Тестова делегована ціль',
        'expected_result' => 'Тетяна закрила тестову ціль',
        'deadline' => $today->modify('+5 days')->format('Y-m-d'),
        'status' => 'in-progress',
        'completed' => 0,
    ]);

    $db->insert('results', [
        'title' => $prefix . ' Ціль підлеглого',
        'company_id' => $companyId,
        'assignee_id' => $subordinateId,
        'reporter_id' => $testerId,
        'description' => 'Тестова ціль підлеглого',
        'expected_result' => 'Підлеглий закрив тестову ціль',
        'deadline' => $today->modify('+4 days')->format('Y-m-d'),
        'status' => 'in-progress',
        'completed' => 0,
    ]);

    $tasks = [
        [$prefix . ' Моя активна задача', $testerId, $testerId, $today->setTime(10, 0)->format('Y-m-d H:i:s'), 'todo', 'important-not-urgent', 30, $myGoalId],
        [$prefix . ' Моя завершена задача', $testerId, $testerId, $today->setTime(11, 0)->format('Y-m-d H:i:s'), 'done', 'important-urgent', 20, $myGoalId],
        [$prefix . ' Делегована активна задача', $tetianaId, $testerId, $today->setTime(12, 0)->format('Y-m-d H:i:s'), 'todo', 'important-urgent', 45, null],
        [$prefix . ' Делегована відкладена задача', $tetianaId, $testerId, $today->setTime(13, 0)->format('Y-m-d H:i:s'), 'postponed', 'not-important-urgent', 25, null],
        [$prefix . ' Завтрашня делегована задача', $tetianaId, $testerId, $today->modify('+1 day')->setTime(9, 0)->format('Y-m-d H:i:s'), 'todo', 'important-not-urgent', 35, null],
        [$prefix . ' Задача підлеглого', $subordinateId, $testerId, $today->setTime(15, 0)->format('Y-m-d H:i:s'), 'todo', 'important-not-urgent', 40, null],
    ];

    foreach ($tasks as [$title, $assigneeId, $taskReporterId, $dueDate, $status, $type, $expectedTime, $resultId]) {
        $db->insert('tasks', [
            'title' => $title,
            'company_id' => $companyId,
            'assignee_id' => $assigneeId,
            'reporter_id' => $taskReporterId,
            'status' => $status,
            'due_date' => $dueDate,
            'description' => 'Тестова задача для бота',
            'expected_result' => 'Тестовий очікуваний результат',
            'actual_result' => $status === 'done' ? 'Виконано' : null,
            'type' => $type,
            'expected_time' => $expectedTime,
            'actual_time' => $status === 'done' ? $expectedTime : 0,
            'result_id' => $resultId,
            'template_id' => null,
        ]);
    }

    $db->insert('templates', [
        'company_id' => $companyId,
        'name' => $prefix . ' Щоденний follow-up',
        'type' => 'important-not-urgent',
        'description' => 'Тестовий шаблон для follow-up',
        'expected_result' => 'Відправлено follow-up',
        'assignee_id' => $testerId,
        'reporter_id' => $testerId,
        'expected_time' => 30,
        'repeat_type' => 'daily',
        'repeat_day' => null,
        'start_time' => '09:30',
    ]);

    $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'permanent' AND (token = 'TG_ACTIVE_COMPANY' OR token = :scoped_token)")->bind(':user_id', $testerId)->bind(':scoped_token', 'TG_ACTIVE_COMPANY:' . $testerId)->execute();
    $db->insert('auth_tokens', [
        'token' => 'TG_ACTIVE_COMPANY:' . $testerId,
        'user_id' => $testerId,
        'company_id' => $companyId,
        'type' => 'permanent',
        'expires_at' => '2099-12-31 23:59:59',
    ]);

    $db->commit();

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'company_name' => (string) ($company['name'] ?? ''),
        'tester_id' => $testerId,
        'tester_username' => 'bot_tester_50',
        'tester_telegram_id' => 90505050,
        'subordinate_id' => $subordinateId,
        'tetiana_id' => $tetianaId,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
