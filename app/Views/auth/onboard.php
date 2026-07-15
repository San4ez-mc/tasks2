<?php
/**
 * Сторінка онбордінгу працівника
 */
$title = 'Онбордінг';
$user = $onboard['user'] ?? [];
$companyName = $onboard['company_name'] ?? '';
$tokenCode = $onboard['token_code'] ?? '';
?>
<!DOCTYPE html>
<html lang="uk">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .onboard-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 460px;
        }

        h1 {
            text-align: center;
            margin-bottom: 6px;
            color: #2c3e50;
            font-size: 24px;
        }

        .onboard-subtitle {
            text-align: center;
            color: #607084;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .onboard-company {
            text-align: center;
            background: #f0f4ff;
            padding: 10px 16px;
            border-radius: 8px;
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .onboard-company strong {
            color: #2d3748;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
            outline: none;
        }

        .form-group input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tg-field {
            position: relative;
        }

        .tg-field .tg-at {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #8899aa;
            font-size: 15px;
            pointer-events: none;
        }

        .tg-field input {
            padding-left: 28px;
        }

        .form-hint {
            font-size: 12px;
            color: #8899aa;
            margin-top: 3px;
        }

        .password-section {
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
            padding-top: 20px;
        }

        .password-section h3 {
            font-size: 15px;
            color: #374151;
            margin-bottom: 12px;
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            transition: opacity 0.2s;
        }

        .submit-btn:hover {
            opacity: 0.9;
        }

        .flash-error {
            background: #fef2f2;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid #fecaca;
        }

        .flash-info {
            background: #eff6ff;
            color: #1e40af;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid #bfdbfe;
        }
    </style>
</head>

<body>
    <div class="onboard-container">
        <h1>👋 Ласкаво просимо!</h1>
        <p class="onboard-subtitle">Перевірте свої дані та створіть пароль для входу</p>

        <?php if (!empty($companyName)): ?>
            <div class="onboard-company">Вас запрошено до компанії <strong><?php echo htmlspecialchars($companyName); ?></strong></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash'])): ?>
            <?php foreach ($_SESSION['flash'] as $type => $msg): ?>
                <div class="flash-<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($msg); ?></div>
            <?php endforeach; ?>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <form method="POST" action="/auth/onboard-complete">
            <input type="hidden" name="token_code" value="<?php echo htmlspecialchars($tokenCode); ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">Ім'я *</label>
                    <input type="text" id="first_name" name="first_name"
                        value="<?php echo htmlspecialchars((string) ($user['first_name'] ?? '')); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Прізвище</label>
                    <input type="text" id="last_name" name="last_name"
                        value="<?php echo htmlspecialchars((string) ($user['last_name'] ?? '')); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                    value="<?php echo htmlspecialchars((string) ($user['email'] ?? '')); ?>"
                    placeholder="name@company.com">
            </div>

            <div class="form-group">
                <label for="telegram_username">Telegram username</label>
                <div class="tg-field">
                    <span class="tg-at">@</span>
                    <input type="text" id="telegram_username" name="telegram_username"
                        value="<?php echo htmlspecialchars((string) ($user['username'] ?? '')); ?>"
                        placeholder="username"
                        pattern="[A-Za-z0-9_]{3,32}"
                        oninput="this.value=this.value.replace(/^@+/,'').replace(/[^A-Za-z0-9_]/g,'')">
                </div>
                <div class="form-hint">Латинські літери, цифри та _ (без @)</div>
            </div>

            <div class="password-section">
                <h3>🔒 Створіть пароль для входу</h3>

                <div class="form-group">
                    <label for="password">Пароль *</label>
                    <input type="password" id="password" name="password" required minlength="6"
                        placeholder="Мінімум 6 символів">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Повторіть пароль *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="6"
                        placeholder="Ще раз">
                </div>
            </div>

            <button type="submit" class="submit-btn">Завершити реєстрацію</button>
        </form>
    </div>
</body>

</html>
