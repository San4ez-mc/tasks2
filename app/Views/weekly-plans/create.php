<?php
$title = 'Створити план-факт';
$layout_container_class = 'container-narrow';

ob_start();
?>
<style>
    .plan-create-page {
        max-width: 760px;
        display: grid;
        gap: 18px;
    }

    .plan-create-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .plan-create-card h1 {
        font-size: 30px;
        margin-bottom: 10px;
        color: #102034;
    }

    .plan-create-card p {
        color: #526276;
        line-height: 1.55;
        margin-bottom: 18px;
    }

    .plan-create-form {
        display: grid;
        gap: 16px;
    }

    .plan-create-field {
        display: grid;
        gap: 8px;
    }

    .plan-create-field label {
        font-weight: 700;
        color: #18324d;
    }

    .plan-create-field input,
    .plan-create-field select,
    .plan-create-field textarea {
        width: 100%;
        border-radius: 12px;
        border: 1px solid #cfd9e5;
        padding: 12px 14px;
        font: inherit;
        background: #fbfdff;
    }

    .plan-create-field textarea {
        min-height: 120px;
        resize: vertical;
    }

    .plan-create-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .plan-create-btn,
    .plan-create-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 180px;
        padding: 12px 16px;
        border-radius: 12px;
        font-weight: 800;
        text-decoration: none;
    }

    .plan-create-btn {
        border: 0;
        background: #102034;
        color: #fff;
        cursor: pointer;
    }

    .plan-create-link {
        background: #e5ebf3;
        color: #334155;
    }

    @media (max-width: 560px) {
        .plan-create-card {
            padding: 18px;
        }

        .plan-create-card h1 {
            font-size: 26px;
        }

        .plan-create-btn,
        .plan-create-link {
            width: 100%;
            min-width: 0;
        }
    }
</style>

<section class="plan-create-page">
    <div class="plan-create-card">
        <h1>Створити план-факт</h1>
        <p>Оберіть співробітника та старт тижня. Якщо plan-fact для цього працівника і цього тижня вже існує, система
            відкриє наявний запис.</p>

        <form method="post" action="/weekly-plans/create" class="plan-create-form">
            <div class="plan-create-field">
                <label for="user_id">Співробітник</label>
                <select id="user_id" name="user_id" required>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $employeeId = (int) ($employee['user_id'] ?? 0);
                        $employeeName = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
                        $employeeTitle = trim((string) ($employee['title'] ?? ''));
                        ?>
                        <option value="<?php echo $employeeId; ?>" <?php echo $employeeId === (int) $selectedUserId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($employeeName !== '' ? $employeeName : ('Користувач #' . $employeeId)); ?>    <?php echo $employeeTitle !== '' ? ' - ' . htmlspecialchars($employeeTitle) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="plan-create-field">
                <label for="week_start">Початок тижня</label>
                <input id="week_start" type="date" name="week_start" value="<?php echo htmlspecialchars($weekStart); ?>"
                    required>
            </div>

            <div class="plan-create-field">
                <label for="notes">Нотатки</label>
                <textarea id="notes" name="notes"
                    placeholder="Фокус тижня, ризики, ключові результати"><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
            </div>

            <div class="plan-create-actions">
                <button type="submit" class="plan-create-btn">Створити план-факт</button>
                <a href="/weekly-plans" class="plan-create-link">Назад до списку</a>
            </div>
        </form>
    </div>
</section>

<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';