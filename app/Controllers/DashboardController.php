<?php
/**
 * Контролер для дашборду
 */

namespace App\Controllers;

use App\Models\Task;
use App\Models\Result;
use App\Models\Company;
use App\Models\Template;
use App\Models\WeeklyPlan;

class DashboardController
{

    /**
     * Головна сторінка дашборду
     */
    public function index()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/company/profile');
        }

        // Отримати завдання та результати
        $task_model = new Task();
        $result_model = new Result();
        $template_model = new Template();
        $weekly_plan_model = new WeeklyPlan();

        $tasks = $task_model->get_by_user($user['id'], $company_id);
        $results = $result_model->get_by_user($user['id'], $company_id);
        $templates = $template_model->get_visible_by_user($company_id, (int) $user['id'], false);
        $company_plans = $weekly_plan_model->getPlansByCompany((int) $company_id);

        $weekly_plans = array_values(array_filter($company_plans, static function ($plan) use ($user) {
            return (int) ($plan['user_id'] ?? 0) === (int) ($user['id'] ?? 0)
                || (int) ($plan['created_by_id'] ?? 0) === (int) ($user['id'] ?? 0);
        }));

        // Статистика
        $total_tasks = count($tasks);
        $completed_tasks = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
        $total_results = count($results);
        $completed_results = count(array_filter($results, fn($r) => $r['completed'] === 1));

        $task_status_counts = [
            'todo' => 0,
            'in-progress' => 0,
            'done' => 0,
            'postponed' => 0,
            'other' => 0,
        ];
        foreach ($tasks as $task) {
            $status = strtolower(trim((string) ($task['status'] ?? '')));
            if (array_key_exists($status, $task_status_counts)) {
                $task_status_counts[$status]++;
            } else {
                $task_status_counts['other']++;
            }
        }

        $result_status_counts = [
            'completed' => 0,
            'in-progress' => 0,
            'on-hold' => 0,
            'other' => 0,
        ];
        foreach ($results as $result) {
            if ((int) ($result['completed'] ?? 0) === 1) {
                $result_status_counts['completed']++;
                continue;
            }

            $status = strtolower(trim((string) ($result['status'] ?? '')));
            if ($status === 'in-progress') {
                $result_status_counts['in-progress']++;
            } elseif ($status === 'on-hold') {
                $result_status_counts['on-hold']++;
            } else {
                $result_status_counts['other']++;
            }
        }

        $template_repeat_counts = [
            'daily' => 0,
            'weekly' => 0,
            'monthly' => 0,
            'none' => 0,
        ];
        foreach ($templates as $template) {
            $repeat = strtolower(trim((string) ($template['repeat_type'] ?? 'none')));
            if (!array_key_exists($repeat, $template_repeat_counts)) {
                $repeat = 'none';
            }
            $template_repeat_counts[$repeat]++;
        }

        $total_templates = count($templates);

        $weekly_plan_items_total = 0;
        $weekly_plan_items_done = 0;
        foreach ($weekly_plans as $plan) {
            $items = $weekly_plan_model->getItems((int) ($plan['id'] ?? 0));
            $weekly_plan_items_total += count($items);

            foreach ($items as $item) {
                $status = strtolower(trim((string) ($item['task_status'] ?? '')));
                if ($status === 'done') {
                    $weekly_plan_items_done++;
                }
            }
        }

        $plan_fact_completion_rate = $weekly_plan_items_total > 0
            ? (int) round(($weekly_plan_items_done / $weekly_plan_items_total) * 100)
            : 0;

        $tasks_completion_rate = $total_tasks > 0
            ? (int) round(($completed_tasks / $total_tasks) * 100)
            : 0;

        $results_completion_rate = $total_results > 0
            ? (int) round(($completed_results / $total_results) * 100)
            : 0;

        require APP_PATH . '/Views/dashboard/index.php';
    }
}
