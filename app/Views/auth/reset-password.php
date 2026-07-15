<?php
/**
 * Сторінка встановлення нового пароля
 */
$title = 'Новий пароль';
?>

<!DOCTYPE html>
<html lang="uk">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - <?php echo APP_NAME; ?></title>
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

        .auth-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 420px;
        }

        h1 {
            text-align: center;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 24px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid;
        }

        .alert-error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #2c3e50;
        }

        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
        }

        button:hover {
            background: #5568d3;
        }

        .meta {
            margin-bottom: 16px;
            padding: 10px;
            font-size: 13px;
            border-radius: 4px;
            background: #f6f8fb;
            color: #4b5563;
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <h1><?php echo APP_NAME; ?></h1>
        <p class="subtitle">Створіть новий пароль для акаунта <?php echo htmlspecialchars((string) ($reset['user']['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>.</p>

        <?php $error = flash('error'); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="meta">
            Посилання тимчасове. Після збереження нового пароля старе посилання стане недійсним.
        </div>

        <form method="POST" action="/auth/reset-password-complete">
            <input type="hidden" name="token_code" value="<?php echo htmlspecialchars((string) ($reset['token_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="password">Новий пароль:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="password_confirm">Повторіть пароль:</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit">Оновити пароль</button>
        </form>
    </div>
</body>

</html>