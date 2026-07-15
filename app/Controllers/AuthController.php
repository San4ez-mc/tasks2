<?php
/**
 * Контролер для авторизації
 */

namespace App\Controllers;

use App\Models\User;
use App\Models\Company;

class AuthController
{
    private const PASSWORD_RESET_TTL_SECONDS = 3600;

    private function finish_login($user, ?string $redirectTo = null, ?string $flashMessage = null)
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user;

        $company_model = new Company();
        $companies = $company_model->get_by_user($user['id']);

        if (empty($companies)) {
            $_SESSION['company_id'] = null;
            flash('info', 'Акаунт створено. Далі створіть або приєднайтесь до компанії.');
            redirect('/company/profile');
        }

        $_SESSION['company_id'] = $companies[0]['id'];

        if ($flashMessage !== null && $flashMessage !== '') {
            flash('success', $flashMessage);
        }

        if ($redirectTo !== null && $redirectTo !== '') {
            redirect($redirectTo);
        }

        redirect('/dashboard');
    }


    /**
     * Сторінка входу
     */
    public function login()
    {
        if (is_auth()) {
            redirect('/dashboard');
        }
        require APP_PATH . '/Views/auth/login.php';
    }

    public function forgot_password()
    {
        if (is_auth()) {
            redirect('/account/settings');
        }

        require APP_PATH . '/Views/auth/forgot-password.php';
    }

    public function forgot_password_post()
    {
        if (is_auth()) {
            redirect('/account/settings');
        }

        $email = strtolower(trim((string) post_param('email')));
        if ($email === '') {
            flash('error', 'Вкажіть email для відновлення пароля.');
            redirect('/auth/forgot-password');
        }

        $user_model = new User();
        $user = $user_model->get_by_email($email);

        if ($user && !empty($user['email'])) {
            $this->ensure_auth_tokens_table();
            $db = new \App\Models\Database();

            $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'password_reset'")
                ->bind(':user_id', (int) $user['id'])
                ->execute();

            $code = $this->generate_auth_code(32);
            $token = 'PWDRESET-' . $code;
            $expiresAt = date('Y-m-d H:i:s', time() + self::PASSWORD_RESET_TTL_SECONDS);

            $db->insert('auth_tokens', [
                'token' => $token,
                'user_id' => (int) $user['id'],
                'company_id' => null,
                'type' => 'password_reset',
                'expires_at' => $expiresAt,
            ]);

            $this->send_password_reset_email($user, $code, $expiresAt);
        }

        flash('success', 'Якщо акаунт з таким email існує, ми вже відправили лист із посиланням для відновлення пароля.');
        redirect('/auth/forgot-password');
    }

    public function reset_password(string $code): void
    {
        if (is_auth()) {
            redirect('/account/settings');
        }

        $reset = $this->get_password_reset_context($code);
        if (!$reset) {
            flash('error', 'Посилання для відновлення пароля не знайдено або вже протерміноване.');
            redirect('/auth/forgot-password');
            return;
        }

        require APP_PATH . '/Views/auth/reset-password.php';
    }

    public function reset_password_post(): void
    {
        if (is_auth()) {
            redirect('/account/settings');
        }

        $code = trim((string) post_param('token_code'));
        $password = (string) post_param('password');
        $password_confirm = (string) post_param('password_confirm');

        if ($code === '') {
            flash('error', 'Невалідне посилання для відновлення пароля.');
            redirect('/auth/forgot-password');
            return;
        }

        if ($password === '' || $password_confirm === '') {
            flash('error', 'Вкажіть новий пароль і підтвердження.');
            redirect('/auth/reset-password/' . urlencode($code));
            return;
        }

        if ($password !== $password_confirm) {
            flash('error', 'Паролі не співпадають.');
            redirect('/auth/reset-password/' . urlencode($code));
            return;
        }

        if (mb_strlen($password) < 6) {
            flash('error', 'Новий пароль має бути не менше 6 символів.');
            redirect('/auth/reset-password/' . urlencode($code));
            return;
        }

        $reset = $this->get_password_reset_context($code);
        if (!$reset) {
            flash('error', 'Посилання для відновлення пароля не знайдено або вже протерміноване.');
            redirect('/auth/forgot-password');
            return;
        }

        $user_model = new User();
        $user_model->update((int) $reset['user']['id'], ['password' => $password]);

        $db = new \App\Models\Database();
        $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'password_reset'")
            ->bind(':user_id', (int) $reset['user']['id'])
            ->execute();

        flash('success', 'Пароль оновлено. Тепер увійдіть з новим паролем.');
        redirect('/auth/login');
    }

    /**
     * Обробка входу
     */
    public function login_post()
    {
        $email = post_param('email');
        $password = post_param('password');

        if (!$email || !$password) {
            flash('error', 'Заповніть усі поля');
            redirect('/auth/login');
        }

        $user_model = new User();
        $user = $user_model->get_by_email($email);

        if (!$user || !$user_model->verify_password($password, $user['password'] ?? '')) {
            flash('error', 'НевернiEmail або пароль');
            redirect('/auth/login');
        }

        $this->finish_login($user);
    }

    public function google_login()
    {
        $credential = $_POST['credential'] ?? null;

        if (!$credential) {
            flash('error', 'Google credential не отримано');
            redirect('/auth/login');
        }

        if (empty(GOOGLE_CLIENT_ID)) {
            flash('error', 'Google вхід не налаштований. Додайте GOOGLE_CLIENT_ID у config.php');
            redirect('/auth/login');
        }

        $token_info_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
        $json = @file_get_contents($token_info_url);

        if (!$json) {
            flash('error', 'Не вдалося верифікувати Google токен');
            redirect('/auth/login');
        }

        $payload = json_decode($json, true);
        if (!$payload || ($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
            flash('error', 'Google токен невалідний для цього додатку');
            redirect('/auth/login');
        }

        $email = $payload['email'] ?? null;
        if (!$email) {
            flash('error', 'Google не повернув email');
            redirect('/auth/login');
        }

        $user_model = new User();
        $user = $user_model->get_by_email($email);

        if (!$user) {
            $full_name = trim($payload['name'] ?? '');
            $parts = preg_split('/\s+/', $full_name, 2);
            $first_name = $parts[0] ?? 'Google';
            $last_name = $parts[1] ?? '';

            $user_model->create([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'photo_url' => $payload['picture'] ?? null,
            ]);

            $user = $user_model->get_by_email($email);
        }

        $this->finish_login($user);
    }

    public function telegram_token($token = null)
    {
        if (is_auth()) {
            redirect('/account/settings');
        }

        $rawToken = trim((string) $token);
        if ($rawToken === '') {
            flash('error', 'Посилання для входу некоректне або неповне.');
            redirect('/auth/login');
        }

        $this->ensure_auth_tokens_table();
        $db = new \App\Models\Database();
        $storedToken = 'TGLOGIN-' . $rawToken;

        $tokenRow = $db->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'temp' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $storedToken)
            ->fetch();

        if (!$tokenRow) {
            flash('error', 'Посилання для входу не знайдено або вже протерміноване. Згенеруйте нове в Telegram-боті.');
            redirect('/auth/login');
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('error', 'Не вдалося визначити користувача для цього посилання.');
            redirect('/auth/login');
        }

        $user_model = new User();
        $user = $user_model->get_by_id($userId);
        if (!$user) {
            flash('error', 'Користувача не знайдено.');
            redirect('/auth/login');
        }

        $db->query('DELETE FROM auth_tokens WHERE id = :id')
            ->bind(':id', (int) ($tokenRow['id'] ?? 0))
            ->execute();

        $this->finish_login($user, '/account/settings', 'Вхід через Telegram виконано. За потреби одразу змініть пароль у налаштуваннях акаунта.');
    }

    /**
     * Сторінка реєстрації
     */
    public function register()
    {
        if (is_auth()) {
            redirect('/dashboard');
        }
        require APP_PATH . '/Views/auth/register.php';
    }

    /**
     * Обробка реєстрації
     */
    public function register_post()
    {
        $first_name = post_param('first_name');
        $last_name = post_param('last_name');
        $email = post_param('email');
        $password = post_param('password');
        $password_confirm = post_param('password_confirm');

        // Валідація
        if (!$first_name || !$email || !$password) {
            flash('error', 'Заповніть усі обов\'язкові поля');
            redirect('/auth/register');
        }

        if ($password !== $password_confirm) {
            flash('error', 'Паролі не совпадают');
            redirect('/auth/register');
        }

        if (strlen($password) < 6) {
            flash('error', 'Пароль має бути не менше 6 символів');
            redirect('/auth/register');
        }

        $user_model = new User();

        // Перевірити, чи користувач уже існує
        if ($user_model->get_by_email($email)) {
            flash('error', 'Користувач з цією поштою вже існує');
            redirect('/auth/register');
        }

        // Створити користувача
        $user_model->create([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password' => $password,
        ]);

        flash('success', 'Реєстрація успішна! Введіть свої дані для входу');
        redirect('/auth/login');
    }

    /**
     * Вихід
     */
    public function logout()
    {
        $_SESSION = [];
        session_destroy();
        redirect('/auth/login');
    }

    private function ensure_auth_tokens_table(): void
    {
        $db = new \App\Models\Database();
        $db->query("CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(255) NOT NULL,
            user_id INT NOT NULL,
            company_id INT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'temp',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_auth_token (token),
            KEY idx_auth_tokens_user (user_id),
            KEY idx_auth_tokens_type_exp (type, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }

    private function generate_auth_code(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function get_password_reset_context(string $code): ?array
    {
        $rawCode = trim($code);
        if ($rawCode === '') {
            return null;
        }

        $this->ensure_auth_tokens_table();
        $db = new \App\Models\Database();
        $token = 'PWDRESET-' . $rawCode;

        $tokenRow = $db->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'password_reset' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $token)
            ->fetch();

        if (!$tokenRow) {
            return null;
        }

        $user_model = new User();
        $user = $user_model->get_by_id((int) ($tokenRow['user_id'] ?? 0));
        if (!$user) {
            return null;
        }

        return [
            'token_code' => $rawCode,
            'token_row' => $tokenRow,
            'user' => $user,
        ];
    }

    private function send_password_reset_email(array $user, string $code, string $expiresAt): void
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $fullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        $greetingName = $fullName !== '' ? $fullName : 'користувачу';
        $resetLink = rtrim(APP_URL, '/') . '/auth/reset-password/' . rawurlencode($code);
        $subject = 'Відновлення пароля у ' . APP_NAME;
        $expiresLabel = gmdate('H:i d.m.Y', strtotime($expiresAt));

        $htmlMessage = '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;">'
            . '<p>Привіт, ' . htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p>Ми отримали запит на відновлення пароля у <strong>' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">Створити новий пароль</a></p>'
            . '<p>Посилання дійсне до ' . htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8') . ' UTC.</p>'
            . '<p>Якщо це були не ви, просто проігноруйте цей лист.</p>'
            . '<p>З повагою,<br>команда ' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</body></html>';

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . APP_NAME . ' <no-reply@fineko.space>',
        ];

        @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlMessage, implode("\r\n", $headers));
    }

    public function onboard(string $code): void
    {
        $this->ensure_auth_tokens_table();
        $db = new \App\Models\Database();
        $storedToken = 'ONBOARD-' . trim($code);

        $tokenRow = $db->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'onboarding' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $storedToken)
            ->fetch();

        if (!$tokenRow) {
            flash('error', 'Посилання для онбордінгу не знайдено або вже протерміноване.');
            redirect('/auth/login');
            return;
        }

        $user_model = new User();
        $user = $user_model->get_by_id((int) ($tokenRow['user_id'] ?? 0));

        if (!$user) {
            flash('error', 'Користувача не знайдено.');
            redirect('/auth/login');
            return;
        }

        $companyId = (int) ($tokenRow['company_id'] ?? 0);
        $companyName = '';
        if ($companyId > 0) {
            $company = $db->query('SELECT name FROM companies WHERE id = :id LIMIT 1')
                ->bind(':id', $companyId)
                ->fetch();
            $companyName = (string) ($company['name'] ?? '');
        }

        $onboard = [
            'token_code' => trim($code),
            'user' => $user,
            'company_name' => $companyName,
        ];

        require APP_PATH . '/Views/auth/onboard.php';
    }

    public function onboard_post(): void
    {
        $code = trim((string) post_param('token_code'));
        if ($code === '') {
            flash('error', 'Невалідне посилання.');
            redirect('/auth/login');
            return;
        }

        $this->ensure_auth_tokens_table();
        $db = new \App\Models\Database();
        $storedToken = 'ONBOARD-' . $code;

        $tokenRow = $db->query("SELECT * FROM auth_tokens WHERE token = :token AND type = 'onboarding' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':token', $storedToken)
            ->fetch();

        if (!$tokenRow) {
            flash('error', 'Посилання протерміноване. Попросіть адміністратора згенерувати нове.');
            redirect('/auth/login');
            return;
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        $companyId = (int) ($tokenRow['company_id'] ?? 0);

        $user_model = new User();
        $user = $user_model->get_by_id($userId);

        if (!$user) {
            flash('error', 'Користувача не знайдено.');
            redirect('/auth/login');
            return;
        }

        $firstName = trim((string) post_param('first_name'));
        $lastName = trim((string) post_param('last_name'));
        $email = strtolower(trim((string) post_param('email')));
        $password = (string) post_param('password');
        $passwordConfirm = (string) post_param('password_confirm');
        $telegramUsername = trim((string) post_param('telegram_username'));

        // Normalize telegram username
        $telegramUsername = strtolower(ltrim($telegramUsername, '@'));
        if ($telegramUsername !== '' && !preg_match('/^[a-z0-9_]{3,32}$/', $telegramUsername)) {
            $telegramUsername = '';
        }

        if ($firstName === '') {
            flash('error', "Вкажіть ім'я.");
            redirect('/auth/onboard/' . $code);
            return;
        }

        if ($password === '' || mb_strlen($password) < 6) {
            flash('error', 'Пароль має бути мінімум 6 символів.');
            redirect('/auth/onboard/' . $code);
            return;
        }

        if ($password !== $passwordConfirm) {
            flash('error', 'Паролі не співпадають.');
            redirect('/auth/onboard/' . $code);
            return;
        }

        // Update user data
        $updateData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $password,
        ];

        if ($email !== '') {
            $existingUser = $user_model->get_by_email($email);
            if ($existingUser && (int) ($existingUser['id'] ?? 0) !== $userId) {
                flash('error', 'Цей email вже використовується іншим користувачем. Вкажіть інший email або зверніться до адміністратора.');
                redirect('/auth/onboard/' . $code);
                return;
            }

            $updateData['email'] = $email;
        }

        if ($telegramUsername !== '') {
            $updateData['username'] = $telegramUsername;
        }

        try {
            $user_model->update($userId, $updateData);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate entry') !== false && stripos($message, 'email') !== false) {
                flash('error', 'Цей email вже використовується іншим користувачем. Вкажіть інший email або зверніться до адміністратора.');
                redirect('/auth/onboard/' . $code);
                return;
            }

            throw $e;
        }

        // Delete used token
        $db->query('DELETE FROM auth_tokens WHERE id = :id')
            ->bind(':id', (int) ($tokenRow['id'] ?? 0))
            ->execute();

        // Reload user after update
        $user = $user_model->get_by_id($userId);
        $this->finish_login($user, '/dashboard', 'Ласкаво просимо до ' . APP_NAME . '! Ваш акаунт налаштовано.');
    }
}
