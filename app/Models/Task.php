<?php
/**
 * Модель для завдань
 */

namespace App\Models;

class Task
{
    private $db;
    private ?array $columns = null;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Отримати завдання за ID
     */
    public function get_by_id($id)
    {
        return $this->db
            ->query('
                SELECT t.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name, a.email as assignee_email,
                       r.first_name as reporter_first_name, r.last_name as reporter_last_name, r.email as reporter_email,
                      c.name as company_name,
                      rs.title as result_title,
                      tp.name as template_name
                FROM tasks t
                LEFT JOIN users a ON t.assignee_id = a.id
                LEFT JOIN users r ON t.reporter_id = r.id
                JOIN companies c ON t.company_id = c.id
                  LEFT JOIN results rs ON t.result_id = rs.id
                  LEFT JOIN templates tp ON t.template_id = tp.id
                WHERE t.id = :id
            ')
            ->bind(':id', $id)
            ->fetch();
    }

    /**
     * Отримати всі завдання компанії
     */
    public function get_by_company($company_id)
    {
        return $this->db
            ->query('
                SELECT t.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                      r.first_name as reporter_first_name, r.last_name as reporter_last_name,
                      rs.title as result_title,
                      tp.name as template_name
                FROM tasks t
                LEFT JOIN users a ON t.assignee_id = a.id
                LEFT JOIN users r ON t.reporter_id = r.id
                  LEFT JOIN results rs ON t.result_id = rs.id
                  LEFT JOIN templates tp ON t.template_id = tp.id
                WHERE t.company_id = :company_id
                ORDER BY t.created_at DESC
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Отримати завдання користувача
     */
    public function get_by_user($user_id, $company_id)
    {
        return $this->db
            ->query('
                SELECT t.*, 
                       a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                      r.first_name as reporter_first_name, r.last_name as reporter_last_name,
                      rs.title as result_title,
                      tp.name as template_name
                FROM tasks t
                LEFT JOIN users a ON t.assignee_id = a.id
                LEFT JOIN users r ON t.reporter_id = r.id
                  LEFT JOIN results rs ON t.result_id = rs.id
                  LEFT JOIN templates tp ON t.template_id = tp.id
                WHERE (t.assignee_id = :user_id OR t.reporter_id = :user_id) 
                AND t.company_id = :company_id
                ORDER BY t.due_date ASC, t.created_at DESC
            ')
            ->bind(':user_id', $user_id)
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Створити завдання
     */
    public function create($data)
    {
        $payload = [
            'title' => $data['title'],
            'company_id' => $data['company_id'],
            'assignee_id' => $data['assignee_id'],
            'reporter_id' => $data['reporter_id'],
            'accepted_at' => $data['accepted_at'] ?? null,
            'status' => $data['status'] ?? 'todo',
            'due_date' => $data['due_date'] ?? null,
            'description' => $data['description'] ?? null,
            'expected_result' => $data['expected_result'] ?? null,
            'actual_result' => $data['actual_result'] ?? null,
            'type' => $data['type'] ?? 'important-urgent',
            'expected_time' => $data['expected_time'] ?? null,
            'actual_time' => $data['actual_time'] ?? null,
            'result_id' => $data['result_id'] ?? null,
            'template_id' => $data['template_id'] ?? null,
        ];

        return $this->db->insert('tasks', $this->filter_existing_columns($payload));
    }

    /**
     * Оновити завдання
     */
    public function update($id, $data)
    {
        $payload = $this->filter_existing_columns($data);

        if (empty($payload)) {
            return false;
        }

        return $this->db->update('tasks', $id, $payload);
    }

    /**
     * Видалити завдання
     */
    public function delete($id)
    {
        return $this->db->delete('tasks', $id);
    }

    /**
     * Отримати прострочені невиконані задачі для конкретного користувача
     */
    public function getOverdueForUser(int $company_id, int $user_id): array
    {
        return $this->db
            ->query('
                SELECT t.id, t.title, t.status, t.due_date, t.expected_time, t.type,
                       t.description, t.expected_result,
                       t.result_id,
                       rs.title AS result_title
                FROM tasks t
                LEFT JOIN results rs ON rs.id = t.result_id
                WHERE t.company_id = :company_id
                  AND t.assignee_id = :user_id
                  AND DATE(t.due_date) >= \'2026-04-27\'
                  AND DATE(t.due_date) < CURDATE()
                  AND COALESCE(t.status, \'todo\') NOT IN (\'done\', \'completed\', \'postponed\')
                ORDER BY t.due_date ASC
            ')
            ->bind(':company_id', $company_id)
            ->bind(':user_id', $user_id)
            ->fetchAll();
    }

    public function repairEmptyTitlesFromWeeklyPlanItems(int $companyId, ?string $startDate = null, ?string $endDate = null): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $sql = '
                        SELECT t.id AS task_id,
                                     wpi.id AS item_id,
                                     t.title AS task_title,
                                     wpi.title AS item_title,
                                     tpl.name AS template_name
            FROM tasks t
            JOIN weekly_plan_items wpi ON wpi.linked_task_id = t.id
                        LEFT JOIN templates tpl ON t.template_id = tpl.id
            WHERE t.company_id = :company_id
                            AND (
                                        t.title IS NULL OR TRIM(t.title) = ""
                                        OR wpi.title IS NULL OR TRIM(wpi.title) = ""
                            )';

        if ($startDate !== null) {
            $sql .= ' AND DATE(t.due_date) >= :start_date';
        }

        if ($endDate !== null) {
            $sql .= ' AND DATE(t.due_date) <= :end_date';
        }

        $stmt = $this->db->query($sql)->bind(':company_id', $companyId);
        if ($startDate !== null) {
            $stmt->bind(':start_date', $startDate);
        }
        if ($endDate !== null) {
            $stmt->bind(':end_date', $endDate);
        }

        $rows = $stmt->fetchAll();
        $repaired = 0;

        foreach ($rows as $row) {
            $taskId = (int) ($row['task_id'] ?? 0);
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($taskId <= 0 || $itemId <= 0) {
                continue;
            }

            $taskTitle = trim((string) ($row['task_title'] ?? ''));
            $itemTitle = trim((string) ($row['item_title'] ?? ''));
            $templateName = trim((string) ($row['template_name'] ?? ''));
            $resolvedTitle = $itemTitle !== ''
                ? $itemTitle
                : ($taskTitle !== ''
                    ? $taskTitle
                    : ($templateName !== '' ? $templateName : ('Задача #' . $taskId)));
            $resolvedTitle = mb_substr($resolvedTitle, 0, 255);

            $updated = false;
            if ($taskTitle === '' && $this->db->update('tasks', $taskId, ['title' => $resolvedTitle])) {
                $updated = true;
            }

            if ($itemTitle === '' && $this->db->update('weekly_plan_items', $itemId, ['title' => $resolvedTitle])) {
                $updated = true;
            }

            if ($updated) {
                $repaired++;
            }
        }

        return $repaired;
    }

    private function filter_existing_columns(array $data): array
    {
        $columns = $this->get_columns();
        $filtered = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function get_columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $rows = $this->db->query('SHOW COLUMNS FROM tasks')->fetchAll();
        $this->columns = array_map(static fn($row) => $row['Field'], $rows);

        return $this->columns;
    }
}