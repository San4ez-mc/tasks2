<?php
/**
 * Модель для шаблонів задач
 */

namespace App\Models;

class Template {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Отримати шаблон за ID
     */
    public function get_by_id($id) {
        return $this->db
            ->query('
                SELECT t.*,
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM templates t
                LEFT JOIN users a  ON t.assignee_id  = a.id
                LEFT JOIN users rp ON t.reporter_id  = rp.id
                WHERE t.id = :id
            ')
            ->bind(':id', $id)
            ->fetch();
    }

    /**
     * Отримати всі шаблони компанії
     */
    public function get_by_company($company_id) {
        return $this->db
            ->query('
                SELECT t.*,
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM templates t
                LEFT JOIN users a  ON t.assignee_id  = a.id
                LEFT JOIN users rp ON t.reporter_id  = rp.id
                WHERE t.company_id = :company_id
                ORDER BY t.updated_at DESC
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    public function get_visible_by_user($company_id, $user_id, bool $includeAll = false) {
        if ($includeAll) {
            return $this->get_by_company($company_id);
        }

        return $this->db
            ->query('
                SELECT t.*,
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                       rp.first_name as reporter_first_name, rp.last_name as reporter_last_name
                FROM templates t
                LEFT JOIN users a  ON t.assignee_id  = a.id
                LEFT JOIN users rp ON t.reporter_id  = rp.id
                WHERE t.company_id = :company_id
                                    AND (
                                            t.reporter_id = :user_id
                                            OR t.assignee_id = :user_id
                                            OR FIND_IN_SET(CAST(:user_id AS CHAR), REPLACE(COALESCE(t.assignee_ids, ""), " ", "")) > 0
                                    )
                ORDER BY t.updated_at DESC
            ')
            ->bind(':company_id', $company_id)
            ->bind(':user_id', $user_id)
            ->fetchAll();
    }

    public function is_visible_to_user(array $template, int $userId, bool $includeAll = false): bool
    {
        if ($includeAll) {
            return true;
        }

        return (int) ($template['reporter_id'] ?? 0) === $userId
            || (int) ($template['assignee_id'] ?? 0) === $userId
            || $this->assigneeListContainsUser($template['assignee_ids'] ?? null, $userId);
    }

    /**
     * Створити шаблон
     */
    public function create($data) {
        return $this->db->insert('templates', [
            'company_id'      => $data['company_id'],
            'name'            => $data['name'],
            'type'            => $data['type'] ?? null,
            'description'     => $data['description'] ?? null,
            'expected_result' => $data['expected_result'] ?? null,
            'assignee_id'     => $data['assignee_id'] ?? null,
            'assignee_ids'    => $data['assignee_ids'] ?? null,
            'reporter_id'     => $data['reporter_id'] ?? null,
            'expected_time'   => $data['expected_time'] ?? null,
            'repeat_type'     => $data['repeat_type'] ?? 'none',
            'repeat_day'      => $data['repeat_day'] ?? null,
            'start_time'      => $data['start_time'] ?? null,
        ]);
    }

    /**
     * Оновити шаблон
     */
    public function update($id, $data) {
        return $this->db->update('templates', $id, $data);
    }

    /**
     * Видалити шаблон
     */
    public function delete($id) {
        return $this->db->delete('templates', $id);
    }

    /**
     * Збільшити лічильник використань
     */
    public function increment_count($id) {
        return $this->db
            ->query('UPDATE templates SET created_count = created_count + 1 WHERE id = :id')
            ->bind(':id', $id)
            ->execute();
    }

    private function assigneeListContainsUser($assigneeIdsRaw, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $raw = trim((string) $assigneeIdsRaw);
        if ($raw === '') {
            return false;
        }

        $parts = array_map('trim', explode(',', $raw));
        foreach ($parts as $part) {
            if ((int) $part === $userId) {
                return true;
            }
        }

        return false;
    }
}
