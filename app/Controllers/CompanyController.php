<?php
/**
 * Контролер для компанії
 */

namespace App\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Models\Database;

class CompanyController
{

    /**
     * Форма створення компанії
     */
    public function create()
    {
        require APP_PATH . '/Views/company/create.php';
    }

    /**
     * Створити нову компанію та додати поточного користувача як owner
     */
    public function store_create()
    {
        $user = get_user();
        $name = trim((string) post_param('name'));
        $description = trim((string) post_param('description'));

        if ($name === '') {
            flash('error', 'Назва компанії обов\'язкова');
            redirect('/company/create');
        }

        $db = new Database();
        $db->beginTransaction();

        try {
            $db->insert('companies', [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);

            $company_id = (int) $db->lastInsertId();

            $db->insert('company_members', [
                'user_id' => (int) ($user['id'] ?? 0),
                'company_id' => $company_id,
                'department_id' => null,
                'title' => 'Owner',
                'role' => 'owner',
                'reports_to' => null,
            ]);

            // Безкоштовний Pro-тріал на 3 тижні для нової компанії
            $trial_plan = 'pro';
            $trial_days = 21;
            $trial_plan_info = SUBSCRIPTION_PLANS[$trial_plan];
            $db->insert('company_subscriptions', [
                'company_id' => $company_id,
                'plan' => $trial_plan,
                'status' => 'trial',
                'member_limit' => $trial_plan_info['member_limit'],
                'ai_bot_enabled' => $trial_plan_info['ai_bot'] ? 1 : 0,
                'price_usd' => 0.00,
                'paid_at' => null,
                'expires_at' => date('Y-m-d H:i:s', strtotime("+{$trial_days} days")),
                'cancelled_at' => null,
                'wfp_order_ref' => null,
            ]);

            $db->commit();

            $_SESSION['company_id'] = $company_id;
            flash('success', 'Компанію створено та активовано');
            redirect('/company/profile');
        } catch (\Throwable $e) {
            $db->rollback();
            flash('error', 'Не вдалося створити компанію');
            redirect('/company/create');
        }
    }

    /**
     * Перемкнути активну компанію
     */
    public function switch_company()
    {
        $user = get_user();
        $company_id = (int) post_param('company_id');
        $return_to = (string) post_param('return_to', '/dashboard');

        $companies = (new Company())->get_by_user((int) ($user['id'] ?? 0));
        $allowed_ids = array_map(function ($company) {
            return (int) ($company['id'] ?? 0);
        }, $companies);

        if (!in_array($company_id, $allowed_ids, true)) {
            flash('error', 'Недоступна компанія для перемикання');
            redirect('/dashboard');
        }

        $_SESSION['company_id'] = $company_id;

        if ($return_to !== '' && str_starts_with($return_to, '/')) {
            redirect($return_to);
        }

        redirect('/dashboard');
    }

    /**
     * Профіль компанії
     */
    public function profile()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/company/create');
        }

        $company_model = new Company();
        $company = $company_model->get_by_id($company_id);
        $employees = $company_model->get_employees($company_id);

        if (!$company) {
            flash('error', 'Компанія не знайдена');
            redirect('/dashboard');
        }

        require APP_PATH . '/Views/company/profile.php';
    }

    public function logs($id = null)
    {
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $company_model = new Company();
        $company = $company_model->get_by_id($company_id);
        $employees = $company_model->get_employees($company_id);
        $selected_user_id = $id !== null ? (int) $id : (int) get_param('user_id', 0);
        $search_query = trim((string) get_param('q', ''));

        $db = new Database();
        $this->ensureTelegramLogTable($db);

        $query = '
            SELECT l.*, u.first_name, u.last_name, u.email
            FROM telegram_ai_interaction_logs l
            LEFT JOIN users u ON u.id = l.app_user_id
            WHERE l.company_id = :company_id
        ';

        if ($selected_user_id > 0) {
            $query .= ' AND l.app_user_id = :user_id';
        }

        if ($search_query !== '') {
            $query .= ' AND (
                l.raw_text LIKE :search
                OR l.transcribed_text LIKE :search
                OR l.normalized_text LIKE :search
                OR l.bot_reply LIKE :search
                OR l.ai_parsed_json LIKE :search
                OR l.route_name LIKE :search
                OR l.route_reason LIKE :search
                OR l.execution_path LIKE :search
                OR l.command_names LIKE :search
            )';
        }

        $query .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 300';

        $statement = $db->query($query)->bind(':company_id', $company_id);
        if ($selected_user_id > 0) {
            $statement->bind(':user_id', $selected_user_id);
        }
        if ($search_query !== '') {
            $statement->bind(':search', '%' . $search_query . '%');
        }

        $logs = $statement->fetchAll();

        require APP_PATH . '/Views/company/logs.php';
    }

    /**
     * Оновити профіль компанії
     */
    public function update_profile()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $name = post_param('name');
        $description = post_param('description');

        if (!$name) {
            flash('error', 'Заповніть обов\'язкові поля');
            redirect('/company/profile');
        }

        $company_model = new Company();
        $company_model->update($company_id, [
            'name' => $name,
            'description' => $description,
        ]);

        flash('success', 'Профіль компанії оновлено');
        redirect('/company/profile');
    }

    /**
     * Форма додавання працівника
     */
    public function add_employee()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);

        require APP_PATH . '/Views/company/add-employee.php';
    }

    /**
     * Зберегти нового працівника
     */
    public function store_employee()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $first_name = post_param('first_name');
        $last_name = post_param('last_name');
        $email = post_param('email');
        $password = post_param('password');
        $title = post_param('title');
        $telegram_id_raw = post_param('telegram_id');
        $telegram_username = trim((string) post_param('telegram_username'));
        $reports_to_raw = post_param('reports_to');
        $reports_to = ($reports_to_raw !== null && $reports_to_raw !== '') ? (int) $reports_to_raw : null;
        $telegram_id = ($telegram_id_raw !== null && $telegram_id_raw !== '') ? (int) $telegram_id_raw : null;

        // Normalize telegram username: strip @, lowercase, validate
        $telegram_username = strtolower(ltrim($telegram_username, '@'));
        if ($telegram_username !== '' && !preg_match('/^[a-z0-9_]{3,32}$/', $telegram_username)) {
            $telegram_username = '';
        }

        if (!$first_name || !$email) {
            flash('error', 'Заповніть обов\'язкові поля');
            redirect('/company/add-employee');
        }

        $user_model = new User();

        // Перевірити, чи користувач уже існує
        $existing_user = $user_model->get_by_email($email);

        if ($existing_user) {
            $employee_id = $existing_user['id'];
        } else {
            // Створити нового користувача
            $result = $user_model->create([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password' => $password ?: substr(md5(time()), 0, 8),
                'telegram_id' => $telegram_id,
                'username' => $telegram_username !== '' ? $telegram_username : null,
            ]);

            // Отримати ID нового користувача
            if ($result) {
                // Переотримати користувача, щоб отримати ID
                $new_user = $user_model->get_by_email($email);
                $employee_id = $new_user['id'];
            } else {
                flash('error', 'Помилка створення користувача');
                redirect('/company/add-employee');
            }
        }

        if ($existing_user) {
            $user_model->update($employee_id, array_filter([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'telegram_id' => $telegram_id,
                'username' => $telegram_username !== '' ? $telegram_username : null,
            ], static fn($value) => $value !== null && $value !== ''));
        }

        // Додати як члена компанії
        $company_model = new Company();

        // Resolve reports_to user_id -> company_members.id
        $reports_to_member_id = null;
        if ($reports_to !== null && $reports_to > 0) {
            $db = new Database();
            $mgr = $db->query('SELECT id FROM company_members WHERE company_id = :cid AND user_id = :uid LIMIT 1')
                ->bind(':cid', $company_id)
                ->bind(':uid', $reports_to)
                ->fetch();
            $reports_to_member_id = $mgr ? (int) $mgr['id'] : null;
        }

        $company_model->add_employee($company_id, $employee_id, $title, 'member', $reports_to_member_id);

        flash('success', 'Працівник успішно додано');
        redirect('/company/profile');
    }

    /**
     * Форма редагування працівника
     */
    public function edit_employee($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $employee = array_values(array_filter($employees, fn($e) => $e['user_id'] == $id))[0] ?? null;

        if (!$employee) {
            flash('error', 'Працівник не знайдено');
            redirect('/company/profile');
        }

        $manager_options = array_values(array_filter($employees, function ($e) use ($id) {
            return (int) ($e['user_id'] ?? 0) !== (int) $id;
        }));

        require APP_PATH . '/Views/company/edit-employee.php';
    }

    /**
     * Оновити працівника
     */
    public function update_employee($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $title = post_param('title');
        $telegram_id_raw = post_param('telegram_id');
        $telegram_username = trim((string) post_param('telegram_username'));
        $reports_to_raw = post_param('reports_to');
        $reports_to = ($reports_to_raw !== null && $reports_to_raw !== '') ? (int) $reports_to_raw : null;
        $telegram_id = ($telegram_id_raw !== null && $telegram_id_raw !== '') ? (int) $telegram_id_raw : null;

        // Normalize telegram username: strip @, lowercase, validate
        $telegram_username = strtolower(ltrim($telegram_username, '@'));
        if ($telegram_username !== '' && !preg_match('/^[a-z0-9_]{3,32}$/', $telegram_username)) {
            $telegram_username = '';
        }

        // Prevent self-reports_to
        if ((int) $reports_to === (int) $id) {
            $reports_to = null;
        }

        // Resolve reports_to user_id -> company_members.id
        $reports_to_member_id = null;
        if ($reports_to !== null && $reports_to > 0) {
            $dbLookup = new Database();
            $mgr = $dbLookup->query('SELECT id FROM company_members WHERE company_id = :cid AND user_id = :uid LIMIT 1')
                ->bind(':cid', $company_id)
                ->bind(':uid', $reports_to)
                ->fetch();
            $reports_to_member_id = $mgr ? (int) $mgr['id'] : null;
        }

        $db = new Database();
        $db->query('UPDATE company_members SET title = :title, reports_to = :reports_to WHERE company_id = :company_id AND user_id = :user_id');
        $db->bind(':title', $title);
        $db->bind(':reports_to', $reports_to_member_id);
        $db->bind(':company_id', $company_id);
        $db->bind(':user_id', $id);
        $db->execute();

        $user_model = new User();
        $user_model->update($id, [
            'telegram_id' => $telegram_id,
            'username' => $telegram_username !== '' ? $telegram_username : null,
        ]);

        flash('success', 'Працівник оновлено');
        redirect('/company/profile');
    }

    /**
     * Видалити працівника
     */
    public function delete_employee($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $company_model = new Company();
        $company_model->remove_employee($company_id, $id);

        flash('success', 'Працівник видалено');
        redirect('/company/profile');
    }

    public function generate_onboarding($employeeId): void
    {
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            flash('error', 'Компанію не знайдено.');
            redirect('/company/profile');
        }

        $db = new Database();

        // Verify employee belongs to this company
        $member = $db->query('SELECT user_id FROM company_members WHERE company_id = :cid AND user_id = :uid')
            ->bind(':cid', $company_id)
            ->bind(':uid', (int) $employeeId)
            ->fetch();

        if (!$member) {
            flash('error', 'Працівника не знайдено в цій компанії.');
            redirect('/company/profile');
        }

        $links = $this->createOnboardingLinks($db, (int) $employeeId, (int) $company_id);

        flash(
            'success',
            "Посилання для онбордінгу (дійсне 1 годину):\n" .
            "🌐 Веб: " . $links['web_link'] . "\n" .
            "📱 Telegram бот: " . $links['telegram_link']
        );
        redirect('/company/profile');
    }

    public function send_onboarding_email($employeeId): void
    {
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            flash('error', 'Компанію не знайдено.');
            redirect('/company/profile');
        }

        $db = new Database();
        $employee = $db->query(
            'SELECT u.id, u.first_name, u.last_name, u.email, c.name AS company_name
             FROM company_members cm
             JOIN users u ON u.id = cm.user_id
             JOIN companies c ON c.id = cm.company_id
             WHERE cm.company_id = :cid AND cm.user_id = :uid
             LIMIT 1'
        )
            ->bind(':cid', (int) $company_id)
            ->bind(':uid', (int) $employeeId)
            ->fetch();

        if (!$employee) {
            flash('error', 'Працівника не знайдено в цій компанії.');
            redirect('/company/profile');
        }

        $email = trim((string) ($employee['email'] ?? ''));
        if ($email === '') {
            flash('error', 'У співробітника немає email для відправки.');
            redirect('/company/profile');
        }

        $links = $this->createOnboardingLinks($db, (int) $employeeId, (int) $company_id);
        $fullName = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
        $greetingName = $fullName !== '' ? $fullName : 'колего';
        $companyName = trim((string) ($employee['company_name'] ?? ''));
        $subject = 'Онбординг у FINEKO' . ($companyName !== '' ? ' для компанії ' . $companyName : '');
        $webLink = $links['web_link'];
        $telegramLink = $links['telegram_link'];

        $htmlMessage = '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;">'
            . '<p>Привіт, ' . htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p>Для вас підготовлено онбординг у FINEKO' . ($companyName !== '' ? ' для компанії <strong>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong>' : '') . '.</p>'
            . '<p>Щоб завершити підключення, скористайтеся одним із варіантів:</p>'
            . '<ul>'
            . '<li><a href="' . htmlspecialchars($webLink, ENT_QUOTES, 'UTF-8') . '">Відкрити веб-онбординг</a></li>'
            . '<li><a href="' . htmlspecialchars($telegramLink, ENT_QUOTES, 'UTF-8') . '">Прив’язати Telegram через бота</a></li>'
            . '</ul>'
            . '<p>Посилання дійсні протягом 1 години.</p>'
            . '<p>Після активації ви зможете входити в систему, працювати із задачами та користуватись ботом.</p>'
            . '<p>З повагою,<br>команда FINEKO</p>'
            . '</body></html>';

        $textMessage = "Привіт, {$greetingName}.\n\n"
            . "Для вас підготовлено онбординг у FINEKO" . ($companyName !== '' ? " для компанії {$companyName}" : '') . ".\n\n"
            . "Веб-онбординг: {$webLink}\n"
            . "Telegram бот: {$telegramLink}\n\n"
            . "Посилання дійсні протягом 1 години.\n\n"
            . "З повагою,\nкоманда FINEKO";

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: FINEKO <no-reply@fineko.space>',
        ];

        $sent = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlMessage, implode("\r\n", $headers));

        if (!$sent) {
            flash(
                'error',
                "Не вдалося відправити лист. Спробуйте ще раз або використайте посилання вручну:\n" .
                "🌐 Веб: {$webLink}\n" .
                "📱 Telegram бот: {$telegramLink}"
            );
            redirect('/company/profile');
        }

        flash('success', 'Онбординг-лист відправлено на ' . $email);
        redirect('/company/profile');
    }

    private function createOnboardingLinks(Database $db, int $employeeId, int $companyId): array
    {

        // Ensure auth_tokens table exists
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

        // Delete old onboarding tokens for this user
        $db->query("DELETE FROM auth_tokens WHERE user_id = :uid AND type IN ('onboarding', 'tg_onboarding')")
            ->bind(':uid', (int) $employeeId)
            ->execute();

        // Generate token
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 32; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        $token = 'ONBOARD-' . $code;
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $db->insert('auth_tokens', [
            'token' => $token,
            'user_id' => (int) $employeeId,
            'company_id' => (int) $companyId,
            'type' => 'onboarding',
            'expires_at' => $expiresAt,
        ]);

        // Generate Telegram deep link token for auto-linking
        $tgCode = '';
        for ($i = 0; $i < 16; $i++) {
            $tgCode .= $alphabet[random_int(0, $max)];
        }

        $db->insert('auth_tokens', [
            'token' => 'TGONB-' . $tgCode,
            'user_id' => (int) $employeeId,
            'company_id' => (int) $companyId,
            'type' => 'tg_onboarding',
            'expires_at' => $expiresAt,
        ]);

        $link = rtrim(APP_URL, '/') . '/auth/onboard/' . $code;
        $tgLink = 'https://t.me/' . TELEGRAM_BOT_USERNAME . '?start=TGONB_' . $tgCode;

        return [
            'web_link' => $link,
            'telegram_link' => $tgLink,
            'expires_at' => $expiresAt,
        ];
    }

    private function ensureTelegramLogTable(Database $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS telegram_ai_interaction_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            chat_id BIGINT NOT NULL,
            telegram_message_id BIGINT NULL,
            telegram_user_id BIGINT NULL,
            app_user_id INT NULL,
            company_id INT NULL,
            chat_type VARCHAR(20) NOT NULL DEFAULT 'private',
            message_kind VARCHAR(20) NOT NULL DEFAULT 'text',
            raw_text MEDIUMTEXT NULL,
            transcribed_text MEDIUMTEXT NULL,
            normalized_text MEDIUMTEXT NULL,
            ai_recent_context MEDIUMTEXT NULL,
            ai_raw_response LONGTEXT NULL,
            ai_parsed_json LONGTEXT NULL,
            route_name VARCHAR(80) NULL,
            route_confidence VARCHAR(20) NULL,
            route_reason VARCHAR(255) NULL,
            execution_path VARCHAR(80) NULL,
            command_names TEXT NULL,
            bot_reply LONGTEXT NULL,
            audio_error TEXT NULL,
            raw_update_json LONGTEXT NULL,
            processing_status VARCHAR(50) NOT NULL DEFAULT 'received',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_telegram_ai_logs_chat_created (chat_id, created_at),
            INDEX idx_telegram_ai_logs_company_created (company_id, created_at),
            INDEX idx_telegram_ai_logs_app_user_created (app_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

        $columns = $db->query('SHOW COLUMNS FROM telegram_ai_interaction_logs')->fetchAll(\PDO::FETCH_ASSOC);
        $existing = array_map(static function ($row) {
            return (string) ($row['Field'] ?? '');
        }, $columns);

        if (!in_array('execution_path', $existing, true)) {
            $db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN execution_path VARCHAR(80) NULL AFTER ai_parsed_json')->execute();
        }

        if (!in_array('route_name', $existing, true)) {
            $db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_name VARCHAR(80) NULL AFTER ai_parsed_json')->execute();
        }

        if (!in_array('route_confidence', $existing, true)) {
            $db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_confidence VARCHAR(20) NULL AFTER route_name')->execute();
        }

        if (!in_array('route_reason', $existing, true)) {
            $db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN route_reason VARCHAR(255) NULL AFTER route_confidence')->execute();
        }

        if (!in_array('command_names', $existing, true)) {
            $db->query('ALTER TABLE telegram_ai_interaction_logs ADD COLUMN command_names TEXT NULL AFTER execution_path')->execute();
        }
    }
}
