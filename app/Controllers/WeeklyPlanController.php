<?php

namespace App\Controllers;

use App\Models\Company;
use App\Models\Result;
use App\Models\Task;
use App\Models\Template;
use App\Models\WeeklyPlan;
use App\Services\TemplateTaskMaterializerService;

class WeeklyPlanController
{
    private const ALLOWED_TASK_STATUSES = ['todo', 'in-progress', 'done', 'postponed'];

    public function index(): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);

        if ($companyId <= 0) {
            redirect('/dashboard');
        }

        $weekStart = $this->defaultPlanStartDate();
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $companyModel = new Company();
        $companyEmployees = $companyModel->get_employees($companyId);
        $isOwner = $this->isOwnerInCompany($companyEmployees, (int) ($user['id'] ?? 0));
        $scope = $this->normalizeScope((string) get_param('scope', 'all'), $isOwner);

        (new TemplateTaskMaterializerService())->ensureTasksForRange($companyId, $weekStart, $weekEnd);
        (new Task())->repairEmptyTitlesFromWeeklyPlanItems($companyId, $weekStart, $weekEnd);

        $employees = $this->getScopedEmployees($companyEmployees, (int) ($user['id'] ?? 0), $scope, $isOwner);
        $visibleUserIds = $this->extractEmployeeUserIds($employees);

        $weeklyPlanModel = new WeeklyPlan();
        $plans = array_values(array_filter(
            $weeklyPlanModel->getPlansByCompany($companyId),
            static function ($plan) use ($visibleUserIds) {
                return in_array((int) ($plan['user_id'] ?? 0), $visibleUserIds, true);
            }
        ));
        $allTasks = (new Task())->get_by_company($companyId);
        $plansByUser = [];
        foreach ($plans as $plan) {
            $plansByUser[(int) ($plan['user_id'] ?? 0)][] = $plan;
        }

        $planSummaries = [];
        foreach ($plans as $plan) {
            $weekTasks = $this->buildWeekTasksForPlan($allTasks, $plan);
            $weeklyPlanModel->ensureTemplateTasksIncludedInPlan($plan, $weekTasks);
            $items = $weeklyPlanModel->getItems((int) $plan['id']);
            $summary = $this->buildPlanSummary($items);

            // Додаткові факт-дані для списку
            $actualMinutes = 0;
            $doneWeek = 0;
            $plannedTaskIds = array_map(static fn($i) => (int) ($i['task_id'] ?? 0), array_filter($items, static fn($i) => (int) ($i['task_id'] ?? 0) > 0));
            $unplannedCount = 0;
            foreach ($weekTasks as $wt) {
                $actualMinutes += (int) ($wt['actual_time'] ?? 0);
                if (in_array(strtolower((string) ($wt['status'] ?? '')), ['done', 'completed'], true)) {
                    $doneWeek++;
                }
                if (!in_array((int) ($wt['id'] ?? 0), $plannedTaskIds, true)) {
                    $unplannedCount++;
                }
            }
            $summary['actual_minutes'] = $actualMinutes;
            $summary['week_task_count'] = count($weekTasks);
            $summary['done_week_tasks'] = $doneWeek;
            $summary['unplanned_count'] = $unplannedCount;

            $planSummaries[(int) ($plan['id'] ?? 0)] = $summary;
        }

        $title = 'План-факт';
        require APP_PATH . '/Views/weekly-plans/index.php';
    }

    public function createForm(): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);

        if ($companyId <= 0) {
            redirect('/dashboard');
        }

        $companyModel = new Company();
        $companyEmployees = $companyModel->get_employees($companyId);
        $employees = $this->getAccessibleEmployees($companyEmployees, (int) ($user['id'] ?? 0));
        if (empty($employees)) {
            flash('error', 'Немає доступних співробітників для створення план-факту.');
            redirect('/weekly-plans');
        }

        $visibleUserIds = $this->extractEmployeeUserIds($employees);
        $selectedUserId = (int) get_param('user_id', (string) ($user['id'] ?? 0));
        if (!in_array($selectedUserId, $visibleUserIds, true)) {
            $selectedUserId = in_array((int) ($user['id'] ?? 0), $visibleUserIds, true)
                ? (int) ($user['id'] ?? 0)
                : (int) ($visibleUserIds[0] ?? 0);
        }

        $weekStart = $this->normalizePlanStartDate((string) get_param('week_start', $this->defaultPlanStartDate()));
        $notes = trim((string) get_param('notes', ''));
        $title = 'Створити план-факт';

        require APP_PATH . '/Views/weekly-plans/create.php';
    }

    public function create(): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);

        if ($companyId <= 0) {
            redirect('/dashboard');
        }

        $companyModel = new Company();
        $employees = $this->getAccessibleEmployees($companyModel->get_employees($companyId), (int) ($user['id'] ?? 0));
        $visibleUserIds = $this->extractEmployeeUserIds($employees);

        $planUserId = (int) post_param('user_id');
        $weekStart = $this->normalizePlanStartDate((string) post_param('week_start', $this->defaultPlanStartDate()));
        $notes = trim((string) post_param('notes', ''));

        if ($planUserId <= 0) {
            flash('error', 'Не вибрано користувача для плану.');
            redirect('/weekly-plans/create?week_start=' . urlencode($weekStart));
        }

        if (!in_array($planUserId, $visibleUserIds, true)) {
            flash('error', 'Ви можете створювати план-факт лише для себе або своїх підлеглих.');
            redirect('/weekly-plans/create?week_start=' . urlencode($weekStart));
        }

        $weeklyPlanModel = new WeeklyPlan();
        $planId = $weeklyPlanModel->createPlan($companyId, $planUserId, (int) ($user['id'] ?? 0), $weekStart, $notes);

        flash('success', 'Тижневий план створено.');
        redirect('/weekly-plans/view/' . $planId);
    }

    public function view($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        if ($companyId <= 0) {
            redirect('/dashboard');
        }

        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));
        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        (new TemplateTaskMaterializerService())->ensureTasksForRange($companyId, (string) ($plan['week_start_date'] ?? ''), (string) ($plan['week_end_date'] ?? ''));
        (new Task())->repairEmptyTitlesFromWeeklyPlanItems($companyId, (string) ($plan['week_start_date'] ?? ''), (string) ($plan['week_end_date'] ?? ''));

        $templates = (new Template())->get_visible_by_user($companyId, (int) ($user['id'] ?? 0), $this->isOwnerInCompany((new Company())->get_employees($companyId), (int) ($user['id'] ?? 0)));
        $employees = $this->getVisibleEmployees((new Company())->get_employees($companyId), (int) ($user['id'] ?? 0));
        $allTasks = (new Task())->get_by_company($companyId);
        $results = (new Result())->get_by_company($companyId);

        // Build flat ordered list: parent goals + their sub-goals with indent
        $subResultsRaw = (new Result())->get_all_sub_results($companyId);
        $subResultsByParent = [];
        foreach ($subResultsRaw as $sr) {
            $pid = (int) ($sr['parent_id'] ?? 0);
            $subResultsByParent[$pid][] = $sr;
        }
        $results_flat = [];
        foreach ($results as $parentResult) {
            $results_flat[] = $parentResult + ['_indent' => false];
            $pid = (int) ($parentResult['id'] ?? 0);
            foreach ($subResultsByParent[$pid] ?? [] as $sub) {
                $results_flat[] = $sub + ['_indent' => true];
            }
        }

        $weekStart = (string) $plan['week_start_date'];
        $weekEnd = (string) $plan['week_end_date'];
        $days = $this->buildWeekDays($weekStart);

        $weekTasks = $this->buildWeekTasksForPlan($allTasks, $plan);

        $weeklyPlanModel->ensureTemplateTasksIncludedInPlan($plan, $weekTasks);
        $items = $weeklyPlanModel->getItems((int) $plan['id']);
        $itemsByDay = [];
        $plannedTaskIds = [];
        $plannedTaskOrder = [];
        $plannedTaskTitles = [];

        foreach ($items as $index => $item) {
            $plannedDate = (string) ($item['planned_date'] ?? '');
            $itemsByDay[$plannedDate][] = $item;
            $taskId = (int) ($item['linked_task_id'] ?? 0);
            if ($taskId > 0) {
                $plannedTaskIds[] = $taskId;
                if (!isset($plannedTaskOrder[$taskId])) {
                    $plannedTaskOrder[$taskId] = $index;
                }
                if (!isset($plannedTaskTitles[$taskId])) {
                    $plannedTaskTitles[$taskId] = trim((string) ($item['title'] ?? ''));
                }
            }
        }

        foreach ($weekTasks as &$weekTask) {
            $weekTaskId = (int) ($weekTask['id'] ?? 0);
            if ($weekTaskId > 0 && isset($plannedTaskTitles[$weekTaskId])) {
                $weekTask['plan_title'] = $plannedTaskTitles[$weekTaskId];
            }
        }
        unset($weekTask);

        $unplannedTasks = array_values(array_filter($weekTasks, static function ($task) use ($plannedTaskIds) {
            return !in_array((int) ($task['id'] ?? 0), $plannedTaskIds, true);
        }));

        $weekTasksByDay = $this->buildFactTasksByDay($weekTasks, $plannedTaskOrder);

        $totalPlanMinutes = 0;
        foreach ($items as $item) {
            $totalPlanMinutes += (int) ($item['expected_time'] ?? 0);
        }

        $planStats = $this->buildPlanSummary($items);
        $factStats = $this->buildFactSummary($items, $weekTasks, $unplannedTasks);

        // --- Прострочені невиконані задачі (всі, не тільки поточний тиждень) ---
        $overdueTasks = (new Task())->getOverdueForUser($companyId, (int) ($user['id'] ?? 0));
        // Alias для view (обидва імені доступні)
        $overdue_tasks = $overdueTasks;
        $planAlerts = $this->buildPlanAlerts($items, $planStats);
        $factAlerts = $this->buildFactAlerts($items, $weekTasks, $unplannedTasks, $factStats);

        $title = 'План-факт';
        $projects = (new \App\Models\Project())->get_by_company($companyId);
        // Передаємо $overdueTasks у view
        require APP_PATH . '/Views/weekly-plans/view.php';
    }

    public function addItem($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $title = $this->normalizeRequiredTitle(post_param('title', null));
        $plannedDate = $this->normalizeDateInWeek((string) post_param('planned_date', ''), (string) $plan['week_start_date']);
        $type = (string) post_param('type', 'important-not-urgent');
        $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'important-not-urgent';
        }

        if ($title === null || $plannedDate === null) {
            flash('error', 'Для додавання в план потрібні дата і назва задачі.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $resultId = post_param('result_id', '') !== '' ? (int) post_param('result_id') : null;
        $startTime = $this->normalizeStartTime((string) post_param('start_time', ''));
        $projectId = post_param('project_id', '') !== '' ? (int) post_param('project_id') : null;

        // Множинні виконавці
        $assigneeIdsRaw = $_POST['assignee_ids'] ?? [];
        $companyEmployees = (new \App\Models\Company())->get_employees($companyId);
        $allowedAssigneeIds = [];
        foreach ($companyEmployees as $emp) {
            $eid = (int) ($emp['user_id'] ?? 0);
            if ($eid > 0) {
                $allowedAssigneeIds[$eid] = true;
            }
        }
        $assigneeIds = [];
        if (is_array($assigneeIdsRaw)) {
            foreach ($assigneeIdsRaw as $rawId) {
                $cid = (int) $rawId;
                if ($cid > 0 && isset($allowedAssigneeIds[$cid])) {
                    $assigneeIds[$cid] = $cid;
                }
            }
        }
        $assigneeIds = array_values($assigneeIds);
        if (empty($assigneeIds)) {
            $fallback = (int) post_param('assignee_id', (string) ($plan['user_id'] ?? 0));
            $assigneeIds = [$fallback > 0 ? $fallback : (int) ($plan['user_id'] ?? 0)];
        }

        foreach ($assigneeIds as $assigneeId) {
            $weeklyPlanModel->addManualItem($plan, [
                'planned_date' => $plannedDate,
                'start_time' => $startTime,
                'title' => $title,
                'description' => trim((string) post_param('description', '')) ?: null,
                'assignee_id' => $assigneeId,
                'reporter_id' => (int) ($user['id'] ?? 0),
                'type' => $type,
                'expected_result' => trim((string) post_param('expected_result', '')) ?: null,
                'expected_time' => post_param('expected_time', '') !== '' ? (int) post_param('expected_time') : null,
                'result_id' => $resultId,
                'project_id' => $projectId,
            ]);
        }

        $msg = count($assigneeIds) === 1 ? 'Задачу додано в тижневий план і в щоденні задачі.' : 'Задачу додано для ' . count($assigneeIds) . ' виконавців.';
        flash('success', $msg);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function addTemplates($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $plannedDate = $this->normalizeDateInWeek((string) post_param('planned_date', ''), (string) $plan['week_start_date']);
        $templateIds = $_POST['template_ids'] ?? [];
        if (!is_array($templateIds) || empty($templateIds) || $plannedDate === null) {
            flash('error', 'Оберіть дату в межах тижня і хоча б один шаблон.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $selectedIds = array_values(array_filter(array_map('intval', $templateIds), static fn($value) => $value > 0));
        $visibleTemplates = (new Template())->get_visible_by_user($companyId, (int) ($user['id'] ?? 0), $this->isOwnerInCompany((new Company())->get_employees($companyId), (int) ($user['id'] ?? 0)));
        $templates = array_values(array_filter($visibleTemplates, static function ($template) use ($selectedIds) {
            return in_array((int) ($template['id'] ?? 0), $selectedIds, true);
        }));

        $weeklyPlanModel->addItemsFromTemplates($plan, $templates, $plannedDate, (int) ($user['id'] ?? 0));

        flash('success', 'Шаблони додано до тижневого плану.');
        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function importGoogleCalendar($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $payloadRaw = (string) post_param('calendar_import_payload', '');
        if (trim($payloadRaw) === '') {
            flash('error', 'Немає даних для імпорту з Google Calendar.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $payload = json_decode(htmlspecialchars_decode($payloadRaw, ENT_QUOTES), true);
        if (!is_array($payload)) {
            flash('error', 'Не вдалося прочитати дані імпорту з Google Calendar.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $calendarName = trim((string) ($payload['calendar_name'] ?? 'Google Calendar'));
        $calendarId = trim((string) ($payload['calendar_id'] ?? ''));
        $events = $payload['events'] ?? [];
        if ($calendarId === '' || !is_array($events) || empty($events)) {
            flash('error', 'Для імпорту потрібно обрати календар і хоча б одну подію.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $importItems = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $plannedDate = $this->normalizeDateInWeek((string) ($event['planned_date'] ?? ''), (string) ($plan['week_start_date'] ?? ''));
            $expectedResult = $this->normalizeOptionalText($event['expected_result'] ?? null);
            if ($plannedDate === null || $expectedResult === null) {
                continue;
            }

            $startTime = $this->normalizeStartTime((string) ($event['start_time'] ?? ''));
            $type = (string) ($event['type'] ?? 'important-not-urgent');
            if (!in_array($type, ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'], true)) {
                $type = 'important-not-urgent';
            }

            $title = $this->normalizeRequiredTitle($event['title'] ?? null);
            if ($title === null) {
                $title = 'Подія Google Calendar';
            }

            $externalEventId = trim((string) ($event['event_id'] ?? ''));
            if ($externalEventId === '') {
                continue;
            }

            $importItems[] = [
                'planned_date' => $plannedDate,
                'start_time' => $startTime,
                'title' => $title,
                'description' => $this->normalizeOptionalText($event['description'] ?? null),
                'assignee_id' => (int) ($plan['user_id'] ?? 0),
                'type' => $type,
                'expected_result' => $expectedResult,
                'external_source' => 'google-calendar',
                'external_reference' => $calendarId . ':' . $externalEventId,
            ];
        }

        if (empty($importItems)) {
            flash('error', 'Не знайдено жодної валідної події для імпорту. Переконайтесь, що для кожної події вказано очікуваний результат.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $result = $weeklyPlanModel->importExternalItems($plan, $importItems, (int) ($user['id'] ?? 0));
        $created = (int) ($result['created'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        if ($created <= 0) {
            flash('error', 'Нових подій з календаря не імпортовано. Можливо, вони вже були додані раніше.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $message = 'Імпортовано ' . $created . ' подій з календаря ' . ($calendarName !== '' ? '"' . $calendarName . '"' : 'Google Calendar') . '.';
        if ($skipped > 0) {
            $message .= ' Пропущено: ' . $skipped . '.';
        }

        flash('success', $message);
        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function delete($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        try {
            $weeklyPlanModel->deletePlan($plan);
            flash('success', 'План-факт видалено.');
        } catch (\Throwable $e) {
            flash('error', 'Не вдалося видалити план-факт.');
        }

        redirect('/weekly-plans');
    }

    public function copyDay($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $sourceDate = $this->normalizeDateInWeek((string) post_param('source_date', ''), (string) $plan['week_start_date']);
        $targetDate = $this->normalizeDateInWeek((string) post_param('target_date', ''), (string) $plan['week_start_date']);

        if ($sourceDate === null || $targetDate === null) {
            flash('error', 'Дні для копіювання мають бути в межах тижня плану.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $itemIds = $_POST['item_ids'] ?? [];
        $selectedItemIds = is_array($itemIds) ? array_values(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0)) : [];

        $copied = $weeklyPlanModel->copyDay($plan, $sourceDate, $targetDate, (int) ($user['id'] ?? 0), $selectedItemIds);
        flash($copied > 0 ? 'success' : 'error', $copied > 0 ? 'Задачі скопійовано на інший день.' : 'На вихідному дні немає задач для копіювання.');
        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function updateItem($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $itemId = (int) post_param('item_id');
        $plannedDate = $this->normalizeDateInWeek((string) post_param('planned_date', ''), (string) $plan['week_start_date']);
        $title = $this->normalizeRequiredTitle(post_param('title', null));
        if ($itemId <= 0 || $plannedDate === null || $title === null) {
            flash('error', 'Не вдалося оновити елемент плану.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $type = (string) post_param('type', 'important-not-urgent');
        $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'important-not-urgent';
        }

        // assignee_ids[] — беремо першого обраного (редагування одного запису)
        $assigneeIdsRaw = $_POST['assignee_ids'] ?? [];
        $assigneeId = (int) ($assigneeIdsRaw[0] ?? 0) ?: (int) post_param('assignee_id', (string) ($plan['user_id'] ?? 0));

        $updated = $weeklyPlanModel->updateItem($plan, $itemId, [
            'planned_date' => $plannedDate,
            'start_time' => $this->normalizeStartTime((string) post_param('start_time', '')),
            'title' => $title,
            'description' => trim((string) post_param('description', '')) ?: null,
            'assignee_id' => $assigneeId,
            'reporter_id' => (int) ($user['id'] ?? 0),
            'type' => $type,
            'expected_result' => trim((string) post_param('expected_result', '')) ?: null,
            'expected_time' => post_param('expected_time', '') !== '' ? (int) post_param('expected_time') : null,
            'project_id' => post_param('project_id', '') !== '' ? (int) post_param('project_id') : null,
        ]);

        flash($updated ? 'success' : 'error', $updated ? 'Елемент плану оновлено.' : 'Не вдалося оновити елемент плану.');

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool) $updated]);
            exit;
        }

        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function deleteItem($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $itemId = (int) post_param('item_id');
        $deleted = $itemId > 0 ? $weeklyPlanModel->deleteItem($plan, $itemId) : false;

        flash($deleted ? 'success' : 'error', $deleted ? 'Елемент плану видалено.' : 'Не вдалося видалити елемент плану.');

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool) $deleted]);
            exit;
        }

        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    public function updateFactTask($id): void
    {
        $user = get_user();
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $weeklyPlanModel = new WeeklyPlan();
        $plan = $this->getAccessiblePlan($weeklyPlanModel, $companyId, (int) $id, (int) ($user['id'] ?? 0));

        if (!$plan) {
            flash('error', 'План не знайдено або у вас немає до нього доступу.');
            redirect('/weekly-plans');
        }

        $taskId = (int) post_param('task_id');
        if ($taskId <= 0) {
            flash('error', 'Задачу не знайдено.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $taskModel = new Task();
        $task = $taskModel->get_by_id($taskId);
        if (!$task || (int) ($task['company_id'] ?? 0) !== $companyId) {
            flash('error', 'Задачу не знайдено.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $status = $this->normalizeTaskStatus(post_param('status', (string) ($task['status'] ?? 'todo')), (string) ($task['status'] ?? 'todo'));
        $resolvedTitle = $this->resolveSubmittedTitle($_POST, (string) ($task['title'] ?? ''));
        if ($resolvedTitle === null) {
            flash('error', 'Назва задачі не може бути порожньою.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $type = (string) post_param('type', (string) ($task['type'] ?? 'important-not-urgent'));
        $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'important-not-urgent';
        }

        $actualResult = $this->normalizeOptionalText(post_param('actual_result', $task['actual_result'] ?? null));
        if ($status === 'done' && $actualResult === null) {
            flash('error', 'Щоб завершити задачу, заповніть фактичний результат.');
            redirect('/weekly-plans/view/' . (int) $plan['id']);
        }

        $currentDueDate = trim((string) ($task['due_date'] ?? ''));
        $currentStartTime = null;
        if ($currentDueDate !== '' && preg_match('/\b(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\b/', $currentDueDate, $timeMatch)) {
            $currentStartTime = strlen($timeMatch[0]) === 5 ? $timeMatch[0] . ':00' : $timeMatch[0];
        }

        $startTimeInput = array_key_exists('start_time', $_POST) ? (string) post_param('start_time', '') : null;
        $startTime = $startTimeInput === null ? $currentStartTime : $this->normalizeStartTime($startTimeInput);
        $taskDate = $currentDueDate !== ''
            ? date('Y-m-d', strtotime($currentDueDate))
            : (string) ($plan['week_start_date'] ?? date('Y-m-d'));

        // Handle completion_date: if marking done with a specific date, override taskDate preserving time
        $completion_date_raw = trim((string) (post_param('completion_date') ?? ''));
        if ($completion_date_raw !== '' && $status === 'done') {
            $completion_date_obj = \DateTime::createFromFormat('Y-m-d', $completion_date_raw);
            if ($completion_date_obj && $completion_date_obj->format('Y-m-d') === $completion_date_raw && $completion_date_raw <= date('Y-m-d')) {
                $taskDate = $completion_date_raw;
            }
        }

        $updated = $taskModel->update($taskId, [
            'title' => $resolvedTitle,
            'status' => $status,
            'type' => $type,
            'assignee_id' => (int) post_param('assignee_id', (string) ($task['assignee_id'] ?? ($plan['user_id'] ?? 0))),
            'due_date' => $this->buildTaskDueDateTime($taskDate, $startTime),
            'description' => trim((string) post_param('description', (string) ($task['description'] ?? ''))) ?: null,
            'expected_result' => trim((string) post_param('expected_result', (string) ($task['expected_result'] ?? ''))) ?: null,
            'actual_result' => $actualResult,
            'expected_time' => post_param('expected_time', $task['expected_time'] ?? '') !== '' ? (int) post_param('expected_time', $task['expected_time'] ?? '') : null,
            'actual_time' => post_param('actual_time', $task['actual_time'] ?? '') !== '' ? (int) post_param('actual_time', $task['actual_time'] ?? '') : null,
        ]);

        if ($updated) {
            $this->createReporterReviewTaskIfNeeded($taskModel, $task, [
                'status' => $status,
                'actual_result' => $actualResult,
            ], $companyId);
        }

        // Якщо AJAX-запит — повертаємо JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => (bool) $updated]);
            exit;
        }

        flash($updated ? 'success' : 'error', $updated ? 'Задачу оновлено.' : 'Не вдалося оновити задачу.');
        redirect('/weekly-plans/view/' . (int) $plan['id']);
    }

    private function buildPlanSummary(array $items): array
    {
        $totalMinutes = 0;
        $done = 0;
        $days = [];

        foreach ($items as $item) {
            $minutes = (int) ($item['expected_time'] ?? 0);
            $date = (string) ($item['planned_date'] ?? '');
            $days[$date] = ($days[$date] ?? 0) + $minutes;
            $totalMinutes += $minutes;

            $status = strtolower(trim((string) ($item['task_status'] ?? 'todo')));
            if (in_array($status, ['done', 'completed'], true)) {
                $done++;
            }
        }

        $totalItems = count($items);
        $completionRate = $totalItems > 0 ? (int) round(($done / $totalItems) * 100) : 0;

        return [
            'total_items' => $totalItems,
            'completed_items' => $done,
            'completion_rate' => $completionRate,
            'total_minutes' => $totalMinutes,
            'day_loads' => $days,
            'max_day_minutes' => empty($days) ? 0 : max($days),
        ];
    }

    private function buildFactSummary(array $items, array $weekTasks, array $unplannedTasks): array
    {
        $completedWeekTasks = 0;
        $urgentImportantCount = 0;
        $actualMinutes = 0;
        $plannedActualMinutes = 0;
        $plannedExpectedMinutes = 0;
        $movedPlannedTasks = 0;

        foreach ($items as $item) {
            $plannedExpectedMinutes += (int) ($item['expected_time'] ?? 0);
            $plannedActualMinutes += (int) ($item['actual_time'] ?? 0);

            $plannedDate = (string) ($item['planned_date'] ?? '');
            $actualDate = !empty($item['task_due_date']) ? date('Y-m-d', strtotime((string) $item['task_due_date'])) : '';
            if ($plannedDate !== '' && $actualDate !== '' && $actualDate !== $plannedDate) {
                $movedPlannedTasks++;
            }
        }

        foreach ($weekTasks as $task) {
            $status = strtolower(trim((string) ($task['status'] ?? 'todo')));
            if (in_array($status, ['done', 'completed'], true)) {
                $completedWeekTasks++;
            }

            if ((string) ($task['type'] ?? '') === 'important-urgent') {
                $urgentImportantCount++;
            }

            $actualMinutes += (int) ($task['actual_time'] ?? 0);
        }

        return [
            'planned_items' => count($items),
            'week_task_count' => count($weekTasks),
            'completed_week_tasks' => $completedWeekTasks,
            'urgent_important_count' => $urgentImportantCount,
            'unplanned_count' => count($unplannedTasks),
            'actual_minutes' => $actualMinutes,
            'planned_expected_minutes' => $plannedExpectedMinutes,
            'planned_actual_minutes' => $plannedActualMinutes,
            'variance_minutes' => $plannedActualMinutes - $plannedExpectedMinutes,
            'moved_planned_tasks' => $movedPlannedTasks,
        ];
    }

    private function buildPlanAlerts(array $items, array $planStats): array
    {
        $alerts = [];
        $workdayMinutes = 8 * 60;
        $maxDayMinutes = (int) ($planStats['max_day_minutes'] ?? 0);
        $dayLoads = $planStats['day_loads'] ?? [];
        $nonUrgentDelegationFound = false;

        foreach ($items as $item) {
            if ((string) ($item['type'] ?? '') === 'not-important-urgent') {
                $nonUrgentDelegationFound = true;
                break;
            }
        }

        if ($maxDayMinutes > 5 * 60) {
            $alerts[] = ['level' => 'warning', 'text' => 'У плані є день з навантаженням понад 5 годин. Є ризик, що через незаплановані задачі тижневий план не виконається повністю.'];
        }

        if ($maxDayMinutes >= 7 * 60) {
            $alerts[] = ['level' => 'danger', 'text' => 'Окремі дні перевантажені майже на повний робочий день. Це занадто щільний план і його варто спростити або рознести.'];
        }

        $activeDays = array_values(array_filter($dayLoads, static fn($minutes) => (int) $minutes > 0));
        if (!empty($activeDays) && max($activeDays) <= (int) round($workdayMinutes * 0.7)) {
            $alerts[] = ['level' => 'success', 'text' => 'План виглядає здорово: кожен день завантажений не більше ніж на 60-70% робочого часу, є місце для незапланованих питань.'];
        }

        if ($nonUrgentDelegationFound) {
            $alerts[] = ['level' => 'info', 'text' => 'У плані є задачі типу "не важливі, але термінові". Їх краще делегувати, щоб не забирати фокус із важливого.'];
        }

        if (empty($items)) {
            $alerts[] = ['level' => 'info', 'text' => 'План ще порожній. Додайте задачі вручну, скопіюйте з іншого дня або підвантажте з шаблонів.'];
        }

        return $alerts;
    }

    private function buildFactAlerts(array $items, array $weekTasks, array $unplannedTasks, array $factStats): array
    {
        $alerts = [];
        $planStats = $this->buildPlanSummary($items);
        $completionRate = (int) ($planStats['completion_rate'] ?? 0);

        if ((int) ($factStats['urgent_important_count'] ?? 0) >= 3) {
            $alerts[] = ['level' => 'warning', 'text' => 'За тиждень накопичилось багато "важливих і термінових" задач. Це схоже на регулярне тушіння пожеж і сигнал до кращого планування.'];
        }

        if ($completionRate >= 70) {
            $alerts[] = ['level' => 'success', 'text' => 'Хороший темп: виконано понад 70% початкового плану. Це сильний тижневий результат.'];
        }

        if ((int) ($factStats['moved_planned_tasks'] ?? 0) >= 2) {
            $alerts[] = ['level' => 'info', 'text' => 'Частину планових задач було перенесено між днями. Це варто врахувати при наступному тижневому плануванні, щоб розклад був реалістичнішим.'];
        }

        $variance = (int) ($factStats['variance_minutes'] ?? 0);
        if ($variance > 90) {
            $alerts[] = ['level' => 'warning', 'text' => 'Фактичні витрати часу по планових задачах суттєво перевищили оцінку. Варто точніше оцінювати складність або закладати буфер.'];
        } elseif ($variance < -90 && !empty($items)) {
            $alerts[] = ['level' => 'success', 'text' => 'Планові задачі виконувались швидше за оцінку. Це означає, що в плані був запас або оцінки можна уточнити.'];
        }

        if (count($unplannedTasks) >= 3) {
            $alerts[] = ['level' => 'info', 'text' => 'За тиждень додалось багато позапланових задач. Варто переглянути буфер часу або ритм пріоритезації.'];
        }

        if (empty($weekTasks)) {
            $alerts[] = ['level' => 'info', 'text' => 'У факті ще немає задач на цей тиждень. Після додавання до плану вони автоматично зʼявляться в щоденних задачах.'];
        }

        return $alerts;
    }

    private function buildFactTasksByDay(array $weekTasks, array $plannedTaskOrder): array
    {
        $tasksByDay = [];

        foreach ($weekTasks as $index => $task) {
            $day = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : '';
            if ($day === '') {
                continue;
            }

            $tasksByDay[$day][] = [
                'task' => $task,
                'planned_order' => $plannedTaskOrder[(int) ($task['id'] ?? 0)] ?? null,
                'original_order' => $index,
            ];
        }

        foreach ($tasksByDay as $day => $entries) {
            usort($entries, static function (array $left, array $right): int {
                $leftPlanned = $left['planned_order'] !== null;
                $rightPlanned = $right['planned_order'] !== null;

                if ($leftPlanned !== $rightPlanned) {
                    return $leftPlanned ? -1 : 1;
                }

                if ($leftPlanned && $rightPlanned && $left['planned_order'] !== $right['planned_order']) {
                    return $left['planned_order'] <=> $right['planned_order'];
                }

                return $left['original_order'] <=> $right['original_order'];
            });

            $tasksByDay[$day] = array_map(static fn(array $entry): array => $entry['task'], $entries);
        }

        return $tasksByDay;
    }

    private function buildWeekTasksForPlan(array $allTasks, array $plan): array
    {
        $weekStart = (string) ($plan['week_start_date'] ?? '');
        $weekEnd = (string) ($plan['week_end_date'] ?? '');

        return array_values(array_filter($allTasks, static function ($task) use ($plan, $weekStart, $weekEnd) {
            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            if ($assigneeId !== (int) ($plan['user_id'] ?? 0)) {
                return false;
            }

            $dueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null;
            if ($dueDate === null) {
                return false;
            }

            return $dueDate >= $weekStart && $dueDate <= $weekEnd;
        }));
    }

    private function buildWeekDays(string $weekStartDate): array
    {
        $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Нд'];
        $days = [];

        for ($index = 0; $index < 7; $index++) {
            $date = date('Y-m-d', strtotime($weekStartDate . ' +' . $index . ' days'));
            $days[] = [
                'date' => $date,
                'label' => $labels[(int) date('N', strtotime($date))] ?? '',
                'display' => date('d.m', strtotime($date)),
            ];
        }

        return $days;
    }

    private function defaultPlanStartDate(): string
    {
        return date('Y-m-d', strtotime('+1 day'));
    }

    private function normalizePlanStartDate(string $rawDate): string
    {
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            $timestamp = strtotime($this->defaultPlanStartDate());
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeDateInWeek(string $rawDate, string $weekStartDate): ?string
    {
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            return null;
        }

        $date = date('Y-m-d', $timestamp);
        $weekEndDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));

        return ($date >= $weekStartDate && $date <= $weekEndDate) ? $date : null;
    }

    private function normalizeStartTime(string $rawTime): ?string
    {
        $time = trim($rawTime);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        return $time . ':00';
    }

    private function buildTaskDueDateTime(string $date, ?string $startTime): string
    {
        return $startTime === null ? $date : $date . ' ' . $startTime;
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

    private function normalizeRequiredTitle($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $title = trim((string) $value);
        return $title === '' ? null : $title;
    }

    private function resolveSubmittedTitle(array $post, string $existingTitle): ?string
    {
        if (!array_key_exists('title', $post)) {
            return $this->normalizeRequiredTitle($existingTitle);
        }

        return $this->normalizeRequiredTitle(post_param('title', null));
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
            $existingDueDate = !empty($existingTask['due_date']) ? date('Y-m-d', strtotime((string) ($existingTask['due_date'] ?? ''))) : null;
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

    private function getVisibleEmployees(array $employees, int $currentUserId): array
    {
        return array_values(array_filter($employees, static function ($employee) use ($currentUserId) {
            $employeeUserId = (int) ($employee['user_id'] ?? 0);
            $managerId = (int) ($employee['reports_to'] ?? 0);

            return $employeeUserId === $currentUserId || $managerId === $currentUserId;
        }));
    }

    private function getAccessibleEmployees(array $employees, int $currentUserId): array
    {
        if ($this->isOwnerInCompany($employees, $currentUserId)) {
            return array_values($employees);
        }

        return $this->getVisibleEmployees($employees, $currentUserId);
    }

    private function getScopedEmployees(array $employees, int $currentUserId, string $scope, bool $isOwner = false): array
    {
        if ($scope === 'company' && $isOwner) {
            return array_values($employees);
        }

        $visibleEmployees = $this->getVisibleEmployees($employees, $currentUserId);

        return array_values(array_filter($visibleEmployees, static function ($employee) use ($currentUserId, $scope) {
            $employeeUserId = (int) ($employee['user_id'] ?? 0);
            $managerId = (int) ($employee['reports_to'] ?? 0);

            return match ($scope) {
                'my' => $employeeUserId === $currentUserId,
                'subordinates' => $managerId === $currentUserId,
                default => true,
            };
        }));
    }

    private function normalizeScope(string $scope, bool $isOwner = false): string
    {
        $normalized = strtolower(trim($scope));
        $allowedScopes = ['all', 'my', 'subordinates'];
        if ($isOwner) {
            $allowedScopes[] = 'company';
        }

        return in_array($normalized, $allowedScopes, true) ? $normalized : 'all';
    }

    private function isOwnerInCompany(array $employees, int $currentUserId): bool
    {
        foreach ($employees as $employee) {
            if ((int) ($employee['user_id'] ?? 0) !== $currentUserId) {
                continue;
            }

            return strtolower(trim((string) ($employee['role'] ?? ''))) === 'owner';
        }

        return false;
    }

    private function extractEmployeeUserIds(array $employees): array
    {
        return array_values(array_unique(array_map(static function ($employee) {
            return (int) ($employee['user_id'] ?? 0);
        }, $employees)));
    }

    private function getAccessiblePlan(WeeklyPlan $weeklyPlanModel, int $companyId, int $planId, int $currentUserId): ?array
    {
        $plan = $weeklyPlanModel->getById($companyId, $planId);
        if (!$plan) {
            return null;
        }

        $employees = $this->getAccessibleEmployees((new Company())->get_employees($companyId), $currentUserId);
        $visibleUserIds = $this->extractEmployeeUserIds($employees);

        return in_array((int) ($plan['user_id'] ?? 0), $visibleUserIds, true) ? $plan : null;
    }
}