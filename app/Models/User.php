<?php
/**
 * Модель для користувачів
 */

namespace App\Models;

class User {
    private $db;
    private $users_columns;

    public function __construct() {
        $this->db = new Database();
        $this->users_columns = null;
    }

    private function get_users_columns() {
        if ($this->users_columns !== null) {
            return $this->users_columns;
        }

        $rows = $this->db->query('SHOW COLUMNS FROM users')->fetchAll();
        $this->users_columns = array_map(function ($row) {
            return $row['Field'];
        }, $rows);

        return $this->users_columns;
    }

    private function has_column($column) {
        return in_array($column, $this->get_users_columns(), true);
    }

    /**
     * Знайти користувача за email
     */
    public function get_by_email($email) {
        return $this->db
            ->query('SELECT * FROM users WHERE email = :email')
            ->bind(':email', $email)
            ->fetch();
    }

    /**
     * Знайти користувача за ID
     */
    public function get_by_id($id) {
        return $this->db
            ->query('SELECT * FROM users WHERE id = :id')
            ->bind(':id', $id)
            ->fetch();
    }

    /**
     * Знайти користувача за Telegram ID
     */
    public function get_by_telegram($telegram_id) {
        return $this->db
            ->query('SELECT * FROM users WHERE telegram_id = :telegram_id')
            ->bind(':telegram_id', $telegram_id)
            ->fetch();
    }

    /**
     * Створити користувача
     */
    public function create($data) {
        $payload = [
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'email' => $data['email'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'telegram_id' => $data['telegram_id'] ?? null,
            'username' => $data['username'] ?? null,
        ];

        if ($this->has_column('password')) {
            $payload['password'] = !empty($data['password'])
                ? password_hash($data['password'], PASSWORD_BCRYPT)
                : null;
        }

        return $this->db->insert('users', $payload);
    }

    /**
     * Оновити користувача
     */
    public function update($id, $data) {
        $update_data = [];
        foreach ($data as $key => $value) {
            if ($key === 'password' && $value) {
                if ($this->has_column('password')) {
                    $update_data[$key] = password_hash($value, PASSWORD_BCRYPT);
                }
            } elseif ($key !== 'password') {
                $update_data[$key] = $value;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        return $this->db->update('users', $id, $update_data);
    }

    /**
     * Перевірити пароль
     */
    public function verify_password($plain_password, $hashed_password) {
        if (!$this->has_column('password')) {
            return false;
        }

        if (empty($hashed_password)) {
            return false;
        }

        return password_verify($plain_password, $hashed_password);
    }

    /**
     * Отримати всіх користувачів компанії
     */
    public function get_by_company($company_id) {
        return $this->db
            ->query('
                SELECT u.* FROM users u
                JOIN company_members cm ON u.id = cm.user_id
                WHERE cm.company_id = :company_id
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }
}
