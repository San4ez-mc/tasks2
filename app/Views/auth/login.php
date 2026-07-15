<?php
/**
 * Сторінка входу
 */
$title = 'Вхід';
$telegramBotLink = !empty(TELEGRAM_BOT_USERNAME)
    ? 'https://t.me/' . rawurlencode(TELEGRAM_BOT_USERNAME) . '?start=TGLOGIN'
    : '';
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
            max-width: 400px;
        }

        h1 {
            text-align: center;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
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

        .alert-success {
            background: #d1e7dd;
            border-color: #198754;
            color: #0f5132;
        }

        .form-group {
            margin-bottom: 20px;
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
            transition: border-color 0.3s;
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
            transition: background 0.3s;
        }

        button:hover {
            background: #5568d3;
        }

        .links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .divider {
            margin: 18px 0;
            text-align: center;
            color: #7f8c8d;
            font-size: 12px;
            position: relative;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 35%;
            height: 1px;
            background: #ddd;
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .oauth-btn {
            display: block;
            width: 100%;
            margin-top: 10px;
            padding: 11px;
            border-radius: 4px;
            border: 1px solid #ddd;
            background: #fff;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
        }

        .oauth-google {
            color: #1f1f1f;
        }

        .oauth-telegram {
            background: #229ed9;
            color: #fff;
            border-color: #229ed9;
        }

        .links a {
            color: #667eea;
            text-decoration: none;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .hint {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            font-size: 12px;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffe69c;
        }

        .telegram-hint {
            background: #e8f5fc;
            border-color: #b6e1f5;
            color: #0d4f6b;
        }

        @media (max-width: 480px) {
            body {
                padding: 14px;
                align-items: stretch;
            }

            .auth-container {
                padding: 24px 18px;
                border-radius: 18px;
            }

            h1 {
                font-size: 28px;
            }

            input,
            button,
            .oauth-btn {
                min-height: 44px;
            }
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <h1><?php echo APP_NAME; ?></h1>
        <p class="subtitle">Введіть свої дані для входу</p>

        <?php $error = flash('error'); ?>
        <?php $success = flash('success'); ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST" action="/auth/login">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="links" style="margin-top:-6px; margin-bottom:16px; text-align:right;">
                <a href="/auth/forgot-password">Забули пароль?</a>
            </div>

            <button type="submit">Увійти</button>

            <div class="divider">або</div>

            <?php if (!empty(GOOGLE_CLIENT_ID)): ?>
                <div id="googleButtonWrap"></div>
            <?php else: ?>
                <div class="hint">
                    Google вхід буде доступний після додавання `GOOGLE_CLIENT_ID` у config.php.
                </div>
            <?php endif; ?>

            <?php if ($telegramBotLink !== ''): ?>
                <a class="oauth-btn oauth-telegram" href="<?php echo htmlspecialchars($telegramBotLink); ?>" target="_blank" rel="noopener noreferrer">
                    Увійти через Telegram
                </a>
                <div class="hint telegram-hint">
                    Відкриється бот і одразу надішле тимчасове посилання для входу. Це заміна Telegram Login widget, який падав з помилкою Bot domain invalid.
                </div>
            <?php else: ?>
                <div class="hint">
                    Telegram вхід ще не налаштований. Додайте TELEGRAM_BOT_USERNAME у config.php.
                </div>
            <?php endif; ?>

            <div class="links">
                <p>Немаєте облікового запису? <a href="/auth/register">Зареєструватися</a></p>
            </div>
        </form>
    </div>

    <form id="googleForm" method="POST" action="/auth/google" style="display:none;">
        <input type="hidden" name="credential" id="google_credential">
    </form>

    <?php if (!empty(GOOGLE_CLIENT_ID)): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
            window.onload = function () {
                google.accounts.id.initialize({
                    client_id: '<?php echo addslashes(GOOGLE_CLIENT_ID); ?>',
                    callback: function (response) {
                        document.getElementById('google_credential').value = response.credential;
                        document.getElementById('googleForm').submit();
                    }
                });

                google.accounts.id.renderButton(
                    document.getElementById('googleButtonWrap'),
                    { theme: 'outline', size: 'large', width: 320, text: 'signin_with' }
                );
            };
        </script>
    <?php endif; ?>

</body>

</html>