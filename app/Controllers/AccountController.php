<?php
/**
 * Контролер налаштувань акаунта
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\User;
use App\Controllers\SubscriptionController;

class AccountController
{
    private const LINK_TTL_SECONDS = 900;
    private const API_TOKEN_TTL_SECONDS = 31536000;
    private const DEFAULT_MORNING_HOUR = 9;
    private const DEFAULT_EVENING_HOUR = 18;

    public function settings()
    {
        $session_user = get_user();
        $this->ensure_auth_tokens_table();
        $this->ensure_digest_settings_table();
        $user_model = new User();
        $user = $user_model->get_by_id((int) ($session_user['id'] ?? 0));

        if (!$user) {
            flash('error', 'Користувача не знайдено');
            redirect('/auth/logout');
        }

        $_SESSION['user'] = $user;

        $active_code = $this->get_active_link_code((int) $user['id']);
        $api_tokens = $this->get_active_api_tokens((int) $user['id']);
        $new_api_token = $_SESSION['new_api_token'] ?? null;
        unset($_SESSION['new_api_token']);
        $digest_settings = $this->get_digest_settings((int) $user['id']);
        $companies = get_user_companies((int) $user['id']);

        $company_id = (int) ($_SESSION['company_id'] ?? 0);

        // Підписка
        (new SubscriptionController())->ensure_table();
        $subscription = get_active_subscription($company_id);

        // Перевірити чи поточний user є owner активної компанії
        $is_company_owner = false;
        if ($company_id > 0) {
            $db = new Database();
            $member_row = $db->query('SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid LIMIT 1')
                ->bind(':cid', $company_id)
                ->bind(':uid', (int) $user['id'])
                ->fetch();
            $is_company_owner = $member_row && strtolower(trim((string) ($member_row['role'] ?? ''))) === 'owner';
        }

        // Список працівників компанії (для popup downgrade)
        $company_employees = [];
        if ($company_id > 0) {
            $company_model = new \App\Models\Company();
            $company_employees = $company_model->get_employees($company_id);
        }

        require APP_PATH . '/Views/account/settings.php';
    }

    public function integrations_claude()
    {
        $session_user = get_user();
        $this->ensure_auth_tokens_table();
        $user_model = new User();
        $user = $user_model->get_by_id((int) ($session_user['id'] ?? 0));

        if (!$user) {
            flash('error', 'Користувача не знайдено');
            redirect('/auth/logout');
        }

        $api_tokens = $this->get_active_api_tokens((int) $user['id']);
        $new_api_token = $_SESSION['new_api_token'] ?? null;
        unset($_SESSION['new_api_token']);

        require APP_PATH . '/Views/account/integrations-claude.php';
    }

    public function update_digest_settings()
    {
        $session_user = get_user();
        $user_id = (int) ($session_user['id'] ?? 0);
        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $this->ensure_digest_settings_table();

        $morning_enabled = post_param('morning_enabled', null) !== null ? 1 : 0;
        $evening_enabled = post_param('evening_enabled', null) !== null ? 1 : 0;
        $morning_hour = (int) post_param('morning_hour', (string) self::DEFAULT_MORNING_HOUR);
        $evening_hour = (int) post_param('evening_hour', (string) self::DEFAULT_EVENING_HOUR);

        if ($morning_hour < 0 || $morning_hour > 23) {
            $morning_hour = self::DEFAULT_MORNING_HOUR;
        }
        if ($evening_hour < 0 || $evening_hour > 23) {
            $evening_hour = self::DEFAULT_EVENING_HOUR;
        }

        $db = new Database();
        $exists = $db->query('SELECT id FROM user_telegram_digest_settings WHERE user_id = :user_id LIMIT 1')
            ->bind(':user_id', $user_id)
            ->fetch();

        $payload = [
            'morning_enabled' => $morning_enabled,
            'morning_hour' => $morning_hour,
            'evening_enabled' => $evening_enabled,
            'evening_hour' => $evening_hour,
        ];

        if ($exists) {
            $db->update('user_telegram_digest_settings', (int) $exists['id'], $payload);
        } else {
            $payload['user_id'] = $user_id;
            $payload['last_morning_sent_at'] = null;
            $payload['last_evening_sent_at'] = null;
            $db->insert('user_telegram_digest_settings', $payload);
        }

        flash('success', 'Налаштування Telegram-дайджестів оновлено.');
        redirect('/account/settings');
    }

    public function create_api_token()
    {
        $user = get_user();
        $user_id = (int) ($user['id'] ?? 0);
        $return_to = (string) post_param('return_to', '/account/settings');
        if (!str_starts_with($return_to, '/account')) {
            $return_to = '/account/settings';
        }

        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $company_id = (int) ($_SESSION['company_id'] ?? 0);
        if ($company_id <= 0) {
            $db = new Database();
            $membership = $db->query('SELECT company_id FROM company_members WHERE user_id = :user_id ORDER BY id ASC LIMIT 1')
                ->bind(':user_id', $user_id)
                ->fetch();
            $company_id = (int) ($membership['company_id'] ?? 0);
        }

        $token = 'tt_api_' . bin2hex(random_bytes(24));
        $expires_at = date('Y-m-d H:i:s', time() + self::API_TOKEN_TTL_SECONDS);

        $db = new Database();
        $db->insert('auth_tokens', [
            'token' => $token,
            'user_id' => $user_id,
            'company_id' => $company_id > 0 ? $company_id : null,
            'type' => 'api',
            'expires_at' => $expires_at,
        ]);

        $_SESSION['new_api_token'] = [
            'token' => $token,
            'expires_at' => $expires_at,
        ];

        flash('success', 'Новий API токен згенеровано. Збережіть його зараз: повторно показати повне значення буде неможливо.');
        redirect($return_to);
    }

    public function reveal_api_token()
    {
        $user = get_user();
        $user_id = (int) ($user['id'] ?? 0);
        if ($user_id <= 0) {
            json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $token_id = (int) ($_GET['token_id'] ?? 0);
        if ($token_id <= 0) {
            json_response(['ok' => false, 'error' => 'Invalid token_id'], 400);
        }

        $db = new Database();
        $row = $db->query("SELECT token FROM auth_tokens WHERE id = :id AND user_id = :user_id AND type = 'api' AND expires_at > UTC_TIMESTAMP() LIMIT 1")
            ->bind(':id', $token_id)
            ->bind(':user_id', $user_id)
            ->fetch();

        if (!$row) {
            json_response(['ok' => false, 'error' => 'Token not found'], 404);
        }

        json_response(['ok' => true, 'token' => (string) ($row['token'] ?? '')]);
    }

    public function revoke_api_token()
    {
        $user = get_user();
        $user_id = (int) ($user['id'] ?? 0);
        $return_to = (string) post_param('return_to', '/account/settings');
        if (!str_starts_with($return_to, '/account')) {
            $return_to = '/account/settings';
        }

        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $token_id = (int) post_param('token_id', '0');
        $db = new Database();

        if ($token_id > 0) {
            $db->query("DELETE FROM auth_tokens WHERE id = :id AND user_id = :user_id AND type = 'api'")
                ->bind(':id', $token_id)
                ->bind(':user_id', $user_id)
                ->execute();
            flash('success', 'API токен відкликано.');
            redirect($return_to);
        }

        $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'api'")
            ->bind(':user_id', $user_id)
            ->execute();
        flash('success', 'Всі API токени відкликано.');
        redirect($return_to);
    }

    public function create_telegram_link_code()
    {
        $user = get_user();
        $user_id = (int) ($user['id'] ?? 0);

        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $db = new Database();
        $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'temp' AND token LIKE 'TGLINK-%'")
            ->bind(':user_id', $user_id)
            ->execute();

        $code = $this->generate_link_code();
        $token = 'TGLINK-' . $code;
        $expires_at = date('Y-m-d H:i:s', time() + self::LINK_TTL_SECONDS);

        $db->insert('auth_tokens', [
            'token' => $token,
            'user_id' => $user_id,
            'company_id' => null,
            'type' => 'temp',
            'expires_at' => $expires_at,
        ]);

        flash('success', 'Код створено. Надішліть боту команду: /link ' . $code);
        redirect('/account/settings');
    }

    public function unlink_telegram()
    {
        $session_user = get_user();
        $user_id = (int) ($session_user['id'] ?? 0);

        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $user_model = new User();
        $user_model->update($user_id, [
            'telegram_id' => null,
            'username' => null,
        ]);

        $fresh_user = $user_model->get_by_id($user_id);
        if ($fresh_user) {
            $_SESSION['user'] = $fresh_user;
        }

        $db = new Database();
        $db->query("DELETE FROM auth_tokens WHERE user_id = :user_id AND type = 'temp' AND token LIKE 'TGLINK-%'")
            ->bind(':user_id', $user_id)
            ->execute();

        flash('success', 'Telegram акаунт відв\'язано');
        redirect('/account/settings');
    }

    public function update_password()
    {
        $session_user = get_user();
        $user_id = (int) ($session_user['id'] ?? 0);

        if ($user_id <= 0) {
            redirect('/auth/login');
        }

        $password = (string) post_param('password', '');
        $password_confirm = (string) post_param('password_confirm', '');

        if ($password === '' || $password_confirm === '') {
            flash('error', 'Вкажіть новий пароль і підтвердження.');
            redirect('/account/settings');
        }

        if ($password !== $password_confirm) {
            flash('error', 'Паролі не співпадають.');
            redirect('/account/settings');
        }

        if (mb_strlen($password) < 6) {
            flash('error', 'Новий пароль має бути не менше 6 символів.');
            redirect('/account/settings');
        }

        $user_model = new User();
        $user_model->update($user_id, ['password' => $password]);

        $fresh_user = $user_model->get_by_id($user_id);
        if ($fresh_user) {
            $_SESSION['user'] = $fresh_user;
        }

        flash('success', 'Пароль успішно оновлено.');
        redirect('/account/settings');
    }

    private function get_active_link_code(int $user_id): ?array
    {
        $db = new Database();

        $row = $db->query("SELECT token, expires_at FROM auth_tokens WHERE user_id = :user_id AND type = 'temp' AND token LIKE 'TGLINK-%' AND expires_at > UTC_TIMESTAMP() ORDER BY expires_at DESC LIMIT 1")
            ->bind(':user_id', $user_id)
            ->fetch();

        if (!$row) {
            return null;
        }

        return [
            'code' => str_replace('TGLINK-', '', (string) $row['token']),
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    private function generate_link_code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function get_active_api_tokens(int $user_id): array
    {
        $db = new Database();
        $rows = $db->query("SELECT id, token, company_id, expires_at, created_at FROM auth_tokens WHERE user_id = :user_id AND type = 'api' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC")
            ->bind(':user_id', $user_id)
            ->fetchAll();

        return array_map(function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'token_masked' => $this->mask_token((string) ($row['token'] ?? '')),
                'company_id' => !empty($row['company_id']) ? (int) $row['company_id'] : null,
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    private function get_digest_settings(int $user_id): array
    {
        $db = new Database();
        $row = $db->query('SELECT * FROM user_telegram_digest_settings WHERE user_id = :user_id LIMIT 1')
            ->bind(':user_id', $user_id)
            ->fetch();

        if (!$row) {
            return [
                'morning_enabled' => 1,
                'morning_hour' => self::DEFAULT_MORNING_HOUR,
                'evening_enabled' => 1,
                'evening_hour' => self::DEFAULT_EVENING_HOUR,
            ];
        }

        return [
            'morning_enabled' => (int) ($row['morning_enabled'] ?? 1),
            'morning_hour' => (int) ($row['morning_hour'] ?? self::DEFAULT_MORNING_HOUR),
            'evening_enabled' => (int) ($row['evening_enabled'] ?? 1),
            'evening_hour' => (int) ($row['evening_hour'] ?? self::DEFAULT_EVENING_HOUR),
        ];
    }

    private function mask_token(string $token): string
    {
        if ($token === '') {
            return '';
        }

        $length = strlen($token);
        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($token, 0, 6) . str_repeat('*', $length - 10) . substr($token, -4);
    }

    private function ensure_auth_tokens_table(): void
    {
        $db = new Database();
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

    private function ensure_digest_settings_table(): void
    {
        $db = new Database();
        $db->query("CREATE TABLE IF NOT EXISTS user_telegram_digest_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            morning_enabled TINYINT(1) NOT NULL DEFAULT 1,
            morning_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
            evening_enabled TINYINT(1) NOT NULL DEFAULT 1,
            evening_hour TINYINT UNSIGNED NOT NULL DEFAULT 18,
            last_morning_sent_at DATETIME NULL,
            last_evening_sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_digest_user (user_id),
            KEY idx_digest_morning (morning_enabled, morning_hour),
            KEY idx_digest_evening (evening_enabled, evening_hour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }
}
