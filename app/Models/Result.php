<?php
/**
 * Модель для результатів
 */

namespace App\Models;

class Result
{
    private $db;
    private ?array $columns = null;

    public function __construct()
    {
        $this->db = new Database();
        $this->ensureSchema();
    }

    /**
     * Отримати результат за ID
     */
    public function get_by_id($id)
    {
        return $this->db
            ->query('
                SELECT r.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name, a.email as assignee_email,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name, rp.email as reporter_email,
                       c.name as company_name
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                LEFT JOIN users rp ON r.reporter_id = rp.id
                JOIN companies c ON r.company_id = c.id
                WHERE r.id = :id
            ')
            ->bind(':id', $id)
            ->fetch();
    }

    /**
     * Отримати всі результати компанії
     */
    public function get_by_company($company_id)
    {
        return $this->db
            ->query('
                SELECT r.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                LEFT JOIN users rp ON r.reporter_id = rp.id
                WHERE r.company_id = :company_id AND r.parent_id IS NULL
                ORDER BY r.created_at DESC
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Отримати результати користувача
     */
    public function get_by_user($user_id, $company_id)
    {
        return $this->db
            ->query('
                SELECT r.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                LEFT JOIN users rp ON r.reporter_id = rp.id
                WHERE (r.assignee_id = :user_id OR r.reporter_id = :user_id) 
                AND r.company_id = :company_id AND r.parent_id IS NULL
                ORDER BY r.created_at DESC
            ')
            ->bind(':user_id', $user_id)
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Отримати всі підрезультати компанії, згруповані за parent_id
     */
    public function get_all_sub_results($company_id)
    {
        return $this->db
            ->query('
                SELECT r.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                LEFT JOIN users rp ON r.reporter_id = rp.id
                WHERE r.company_id = :company_id AND r.parent_id IS NOT NULL
                ORDER BY r.parent_id ASC, r.created_at ASC
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Отримати підрезультати
     */
    public function get_children($parent_id)
    {
        return $this->db
            ->query('
                SELECT r.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                LEFT JOIN users rp ON r.reporter_id = rp.id
                WHERE r.parent_id = :parent_id
                ORDER BY r.created_at DESC
            ')
            ->bind(':parent_id', $parent_id)
            ->fetchAll();
    }

    /**
     * Створити результат
     */
    public function create($data)
    {
        return $this->db->insert('results', $this->filterExistingColumns([
            'title' => $data['title'],
            'company_id' => $data['company_id'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'reporter_id' => $data['reporter_id'],
            'description' => $data['description'] ?? null,
            'expected_result' => $data['expected_result'] ?? null,
            'instruction' => $data['instruction'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'status' => $data['status'] ?? 'in-progress',
            'parent_id' => $data['parent_id'] ?? null,
            'completed' => $data['completed'] ?? 0,
        ]));
    }

    public function lastInsertId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    /**
     * Оновити результат
     */
    public function update($id, $data)
    {
        $payload = $this->filterExistingColumns($data);

        if (empty($payload)) {
            return false;
        }

        return $this->db->update('results', $id, $payload);
    }

    /**
     * Видалити результат
     */
    public function delete($id)
    {
        return $this->db->delete('results', $id);
    }

    private function ensureSchema(): void
    {
        $columns = $this->getColumns();
        if (!in_array('expected_result', $columns, true)) {
            $this->db->query('ALTER TABLE results ADD COLUMN expected_result TEXT NULL AFTER description')->execute();
            $this->columns = null;
        }
    }

    private function filterExistingColumns(array $data): array
    {
        $columns = $this->getColumns();
        $filtered = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function getColumns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $rows = $this->db->query('SHOW COLUMNS FROM results')->fetchAll();
        $this->columns = array_map(static fn($row) => $row['Field'], $rows);

        return $this->columns;
    }
}