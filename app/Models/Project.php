<?php

namespace App\Models;

class Project
{
    private $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function get_by_company(int $company_id): array
    {
        return $this->db
            ->query('
                SELECT p.*,
                       u.first_name as creator_first_name, u.last_name as creator_last_name,
                       COUNT(pm.id) as member_count
                FROM projects p
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN project_members pm ON pm.project_id = p.id
                WHERE p.company_id = :company_id
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    public function get_by_id(int $id): ?array
    {
        $project = $this->db
            ->query('SELECT * FROM projects WHERE id = :id')
            ->bind(':id', $id)
            ->fetch();
        return $project ?: null;
    }

    public function get_members(int $project_id): array
    {
        return $this->db
            ->query('
                SELECT u.id, u.first_name, u.last_name, u.email
                FROM project_members pm
                JOIN users u ON u.id = pm.user_id
                WHERE pm.project_id = :project_id
                ORDER BY u.first_name, u.last_name
            ')
            ->bind(':project_id', $project_id)
            ->fetchAll();
    }

    public function create(int $company_id, int $created_by, string $name, string $description): int
    {
        $this->db
            ->query('INSERT INTO projects (company_id, created_by, name, description) VALUES (:company_id, :created_by, :name, :description)')
            ->bind(':company_id', $company_id)
            ->bind(':created_by', $created_by)
            ->bind(':name', $name)
            ->bind(':description', $description)
            ->execute();
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $description): void
    {
        $this->db
            ->query('UPDATE projects SET name = :name, description = :description WHERE id = :id')
            ->bind(':name', $name)
            ->bind(':description', $description)
            ->bind(':id', $id)
            ->execute();
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM project_members WHERE project_id = :id')->bind(':id', $id)->execute();
        $this->db->query('DELETE FROM projects WHERE id = :id')->bind(':id', $id)->execute();
    }

    public function set_members(int $project_id, array $user_ids): void
    {
        $this->db->query('DELETE FROM project_members WHERE project_id = :pid')->bind(':pid', $project_id)->execute();
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0)
                continue;
            $this->db
                ->query('INSERT IGNORE INTO project_members (project_id, user_id) VALUES (:pid, :uid)')
                ->bind(':pid', $project_id)
                ->bind(':uid', $uid)
                ->execute();
        }
    }

    public function get_tasks(int $project_id): array
    {
        return $this->db
            ->query('
                SELECT t.*,
                       u.first_name AS assignee_first_name, u.last_name AS assignee_last_name
                FROM tasks t
                LEFT JOIN users u ON u.id = t.assignee_id
                WHERE t.project_id = :project_id
                ORDER BY t.created_at DESC
            ')
            ->bind(':project_id', $project_id)
            ->fetchAll();
    }

    public function get_results_by_project(int $project_id): array
    {
        return $this->db
            ->query('
                SELECT DISTINCT r.*,
                       a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
                FROM results r
                JOIN tasks t ON t.result_id = r.id
                LEFT JOIN users a ON a.id = r.assignee_id
                WHERE t.project_id = :project_id
                ORDER BY r.created_at DESC
            ')
            ->bind(':project_id', $project_id)
            ->fetchAll();
    }

    /** Projects visible to a given user (member or creator) */
    public function get_visible_by_user(int $company_id, int $user_id): array
    {
        return $this->db
            ->query('
                SELECT DISTINCT p.id, p.name
                FROM projects p
                LEFT JOIN project_members pm ON pm.project_id = p.id
                WHERE p.company_id = :company_id
                  AND (p.created_by = :uid OR pm.user_id = :uid2)
                ORDER BY p.name
            ')
            ->bind(':company_id', $company_id)
            ->bind(':uid', $user_id)
            ->bind(':uid2', $user_id)
            ->fetchAll();
    }
}
