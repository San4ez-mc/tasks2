<?php
/**
 * Перегляд завдання
 */
$taskData = $taskItem ?? [];
$title = 'Завдання: ' . htmlspecialchars((string) ($taskData['title'] ?? ''));
?>
<?php
ob_start();
?>
<style>
    .simple-view-page {
        max-width: 920px;
        display: grid;
        gap: 18px;
    }

    .simple-view-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .simple-view-card h2 {
        font-size: 30px;
        margin-bottom: 16px;
        color: #102034;
    }

    .simple-view-card p {
        color: #334155;
        line-height: 1.55;
        margin-bottom: 10px;
        word-break: break-word;
    }

    .simple-note {
        background: #f8fafc;
        padding: 12px;
        border-radius: 12px;
    }

    .simple-actions {
        margin-top: 20px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .simple-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 160px;
        padding: 12px 16px;
        border-radius: 12px;
        font-weight: 800;
        text-decoration: none;
        color: #fff;
    }

    .simple-link.edit {
        background: #f39c12;
    }

    .simple-link.delete {
        background: #dc2626;
    }

    .simple-link.back {
        background: #95a5a6;
    }

    @media (max-width: 560px) {
        .simple-view-card {
            padding: 18px;
        }

        .simple-view-card h2 {
            font-size: 26px;
        }

        .simple-link {
            width: 100%;
            min-width: 0;
        }
    }
</style>
<section class="simple-view-page">
    <div class="simple-view-card">
        <h2><?php echo htmlspecialchars((string) ($taskData['title'] ?? '')); ?></h2>

        <p><strong>Статус:</strong> <?php echo htmlspecialchars((string) ($taskData['status'] ?? '')); ?></p>
        <p><strong>Тип:</strong> <?php echo htmlspecialchars((string) ($taskData['type'] ?? '')); ?></p>
        <p><strong>Виконавець:</strong>
            <?php echo htmlspecialchars(trim((string) ($taskData['assignee_first_name'] ?? '') . ' ' . (string) ($taskData['assignee_last_name'] ?? ''))); ?>
        </p>
        <p><strong>Звітувач:</strong>
            <?php echo htmlspecialchars(trim((string) ($taskData['reporter_first_name'] ?? '') . ' ' . (string) ($taskData['reporter_last_name'] ?? ''))); ?>
        </p>
        <p><strong>Дата створення:</strong>
            <?php echo !empty($taskData['created_at']) ? date('d.m.Y H:i', strtotime((string) $taskData['created_at'])) : '—'; ?>
        </p>
        <p><strong>Дата закінчення:</strong>
            <?php echo !empty($taskData['due_date']) ? date('d.m.Y', strtotime((string) $taskData['due_date'])) : '—'; ?>
        </p>
        <p><strong>Очікуваний час:</strong>
            <?php echo !empty($taskData['expected_time']) ? ((int) $taskData['expected_time']) . ' хв' : '—'; ?>
        </p>
        <p><strong>Фактичний час:</strong>
            <?php echo !empty($taskData['actual_time']) ? ((int) $taskData['actual_time']) . ' хв' : '—'; ?></p>

        <?php if (!empty($taskData['expected_result'])): ?>
            <p><strong>Очікуваний результат (опис керівника):</strong></p>
            <p class="simple-note">
                <?php echo htmlspecialchars((string) $taskData['expected_result']); ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($taskData['actual_result'])): ?>
            <p><strong>Фактичний результат (виконання працівника):</strong></p>
            <p class="simple-note">
                <?php echo htmlspecialchars((string) $taskData['actual_result']); ?>
            </p>
        <?php endif; ?>

        <div class="simple-actions">
            <a href="/tasks/edit/<?php echo (int) ($taskData['id'] ?? 0); ?>" class="simple-link edit">Редагувати</a>
            <a href="/tasks/delete/<?php echo (int) ($taskData['id'] ?? 0); ?>" class="simple-link delete"
                onclick="return confirm('Ви впевнені?');">Видалити</a>
            <a href="/tasks" class="simple-link back">Назад</a>
        </div>
    </div>
</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
