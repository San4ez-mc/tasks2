<?php
/**
 * Модель для компаній
 */

namespace App\Models;

class Company
{
    private $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Отримати компанію за ID
     */
    public function get_by_id($id)
    {
        return $this->db
            ->query('SELECT * FROM companies WHERE id = :id')
            ->bind(':id', $id)
            ->fetch();
    }

    /**
     * Отримати всі компанії користувача
     */
    public function get_by_user($user_id)
    {
        return $this->db
            ->query('
                SELECT c.* FROM companies c
                JOIN company_members cm ON c.id = cm.company_id
                WHERE cm.user_id = :user_id
            ')
            ->bind(':user_id', $user_id)
            ->fetchAll();
    }

    /**
     * Створити компанію
     */
    public function create($data)
    {
        return $this->db->insert('companies', [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
        ]);
    }

    /**
     * Оновити компанію
     */
    public function update($id, $data)
    {
        return $this->db->update('companies', $id, $data);
    }

    /**
     * Видалити компанію
     */
    public function delete($id)
    {
        return $this->db->delete('companies', $id);
    }

    /**
     * Отримати працівників компанії
     */
    public function get_employees($company_id)
    {
        return $this->db
            ->query('
                SELECT cm.id AS company_member_id,
                       cm.company_id,
                       cm.user_id,
                       cm.title,
                       cm.role,
                       cm.reports_to AS reports_to_member_id,
                       mgr_cm.user_id AS reports_to,
                       u.first_name, u.last_name, u.email, u.phone_number,
                       u.telegram_id, u.username,
                       mgr.first_name as manager_first_name,
                       mgr.last_name as manager_last_name
                FROM company_members cm
                JOIN users u ON cm.user_id = u.id
                LEFT JOIN company_members mgr_cm ON mgr_cm.id = cm.reports_to
                LEFT JOIN users mgr ON mgr.id = mgr_cm.user_id
                WHERE cm.company_id = :company_id
            ')
            ->bind(':company_id', $company_id)
            ->fetchAll();
    }

    /**
     * Додати працівника
     */
    public function add_employee($company_id, $user_id, $title = null, $role = 'member', $reports_to = null)
    {
        return $this->db->insert('company_members', [
            'company_id' => $company_id,
            'user_id' => $user_id,
            'title' => $title,
            'role' => $role,
            'reports_to' => $reports_to,
        ]);
    }

    /**
     * Видалити працівника
     */
    public function remove_employee($company_id, $user_id)
    {
        $this->db->query('DELETE FROM company_members WHERE company_id = :company_id AND user_id = :user_id');
        $this->db->bind(':company_id', $company_id);
        $this->db->bind(':user_id', $user_id);
        return $this->db->execute();
    }
}
