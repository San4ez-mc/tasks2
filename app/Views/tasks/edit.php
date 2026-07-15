<?php
/**
 * Редагування завдання
 */
$taskData = $taskItem ?? [];
$title = 'Редагувати завдання';
?>
<?php
ob_start();
?>
<style>
    .simple-form-page {
        max-width: 760px;
        display: grid;
        gap: 18px;
    }

    .simple-form-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .simple-form-card h2 {
        font-size: 30px;
        margin-bottom: 16px;
        color: #102034;
    }

    .form-group {
        display: grid;
        gap: 8px;
        margin-bottom: 16px;
    }

    .form-group label {
        font-weight: 700;
        color: #18324d;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        border-radius: 12px;
        border: 1px solid #cfd9e5;
        padding: 12px 14px;
        font: inherit;
        background: #fbfdff;
    }

    .form-group textarea {
        min-height: 110px;
        resize: vertical;
    }

    .simple-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .simple-btn,
    .simple-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 180px;
        padding: 12px 16px;
        border-radius: 12px;
        font-weight: 800;
        text-decoration: none;
    }

    .simple-btn {
        border: 0;
        background: #102034;
        color: #fff;
        cursor: pointer;
    }

    .simple-link {
        background: #e5ebf3;
        color: #334155;
    }

    @media (max-width: 560px) {
        .simple-form-card {
            padding: 18px;
        }

        .simple-form-card h2 {
            font-size: 26px;
        }

        .simple-btn,
        .simple-link {
            width: 100%;
            min-width: 0;
        }
    }
</style>
<section class="simple-form-page">
    <div class="simple-form-card">
        <h2><?php echo $title; ?></h2>

        <form method="POST" action="/tasks/edit/<?php echo (int) ($taskData['id'] ?? 0); ?>">
            <div class="form-group">
                <label for="title">Назва завдання:</label>
                <input type="text" id="title" name="title"
                    value="<?php echo htmlspecialchars((string) ($taskData['title'] ?? '')); ?>" required pattern=".*\S.*">
            </div>

            <div class="form-group">
                <label for="status">Статус:</label>
                <select id="status" name="status">
                    <option value="todo" <?php echo (($taskData['status'] ?? '') === 'todo') ? 'selected' : ''; ?>>Не
                        розпочато</option>
                    <option value="in-progress" <?php echo (($taskData['status'] ?? '') === 'in-progress') ? 'selected' : ''; ?>>В
                        процесі</option>
                    <option value="done" <?php echo (($taskData['status'] ?? '') === 'done') ? 'selected' : ''; ?>>Готово
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="actual_result">Фактичний результат (виконання працівника):</label>
                <textarea id="actual_result"
                    name="actual_result"><?php echo htmlspecialchars((string) ($taskData['actual_result'] ?? '')); ?></textarea>
            </div>

            <div class="form-group">
                <label for="actual_time">Фактичний час (хвилин):</label>
                <input type="number" id="actual_time" name="actual_time"
                    value="<?php echo htmlspecialchars((string) ($taskData['actual_time'] ?? '')); ?>">
            </div>

            <div class="simple-actions">
                <button type="submit" class="simple-btn">Зберегти зміни</button>
                <a href="/tasks/view/<?php echo (int) ($taskData['id'] ?? 0); ?>" class="simple-link">Скасувати</a>
            </div>
        </form>
    </div>
</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
