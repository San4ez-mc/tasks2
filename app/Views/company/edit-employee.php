<?php
$title = 'Редагувати працівника';
$layout_container_class = 'container-wide';
ob_start();
?>
<style>
    .employee-shell {
        max-width: 980px;
        display: grid;
        gap: 18px;
    }

    .employee-hero {
        background: linear-gradient(135deg, #17324d 0%, #26557f 100%);
        color: #fff;
        border-radius: 22px;
        padding: 28px;
        box-shadow: 0 20px 44px rgba(16, 32, 52, .16);
    }

    .employee-hero h1 {
        font-size: 34px;
        margin-bottom: 10px;
    }

    .employee-hero p {
        color: rgba(255, 255, 255, .82);
        max-width: 780px;
        line-height: 1.6;
    }

    .employee-form-card {
        background: #fff;
        border: 1px solid #dbe5ef;
        border-radius: 22px;
        padding: 24px;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
    }

    .employee-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .employee-field {
        display: grid;
        gap: 8px;
    }

    .employee-field label {
        font-weight: 700;
        color: #18324d;
    }

    .employee-field input,
    .employee-field select {
        width: 100%;
        border-radius: 14px;
        border: 1px solid #cfd9e5;
        padding: 12px 14px;
        font: inherit;
        background: #fbfdff;
    }

    .employee-static {
        border-radius: 14px;
        border: 1px solid #d6e1ec;
        padding: 12px 14px;
        background: #f8fbfe;
        color: #18324d;
    }

    .employee-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    .employee-btn,
    .employee-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 160px;
        border-radius: 14px;
        padding: 12px 16px;
        font-weight: 800;
        text-decoration: none;
    }

    .employee-btn {
        border: 0;
        background: #102034;
        color: #fff;
        cursor: pointer;
    }

    .employee-link {
        background: #eef4fb;
        color: #17324d;
        border: 1px solid #d4e0ec;
    }

    @media (max-width: 820px) {
        .employee-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="employee-shell">
    <section class="employee-hero">
        <h1>Редагувати працівника</h1>
        <p>Оновіть роль у структурі, керівника і Telegram-прив'язку. Це допоможе і в організаційній схемі, і в роботі
            через бота.</p>
    </section>

    <section class="employee-form-card">
        <form method="POST" action="/company/edit-employee/<?php echo (int) $employee['user_id']; ?>">
            <div class="employee-grid">
                <div class="employee-field">
                    <label>Працівник</label>
                    <div class="employee-static">
                        <?php echo htmlspecialchars(trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?: '—'); ?>
                    </div>
                </div>

                <div class="employee-field">
                    <label>Email</label>
                    <div class="employee-static"><?php echo htmlspecialchars((string) ($employee['email'] ?? '—')); ?>
                    </div>
                </div>

                <div class="employee-field">
                    <label for="title">Посада</label>
                    <input type="text" id="title" name="title"
                        value="<?php echo htmlspecialchars($employee['title'] ?? ''); ?>">
                </div>

                <div class="employee-field">
                    <label for="reports_to">Керівник</label>
                    <select id="reports_to" name="reports_to">
                        <option value="">Без керівника</option>
                        <?php foreach (($manager_options ?? []) as $mgr):
                            $mgr_id = (int) ($mgr['user_id'] ?? 0);
                            $selected = (int) ($employee['reports_to'] ?? 0) === $mgr_id ? 'selected' : '';
                            ?>
                            <option value="<?php echo $mgr_id; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars(trim(($mgr['first_name'] ?? '') . ' ' . ($mgr['last_name'] ?? '')) ?: '—'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="employee-field">
                    <label for="telegram_id">Telegram ID</label>
                    <input type="number" id="telegram_id" name="telegram_id"
                        value="<?php echo htmlspecialchars((string) ($employee['telegram_id'] ?? '')); ?>"
                        placeholder="Наприклад: 123456789">
                </div>

                <div class="employee-field">
                    <label for="telegram_username">Telegram username</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8899aa;font-size:15px;pointer-events:none">@</span>
                        <input type="text" id="telegram_username" name="telegram_username"
                            value="<?php echo htmlspecialchars((string) ($employee['username'] ?? '')); ?>"
                            placeholder="username"
                            pattern="[A-Za-z0-9_]{3,32}"
                            title="Від 3 до 32 символів: латинські літери, цифри або _"
                            style="padding-left:28px"
                            oninput="this.value=this.value.replace(/^@+/,'').replace(/[^A-Za-z0-9_]/g,'')">
                    </div>
                    <small>Тільки латинські літери, цифри та _ (без @). Наприклад: ivan_petrenko</small>
                </div>
            </div>

            <div class="employee-actions">
                <button type="submit" class="employee-btn">Зберегти зміни</button>
                <a href="/company/profile" class="employee-link">Скасувати</a>
            </div>
        </form>
    </section>
</div>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
