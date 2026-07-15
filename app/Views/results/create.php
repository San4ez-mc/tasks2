<?php
/**
 * Створення нового результату
 */
$title = 'Створити ціль';
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

        <form method="POST" action="/results/create">
            <div class="form-group">
                <label for="title">Назва цілі: *</label>
                <input type="text" id="title" name="title" required>
            </div>

            <div class="form-group">
                <label for="description">Опис:</label>
                <textarea id="description" name="description"></textarea>
            </div>

            <div class="form-group">
                <label for="expected_result">Очікуваний результат:</label>
                <textarea id="expected_result" name="expected_result"></textarea>
            </div>

            <div class="form-group">
                <label for="assignee_id">Виконавець:</label>
                <select id="assignee_id" name="assignee_id">
                    <option value="">-- Без виконавця --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['user_id']; ?>">
                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="simple-actions">
                <button type="submit" class="simple-btn">Створити ціль</button>
                <a href="/results" class="simple-link">Скасувати</a>
            </div>
        </form>
    </div>
</section>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
