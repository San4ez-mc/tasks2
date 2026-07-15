<?php

require __DIR__ . '/config.php';

use App\Models\Database;

$db = new Database();
$companyId = 50;
$prefix = '[AUTO KOROBOVA]';

$company = $db
    ->query('SELECT id, name FROM companies WHERE id = :id LIMIT 1')
    ->bind(':id', $companyId)
    ->fetch();

if (!$company) {
    fwrite(STDERR, "Company 50 not found.\n");
    exit(1);
}

$members = $db
    ->query(
        'SELECT u.id, u.first_name, u.last_name, cm.role
         FROM company_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.company_id = :company_id
         ORDER BY u.first_name, u.last_name'
    )
    ->bind(':company_id', $companyId)
    ->fetchAll();

$tetiana = null;
$reporter = null;

foreach ($members as $member) {
    $firstName = mb_strtolower(trim((string) ($member['first_name'] ?? '')));
    $lastName = mb_strtolower(trim((string) ($member['last_name'] ?? '')));
    $fullName = trim($firstName . ' ' . $lastName);

    if ($tetiana === null && ($fullName === 'тетяна коробова' || ($firstName === 'тетяна' && $lastName === 'коробова'))) {
        $tetiana = $member;
    }

    $role = strtolower(trim((string) ($member['role'] ?? '')));
    if ($reporter === null && in_array($role, ['owner', 'admin'], true)) {
        $reporter = $member;
    }
}

if (!$tetiana) {
    fwrite(STDERR, "Tetiana Korobova not found in company 50. Available members:\n");
    foreach ($members as $member) {
        fwrite(STDERR, '- ' . trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) . "\n");
    }
    exit(1);
}

if (!$reporter) {
    $reporter = $tetiana;
}

$tasks = [
    ['day' => 0, 'time' => '09:30:00', 'title' => 'Підготувати список пріоритетів на тиждень', 'description' => 'Зібрати поточні задачі, пріоритети і вузькі місця на найближчі 7 днів.', 'expected_result' => 'Є структурований список пріоритетів на тиждень.', 'expected_time' => 45, 'type' => 'important-not-urgent'],
    ['day' => 0, 'time' => '14:00:00', 'title' => 'Оновити статуси по активних задачах', 'description' => 'Перевірити поточний стан задач і привести статуси до актуального вигляду.', 'expected_result' => 'Статуси в системі відповідають реальному стану робіт.', 'expected_time' => 40, 'type' => 'important-urgent'],
    ['day' => 1, 'time' => '10:00:00', 'title' => 'Підготувати матеріали до командної зустрічі', 'description' => 'Зібрати короткий апдейт, блокери та питання для обговорення.', 'expected_result' => 'Готовий набір тез для зустрічі команди.', 'expected_time' => 60, 'type' => 'important-not-urgent'],
    ['day' => 1, 'time' => '16:00:00', 'title' => 'Почистити список вхідних звернень', 'description' => 'Розібрати нові звернення, призначити next step і відповідальних де потрібно.', 'expected_result' => 'Нові звернення розібрані і не висять без руху.', 'expected_time' => 50, 'type' => 'important-urgent'],
    ['day' => 2, 'time' => '11:00:00', 'title' => 'Підготувати чернетку тижневого звіту', 'description' => 'Зібрати проміжні результати та коротко описати виконану роботу.', 'expected_result' => 'Є чернетка звіту з основними результатами.', 'expected_time' => 55, 'type' => 'important-not-urgent'],
    ['day' => 2, 'time' => '15:30:00', 'title' => 'Перевірити дедлайни по відкритих задачах', 'description' => 'Переглянути найближчі дедлайни, перенести ризикові задачі в пріоритет.', 'expected_result' => 'Зрозуміло, які задачі потребують уваги цього тижня.', 'expected_time' => 35, 'type' => 'important-urgent'],
    ['day' => 3, 'time' => '09:45:00', 'title' => 'Підготувати список блокерів і рішень', 'description' => 'Зібрати перелік перешкод у роботі та запропонувати способи розблокування.', 'expected_result' => 'Сформований список блокерів і можливих рішень.', 'expected_time' => 45, 'type' => 'important-not-urgent'],
    ['day' => 4, 'time' => '12:00:00', 'title' => 'Уточнити план задач на наступний тиждень', 'description' => 'Перевірити що переноситься далі, а що треба закрити до кінця поточного тижня.', 'expected_result' => 'Чернетка плану на наступний тиждень готова.', 'expected_time' => 50, 'type' => 'important-not-urgent'],
    ['day' => 5, 'time' => '10:30:00', 'title' => 'Зібрати цифри для підсумку тижня', 'description' => 'Порахувати завершені задачі, відкриті питання і ключові результати.', 'expected_result' => 'Підготовлені цифри та факти для фінального підсумку.', 'expected_time' => 40, 'type' => 'important-not-urgent'],
    ['day' => 6, 'time' => '15:00:00', 'title' => 'Оформити фінальний підсумок тижня', 'description' => 'Звести результати, ризики і наступні кроки в короткий підсумковий текст.', 'expected_result' => 'Готовий підсумок тижня для керівника або команди.', 'expected_time' => 60, 'type' => 'important-urgent'],
];

$db->beginTransaction();

try {
    $db
        ->query('DELETE FROM tasks WHERE company_id = :company_id AND assignee_id = :assignee_id AND title LIKE :prefix')
        ->bind(':company_id', $companyId)
        ->bind(':assignee_id', (int) $tetiana['id'])
        ->bind(':prefix', $prefix . '%')
        ->execute();

    $today = new DateTimeImmutable('today');

    foreach ($tasks as $task) {
        $dueAt = $today->modify('+' . (int) $task['day'] . ' day')->format('Y-m-d') . ' ' . $task['time'];

        $db->insert('tasks', [
            'title' => $prefix . ' ' . $task['title'],
            'company_id' => $companyId,
            'assignee_id' => (int) $tetiana['id'],
            'reporter_id' => (int) $reporter['id'],
            'status' => 'todo',
            'due_date' => $dueAt,
            'description' => $task['description'],
            'expected_result' => $task['expected_result'],
            'actual_result' => null,
            'type' => $task['type'],
            'expected_time' => (int) $task['expected_time'],
            'actual_time' => 0,
            'result_id' => null,
            'template_id' => null,
        ]);
    }

    $db->commit();

    echo 'Generated ' . count($tasks) . " tasks for Tetiana Korobova in company 50.\n";
    echo 'Tetiana ID: ' . (int) $tetiana['id'] . ', Reporter ID: ' . (int) $reporter['id'] . ", Company: " . (string) ($company['name'] ?? '50') . "\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}