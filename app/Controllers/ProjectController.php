<?php

namespace App\Controllers;

use App\Models\Project;
use App\Models\Company;

class ProjectController
{
    private function requireCompany(): int
    {
        $company_id = $_SESSION['company_id'] ?? null;
        if (!$company_id) {
            redirect('/dashboard');
        }
        return (int) $company_id;
    }

    public function index(): void
    {
        $company_id = $this->requireCompany();
        $model = new Project();
        $projects = $model->get_by_company($company_id);

        $flash_success = flash('success');
        $flash_error = flash('error');

        require APP_PATH . '/Views/projects/index.php';
    }

    public function create(): void
    {
        $this->requireCompany();
        $company_id = (int) ($_SESSION['company_id']);
        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);

        require APP_PATH . '/Views/projects/create.php';
    }

    public function create_post(): void
    {
        $company_id = $this->requireCompany();
        $user = get_user();

        $name = trim(post_param('name'));
        $description = trim(post_param('description'));
        $member_ids = $_POST['member_ids'] ?? [];

        if ($name === '') {
            flash('error', 'Назва проекту обов\'язкова');
            redirect('/projects/create');
        }

        $model = new Project();
        $project_id = $model->create($company_id, (int) $user['id'], $name, $description);

        // Always add creator as member
        $member_ids[] = (int) $user['id'];
        $model->set_members($project_id, array_map('intval', $member_ids));

        flash('success', 'Проект створено');
        redirect('/projects');
    }

    public function view(int $id): void
    {
        $company_id = $this->requireCompany();
        $model = new Project();
        $project = $model->get_by_id($id);

        if (!$project || (int) $project['company_id'] !== $company_id) {
            not_found();
        }

        $members = $model->get_members($id);
        $tasks   = $model->get_tasks($id);
        $results = $model->get_results_by_project($id);

        require APP_PATH . '/Views/projects/view.php';
    }

    public function edit(int $id): void
    {
        $company_id = $this->requireCompany();
        $model = new Project();
        $project = $model->get_by_id($id);

        if (!$project || (int) $project['company_id'] !== $company_id) {
            not_found();
        }

        $members = $model->get_members($id);
        $member_ids = array_column($members, 'id');

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $flash_error = flash('error');

        require APP_PATH . '/Views/projects/edit.php';
    }

    public function edit_post(int $id): void
    {
        $company_id = $this->requireCompany();
        $model = new Project();
        $project = $model->get_by_id($id);

        if (!$project || (int) $project['company_id'] !== $company_id) {
            not_found();
        }

        $name = trim(post_param('name'));
        $description = trim(post_param('description'));
        $member_ids = $_POST['member_ids'] ?? [];

        if ($name === '') {
            flash('error', 'Назва проекту обов\'язкова');
            redirect('/projects/edit/' . $id);
        }

        $model->update($id, $name, $description);

        // Always keep creator
        $member_ids[] = (int) $project['created_by'];
        $model->set_members($id, array_map('intval', $member_ids));

        flash('success', 'Проект оновлено');
        redirect('/projects');
    }

    public function delete_post(int $id): void
    {
        $company_id = $this->requireCompany();
        $model = new Project();
        $project = $model->get_by_id($id);

        if (!$project || (int) $project['company_id'] !== $company_id) {
            not_found();
        }

        $model->delete($id);
        flash('success', 'Проект видалено');
        redirect('/projects');
    }
}
