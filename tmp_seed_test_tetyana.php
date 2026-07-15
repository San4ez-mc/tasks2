<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();

$company = $db
    ->query("SELECT id, name FROM companies WHERE LOWER(TRIM(name)) = 'тест' LIMIT 1")
    ->fetch();

if (!$company) {
    fwrite(STDERR, "Company 'тест' not found.\n");
    exit(1);
}

$members = $db
    ->query(
        'SELECT u.id, u.first_name, u.last_name, cm.role
         FROM company_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.company_id = :company_id'
    )
    ->bind(':company_id', (int) $company['id'])
    ->fetchAll();

$tetiana = null;
$reporter = null;

foreach ($members as $member) {
    $fullName = mb_strtolower(trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))));
    $firstName = mb_strtolower(trim((string) ($member['first_name'] ?? '')));

    if ($tetiana === null && ($firstName === 'тетяна' || str_contains($fullName, 'тетяна'))) {
        $tetiana = $member;
    }

    $role = strtolower(trim((string) ($member['role'] ?? '')));
    if ($reporter === null && in_array($role, ['owner', 'admin'], true)) {
        $reporter = $member;
    }
}

if (!$tetiana) {
    fwrite(STDERR, "Tetiana not found in company 'тест'.\n");
    exit(1);
}

if (!$reporter) {
    $reporter = $tetiana;
}

$prefix = '[AUTO TEST TETYANA]';

$db->beginTransaction();

try {
    $existingResults = $db
        ->query('SELECT id FROM results WHERE company_id = :company_id AND assignee_id = :assignee_id AND title LIKE :prefix')
        ->bind(':company_id', (int) $company['id'])
        ->bind(':assignee_id', (int) $tetiana['id'])
        ->bind(':prefix', $prefix . '%')
        ->fetchAll();

    foreach ($existingResults as $result) {
        $db->delete('results', (int) $result['id']);
    }

    $db
        ->query('DELETE FROM tasks WHERE company_id = :company_id AND assignee_id = :assignee_id AND title LIKE :prefix')
        ->bind(':company_id', (int) $company['id'])
        ->bind(':assignee_id', (int) $tetiana['id'])
        ->bind(':prefix', $prefix . '%')
        ->execute();

    $today = new DateTimeImmutable('today');
    $dates = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $dates[] = $today->modify('+' . $offset . ' day');
    }

    $goals = [
        [
            'title' => $prefix . ' Підготувати контент-план на тиждень',
            'description' => 'Сформувати теми, формати та дедлайни для публікацій на найближчі 7 днів.',
            'expected_result' => 'Готовий контент-план з темами, каналами і статусами підготовки.',
            'deadline' => $dates[6]->format('Y-m-d'),
        ],
        [
            'title' => $prefix . ' Оновити базу клієнтських комунікацій',
            'description' => 'Привести до ладу статуси діалогів, домовленостей і наступних кроків по клієнтах.',
            'expected_result' => 'Актуальна таблиця контактів та зрозумілий перелік наступних дій.',
            'deadline' => $dates[5]->format('Y-m-d'),
        ],
        [
            'title' => $prefix . ' Підготувати звіт по активностях відділу',
            'description' => 'Зібрати результати роботи за тиждень і підготувати короткий підсумковий звіт.',
            'expected_result' => 'Звіт з основними цифрами, висновками і ризиками.',
            'deadline' => $dates[6]->format('Y-m-d'),
        ],
    ];

    $goalIds = [];
    foreach ($goals as $goal) {
        $db->insert('results', [
            'title' => $goal['title'],
            'company_id' => (int) $company['id'],
            'assignee_id' => (int) $tetiana['id'],
            'reporter_id' => (int) $reporter['id'],
            'description' => $goal['description'],
            'expected_result' => $goal['expected_result'],
            'deadline' => $goal['deadline'],
            'status' => 'in-progress',
            'completed' => 0,
        ]);
        $goalIds[] = (int) $db->lastInsertId();
    }

    $tasks = [
        [
            'date' => $dates[0]->setTime(9, 30),
            'title' => $prefix . ' Зібрати ідеї для контент-плану',
            'description' => 'Переглянути минулі пости, новини компанії та виписати 10-12 ідей.',
            'expected_result' => 'Список тем для контент-плану на тиждень.',
            'expected_time' => 60,
            'type' => 'important-not-urgent',
            'result_id' => 0,
        ],
        [
            'date' => $dates[0]->setTime(14, 0),
            'title' => $prefix . ' Оновити статуси по відкритих клієнтах',
            'description' => 'Звірити останні переписки та оновити статуси в таблиці.',
            'expected_result' => 'У кожного активного клієнта є актуальний статус і next step.',
            'expected_time' => 45,
            'type' => 'important-urgent',
            'result_id' => 1,
        ],
        [
            'date' => $dates[1]->setTime(10, 0),
            'title' => $prefix . ' Скласти чернетку контент-плану',
            'description' => 'Розподілити теми по днях та каналах публікації.',
            'expected_result' => 'Чернетка контент-плану на 7 днів.',
            'expected_time' => 90,
            'type' => 'important-not-urgent',
            'result_id' => 0,
        ],
        [
            'date' => $dates[1]->setTime(16, 0),
            'title' => $prefix . ' Підготувати 5 follow-up повідомлень',
            'description' => 'Сформувати та відправити follow-up клієнтам, з якими немає відповіді понад 3 дні.',
            'expected_result' => 'Відправлено щонайменше 5 follow-up повідомлень.',
            'expected_time' => 40,
            'type' => 'important-urgent',
            'result_id' => 1,
        ],
        [
            'date' => $dates[2]->setTime(11, 0),
            'title' => $prefix . ' Узгодити контент-план з керівником',
            'description' => 'Показати чернетку, зафіксувати правки та пріоритети.',
            'expected_result' => 'Узгоджений план з коментарями і правками.',
            'expected_time' => 30,
            'type' => 'important-urgent',
            'result_id' => 0,
        ],
        [
            'date' => $dates[2]->setTime(15, 30),
            'title' => $prefix . ' Почистити дублікати в клієнтській базі',
            'description' => 'Знайти і прибрати дублікати контактів та компаній.',
            'expected_result' => 'База без дублікатів по активним клієнтам.',
            'expected_time' => 50,
            'type' => 'not-important-urgent',
            'result_id' => 1,
        ],
        [
            'date' => $dates[3]->setTime(9, 45),
            'title' => $prefix . ' Підготувати короткий звіт по задачах середини тижня',
            'description' => 'Зібрати проміжний статус по виконанню цілей та задач.',
            'expected_result' => 'Короткий апдейт по прогресу за 3-4 дні.',
            'expected_time' => 35,
            'type' => 'important-not-urgent',
            'result_id' => 2,
        ],
        [
            'date' => $dates[4]->setTime(12, 0),
            'title' => $prefix . ' Підготувати фінальну версію контент-плану',
            'description' => 'Внести фінальні правки та оформити план в зручному вигляді.',
            'expected_result' => 'Фінальний контент-план готовий до роботи.',
            'expected_time' => 60,
            'type' => 'important-not-urgent',
            'result_id' => 0,
        ],
        [
            'date' => $dates[5]->setTime(10, 30),
            'title' => $prefix . ' Зібрати метрики по комунікаціях',
            'description' => 'Порахувати кількість контактів, відповідей і домовленостей за тиждень.',
            'expected_result' => 'Є цифри для підсумкового звіту.',
            'expected_time' => 45,
            'type' => 'important-not-urgent',
            'result_id' => 2,
        ],
        [
            'date' => $dates[6]->setTime(15, 0),
            'title' => $prefix . ' Зробити підсумковий звіт за тиждень',
            'description' => 'Описати що зроблено, що не завершено і які наступні кроки.',
            'expected_result' => 'Готовий підсумковий звіт по тижню.',
            'expected_time' => 75,
            'type' => 'important-urgent',
            'result_id' => 2,
        ],
    ];

    foreach ($tasks as $task) {
        $goalIndex = (int) $task['result_id'];
        $linkedGoalId = $goalIds[$goalIndex] ?? null;

        $db->insert('tasks', [
            'title' => $task['title'],
            'company_id' => (int) $company['id'],
            'assignee_id' => (int) $tetiana['id'],
            'reporter_id' => (int) $reporter['id'],
            'status' => 'todo',
            'due_date' => $task['date']->format('Y-m-d H:i:s'),
            'description' => $task['description'],
            'expected_result' => $task['expected_result'],
            'actual_result' => null,
            'type' => $task['type'],
            'expected_time' => (int) $task['expected_time'],
            'actual_time' => 0,
            'result_id' => $linkedGoalId,
            'template_id' => null,
        ]);
    }

    $db->commit();

    echo 'Generated ' . count($goalIds) . " goals and " . count($tasks) . " tasks for Tetiana in company 'тест'.\n";
    echo 'Company ID: ' . (int) $company['id'] . ", Tetiana ID: " . (int) $tetiana['id'] . ", Reporter ID: " . (int) $reporter['id'] . "\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}