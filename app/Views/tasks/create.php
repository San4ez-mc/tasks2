<?php
/**
 * Створення нового завдання
 */
$title = 'Створити завдання';
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

        <form method="POST" action="/tasks/create">
            <div class="form-group">
                <label for="title">Назва завдання: *</label>
                <input type="text" id="title" name="title" required pattern=".*\S.*">
            </div>

            <div class="form-group">
                <label for="assignee_id">Виконавець: *</label>
                <select id="assignee_id" name="assignee_id" required>
                    <option value="">-- Виберіть виконавця --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['user_id']; ?>">
                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="type">Тип:*</label>
                <select id="type" name="type" required>
                    <option value="important-urgent">🔴 Важлива термінова</option>
                    <option value="important-not-urgent">🔵 Важлива нетермінова</option>
                    <option value="not-important-urgent">🟣 Неважлива термінова</option>
                    <option value="not-important-not-urgent">⚪ Неважлива нетермінова</option>
                </select>
            </div>

            <div class="form-group">
                <label for="due_date">Дата закінчення:</label>
                <input type="date" id="due_date" name="due_date">
            </div>

            <div class="form-group">
                <label for="expected_result">Очікуваний результат: *</label>
                <textarea id="expected_result" name="expected_result" required></textarea>
                <small style="color:#64748b;font-size:12px;line-height:1.4;">Коли заповнене — звужує задачу, не дає
                    робити зайвого. Якщо задачу делеговано — виконавець одразу розуміє, що саме від нього очікується, і
                    переробок менше.</small>
            </div>

            <div class="form-group">
                <label for="expected_time">Очікуваний час виконання, хв: *</label>
                <input type="number" id="expected_time" name="expected_time" min="1" step="5" required>
                <small style="color:#64748b;font-size:12px;line-height:1.4;">Дозволяє розуміти, скільки і яких реально
                    задач можна поставити в день, і не перевантажувати виконавця.</small>
            </div>

            <div class="simple-actions">
                <button type="submit" class="simple-btn">Створити завдання</button>
                <a href="/tasks" class="simple-link">Скасувати</a>
            </div>
        </form>
    </div>
</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
