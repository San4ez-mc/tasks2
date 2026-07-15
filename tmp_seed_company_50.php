<?php

require __DIR__ . '/config.php';

use App\Models\Database;
use App\Models\User;

$companyId = 50;
$prefix = '[AUTO C50]';

$db = new Database();
$userModel = new User();

$company = $db
    ->query('SELECT id, name FROM companies WHERE id = :id LIMIT 1')
    ->bind(':id', $companyId)
    ->fetch();

if (!$company) {
    fwrite(STDERR, "Company #{$companyId} not found.\n");
    exit(1);
}

$existingMembers = $db
    ->query('SELECT cm.user_id, cm.role FROM company_members cm WHERE cm.company_id = :company_id ORDER BY cm.id ASC')
    ->bind(':company_id', $companyId)
    ->fetchAll();

$reporterId = 0;
foreach ($existingMembers as $member) {
    if (in_array(strtolower(trim((string) ($member['role'] ?? ''))), ['owner', 'admin'], true)) {
        $reporterId = (int) ($member['user_id'] ?? 0);
        break;
    }
}
if ($reporterId <= 0 && !empty($existingMembers)) {
    $reporterId = (int) ($existingMembers[0]['user_id'] ?? 0);
}
if ($reporterId <= 0) {
    fwrite(STDERR, "Company #{$companyId} has no members to use as reporter.\n");
    exit(1);
}

$testUsers = [
    ['first_name' => 'Тест', 'last_name' => 'Анна', 'email' => 'auto.c50.anna@example.com', 'title' => 'Менеджер проєктів'],
    ['first_name' => 'Тест', 'last_name' => 'Богдан', 'email' => 'auto.c50.bogdan@example.com', 'title' => 'Маркетолог'],
    ['first_name' => 'Тест', 'last_name' => 'Вікторія', 'email' => 'auto.c50.vika@example.com', 'title' => 'Операційний менеджер'],
    ['first_name' => 'Тест', 'last_name' => 'Ганна', 'email' => 'auto.c50.hanna@example.com', 'title' => 'Координатор'],
    ['first_name' => 'Тест', 'last_name' => 'Денис', 'email' => 'auto.c50.denis@example.com', 'title' => 'Sales manager'],
    ['first_name' => 'Тест', 'last_name' => 'Єва', 'email' => 'auto.c50.eva@example.com', 'title' => 'Асистент керівника'],
];

$today = new DateTimeImmutable('today');
$weekDates = [];
for ($offset = 0; $offset < 7; $offset++) {
    $weekDates[] = $today->modify('+' . $offset . ' day');
}

$createdUsers = [];

$db->beginTransaction();

try {
    foreach ($testUsers as $userSpec) {
        $user = $userModel->get_by_email($userSpec['email']);
        if (!$user) {
            $userModel->create([
                'first_name' => $userSpec['first_name'],
                'last_name' => $userSpec['last_name'],
                'email' => $userSpec['email'],
                'password' => 'Test1234!',
                'username' => null,
                'telegram_id' => null,
            ]);

            $user = $userModel->get_by_email($userSpec['email']);
        }

        if (!$user) {
            throw new RuntimeException('Failed to create or fetch user ' . $userSpec['email']);
        }

        $membership = $db
            ->query('SELECT id FROM company_members WHERE company_id = :company_id AND user_id = :user_id LIMIT 1')
            ->bind(':company_id', $companyId)
            ->bind(':user_id', (int) $user['id'])
            ->fetch();

        if (!$membership) {
            $db->insert('company_members', [
                'user_id' => (int) $user['id'],
                'company_id' => $companyId,
                'department_id' => null,
                'title' => $userSpec['title'],
                'role' => 'member',
                'reports_to' => null,
            ]);
        }

        $createdUsers[] = [
            'id' => (int) $user['id'],
            'name' => trim($userSpec['first_name'] . ' ' . $userSpec['last_name']),
            'email' => $userSpec['email'],
        ];
    }

    $userIds = array_map(static fn($item) => (int) $item['id'], $createdUsers);
    if (!empty($userIds)) {
        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        $existingResults = $db
            ->query("SELECT id FROM results WHERE company_id = ? AND assignee_id IN ({$placeholders}) AND title LIKE ?")
            ->bind(1, $companyId);
        $paramIndex = 2;
        foreach ($userIds as $userId) {
            $existingResults->bind($paramIndex, $userId);
            $paramIndex++;
        }
        $existingResults->bind($paramIndex, $prefix . '%');
        $resultRows = $existingResults->fetchAll();
        foreach ($resultRows as $row) {
            $db->delete('results', (int) $row['id']);
        }

        $deleteTasks = $db->query("DELETE FROM tasks WHERE company_id = ? AND assignee_id IN ({$placeholders}) AND title LIKE ?");
        $deleteTasks->bind(1, $companyId);
        $paramIndex = 2;
        foreach ($userIds as $userId) {
            $deleteTasks->bind($paramIndex, $userId);
            $paramIndex++;
        }
        $deleteTasks->bind($paramIndex, $prefix . '%');
        $deleteTasks->execute();
    }

    $totalGoals = 0;
    $totalTasks = 0;

    foreach ($createdUsers as $index => $seedUser) {
        $goalIds = [];
        $personSlug = 'U' . ($index + 1);
        $goals = [
            [
                'title' => $prefix . " {$personSlug} Розібрати пріоритети на тиждень",
                'description' => 'Сформувати список фокусних задач і розкласти їх по днях.',
                'expected_result' => 'Є чіткий робочий план на 7 днів.',
                'deadline' => $weekDates[1]->format('Y-m-d'),
            ],
            [
                'title' => $prefix . " {$personSlug} Довести 2 ключові задачі до результату",
                'description' => 'Завершити найважливіші задачі поточного тижня і зафіксувати результат.',
                'expected_result' => 'Мінімум дві важливі задачі закриті та описаний результат.',
                'deadline' => $weekDates[4]->format('Y-m-d'),
            ],
            [
                'title' => $prefix . " {$personSlug} Підготувати підсумок тижня",
                'description' => 'Зібрати виконане, проблеми і наступні кроки.',
                'expected_result' => 'Короткий тижневий звіт без прогалин.',
                'deadline' => $weekDates[6]->format('Y-m-d'),
            ],
        ];

        foreach ($goals as $goal) {
            $db->insert('results', [
                'title' => $goal['title'],
                'company_id' => $companyId,
                'assignee_id' => $seedUser['id'],
                'reporter_id' => $reporterId,
                'description' => $goal['description'],
                'expected_result' => $goal['expected_result'],
                'deadline' => $goal['deadline'],
                'status' => 'in-progress',
                'completed' => 0,
            ]);
            $goalIds[] = (int) $db->lastInsertId();
            $totalGoals++;
        }

        $taskTemplates = [
            ['day' => 0, 'time' => [9, 30], 'title' => 'Підготувати список пріоритетів', 'desc' => 'Зібрати всі відкриті питання і скласти короткий пріоритетний список.', 'result' => 'Список пріоритетів на сьогодні і тиждень.', 'minutes' => 35, 'type' => 'important-urgent', 'goal' => 0],
            ['day' => 0, 'time' => [15, 0], 'title' => 'Оновити статуси активних задач', 'desc' => 'Перевірити прогрес по всіх активних задачах і оновити коментарі.', 'result' => 'Статуси задач актуальні.', 'minutes' => 40, 'type' => 'important-not-urgent', 'goal' => 0],
            ['day' => 1, 'time' => [10, 0], 'title' => 'Зробити перший важливий блок роботи', 'desc' => 'Закрити одну помітну частину ключової задачі.', 'result' => 'Є відчутний прогрес по ключовій задачі.', 'minutes' => 90, 'type' => 'important-urgent', 'goal' => 1],
            ['day' => 2, 'time' => [11, 30], 'title' => 'Підготувати матеріали для узгодження', 'desc' => 'Зібрати проміжні матеріали або нотатки для керівника.', 'result' => 'Матеріали підготовлені і готові до показу.', 'minutes' => 45, 'type' => 'important-not-urgent', 'goal' => 1],
            ['day' => 3, 'time' => [14, 0], 'title' => 'Завершити другий важливий блок роботи', 'desc' => 'Довести ще одну ключову частину задачі до готового стану.', 'result' => 'Другий помітний блок завершений.', 'minutes' => 75, 'type' => 'important-urgent', 'goal' => 1],
            ['day' => 4, 'time' => [16, 0], 'title' => 'Почистити хвости і дрібні задачі', 'desc' => 'Закрити невеликі завислі питання по поточному напряму.', 'result' => 'Дрібні блокери зняті.', 'minutes' => 30, 'type' => 'not-important-urgent', 'goal' => 1],
            ['day' => 5, 'time' => [10, 30], 'title' => 'Зібрати факти для звіту', 'desc' => 'Підготувати цифри, завершені задачі і короткі висновки.', 'result' => 'Є дані для фінального звіту.', 'minutes' => 40, 'type' => 'important-not-urgent', 'goal' => 2],
            ['day' => 6, 'time' => [15, 30], 'title' => 'Написати підсумок тижня', 'desc' => 'Сформувати короткий звіт: що зроблено, що не встигли, що далі.', 'result' => 'Звіт готовий і зрозумілий.', 'minutes' => 50, 'type' => 'important-urgent', 'goal' => 2],
        ];

        foreach ($taskTemplates as $taskTemplate) {
            $date = $weekDates[$taskTemplate['day']]->setTime($taskTemplate['time'][0], $taskTemplate['time'][1]);
            $db->insert('tasks', [
                'title' => $prefix . " {$personSlug} " . $taskTemplate['title'],
                'company_id' => $companyId,
                'assignee_id' => $seedUser['id'],
                'reporter_id' => $reporterId,
                'status' => 'todo',
                'due_date' => $date->format('Y-m-d H:i:s'),
                'description' => $taskTemplate['desc'],
                'expected_result' => $taskTemplate['result'],
                'actual_result' => null,
                'type' => $taskTemplate['type'],
                'expected_time' => $taskTemplate['minutes'],
                'actual_time' => 0,
                'result_id' => $goalIds[$taskTemplate['goal']] ?? null,
                'template_id' => null,
            ]);
            $totalTasks++;
        }
    }

    $db->commit();

    echo "Company #{$companyId}: created/ensured " . count($createdUsers) . " test users, {$totalGoals} goals, {$totalTasks} tasks.\n";
    foreach ($createdUsers as $seedUser) {
        echo '- ' . $seedUser['name'] . ' <' . $seedUser['email'] . '> user_id=' . $seedUser['id'] . "\n";
    }
    echo "Password for test users: Test1234!\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}