<?php
/**
 * Контролер глобального пошуку
 */

namespace App\Controllers;

use App\Models\Database;

class SearchController
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * GET /search?q=...  — повертає JSON з результатами пошуку
     */
    public function search(): void
    {
        $user = get_user();
        $company_id = (int) ($_SESSION['company_id'] ?? 0);

        if (!$user || $company_id <= 0) {
            json_response(['results' => []]);
        }

        $q = trim((string) get_param('q', ''));

        if (mb_strlen($q) < 2) {
            json_response(['results' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $results = [];

        // --- Задачі ---
        $tasks = $this->db
            ->query('
                SELECT t.id, t.title, t.status, t.due_date,
                       a.first_name AS af, a.last_name AS al,
                       rs.title AS result_title
                FROM tasks t
                LEFT JOIN users a ON t.assignee_id = a.id
                LEFT JOIN results rs ON t.result_id = rs.id
                WHERE t.company_id = :cid
                  AND (t.title LIKE :q OR t.description LIKE :q OR t.expected_result LIKE :q)
                ORDER BY t.due_date DESC, t.created_at DESC
                LIMIT 12
            ')
            ->bind(':cid', $company_id)
            ->bind(':q', $like)
            ->fetchAll();

        foreach ($tasks as $task) {
            $assignee = trim((string) ($task['af'] ?? '') . ' ' . (string) ($task['al'] ?? ''));
            $meta_parts = [];
            if ($assignee) {
                $meta_parts[] = $assignee;
            }
            if (!empty($task['due_date'])) {
                $meta_parts[] = date('d.m.Y', strtotime($task['due_date']));
            }
            if (!empty($task['result_title'])) {
                $meta_parts[] = 'Ціль: ' . $task['result_title'];
            }

            $results[] = [
                'type' => 'task',
                'title' => (string) ($task['title'] ?? ''),
                'meta' => implode(' · ', $meta_parts),
                'url' => '/tasks/view/' . (int) $task['id'],
            ];
        }

        // --- Цілі ---
        $goals = $this->db
            ->query('
                SELECT r.id, r.title, r.status,
                       a.first_name AS af, a.last_name AS al
                FROM results r
                LEFT JOIN users a ON r.assignee_id = a.id
                WHERE r.company_id = :cid
                  AND (r.title LIKE :q OR r.description LIKE :q)
                ORDER BY r.created_at DESC
                LIMIT 8
            ')
            ->bind(':cid', $company_id)
            ->bind(':q', $like)
            ->fetchAll();

        foreach ($goals as $goal) {
            $assignee = trim((string) ($goal['af'] ?? '') . ' ' . (string) ($goal['al'] ?? ''));
            $meta_parts = $assignee ? [$assignee] : [];
            if (!empty($goal['status'])) {
                $statusMap = ['in-progress' => 'В процесі', 'done' => 'Завершено', 'todo' => 'Нова', 'postponed' => 'Відкладено'];
                $meta_parts[] = $statusMap[$goal['status']] ?? $goal['status'];
            }

            $results[] = [
                'type' => 'goal',
                'title' => (string) ($goal['title'] ?? ''),
                'meta' => implode(' · ', $meta_parts),
                'url' => '/results',
            ];
        }

        // --- Шаблони ---
        $templates = $this->db
            ->query('
                SELECT t.id, t.name, t.description
                FROM templates t
                WHERE t.company_id = :cid
                  AND (t.name LIKE :q OR t.description LIKE :q)
                ORDER BY t.updated_at DESC
                LIMIT 6
            ')
            ->bind(':cid', $company_id)
            ->bind(':q', $like)
            ->fetchAll();

        foreach ($templates as $tpl) {
            $results[] = [
                'type' => 'template',
                'title' => (string) ($tpl['name'] ?? ''),
                'meta' => mb_strimwidth((string) ($tpl['description'] ?? ''), 0, 80, '…'),
                'url' => '/templates',
            ];
        }

        json_response(['results' => $results]);
    }
}
