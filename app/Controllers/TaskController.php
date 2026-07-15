<?php
/**
 * Контролер для завдань
 */

namespace App\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Company;
use App\Models\Result;
use App\Models\Template;
use App\Models\WeeklyPlan;
use App\Services\TemplateTaskMaterializerService;

class TaskController
{
    private const ALLOWED_TASK_STATUSES = ['todo', 'in-progress', 'done', 'postponed'];

    /**
     * Список всіх завдань
     */
    public function index()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $selected_tab = get_param('tab', 'my');
        $selected_status = get_param('status', 'all');
        $selected_date = get_param('date', date('Y-m-d'));

        $allowed_tabs = ['my', 'delegated', 'subordinates', 'postponed'];
        $allowed_statuses = ['active', 'todo', 'in-progress', 'done', 'postponed', 'all'];

        if (!in_array($selected_tab, $allowed_tabs, true)) {
            $selected_tab = 'my';
        }

        if (!in_array($selected_status, $allowed_statuses, true)) {
            $selected_status = 'all';
        }

        $date_obj = \DateTime::createFromFormat('Y-m-d', (string) $selected_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $selected_date) {
            $selected_date = date('Y-m-d');
        }

        (new TemplateTaskMaterializerService())->ensureTasksForDate((int) $company_id, $selected_date);

        $task_model = new Task();
        $task_model->repairEmptyTitlesFromWeeklyPlanItems((int) $company_id, $selected_date, $selected_date);
        $all_tasks = $task_model->get_by_company($company_id);

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $is_owner = false;
        foreach ($employees as $employee) {
            if ((int) ($employee['user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
                continue;
            }

            $is_owner = strtolower(trim((string) ($employee['role'] ?? ''))) === 'owner';
            break;
        }

        $result_model = new Result();
        $template_model = new Template();
        $all_goals = $result_model->get_by_company($company_id);
        $all_sub_results = $result_model->get_all_sub_results($company_id);
        $templates = $template_model->get_visible_by_user($company_id, (int) ($user['id'] ?? 0), $is_owner);

        $subordinate_ids = [];
        foreach ($employees as $employee) {
            if ((int) ($employee['reports_to'] ?? 0) === (int) ($user['id'] ?? 0)) {
                $subordinate_ids[] = (int) ($employee['user_id'] ?? 0);
            }
        }

        $goals = array_values(array_filter($all_goals, function ($goal) use ($user, $selected_tab, $selected_status, $subordinate_ids) {
            $assignee_id = (int) ($goal['assignee_id'] ?? 0);
            $reporter_id = (int) ($goal['reporter_id'] ?? 0);
            $user_id = (int) ($user['id'] ?? 0);

            $is_done = (int) ($goal['completed'] ?? 0) === 1;
            $status = (string) ($goal['status'] ?? ($is_done ? 'done' : 'in-progress'));

            switch ($selected_tab) {
                case 'my':
                    if ($assignee_id !== $user_id) {
                        return false;
                    }
                    break;
                case 'delegated':
                    if (!($reporter_id === $user_id && $assignee_id !== $user_id)) {
                        return false;
                    }
                    break;
                case 'subordinates':
                    if (!in_array($assignee_id, $subordinate_ids, true)) {
                        return false;
                    }
                    break;
                case 'postponed':
                    if ($status !== 'postponed') {
                        return false;
                    }
                    break;
            }

            if ($selected_status === 'all') {
                return true;
            }

            if ($selected_status === 'active') {
                return in_array($status, ['in-progress', 'todo'], true);
            }

            return $status === $selected_status;
        }));

        $is_task_accepted = function (array $task): bool {
            return $this->isTaskAccepted($task);
        };

        $tasks = array_values(array_filter($all_tasks, function ($task) use ($user, $selected_tab, $selected_status, $selected_date, $subordinate_ids, $is_task_accepted) {
            $task_due_date = null;
            if (!empty($task['due_date'])) {
                $task_due_date = date('Y-m-d', strtotime($task['due_date']));
            }

            if ($task_due_date !== $selected_date) {
                return false;
            }

            $assignee_id = (int) ($task['assignee_id'] ?? 0);
            $reporter_id = (int) ($task['reporter_id'] ?? 0);
            $user_id = (int) ($user['id'] ?? 0);
            $status = (string) ($task['status'] ?? 'todo');

            switch ($selected_tab) {
                case 'my':
                    if ($assignee_id !== $user_id) {
                        return false;
                    }
                    if ($reporter_id !== $user_id && !$is_task_accepted($task)) {
                        return false;
                    }
                    break;
                case 'delegated':
                    if (!($reporter_id === $user_id && $assignee_id !== $user_id)) {
                        return false;
                    }
                    break;
                case 'subordinates':
                    if (!in_array($assignee_id, $subordinate_ids, true)) {
                        return false;
                    }
                    break;
                case 'postponed':
                    if ($status !== 'postponed') {
                        return false;
                    }
                    break;
            }

            if ($selected_status === 'all') {
                return true;
            }

            if ($selected_status === 'active') {
                return in_array($status, ['todo', 'in-progress'], true);
            }

            return $status === $selected_status;
        }));

        $incoming_assigned_tasks = [];
        if ($selected_tab === 'my' && in_array($selected_status, ['active', 'todo', 'all'], true)) {
            $incoming_assigned_tasks = array_values(array_filter($all_tasks, function ($task) use ($user, $selected_date, $is_task_accepted) {
                $task_due_date = null;
                if (!empty($task['due_date'])) {
                    $task_due_date = date('Y-m-d', strtotime($task['due_date']));
                }

                if ($task_due_date !== $selected_date) {
                    return false;
                }

                $assignee_id = (int) ($task['assignee_id'] ?? 0);
                $reporter_id = (int) ($task['reporter_id'] ?? 0);
                $user_id = (int) ($user['id'] ?? 0);
                $status = (string) ($task['status'] ?? 'todo');

                if ($assignee_id !== $user_id || $reporter_id === $user_id) {
                    return false;
                }

                if ($is_task_accepted($task)) {
                    return false;
                }

                return in_array($status, ['todo', 'in-progress'], true);
            }));
        }

        $overdue_tasks = $task_model->getOverdueForUser((int) $company_id, (int) ($user['id'] ?? 0));

        $project_model = new \App\Models\Project();
        $projects = $project_model->get_by_company((int) $company_id);

        require APP_PATH . '/Views/tasks/index.php';
    }

    /**
     * Форма створення нової задачі
     */
    public function create()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        // Отримати список користувачів компанії для призначення
        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);

        require APP_PATH . '/Views/tasks/create.php';
    }

    /**
     * Зберегти нову задачу
     */
    public function store()
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $title = $this->normalizeTaskTitle(post_param('title'));
        $assignee_id = post_param('assignee_id');
        $assignee_ids_raw = $_POST['assignee_ids'] ?? [];
        $due_date = post_param('due_date');
        $expected_result = post_param('expected_result');
        $expected_time = post_param('expected_time');
        $description = post_param('description');
        $actual_result = $this->normalizeOptionalText(post_param('actual_result'));
        $type = post_param('type', 'important-urgent');
        $status = $this->normalizeTaskStatus(post_param('status'), 'todo');
        $result_id_raw = post_param('result_id');
        $template_id_raw = post_param('template_id');
        $project_id_raw = post_param('project_id');
        $result_id = ($result_id_raw !== null && $result_id_raw !== '') ? (int) $result_id_raw : null;
        $template_id = ($template_id_raw !== null && $template_id_raw !== '') ? (int) $template_id_raw : null;
        $project_id = ($project_id_raw !== null && $project_id_raw !== '') ? (int) $project_id_raw : null;
        $return_url = $this->resolveReturnUrl(post_param('return_url'), '/tasks');

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $allowed_assignee_ids = [];
        foreach ($employees as $employee) {
            $employee_user_id = (int) ($employee['user_id'] ?? 0);
            if ($employee_user_id > 0) {
                $allowed_assignee_ids[$employee_user_id] = true;
            }
        }

        $assignee_ids = [];
        if (is_array($assignee_ids_raw)) {
            foreach ($assignee_ids_raw as $raw_assignee_id) {
                $candidate_id = (int) $raw_assignee_id;
                if ($candidate_id > 0 && isset($allowed_assignee_ids[$candidate_id])) {
                    $assignee_ids[$candidate_id] = $candidate_id;
                }
            }
        }

        $fallback_assignee_id = (int) $assignee_id;
        if (!empty($assignee_ids)) {
            $assignee_ids = array_values($assignee_ids);
        } elseif ($fallback_assignee_id > 0 && isset($allowed_assignee_ids[$fallback_assignee_id])) {
            $assignee_ids = [$fallback_assignee_id];
        }

        if ($title === null || empty($assignee_ids) || $expected_result === null || trim((string) $expected_result) === '' || $expected_time === null || (int) $expected_time <= 0) {
            $err = $title === null ? 'Назва задачі не може бути порожньою.' :
                (empty($assignee_ids) ? 'Виберіть виконавця.' :
                    ($expected_result === null || trim((string) $expected_result) === '' ? 'Очікуваний результат обов’язковий.' :
                        ($expected_time === null || (int) $expected_time <= 0 ? 'Планований час обов’язковий і має бути більше 0.' : 'Заповніть обов’язкові поля')));
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }
            flash('error', $err);
            redirect($return_url ?: '/tasks/create');
        }

        if ($status === 'done' && $actual_result === null) {
            flash('error', 'Щоб завершити задачу, заповніть фактичний результат.');
            redirect($return_url);
        }

        $task_model = new Task();
        foreach ($assignee_ids as $target_assignee_id) {
            $task_model->create([
                'title' => $title,
                'company_id' => $company_id,
                'assignee_id' => $target_assignee_id,
                'reporter_id' => $user['id'],
                'accepted_at' => ((int) $target_assignee_id === (int) ($user['id'] ?? 0)) ? date('Y-m-d H:i:s') : null,
                'status' => $status,
                'due_date' => $due_date ?: null,
                'description' => $description ?: null,
                'expected_result' => $expected_result,
                'actual_result' => $actual_result,
                'type' => $type,
                'result_id' => $result_id,
                'template_id' => $template_id,
                'project_id' => $project_id,
            ]);
        }

        // Ajax: якщо X-Requested-With: XMLHttpRequest, повертаємо JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        $created_count = count($assignee_ids);
        flash('success', $created_count === 1 ? 'Завдання успішно створено' : 'Створено ' . $created_count . ' окремі задачі для виконавців');
        redirect($return_url);
    }

    /**
     * Форма редагування задачі
     */
    public function edit($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            redirect('/dashboard');
        }

        $task_model = new Task();
        $task = $task_model->get_by_id($id);

        if (!$task || $task['company_id'] != $company_id) {
            flash('error', 'Завдання не знайдено');
            redirect('/tasks');
        }

        $company_model = new Company();
        $employees = $company_model->get_employees($company_id);
        $taskItem = $task;

        require APP_PATH . '/Views/tasks/edit.php';
    }

    /**
     * Оновити завдання
     */
    public function update($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;
        $isAjaxRequest = $this->isAjaxRequest();

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $task_model = new Task();
        $task = $task_model->get_by_id($id);

        if (!$task || $task['company_id'] != $company_id) {
            json_response(['error' => 'Task not found'], 404);
        }

        if ($this->isTaskAwaitingAcceptance($task, (int) ($user['id'] ?? 0))) {
            if ($isAjaxRequest) {
                json_response(['error' => 'Task must be accepted first'], 409);
            }
            flash('error', 'Цю задачу спочатку потрібно прийняти. У блоці "Задачі призначені мені" натисніть "Прийняти".');
            $return_url = $this->resolveReturnUrl(post_param('return_url'), '/tasks');
            redirect($return_url);
        }

        $postedStatus = post_param('status');
        $resolvedStatus = $this->normalizeTaskStatus($postedStatus, (string) ($task['status'] ?? 'todo'));
        $postedActualResult = post_param('actual_result');
        $resolvedActualResult = $postedActualResult !== null
            ? $this->normalizeOptionalText($postedActualResult)
            : $this->normalizeOptionalText($task['actual_result'] ?? null);
        $resolvedTitle = $this->resolveSubmittedTaskTitle($_POST, (string) ($task['title'] ?? ''));

        if ($resolvedTitle === null) {
            if ($isAjaxRequest) {
                json_response(['error' => 'Task title cannot be empty'], 422);
            }
            flash('error', 'Назва задачі не може бути порожньою.');
            $return_url = $this->resolveReturnUrl(post_param('return_url'), '/tasks/view/' . $id);
            redirect($return_url);
        }

        if ($resolvedStatus === 'done' && $resolvedActualResult === null) {
            if ($isAjaxRequest) {
                json_response(['error' => 'Actual result is required for done status'], 422);
            }
            flash('error', 'Щоб завершити задачу, заповніть фактичний результат.');
            $return_url = $this->resolveReturnUrl(post_param('return_url'), '/tasks/view/' . $id);
            redirect($return_url);
        }

        $data = [
            'title' => $resolvedTitle,
            'status' => $resolvedStatus,
            'due_date' => post_param('due_date') ?: $task['due_date'],
            'description' => post_param('description') ?: ($task['description'] ?? null),
            'expected_result' => post_param('expected_result') ?: ($task['expected_result'] ?? null),
            'actual_result' => $resolvedActualResult,
            'type' => post_param('type') ?: ($task['type'] ?? null),
            'assignee_id' => post_param('assignee_id') ?: ($task['assignee_id'] ?? null),
            'expected_time' => post_param('expected_time') !== null ? post_param('expected_time') : ($task['expected_time'] ?? null),
            'actual_time' => post_param('actual_time') ?: ($task['actual_time'] ?? null),
            'result_id' => (post_param('result_id') !== null && post_param('result_id') !== '') ? (int) post_param('result_id') : null,
            'project_id' => (post_param('project_id') !== null && post_param('project_id') !== '') ? (int) post_param('project_id') : null,
            // Template source is immutable after creation.
            'template_id' => $task['template_id'] ?? null,
        ];

        if (array_key_exists('accepted_at', $task)) {
            $previous_assignee_id = (int) ($task['assignee_id'] ?? 0);
            $new_assignee_id = (int) ($data['assignee_id'] ?? 0);
            $reporter_id = (int) ($task['reporter_id'] ?? 0);

            if ($new_assignee_id !== $previous_assignee_id) {
                $data['accepted_at'] = ($new_assignee_id > 0 && $new_assignee_id === $reporter_id)
                    ? date('Y-m-d H:i:s')
                    : null;
            }
        }

        // Handle completion_date: if marking done with a specific date, update due_date preserving time
        $completion_date_raw = trim((string) (post_param('completion_date') ?? ''));
        if ($completion_date_raw !== '' && $resolvedStatus === 'done') {
            $completion_date_obj = \DateTime::createFromFormat('Y-m-d', $completion_date_raw);
            if ($completion_date_obj && $completion_date_obj->format('Y-m-d') === $completion_date_raw && $completion_date_raw <= date('Y-m-d')) {
                $existing_due = (string) ($task['due_date'] ?? '');
                $time_part = strlen($existing_due) > 10 ? substr($existing_due, 10) : '';
                $data['due_date'] = $completion_date_raw . $time_part;
            }
        }

        $task_model->update($id, $data);
        $updatedTask = $task_model->get_by_id($id);
        if ($updatedTask) {
            (new WeeklyPlan())->syncItemFromTask($updatedTask);
        }
        $this->createReporterReviewTaskIfNeeded($task_model, $task, $data, (int) $company_id);

        if ($isAjaxRequest) {
            json_response([
                'ok' => true,
                'task_id' => (int) $id,
                'status' => (string) ($updatedTask['status'] ?? $data['status'] ?? $resolvedStatus),
                'actual_time' => (int) ($updatedTask['actual_time'] ?? $data['actual_time'] ?? 0),
            ]);
        }

        flash('success', 'Завдання оновлено');
        $return_url = post_param('return_url');
        if ($return_url && str_starts_with($return_url, '/tasks')) {
            redirect($return_url);
        }
        redirect('/tasks/view/' . $id);
    }

    /**
     * AJAX: вирішити прострочену задачу (позначити виконаною або перенести)
     */
    public function resolveOverdue(): void
    {
        $user = get_user();
        $company_id = (int) ($_SESSION['company_id'] ?? 0);
        if ($company_id <= 0) {
            json_response(['error' => 'Company not found'], 400);
        }

        $task_id = (int) (post_param('task_id') ?? 0);
        $action = trim((string) (post_param('action') ?? ''));

        if ($task_id <= 0 || !in_array($action, ['done', 'reschedule', 'delete'], true)) {
            json_response(['error' => 'Invalid params'], 422);
        }

        $task_model = new Task();
        $task = $task_model->get_by_id($task_id);

        if (!$task || (int) ($task['company_id'] ?? 0) !== $company_id) {
            json_response(['error' => 'Task not found'], 404);
        }

        if ((int) ($task['assignee_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            json_response(['error' => 'Access denied'], 403);
        }

        if ($action === 'done') {
            $actual_result = trim((string) (post_param('actual_result') ?? ''));
            if ($actual_result === '') {
                $actual_result = 'Виконано';
            }
            $actual_time = post_param('actual_time');
            $actual_time = ($actual_time !== null && (int) $actual_time > 0) ? (int) $actual_time : null;

            // Completion date: may differ from original due_date (e.g. done today but was planned for yesterday)
            $completion_date_raw = trim((string) (post_param('completion_date') ?? ''));
            $completion_date_obj = $completion_date_raw !== '' ? \DateTime::createFromFormat('Y-m-d', $completion_date_raw) : null;
            $completion_date = ($completion_date_obj && $completion_date_obj->format('Y-m-d') === $completion_date_raw && $completion_date_raw <= date('Y-m-d'))
                ? $completion_date_raw
                : null;

            $update_payload = [
                'status' => 'done',
                'actual_result' => $actual_result,
                'actual_time' => $actual_time,
            ];

            // If a specific completion date was chosen, update due_date to that date (preserve time if present)
            if ($completion_date !== null) {
                $existing_due = (string) ($task['due_date'] ?? '');
                $time_part = strlen($existing_due) > 10 ? substr($existing_due, 10) : '';
                $update_payload['due_date'] = $completion_date . $time_part;
            }

            $task_model->update($task_id, $update_payload);

            // Optionally mark linked goal as done
            $result_id = (int) ($task['result_id'] ?? 0);
            if ($result_id > 0 && post_param('mark_result_done') === '1') {
                (new Result())->update($result_id, ['status' => 'done', 'completed' => 1]);
            }

            json_response(['ok' => true, 'task_id' => $task_id, 'action' => 'done']);
        }

        if ($action === 'reschedule') {
            $new_date = trim((string) (post_param('new_date') ?? ''));
            $date_obj = \DateTime::createFromFormat('Y-m-d', $new_date);
            if (!$date_obj || $date_obj->format('Y-m-d') !== $new_date) {
                json_response(['error' => 'Invalid date'], 422);
            }
            if ($new_date < date('Y-m-d')) {
                json_response(['error' => 'Date cannot be in the past'], 422);
            }
            $existing_due = (string) ($task['due_date'] ?? '');
            $time_part = '';
            if (strlen($existing_due) > 10) {
                $time_part = substr($existing_due, 10);
            }
            $task_model->update($task_id, [
                'due_date' => $new_date . $time_part,
                'status' => 'todo',
            ]);

            // If the new date falls outside the current plan-fact week, attach the task to the correct weekly plan
            $weekly_plan_result = $this->attachTaskToWeeklyPlanIfNeeded($task_id, $task, $new_date, $company_id, (int) ($user['id'] ?? 0));

            json_response(['ok' => true, 'task_id' => $task_id, 'action' => 'reschedule', 'new_date' => $new_date, 'plan_attached' => $weekly_plan_result]);
        }

        if ($action === 'delete') {
            $task_model->delete($task_id);
            json_response(['ok' => true, 'task_id' => $task_id, 'action' => 'delete']);
        }
    }

    public function accept($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $task_model = new Task();
        $task = $task_model->get_by_id($id);

        if (!$task || (int) ($task['company_id'] ?? 0) !== (int) $company_id) {
            flash('error', 'Завдання не знайдено');
            redirect('/tasks');
        }

        $user_id = (int) ($user['id'] ?? 0);
        $assignee_id = (int) ($task['assignee_id'] ?? 0);
        $reporter_id = (int) ($task['reporter_id'] ?? 0);

        if ($assignee_id !== $user_id || $reporter_id === $user_id) {
            flash('error', 'Ви не можете прийняти це завдання.');
            $return_url = $this->resolveReturnUrl(post_param('return_url'), '/tasks');
            redirect($return_url);
        }

        if (!$this->isTaskAccepted($task)) {
            $task_model->update($id, ['accepted_at' => date('Y-m-d H:i:s')]);
            flash('success', 'Задачу прийнято. Тепер вона в основному списку задач.');
        } else {
            flash('success', 'Задача вже прийнята.');
        }

        $task_date = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : date('Y-m-d');
        $fallback_url = '/tasks?' . http_build_query([
            'tab' => 'my',
            'status' => 'active',
            'date' => $task_date,
        ]);
        $return_url = $this->resolveReturnUrl(post_param('return_url'), $fallback_url);
        redirect($return_url);
    }

    private function createReporterReviewTaskIfNeeded(Task $taskModel, array $task, array $data, int $companyId): void
    {
        $newStatus = strtolower(trim((string) ($data['status'] ?? $task['status'] ?? 'todo')));
        $oldStatus = strtolower(trim((string) ($task['status'] ?? 'todo')));
        if (!in_array($newStatus, ['done', 'completed'], true) || in_array($oldStatus, ['done', 'completed'], true)) {
            return;
        }

        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        $reporterId = (int) ($task['reporter_id'] ?? 0);
        if ($companyId <= 0 || $assigneeId <= 0 || $reporterId <= 0 || $assigneeId === $reporterId) {
            return;
        }

        $reviewTitle = 'Перевірити ' . trim((string) ($task['title'] ?? 'задачу'));
        $reviewDueDate = date('Y-m-d');

        $existingTodayTasks = $taskModel->get_by_company($companyId);
        foreach ($existingTodayTasks as $existingTask) {
            $existingDueDate = !empty($existingTask['due_date']) ? date('Y-m-d', strtotime((string) $existingTask['due_date'])) : null;
            if (
                (int) ($existingTask['assignee_id'] ?? 0) === $reporterId
                && (int) ($existingTask['reporter_id'] ?? 0) === $reporterId
                && $existingDueDate === $reviewDueDate
                && trim((string) ($existingTask['title'] ?? '')) === $reviewTitle
            ) {
                return;
            }
        }

        $taskModel->create([
            'title' => mb_substr($reviewTitle, 0, 255),
            'company_id' => $companyId,
            'assignee_id' => $reporterId,
            'reporter_id' => $reporterId,
            'status' => 'todo',
            'due_date' => $reviewDueDate,
            'description' => 'Автоматично створено після виконання делегованої задачі.',
            'expected_result' => 'Перевірити результат виконання задачі: ' . trim((string) ($task['title'] ?? '')),
            'expected_time' => 15,
            'type' => 'important-not-urgent',
            'result_id' => $task['result_id'] ?? null,
            'template_id' => null,
        ]);
    }

    /**
     * Подивитися завдання
     */
    public function view($id)
    {
        redirect('/tasks');
    }

    /**
     * Видалити завдання
     */
    public function delete($id)
    {
        $user = get_user();
        $company_id = $_SESSION['company_id'] ?? null;

        if (!$company_id) {
            json_response(['error' => 'Company not found'], 400);
        }

        $task_model = new Task();
        $task = $task_model->get_by_id($id);

        if (!$task || $task['company_id'] != $company_id) {
            json_response(['error' => 'Task not found'], 404);
        }

        (new WeeklyPlan())->deleteItemByLinkedTaskId((int) $id);
        $task_model->delete($id);

        flash('success', 'Завдання видалено');
        redirect('/tasks');
    }

    private function normalizeTaskStatus($rawStatus, string $default = 'todo'): string
    {
        $status = trim((string) $rawStatus);
        if ($status === '' || !in_array($status, self::ALLOWED_TASK_STATUSES, true)) {
            return in_array($default, self::ALLOWED_TASK_STATUSES, true) ? $default : 'todo';
        }

        return $status;
    }

    private function normalizeOptionalText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function normalizeTaskTitle($rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $text = trim((string) $rawValue);
        return $text === '' ? null : $text;
    }

    private function resolveSubmittedTaskTitle(array $post, string $existingTitle): ?string
    {
        if (!array_key_exists('title', $post)) {
            return $this->normalizeTaskTitle($existingTitle);
        }

        return $this->normalizeTaskTitle(post_param('title'));
    }

    /**
     * When a task is rescheduled to a date outside the current plan-fact week, find or create the
     * weekly plan for that new week and add the task as an item there. If the task already belongs
     * to a plan that covers the new date, do nothing.
     *
     * Returns a status string for debugging ('attached'|'plan_created_and_attached'|'already_in_plan'|'no_current_plan'|'skipped').
     */
    private function attachTaskToWeeklyPlanIfNeeded(int $taskId, array $task, string $newDate, int $companyId, int $userId): string
    {
        $weeklyPlanModel = new WeeklyPlan();

        // Find the plan that originally held this task
        $existingItem = $weeklyPlanModel->getItemByLinkedTaskId($taskId);
        $currentWeekStart = $existingItem ? (string) ($existingItem['week_start_date'] ?? '') : '';
        $currentWeekEnd   = $existingItem ? (string) ($existingItem['week_end_date'] ?? '') : '';

        // If the new date is still within the current plan week, nothing to do
        if ($currentWeekStart !== '' && $newDate >= $currentWeekStart && $newDate <= $currentWeekEnd) {
            return 'skipped';
        }

        // If there is no current plan at all, also skip (no plan to reference)
        if ($currentWeekStart === '' && $existingItem === null) {
            return 'no_current_plan';
        }

        // Determine Monday of the target week
        $targetMonday = date('Y-m-d', strtotime('monday this week', strtotime($newDate)));
        if (date('N', strtotime($newDate)) === '1') {
            $targetMonday = $newDate;
        }

        // Find or create the target plan
        $targetPlan = $weeklyPlanModel->findByUserAndWeek($companyId, $userId, $targetMonday);
        $planCreated = false;
        if (!$targetPlan) {
            $creatorId = (int) ($existingItem['reporter_id'] ?? $userId);
            $planId = $weeklyPlanModel->createPlan($companyId, $userId, $creatorId, $targetMonday);
            if ($planId <= 0) {
                return 'skipped';
            }
            $targetPlan = $weeklyPlanModel->getById($companyId, $planId);
            $planCreated = true;
        }

        if (!$targetPlan) {
            return 'skipped';
        }

        // Check if there is already a plan item for this task in the target plan
        $alreadyInPlan = false;
        $existingItems = $weeklyPlanModel->getItems((int) $targetPlan['id']);
        foreach ($existingItems as $item) {
            if ((int) ($item['linked_task_id'] ?? 0) === $taskId) {
                $alreadyInPlan = true;
                break;
            }
        }

        if ($alreadyInPlan) {
            return 'already_in_plan';
        }

        // Add item to the target plan, linking the existing task
        $this->insertPlanItemForExistingTask($weeklyPlanModel, $targetPlan, $task, $taskId, $newDate);

        return $planCreated ? 'plan_created_and_attached' : 'attached';
    }

    /**
     * Insert a weekly_plan_item row that links an already-existing task.
     */
    private function insertPlanItemForExistingTask(WeeklyPlan $weeklyPlanModel, array $plan, array $task, int $taskId, string $plannedDate): void
    {
        $db = new \App\Models\Database();

        $sortOrderRow = $db
            ->query('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM weekly_plan_items WHERE weekly_plan_id = :wpid AND planned_date = :pd')
            ->bind(':wpid', (int) $plan['id'])
            ->bind(':pd', $plannedDate)
            ->fetch();
        $sortOrder = (int) ($sortOrderRow['max_sort'] ?? 0) + 1;

        $title = trim((string) ($task['title'] ?? ''));
        if ($title === '') {
            return;
        }

        $startTime = null;
        $existingDue = (string) ($task['due_date'] ?? '');
        if (strlen($existingDue) > 10) {
            $timePart = trim(substr($existingDue, 10));
            if (preg_match('/^[T ]?(\d{2}:\d{2})/', $timePart, $m)) {
                $startTime = $m[1];
            }
        }

        $db->insert('weekly_plan_items', [
            'weekly_plan_id'    => (int) $plan['id'],
            'linked_task_id'    => $taskId,
            'source_template_id' => isset($task['template_id']) && (int) $task['template_id'] > 0 ? (int) $task['template_id'] : null,
            'planned_date'      => $plannedDate,
            'start_time'        => $startTime,
            'weekday_index'     => (int) date('N', strtotime($plannedDate)) - 1,
            'sort_order'        => $sortOrder,
            'title'             => mb_substr($title, 0, 255),
            'description'       => $task['description'] ?? null,
            'assignee_id'       => (int) ($task['assignee_id'] ?? $plan['user_id']),
            'reporter_id'       => (int) ($task['reporter_id'] ?? $plan['created_by_id'] ?? $plan['user_id']),
            'type'              => (string) ($task['type'] ?? 'important-not-urgent'),
            'expected_result'   => $task['expected_result'] ?? null,
            'expected_time'     => isset($task['expected_time']) && (int) $task['expected_time'] > 0 ? (int) $task['expected_time'] : null,
        ]);
    }

    private function resolveReturnUrl($value, string $fallback): string
    {
        $url = trim((string) $value);
        if ($url !== '' && str_starts_with($url, '/')) {
            return $url;
        }

        return $fallback;
    }

    private function isTaskAccepted(array $task): bool
    {
        $assignee_id = (int) ($task['assignee_id'] ?? 0);
        $reporter_id = (int) ($task['reporter_id'] ?? 0);

        if ($assignee_id > 0 && $assignee_id === $reporter_id) {
            return true;
        }

        if (!array_key_exists('accepted_at', $task)) {
            return true;
        }

        $accepted_at = trim((string) ($task['accepted_at'] ?? ''));
        return $accepted_at !== '' && $accepted_at !== '0000-00-00 00:00:00';
    }

    private function isTaskAwaitingAcceptance(array $task, int $userId): bool
    {
        $assignee_id = (int) ($task['assignee_id'] ?? 0);
        $reporter_id = (int) ($task['reporter_id'] ?? 0);
        if ($assignee_id !== $userId || $reporter_id === $userId) {
            return false;
        }

        return !$this->isTaskAccepted($task);
    }

    private function isAjaxRequest(): bool
    {
        $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        return $requestedWith === 'xmlhttprequest';
    }
}
