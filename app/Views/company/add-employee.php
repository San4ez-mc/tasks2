<?php
$title = 'Додати працівника';
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
        background: linear-gradient(135deg, #102034 0%, #1b3c62 100%);
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

    .employee-field.span-2 {
        grid-column: span 2;
    }

    .employee-field label {
        font-weight: 700;
        color: #18324d;
    }

    .employee-field small {
        color: #6d7d90;
        font-size: 13px;
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

    .employee-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 8px;
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

    .employee-tip {
        margin-top: 18px;
        border-radius: 16px;
        padding: 14px 16px;
        background: #eef7ff;
        border: 1px solid #c8ddf2;
        color: #29507a;
        line-height: 1.5;
    }

    @media (max-width: 820px) {
        .employee-grid {
            grid-template-columns: 1fr;
        }

        .employee-field.span-2 {
            grid-column: span 1;
        }
    }
</style>

<div class="employee-shell">
    <section class="employee-hero">
        <h1>Додати працівника</h1>
        <p>Створіть нового користувача або додайте до компанії вже існуючий email. Тут же можна одразу заповнити роль у
            команді, керівника та Telegram-контакти.</p>
    </section>

    <section class="employee-form-card">
        <form method="POST" action="/company/add-employee">
            <div class="employee-grid">
                <div class="employee-field">
                    <label for="first_name">Ім'я *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>

                <div class="employee-field">
                    <label for="last_name">Прізвище</label>
                    <input type="text" id="last_name" name="last_name">
                </div>

                <div class="employee-field">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="employee-field">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" placeholder="Можна залишити порожнім">
                    <small>Якщо не вказати, система створить тимчасовий пароль.</small>
                </div>

                <div class="employee-field">
                    <label for="title">Посада</label>
                    <input type="text" id="title" name="title" placeholder="Наприклад: Project manager">
                </div>

                <div class="employee-field">
                    <label for="reports_to">Керівник</label>
                    <select id="reports_to" name="reports_to">
                        <option value="">Без керівника</option>
                        <?php foreach (($employees ?? []) as $emp): ?>
                            <option value="<?php echo (int) ($emp['user_id'] ?? 0); ?>">
                                <?php echo htmlspecialchars(trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')) ?: '—'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="employee-field">
                    <label for="telegram_id">Telegram ID</label>
                    <input type="number" id="telegram_id" name="telegram_id" placeholder="Наприклад: 123456789">
                    <small>Корисно, якщо Telegram уже відомий і його треба прив'язати вручну.</small>
                </div>

                <div class="employee-field">
                    <label for="telegram_username">Telegram username</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8899aa;font-size:15px;pointer-events:none">@</span>
                        <input type="text" id="telegram_username" name="telegram_username"
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
                <button type="submit" class="employee-btn">Додати працівника</button>
                <a href="/company/profile" class="employee-link">Скасувати</a>
            </div>

            <div class="employee-tip">Після додавання працівника Telegram можна буде використовувати і для логіну, і для
                роботи з ботом, якщо вказаний коректний Telegram ID або працівник сам прив'яже акаунт через бот.</div>
        </form>
    </section>
</div>
<?php
$content = ob_get_clean();
require APP_PATH . '/Views/layouts/main.php';
