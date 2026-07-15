<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Database;
use App\Models\Project;
use App\Models\Result;
use App\Models\Task;
use App\Models\Template;
use App\Services\TelegramPlanFactService;
use App\Services\TemplateTaskMaterializerService;

class TelegramIntentCommandService
{
    private Task $taskModel;
    private Result $resultModel;
    private Template $templateModel;
    private Company $companyModel;
    private Project $projectModel;
    private Database $db;
    private TelegramPlanFactService $planFactService;

    public function __construct()
    {
        $this->taskModel = new Task();
        $this->resultModel = new Result();
        $this->templateModel = new Template();
        $this->companyModel = new Company();
        $this->projectModel = new Project();
        $this->db = new Database();
        $this->planFactService = new TelegramPlanFactService();
    }

    public function executeCommands(int $companyId, array $reporter, array $commands): ?array
    {
        if (empty($commands)) {
            return null;
        }

        $reporterId = (int) ($reporter['id'] ?? 0);
        if ($companyId <= 0 || $reporterId <= 0) {
            return null;
        }

        $employees = $this->companyModel->get_employees($companyId);
        $summary = ['goals' => 0, 'subGoals' => 0, 'tasks' => 0, 'templates' => 0, 'created' => [], 'updated' => []];
        $sections = [];
        $handled = false;

        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $name = strtolower(trim((string) ($command['name'] ?? $command['command'] ?? '')));
            $args = $command['args'] ?? [];
            if (!is_array($args) || $name === '') {
                continue;
            }

            switch ($name) {
                case 'manage_tasks':
                    $section = $this->handleManageTasks($companyId, $reporterId, $employees, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'manage_results':
                    $section = $this->handleManageResults($companyId, $reporterId, $employees, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'manage_templates':
                    $section = $this->handleManageTemplates($companyId, $reporterId, $employees, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'create_tasks':
                    $section = $this->handleCreateTasksWithReply($companyId, $reporterId, $employees, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'create_template':
                    $section = $this->handleCreateTemplatesWithReply($companyId, $reporterId, $employees, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'create_goal':
                    $this->handleCreateGoal($companyId, $reporterId, $employees, $args, $summary);
                    $handled = true;
                    break;

                case 'create_subgoal':
                    $this->handleCreateSubGoal($companyId, $reporterId, $employees, $args, $summary);
                    $handled = true;
                    break;

                case 'list_tasks':
                    $sections[] = $this->buildTaskListSection($companyId, $reporterId, $employees, $args, 'my');
                    $handled = true;
                    break;

                case 'list_goals':
                    $sections[] = $this->buildGoalListSection($companyId, $reporterId, $employees, $args, 'my');
                    $handled = true;
                    break;

                case 'list_subordinate_tasks':
                    $sections[] = $this->buildTaskListSection($companyId, $reporterId, $employees, $args, 'subordinates');
                    $handled = true;
                    break;

                case 'list_delegated_tasks':
                    $sections[] = $this->buildTaskListSection($companyId, $reporterId, $employees, $args, 'delegated');
                    $handled = true;
                    break;

                case 'show_plan_fact':
                    $sections[] = $this->planFactService->buildReply($companyId, $reporterId, $employees, $args);
                    $handled = true;
                    break;

                case 'list_projects':
                case 'manage_projects':
                    $section = $this->handleManageProjects($companyId, $reporterId, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;

                case 'create_project':
                    $section = $this->handleCreateProjectWithReply($companyId, $reporterId, $args, $summary);
                    if ($section !== '') {
                        $sections[] = $section;
                    }
                    $handled = true;
                    break;
            }
        }

        if (!$handled) {
            return null;
        }

        $replyParts = [];
        if ($summary['goals'] > 0 || $summary['subGoals'] > 0 || $summary['tasks'] > 0 || $summary['templates'] > 0) {
            $replyParts[] = $this->buildCreatedItemsReply($summary);
        }

        foreach ($sections as $section) {
            if (trim($section) !== '') {
                $replyParts[] = $section;
            }
        }

        return [
            'handled' => true,
            'reply' => trim(implode("\n\n", $replyParts)),
            'summary' => $summary,
        ];
    }

    private function handleManageTasks(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if ($action === '') {
            $action = !empty($args['tasks']) ? 'create' : 'list';
        }

        if ($action === 'create') {
            return $this->handleCreateTasksWithReply($companyId, $reporterId, $employees, $args, $summary);
        }

        if ($action === 'update') {
            return $this->handleUpdateTasks($companyId, $reporterId, $employees, $args, $summary);
        }

        $scope = strtolower(trim((string) ($args['scope'] ?? 'my')));
        $mode = match ($scope) {
            'delegated' => 'delegated',
            'subordinates', 'subordinate' => 'subordinates',
            default => 'my',
        };

        return $this->buildTaskListSection($companyId, $reporterId, $employees, $args, $mode);
    }

    private function handleManageResults(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if ($action === '') {
            $hasCreatePayload = !empty($args['results']) || !empty($args['items']) || !empty($args['tree']) || trim((string) ($args['title'] ?? '')) !== '';
            $action = $hasCreatePayload ? 'create' : 'list';
        }

        if ($action === 'create') {
            $nodes = $this->normalizeResultNodes($args);
            if (empty($nodes)) {
                return '';
            }

            $parentId = isset($args['parentId']) ? (int) $args['parentId'] : 0;
            if ($parentId <= 0) {
                $parentTitle = (string) ($args['parentTitle'] ?? $args['parentResultTitle'] ?? $args['targetTitle'] ?? '');
                $parentResult = $this->findResultByTitle($companyId, $parentTitle);
                $parentId = (int) ($parentResult['id'] ?? 0);
            }

            $this->createResultNodes($companyId, $reporterId, $employees, $nodes, $parentId > 0 ? $parentId : null, $reporterId, $summary);
            return '';
        }

        if ($action === 'update') {
            return $this->handleUpdateResults($companyId, $reporterId, $employees, $args, $summary);
        }

        $scope = strtolower(trim((string) ($args['scope'] ?? 'my')));
        $mode = match ($scope) {
            'delegated' => 'delegated',
            'subordinates', 'subordinate' => 'subordinates',
            'all' => 'all',
            default => 'my',
        };

        return $this->buildGoalListSection($companyId, $reporterId, $employees, $args, $mode);
    }

    private function handleManageTemplates(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if ($action === '') {
            $action = !empty($args['templates']) || trim((string) ($args['title'] ?? $args['name'] ?? '')) !== '' ? 'create' : '';
        }

        if ($action === 'create') {
            return $this->handleCreateTemplatesWithReply($companyId, $reporterId, $employees, $args, $summary);
        }

        if ($action === 'update') {
            return $this->handleUpdateTemplates($companyId, $reporterId, $employees, $args, $summary);
        }

        if ($action === 'delete') {
            return $this->handleDeleteTemplates($companyId, $args);
        }

        return '';
    }

    private function handleCreateTasks(int $companyId, int $reporterId, array $employees, array $args, array &$summary): void
    {
        $tasks = $args['tasks'] ?? [];
        if (!is_array($tasks)) {
            return;
        }

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $assigneeId = $this->resolveAssigneeId($employees, (string) ($task['assignee'] ?? ''), $reporterId);
            $type = (string) ($task['type'] ?? 'important-not-urgent');
            $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'important-not-urgent';
            }

            $this->taskModel->create([
                'title' => mb_substr($title, 0, 255),
                'company_id' => $companyId,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'status' => 'todo',
                'due_date' => $this->normalizeDate($task['date'] ?? $task['dueDate'] ?? null),
                'description' => $this->nullIfEmpty((string) ($task['description'] ?? '')),
                'expected_result' => $this->nullIfEmpty((string) ($task['expectedResult'] ?? '')),
                'expected_time' => $this->normalizeExpectedTime($task['expectedTime'] ?? $task['expected_time'] ?? null),
                'type' => $type,
            ]);

            $summary['tasks']++;
            $summary['created'][] = 'Задача: ' . mb_substr($title, 0, 255);
        }
    }

    private function handleCreateTasksWithReply(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $tasks = $args['tasks'] ?? [];
        if (!is_array($tasks) || empty($tasks)) {
            $title = trim((string) ($args['title'] ?? ''));
            if ($title !== '') {
                $tasks = [
                    [
                        'title' => $title,
                        'assignee' => (string) ($args['assignee'] ?? ''),
                        'date' => $args['date'] ?? null,
                        'startTime' => $args['startTime'] ?? $args['start_time'] ?? null,
                        'description' => $args['description'] ?? null,
                        'expectedResult' => $args['expectedResult'] ?? null,
                        'expectedTime' => $args['expectedTime'] ?? null,
                        'type' => $args['type'] ?? null,
                    ]
                ];
            }
        }

        if (!is_array($tasks) || empty($tasks)) {
            return '';
        }

        $blocks = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $assigneeId = $this->resolveAssigneeId($employees, (string) ($task['assignee'] ?? ''), $reporterId);
            $type = (string) ($task['type'] ?? 'important-not-urgent');
            $allowedTypes = ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'important-not-urgent';
            }

            $dueDate = $this->normalizeTaskDueDateTime($task['date'] ?? $task['dueDate'] ?? null, $task['startTime'] ?? $task['start_time'] ?? null);
            $description = $this->nullIfEmpty((string) ($task['description'] ?? ''));
            $expectedResult = $this->nullIfEmpty((string) ($task['expectedResult'] ?? ''));
            $expectedTime = $this->normalizeExpectedTime($task['expectedTime'] ?? $task['expected_time'] ?? null);
            $startTime = $this->normalizeStartTime($task['startTime'] ?? $task['start_time'] ?? null);

            $this->taskModel->create([
                'title' => mb_substr($title, 0, 255),
                'company_id' => $companyId,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'status' => 'todo',
                'due_date' => $dueDate,
                'description' => $description,
                'expected_result' => $expectedResult,
                'expected_time' => $expectedTime,
                'type' => $type,
            ]);

            $summary['tasks']++;
            $summary['created'][] = 'Задача: ' . mb_substr($title, 0, 255);
            $blocks[] = $this->buildTaskReplyBlock('📝 Задачу створено', [
                'title' => $title,
                'assignee_name' => $this->findEmployeeDisplayName($employees, $assigneeId),
                'due_date' => $dueDate,
                'start_time' => $startTime,
                'description' => $description,
                'expected_result' => $expectedResult,
                'expected_time' => $expectedTime,
                'type' => $type,
                'status' => 'todo',
            ]);
        }

        return implode("\n\n", array_filter($blocks));
    }

    private function handleCreateTemplates(int $companyId, int $reporterId, array $employees, array $args, array &$summary): void
    {
        $templates = $args['templates'] ?? [];
        if (!is_array($templates) || empty($templates)) {
            $name = trim((string) ($args['name'] ?? $args['title'] ?? ''));
            if ($name !== '') {
                $templates = [$args + ['name' => $name]];
            }
        }

        if (!is_array($templates)) {
            return;
        }

        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $name = trim((string) ($template['name'] ?? $template['title'] ?? ''));
            if ($name === '') {
                continue;
            }

            $this->templateModel->create([
                'company_id' => $companyId,
                'name' => mb_substr($name, 0, 255),
                'type' => $this->nullIfEmpty((string) ($template['type'] ?? '')),
                'description' => $this->nullIfEmpty((string) ($template['description'] ?? '')),
                'expected_result' => $this->nullIfEmpty((string) ($template['expectedResult'] ?? $template['expected_result'] ?? '')),
                'assignee_id' => $this->resolveAssigneeId($employees, (string) ($template['assignee'] ?? ''), $reporterId),
                'reporter_id' => $reporterId,
                'expected_time' => $this->normalizeExpectedTime($template['expectedTime'] ?? $template['expected_time'] ?? null),
                'repeat_type' => $this->normalizeRepeatType($template['repeatType'] ?? $template['repeat_type'] ?? null),
                'repeat_day' => $this->normalizeRepeatDay($template['repeatDay'] ?? $template['repeat_day'] ?? null),
                'start_time' => $this->normalizeStartTime($template['startTime'] ?? $template['start_time'] ?? null),
            ]);

            $summary['templates']++;
            $summary['created'][] = 'Шаблон: ' . mb_substr($name, 0, 255);
        }
    }

    private function handleCreateTemplatesWithReply(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $templates = $this->normalizeTemplatePayloads($args);
        if (empty($templates)) {
            return '';
        }

        $createdBlocks = [];
        foreach ($templates as $template) {
            $name = trim((string) ($template['name'] ?? $template['title'] ?? ''));
            if ($name === '') {
                continue;
            }

            $assigneeId = $this->resolveAssigneeId($employees, (string) ($template['assignee'] ?? ''), $reporterId);
            $assigneeName = $this->findEmployeeDisplayName($employees, $assigneeId);
            $expectedTime = $this->normalizeExpectedTime($template['expectedTime'] ?? $template['expected_time'] ?? null);
            $repeatType = $this->normalizeRepeatType($template['repeatType'] ?? $template['repeat_type'] ?? null);
            $repeatDay = $this->normalizeRepeatDay($template['repeatDay'] ?? $template['repeat_day'] ?? null);
            $startTime = $this->normalizeStartTime($template['startTime'] ?? $template['start_time'] ?? null);

            $this->templateModel->create([
                'company_id' => $companyId,
                'name' => mb_substr($name, 0, 255),
                'type' => $this->nullIfEmpty((string) ($template['type'] ?? '')),
                'description' => $this->nullIfEmpty((string) ($template['description'] ?? '')),
                'expected_result' => $this->nullIfEmpty((string) ($template['expectedResult'] ?? $template['expected_result'] ?? '')),
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'expected_time' => $expectedTime,
                'repeat_type' => $repeatType,
                'repeat_day' => $repeatDay,
                'start_time' => $startTime,
            ]);

            $summary['templates']++;
            $summary['created'][] = 'Шаблон: ' . mb_substr($name, 0, 255);
            $createdBlocks[] = $this->buildTemplateReplyBlock('🧩 Шаблон створено', [
                'name' => $name,
                'assignee_name' => $assigneeName,
                'expected_result' => $template['expectedResult'] ?? $template['expected_result'] ?? null,
                'expected_time' => $expectedTime,
                'repeat_type' => $repeatType,
                'repeat_day' => $repeatDay,
                'start_time' => $startTime,
            ]);
        }

        return implode("\n\n", array_filter($createdBlocks));
    }

    private function handleUpdateTasks(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $updates = $this->normalizeTaskUpdatePayloads($args);
        if (empty($updates)) {
            return 'Не вистачає даних для редагування задачі.';
        }

        $blocks = [];
        foreach ($updates as $update) {
            $targetTitle = trim((string) ($update['targetTitle'] ?? $update['currentTitle'] ?? $update['existingTitle'] ?? ''));
            if ($targetTitle === '') {
                $targetTitle = trim((string) ($update['title'] ?? ''));
            }

            $task = $this->findTaskByTitle($companyId, $targetTitle);
            if (!$task) {
                $blocks[] = '• Не знайшов задачу: ' . $targetTitle;
                continue;
            }

            $payload = [];
            $newTitle = trim((string) ($update['newTitle'] ?? ''));
            if ($newTitle !== '') {
                $payload['title'] = mb_substr($newTitle, 0, 255);
            }

            if (array_key_exists('assignee', $update)) {
                $payload['assignee_id'] = $this->resolveAssigneeId($employees, (string) ($update['assignee'] ?? ''), (int) ($task['assignee_id'] ?? $reporterId));
            }

            $date = $this->normalizeDate($update['date'] ?? $update['dueDate'] ?? null);
            if ($date !== null) {
                $existingStartTime = !empty($task['due_date']) ? date('H:i', strtotime((string) $task['due_date'])) : null;
                $payload['due_date'] = $this->normalizeTaskDueDateTime($date, $update['startTime'] ?? $update['start_time'] ?? $existingStartTime);
            }

            if ((array_key_exists('startTime', $update) || array_key_exists('start_time', $update)) && $date === null) {
                $existingDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null;
                $payload['due_date'] = $this->normalizeTaskDueDateTime($existingDate, $update['startTime'] ?? $update['start_time'] ?? null);
            }

            if (array_key_exists('description', $update)) {
                $payload['description'] = $this->nullIfEmpty((string) ($update['description'] ?? ''));
            }

            if (array_key_exists('expectedResult', $update) || array_key_exists('expected_result', $update)) {
                $payload['expected_result'] = $this->nullIfEmpty((string) ($update['expectedResult'] ?? $update['expected_result'] ?? ''));
            }

            if (array_key_exists('expectedTime', $update) || array_key_exists('expected_time', $update)) {
                $payload['expected_time'] = $this->normalizeExpectedTime($update['expectedTime'] ?? $update['expected_time'] ?? null);
            }

            if (array_key_exists('type', $update)) {
                $type = (string) ($update['type'] ?? '');
                if (in_array($type, ['important-urgent', 'important-not-urgent', 'not-important-urgent', 'not-important-not-urgent'], true)) {
                    $payload['type'] = $type;
                }
            }

            if (array_key_exists('status', $update)) {
                $status = $this->normalizeTaskStatus((string) ($update['status'] ?? ''));
                if ($status !== null) {
                    $payload['status'] = $status;
                }
            }

            if (empty($payload)) {
                $blocks[] = '• Для задачі "' . ((string) ($task['title'] ?? $targetTitle)) . '" немає змін.';
                continue;
            }

            $this->taskModel->update((int) ($task['id'] ?? 0), $payload);
            $finalTitle = (string) ($payload['title'] ?? $task['title'] ?? $targetTitle);
            $summary['updated'][] = 'Задача: ' . $finalTitle;
            $mergedTask = $task;
            foreach ($payload as $key => $value) {
                $mergedTask[$key] = $value;
            }

            $blocks[] = $this->buildTaskReplyBlock('✏️ Задачу оновлено', [
                'title' => $mergedTask['title'] ?? $finalTitle,
                'assignee_name' => $this->findEmployeeDisplayName($employees, (int) ($mergedTask['assignee_id'] ?? 0)),
                'due_date' => $mergedTask['due_date'] ?? null,
                'start_time' => !empty($mergedTask['due_date']) ? date('H:i', strtotime((string) $mergedTask['due_date'])) : null,
                'description' => $mergedTask['description'] ?? null,
                'expected_result' => $mergedTask['expected_result'] ?? null,
                'expected_time' => $mergedTask['expected_time'] ?? null,
                'type' => $mergedTask['type'] ?? null,
                'status' => $mergedTask['status'] ?? null,
            ]);
        }

        return implode("\n\n", array_filter($blocks));
    }

    private function handleUpdateResults(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $updates = $this->normalizeResultUpdatePayloads($args);
        if (empty($updates)) {
            return 'Не вистачає даних для редагування цілі.';
        }

        $lines = [];
        foreach ($updates as $update) {
            $targetTitle = trim((string) ($update['targetTitle'] ?? $update['currentTitle'] ?? $update['existingTitle'] ?? $update['title'] ?? ''));
            $result = $this->findResultByTitle($companyId, $targetTitle);
            if (!$result) {
                $lines[] = '• Не знайшов ціль: ' . $targetTitle;
                continue;
            }

            $payload = [];
            $newTitle = trim((string) ($update['newTitle'] ?? ''));
            if ($newTitle !== '') {
                $payload['title'] = mb_substr($newTitle, 0, 255);
            }

            if (array_key_exists('description', $update)) {
                $payload['description'] = $this->nullIfEmpty((string) ($update['description'] ?? ''));
            }

            if (array_key_exists('assignee', $update)) {
                $payload['assignee_id'] = $this->resolveAssigneeId($employees, (string) ($update['assignee'] ?? ''), (int) ($result['assignee_id'] ?? $reporterId));
            }

            if (array_key_exists('status', $update) || array_key_exists('completed', $update)) {
                $payload['completed'] = $this->normalizeResultCompleted($update['status'] ?? $update['completed'] ?? null);
            }

            if (empty($payload)) {
                $lines[] = '• Для цілі "' . ((string) ($result['title'] ?? $targetTitle)) . '" немає змін.';
                continue;
            }

            $this->resultModel->update((int) ($result['id'] ?? 0), $payload);
            $finalTitle = (string) ($payload['title'] ?? $result['title'] ?? $targetTitle);
            $summary['updated'][] = 'Ціль: ' . $finalTitle;
            $lines[] = '• Ціль оновлена: ' . $finalTitle;
        }

        return empty($lines) ? '' : "✏️ Оновлення цілей\n" . implode("\n", $lines);
    }

    private function handleUpdateTemplates(int $companyId, int $reporterId, array $employees, array $args, array &$summary): string
    {
        $updates = $this->normalizeTemplatePayloads($args);
        if (empty($updates)) {
            return 'Не вистачає даних для редагування шаблону.';
        }

        $blocks = [];
        foreach ($updates as $update) {
            $targetTitle = trim((string) ($update['targetTitle'] ?? $update['currentTitle'] ?? $update['existingTitle'] ?? $update['name'] ?? $update['title'] ?? ''));
            $template = $this->findTemplateByTitle($companyId, $targetTitle);
            if (!$template) {
                $blocks[] = '• Не знайшов шаблон: ' . $targetTitle;
                continue;
            }

            $payload = [];
            $newTitle = trim((string) ($update['newTitle'] ?? ''));
            if ($newTitle !== '') {
                $payload['name'] = mb_substr($newTitle, 0, 255);
            }

            if (array_key_exists('description', $update)) {
                $payload['description'] = $this->nullIfEmpty((string) ($update['description'] ?? ''));
            }

            if (array_key_exists('expectedResult', $update) || array_key_exists('expected_result', $update)) {
                $payload['expected_result'] = $this->nullIfEmpty((string) ($update['expectedResult'] ?? $update['expected_result'] ?? ''));
            }

            if (array_key_exists('expectedTime', $update) || array_key_exists('expected_time', $update)) {
                $payload['expected_time'] = $this->normalizeExpectedTime($update['expectedTime'] ?? $update['expected_time'] ?? null);
            }

            if (array_key_exists('repeatType', $update) || array_key_exists('repeat_type', $update)) {
                $payload['repeat_type'] = $this->normalizeRepeatType($update['repeatType'] ?? $update['repeat_type'] ?? null);
            }

            if (array_key_exists('repeatDay', $update) || array_key_exists('repeat_day', $update)) {
                $payload['repeat_day'] = $this->normalizeRepeatDay($update['repeatDay'] ?? $update['repeat_day'] ?? null);
            }

            if (array_key_exists('startTime', $update) || array_key_exists('start_time', $update)) {
                $payload['start_time'] = $this->normalizeStartTime($update['startTime'] ?? $update['start_time'] ?? null);
            }

            if (array_key_exists('type', $update)) {
                $payload['type'] = $this->nullIfEmpty((string) ($update['type'] ?? ''));
            }

            if (array_key_exists('assignee', $update)) {
                $payload['assignee_id'] = $this->resolveAssigneeId($employees, (string) ($update['assignee'] ?? ''), (int) ($template['assignee_id'] ?? $reporterId));
            }

            if (empty($payload)) {
                $blocks[] = '• Для шаблону "' . ((string) ($template['name'] ?? $targetTitle)) . '" немає змін.';
                continue;
            }

            $this->templateModel->update((int) ($template['id'] ?? 0), $payload);
            $mergedTemplate = $template;
            foreach ($payload as $key => $value) {
                $mergedTemplate[$key] = $value;
            }

            $summary['updated'][] = 'Шаблон: ' . ((string) ($payload['name'] ?? $template['name'] ?? $targetTitle));
            $blocks[] = $this->buildTemplateReplyBlock('✏️ Шаблон оновлено', [
                'name' => $mergedTemplate['name'] ?? $template['name'] ?? $targetTitle,
                'assignee_name' => $this->findEmployeeDisplayName($employees, (int) ($mergedTemplate['assignee_id'] ?? 0)),
                'expected_result' => $mergedTemplate['expected_result'] ?? null,
                'expected_time' => $mergedTemplate['expected_time'] ?? null,
                'repeat_type' => $mergedTemplate['repeat_type'] ?? 'none',
                'repeat_day' => $mergedTemplate['repeat_day'] ?? null,
                'start_time' => $mergedTemplate['start_time'] ?? null,
            ]);
        }

        return implode("\n\n", array_filter($blocks));
    }

    private function handleCreateGoal(int $companyId, int $reporterId, array $employees, array $args, array &$summary): void
    {
        $node = [
            'title' => (string) ($args['title'] ?? ''),
            'description' => $args['description'] ?? null,
            'assignee' => (string) ($args['assignee'] ?? ''),
            'children' => $args['children'] ?? $args['subGoals'] ?? [],
        ];

        $this->createResultNodes($companyId, $reporterId, $employees, [$node], null, $reporterId, $summary);
    }

    private function handleCreateSubGoal(int $companyId, int $reporterId, array $employees, array $args, array &$summary): void
    {
        $parentGoalId = isset($args['parentGoalId']) ? (int) $args['parentGoalId'] : 0;
        if ($parentGoalId <= 0) {
            $parentGoal = $this->findResultByTitle($companyId, (string) ($args['parentGoalTitle'] ?? $args['parentTitle'] ?? $args['goalTitle'] ?? ''));
            $parentGoalId = (int) ($parentGoal['id'] ?? 0);
        }

        if ($parentGoalId <= 0) {
            return;
        }

        $nodes = $this->normalizeResultNodes($args);
        if (empty($nodes)) {
            return;
        }

        $this->createResultNodes($companyId, $reporterId, $employees, $nodes, $parentGoalId, $reporterId, $summary);
    }

    private function handleManageProjects(int $companyId, int $reporterId, array $args, array &$summary): string
    {
        $action = strtolower(trim((string) ($args['action'] ?? 'list')));

        if ($action === 'create') {
            return $this->handleCreateProjectWithReply($companyId, $reporterId, $args, $summary);
        }

        // Default: list
        $projects = $this->projectModel->get_by_company($companyId);

        if (empty($projects)) {
            return '📁 Проектів ще немає.';
        }

        $lines = ['📁 Проекти:'];
        foreach (array_slice($projects, 0, 15) as $project) {
            $name = (string) ($project['name'] ?? '');
            $memberCount = (int) ($project['member_count'] ?? 0);
            $line = '• ' . $name;
            if ($memberCount > 0) {
                $line .= ' — ' . $memberCount . ' учасн.';
            }
            $lines[] = $line;
        }

        if (count($projects) > 15) {
            $lines[] = '• ➕ Ще проектів: ' . (count($projects) - 15);
        }

        return implode("\n", $lines);
    }

    private function handleCreateProjectWithReply(int $companyId, int $reporterId, array $args, array &$summary): string
    {
        $name = trim((string) ($args['name'] ?? $args['title'] ?? ''));
        if ($name === '') {
            return '⚠️ Вкажіть назву проекту.';
        }

        $description = trim((string) ($args['description'] ?? ''));
        $projectId = $this->projectModel->create($companyId, $reporterId, mb_substr($name, 0, 255), $description);

        if ($projectId <= 0) {
            return '⚠️ Не вдалося створити проект.';
        }

        $summary['created'][] = 'Проект: ' . mb_substr($name, 0, 255);

        $lines = ['✅ Проект створено', '• Назва: ' . $name];
        if ($description !== '') {
            $lines[] = '• Опис: ' . $description;
        }

        return implode("\n", $lines);
    }

    private function handleDeleteTemplates(int $companyId, array $args): string
    {
        $targetTitle = trim((string) ($args['targetTitle'] ?? $args['currentTitle'] ?? $args['existingTitle'] ?? $args['name'] ?? $args['title'] ?? ''));
        if ($targetTitle === '') {
            return '⚠️ Вкажіть назву шаблону для видалення.';
        }

        $row = $this->db
            ->query('SELECT id, name FROM templates WHERE company_id = :company_id AND LOWER(name) LIKE :name LIMIT 1')
            ->bind(':company_id', $companyId)
            ->bind(':name', '%' . mb_strtolower($targetTitle) . '%')
            ->fetch();

        if (!$row) {
            return '⚠️ Шаблон не знайдено: ' . $targetTitle;
        }

        $this->db
            ->query('DELETE FROM templates WHERE id = :id')
            ->bind(':id', (int) $row['id'])
            ->execute();

        return '✅ Шаблон видалено: ' . (string) ($row['name'] ?? $targetTitle);
    }

    private function buildTaskListSection(int $companyId, int $reporterId, array $employees, array $args, string $mode): string
    {
        $date = $this->normalizeDate($args['date'] ?? 'today') ?? date('Y-m-d');
        (new TemplateTaskMaterializerService())->ensureTasksForDate($companyId, $date);
        $tasks = $this->taskModel->get_by_company($companyId);
        $dateLabel = $this->formatShortTelegramDate($date);
        $status = strtolower(trim((string) ($args['status'] ?? 'active')));
        $status = $status === '' ? 'active' : $status;

        $subordinateIds = [];
        foreach ($employees as $employee) {
            if ((int) ($employee['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($employee['user_id'] ?? 0);
            }
        }

        $filtered = array_values(array_filter($tasks, function ($task) use ($reporterId, $date, $status, $mode, $subordinateIds, $args, $employees) {
            $taskDueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null;
            if ($date !== '' && $taskDueDate !== $date) {
                return false;
            }

            $taskStatus = strtolower((string) ($task['status'] ?? 'todo'));
            if ($status !== 'all') {
                if ($status === 'active' && !in_array($taskStatus, ['todo', 'in-progress'], true)) {
                    return false;
                }
                if ($status !== 'active' && $taskStatus !== $status) {
                    return false;
                }
            }

            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            $reporterTaskId = (int) ($task['reporter_id'] ?? 0);

            if ($mode === 'my' && $assigneeId !== $reporterId) {
                return false;
            }

            if ($mode === 'delegated' && !($reporterTaskId === $reporterId && $assigneeId !== $reporterId)) {
                return false;
            }

            if ($mode === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            $assigneeFilter = trim((string) ($args['assignee'] ?? ''));
            if ($assigneeFilter !== '') {
                $resolvedAssigneeId = $this->resolveAssigneeId($employees, $assigneeFilter, 0);
                if ($resolvedAssigneeId > 0 && $assigneeId !== $resolvedAssigneeId) {
                    return false;
                }
            }

            return true;
        }));

        $title = match ($mode) {
            'delegated' => '📤 Делеговані задачі',
            'subordinates' => '👥 Задачі підлеглих',
            default => '🗂️ Задачі',
        };

        $lines = [$title . ' на ' . $dateLabel . ':'];
        if (empty($filtered)) {
            $lines[] = '• На цю дату задач немає';
            return implode("\n", $lines);
        }

        foreach (array_slice($filtered, 0, 12) as $task) {
            $assigneeName = trim((string) ($task['assignee_first_name'] ?? '') . ' ' . (string) ($task['assignee_last_name'] ?? ''));
            $statusLabel = (string) ($task['status'] ?? 'todo');
            $line = '• ' . $this->formatTelegramStatusMarker($statusLabel, (int) ($task['completed'] ?? 0) === 1) . ' ' . (string) ($task['title'] ?? '');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
        }

        if (count($filtered) > 12) {
            $lines[] = '• ➕ Ще задач: ' . (count($filtered) - 12);
        }

        return implode("\n", $lines);
    }

    private function buildGoalListSection(int $companyId, int $reporterId, array $employees, array $args, string $mode): string
    {
        $goals = $this->resultModel->get_by_company($companyId);
        $subGoals = $this->resultModel->get_all_sub_results($companyId);
        $status = strtolower(trim((string) ($args['status'] ?? 'all')));
        $status = $status === '' ? 'all' : $status;

        $subordinateIds = [];
        foreach ($employees as $employee) {
            if ((int) ($employee['reports_to'] ?? 0) === $reporterId) {
                $subordinateIds[] = (int) ($employee['user_id'] ?? 0);
            }
        }

        $childrenMap = [];
        foreach ($subGoals as $subGoal) {
            $parentId = (int) ($subGoal['parent_id'] ?? 0);
            if ($parentId <= 0) {
                continue;
            }

            $childrenMap[$parentId][] = $subGoal;
        }

        $filtered = array_values(array_filter($goals, function ($goal) use ($reporterId, $status, $mode, $subordinateIds, $args, $employees) {
            $completed = (int) ($goal['completed'] ?? 0) === 1;
            if ($status !== 'all') {
                if ($status === 'active' && $completed) {
                    return false;
                }

                if (in_array($status, ['done', 'completed'], true) && !$completed) {
                    return false;
                }
            }

            $assigneeId = (int) ($goal['assignee_id'] ?? 0);
            $reporterGoalId = (int) ($goal['reporter_id'] ?? 0);

            if ($mode === 'my' && $assigneeId !== $reporterId) {
                return false;
            }

            if ($mode === 'delegated' && !($reporterGoalId === $reporterId && $assigneeId !== $reporterId)) {
                return false;
            }

            if ($mode === 'subordinates' && !in_array($assigneeId, $subordinateIds, true)) {
                return false;
            }

            $assigneeFilter = trim((string) ($args['assignee'] ?? ''));
            if ($assigneeFilter !== '') {
                $resolvedAssigneeId = $this->resolveAssigneeId($employees, $assigneeFilter, 0);
                if ($resolvedAssigneeId > 0 && $assigneeId !== $resolvedAssigneeId) {
                    return false;
                }
            }

            return true;
        }));

        $title = match ($mode) {
            'delegated' => '📤 Делеговані цілі',
            'subordinates' => '👥 Цілі підлеглих',
            'all' => '🎯 Цілі',
            default => '🎯 Мої цілі',
        };

        $lines = [$title . ':'];
        if (empty($filtered)) {
            $lines[] = '• Нічого не знайдено';
            return implode("\n", $lines);
        }

        foreach (array_slice($filtered, 0, 12) as $goal) {
            $goalId = (int) ($goal['id'] ?? 0);
            $assigneeName = trim((string) ($goal['assignee_first_name'] ?? '') . ' ' . (string) ($goal['assignee_last_name'] ?? ''));
            $line = '• ' . $this->formatTelegramStatusMarker((string) ($goal['status'] ?? ''), (int) ($goal['completed'] ?? 0) === 1) . ' ' . (string) ($goal['title'] ?? '');
            if ($assigneeName !== '') {
                $line .= ' — ' . $assigneeName;
            }
            $lines[] = $line;
            $remainingChildren = 24;
            $this->appendChildResultLines($lines, $childrenMap, $goalId, 1, $remainingChildren);
        }

        if (count($filtered) > 12) {
            $lines[] = '• ➕ Ще цілей: ' . (count($filtered) - 12);
        }

        return implode("\n", $lines);
    }

    private function buildCreatedItemsReply(array $summary): string
    {
        $lines = ['✅ Готово, збережено у FINEKO:'];

        foreach (array_slice($summary['created'] ?? [], 0, 5) as $itemLabel) {
            $lines[] = '• ' . $itemLabel;
        }

        if (($summary['goals'] ?? 0) > 0) {
            $lines[] = '🎯 Цілей: ' . $summary['goals'];
        }
        if (($summary['subGoals'] ?? 0) > 0) {
            $lines[] = '🧩 Підцілей: ' . $summary['subGoals'];
        }
        if (($summary['tasks'] ?? 0) > 0) {
            $lines[] = '📝 Задач: ' . $summary['tasks'];
        }
        if (($summary['templates'] ?? 0) > 0) {
            $lines[] = '🧠 Шаблонів: ' . $summary['templates'];
        }
        if (!empty($summary['updated'])) {
            $lines[] = '✏️ Оновлено: ' . count($summary['updated']);
        }

        return implode("\n", $lines);
    }

    private function createResultNodes(int $companyId, int $reporterId, array $employees, array $nodes, ?int $parentId, int $defaultAssigneeId, array &$summary): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $title = trim((string) ($node['title'] ?? $node['name'] ?? ''));
            if ($title === '') {
                continue;
            }

            $assigneeId = $this->resolveAssigneeId($employees, (string) ($node['assignee'] ?? ''), $defaultAssigneeId ?: $reporterId);
            $this->db->insert('results', [
                'title' => mb_substr($title, 0, 255),
                'company_id' => $companyId,
                'assignee_id' => $assigneeId,
                'reporter_id' => $reporterId,
                'description' => $this->nullIfEmpty((string) ($node['description'] ?? '')),
                'parent_id' => $parentId,
                'completed' => 0,
            ]);

            $resultId = (int) $this->db->lastInsertId();
            if ($parentId === null) {
                $summary['goals']++;
                $summary['created'][] = 'Ціль: ' . mb_substr($title, 0, 255);
            } else {
                $summary['subGoals']++;
                $summary['created'][] = 'Підціль: ' . mb_substr($title, 0, 255);
            }

            $children = $this->extractChildrenNodes($node);
            if ($resultId > 0 && !empty($children)) {
                $this->createResultNodes($companyId, $reporterId, $employees, $children, $resultId, $assigneeId ?: $defaultAssigneeId, $summary);
            }
        }
    }

    private function normalizeResultNodes(array $args): array
    {
        $nodes = $args['results'] ?? $args['items'] ?? $args['tree'] ?? [];
        if (is_array($nodes) && !empty($nodes)) {
            return array_values(array_filter($nodes, 'is_array'));
        }

        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            return [];
        }

        return [
            [
                'title' => $title,
                'description' => $args['description'] ?? null,
                'assignee' => $args['assignee'] ?? '',
                'children' => $args['children'] ?? $args['subGoals'] ?? [],
            ]
        ];
    }

    private function normalizeTaskUpdatePayloads(array $args): array
    {
        $tasks = $args['tasks'] ?? [];
        if (is_array($tasks) && !empty($tasks)) {
            return array_values(array_filter($tasks, 'is_array'));
        }

        return [array_filter($args, static fn($value) => $value !== null)];
    }

    private function normalizeResultUpdatePayloads(array $args): array
    {
        $results = $args['results'] ?? [];
        if (is_array($results) && !empty($results)) {
            return array_values(array_filter($results, 'is_array'));
        }

        return [array_filter($args, static fn($value) => $value !== null)];
    }

    private function normalizeTemplatePayloads(array $args): array
    {
        $templates = $args['templates'] ?? [];
        if (is_array($templates) && !empty($templates)) {
            return array_values(array_filter($templates, 'is_array'));
        }

        $name = trim((string) ($args['name'] ?? $args['title'] ?? ''));
        if ($name === '' && trim((string) ($args['targetTitle'] ?? '')) === '') {
            return [];
        }

        return [array_filter($args, static fn($value) => $value !== null)];
    }

    private function extractChildrenNodes(array $node): array
    {
        $children = $node['children'] ?? $node['subGoals'] ?? [];
        return is_array($children) ? array_values(array_filter($children, 'is_array')) : [];
    }

    private function appendChildResultLines(array &$lines, array $childrenMap, int $parentId, int $depth, int &$remaining): void
    {
        if ($remaining <= 0 || empty($childrenMap[$parentId])) {
            return;
        }

        foreach ($childrenMap[$parentId] as $child) {
            if ($remaining <= 0) {
                break;
            }

            $title = trim((string) ($child['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $lines[] = str_repeat('  ', $depth) . '↳ ' . $this->formatTelegramStatusMarker((string) ($child['status'] ?? ''), (int) ($child['completed'] ?? 0) === 1) . ' ' . $title;
            $remaining--;
            $this->appendChildResultLines($lines, $childrenMap, (int) ($child['id'] ?? 0), $depth + 1, $remaining);
        }
    }

    private function findResultByTitle(int $companyId, string $title): ?array
    {
        $needle = mb_strtolower(trim($title));
        if ($needle === '') {
            return null;
        }

        $results = array_merge(
            $this->resultModel->get_by_company($companyId),
            $this->resultModel->get_all_sub_results($companyId)
        );

        foreach ($results as $result) {
            $resultTitle = mb_strtolower(trim((string) ($result['title'] ?? '')));
            if ($resultTitle === $needle) {
                return $result;
            }
        }

        foreach ($results as $result) {
            $resultTitle = mb_strtolower(trim((string) ($result['title'] ?? '')));
            if ($resultTitle !== '' && (str_contains($resultTitle, $needle) || str_contains($needle, $resultTitle))) {
                return $result;
            }
        }

        return null;
    }

    private function findTaskByTitle(int $companyId, string $title): ?array
    {
        $needle = mb_strtolower(trim($title));
        if ($needle === '') {
            return null;
        }

        $tasks = $this->taskModel->get_by_company($companyId);
        foreach ($tasks as $task) {
            $taskTitle = mb_strtolower(trim((string) ($task['title'] ?? '')));
            if ($taskTitle === $needle) {
                return $task;
            }
        }

        foreach ($tasks as $task) {
            $taskTitle = mb_strtolower(trim((string) ($task['title'] ?? '')));
            if ($taskTitle !== '' && (str_contains($taskTitle, $needle) || str_contains($needle, $taskTitle))) {
                return $task;
            }
        }

        return null;
    }

    private function findTemplateByTitle(int $companyId, string $title): ?array
    {
        $needle = mb_strtolower(trim($title));
        if ($needle === '') {
            return null;
        }

        $templates = $this->templateModel->get_by_company($companyId);
        foreach ($templates as $template) {
            $templateTitle = mb_strtolower(trim((string) ($template['name'] ?? '')));
            if ($templateTitle === $needle) {
                return $template;
            }
        }

        foreach ($templates as $template) {
            $templateTitle = mb_strtolower(trim((string) ($template['name'] ?? '')));
            if ($templateTitle !== '' && (str_contains($templateTitle, $needle) || str_contains($needle, $templateTitle))) {
                return $template;
            }
        }

        return null;
    }

    private function findEmployeeDisplayName(array $employees, int $userId): string
    {
        foreach ($employees as $employee) {
            if ((int) ($employee['user_id'] ?? 0) !== $userId) {
                continue;
            }

            return trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
        }

        return '';
    }

    private function buildTemplateReplyBlock(string $heading, array $template): string
    {
        $lines = [$heading . ':'];
        $lines[] = '• Назва: ' . (string) ($template['name'] ?? '—');

        $repeatLabel = $this->formatRepeatLabel((string) ($template['repeat_type'] ?? 'none'), $template['repeat_day'] ?? null);
        $lines[] = '• Повторення: ' . $repeatLabel;

        $expectedTime = $this->normalizeExpectedTime($template['expected_time'] ?? null);
        $lines[] = '• Очікуваний час: ' . ($expectedTime !== null ? $expectedTime . ' хв' : '—');

        $startTime = trim((string) ($template['start_time'] ?? ''));
        if ($startTime !== '') {
            $lines[] = '• Час старту: ' . $startTime;
        }

        $assigneeName = trim((string) ($template['assignee_name'] ?? ''));
        if ($assigneeName !== '') {
            $lines[] = '• Виконавець: ' . $assigneeName;
        }

        $expectedResult = trim((string) ($template['expected_result'] ?? ''));
        if ($expectedResult !== '') {
            $lines[] = '• Очікуваний результат: ' . $expectedResult;
        }

        return implode("\n", $lines);
    }

    private function buildTaskReplyBlock(string $heading, array $task): string
    {
        $lines = [$heading . ':'];
        $lines[] = '• Назва: ' . (string) ($task['title'] ?? '—');

        $dueDate = trim((string) ($task['due_date'] ?? ''));
        $lines[] = '• На коли: ' . ($dueDate !== '' ? $this->formatShortTelegramDate($dueDate) : '—');

        $startTime = trim((string) ($task['start_time'] ?? ''));
        if ($startTime === '' && $dueDate !== '') {
            $timestamp = strtotime($dueDate);
            if ($timestamp !== false) {
                $derivedStartTime = date('H:i', $timestamp);
                if ($derivedStartTime !== '00:00') {
                    $startTime = $derivedStartTime;
                }
            }
        }
        $lines[] = '• Час старту: ' . ($startTime !== '' ? $startTime : '—');

        $assigneeName = trim((string) ($task['assignee_name'] ?? ''));
        if ($assigneeName !== '') {
            $lines[] = '• Виконавець: ' . $assigneeName;
        }

        $description = trim((string) ($task['description'] ?? ''));
        $lines[] = '• Опис: ' . ($description !== '' ? $description : '—');

        $expectedResult = trim((string) ($task['expected_result'] ?? ''));
        $lines[] = '• Очікуваний результат: ' . ($expectedResult !== '' ? $expectedResult : '—');

        $expectedTime = $this->normalizeExpectedTime($task['expected_time'] ?? null);
        $lines[] = '• Очікуваний час: ' . ($expectedTime !== null ? $expectedTime . ' хв' : '—');

        $typeLabel = $this->formatTaskTypeLabel((string) ($task['type'] ?? ''));
        $lines[] = '• Тип: ' . ($typeLabel !== '' ? $typeLabel : '—');

        $statusLabel = $this->formatTaskStatusLabel((string) ($task['status'] ?? ''));
        if ($statusLabel !== '') {
            $lines[] = '• Статус: ' . $statusLabel;
        }

        return implode("\n", $lines);
    }

    private function formatTaskTypeLabel(string $type): string
    {
        return match (trim($type)) {
            'important-urgent' => 'Важлива і термінова',
            'important-not-urgent' => 'Важлива і нетермінова',
            'not-important-urgent' => 'Неважлива і термінова',
            'not-important-not-urgent' => 'Неважлива і нетермінова',
            default => '',
        };
    }

    private function formatTaskStatusLabel(string $status): string
    {
        return match (trim(strtolower($status))) {
            'todo' => 'Нова',
            'in-progress' => 'В роботі',
            'done', 'completed' => 'Виконана',
            'postponed' => 'Відкладена',
            default => '',
        };
    }

    private function formatRepeatLabel(string $repeatType, ?string $repeatDay): string
    {
        $repeatType = strtolower(trim($repeatType));

        return match ($repeatType) {
            'daily' => 'Щодня',
            'weekly' => 'Щотижня' . ($repeatDay ? ' (' . $repeatDay . ')' : ''),
            'monthly' => 'Щомісяця',
            default => 'Не повторюється',
        };
    }

    private function normalizeTaskStatus(string $status): ?string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return null;
        }

        return match ($status) {
            'todo', 'to-do', 'нова' => 'todo',
            'in-progress', 'in progress', 'в роботі' => 'in-progress',
            'done', 'completed', 'готово', 'виконано' => 'done',
            'postponed', 'відкладено' => 'postponed',
            default => null,
        };
    }

    private function normalizeResultCompleted(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $normalized = strtolower(trim((string) ($value ?? '')));
        if (in_array($normalized, ['1', 'true', 'done', 'completed', 'виконано', 'готово'], true)) {
            return 1;
        }

        return 0;
    }

    private function resolveAssigneeId(array $employees, string $assigneeText, int $defaultId): int
    {
        $needle = mb_strtolower(trim($assigneeText));
        if ($needle === '') {
            return $defaultId;
        }

        foreach ($employees as $employee) {
            $first = mb_strtolower((string) ($employee['first_name'] ?? ''));
            $last = mb_strtolower((string) ($employee['last_name'] ?? ''));
            $full = trim($first . ' ' . $last);
            $title = mb_strtolower((string) ($employee['title'] ?? ''));

            if ($needle === $first || $needle === $last || $needle === $full || ($title !== '' && $needle === $title)) {
                return (int) ($employee['user_id'] ?? $defaultId);
            }

            if ($first !== '' && str_contains($needle, $first)) {
                return (int) ($employee['user_id'] ?? $defaultId);
            }

            if ($last !== '' && str_contains($needle, $last)) {
                return (int) ($employee['user_id'] ?? $defaultId);
            }
        }

        return $defaultId;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);
        if (in_array($lower, ['today', 'сьогодні'], true)) {
            return date('Y-m-d');
        }

        if (in_array($lower, ['tomorrow', 'завтра'], true)) {
            return date('Y-m-d', strtotime('+1 day'));
        }

        $date = \DateTime::createFromFormat('Y-m-d', $raw);
        if ($date && $date->format('Y-m-d') === $raw) {
            return $raw;
        }

        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    private function normalizeExpectedTime(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $minutes = (int) $value;
            return $minutes > 0 ? $minutes : null;
        }

        $raw = mb_strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*(год|годин|година|h|hr|hrs)/u', $raw, $match)) {
            return ((int) $match[1]) * 60;
        }

        if (preg_match('/(\d+)\s*(хв|хвилин|хвилина|min|mins|minutes)/u', $raw, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/(\d+)/u', $raw, $match)) {
            $minutes = (int) $match[1];
            return $minutes > 0 ? $minutes : null;
        }

        return null;
    }

    private function normalizeRepeatType(mixed $value): string
    {
        $raw = mb_strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return 'none';
        }

        if (in_array($raw, ['daily', 'щодня', 'щоденний', 'щоденно', 'кожен день', 'кожного дня'], true)) {
            return 'daily';
        }

        if (in_array($raw, ['weekly', 'щотижня', 'щотижневий', 'щотижнево', 'кожного тижня'], true)) {
            return 'weekly';
        }

        if (in_array($raw, ['monthly', 'щомісяця', 'щомісячний', 'щомісячно', 'кожного місяця'], true)) {
            return 'monthly';
        }

        return 'none';
    }

    private function normalizeRepeatDay(mixed $value): ?string
    {
        $raw = mb_strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }

        return match ($raw) {
            'пн', 'понеділок', 'monday', 'mon' => 'Пн',
            'вт', 'вівторок', 'вiвторок', 'tuesday', 'tue' => 'Вт',
            'ср', 'середа', 'wednesday', 'wed' => 'Ср',
            'чт', 'четвер', 'thursday', 'thu' => 'Чт',
            'пт', 'пʼятниця', 'пятниця', 'friday', 'fri' => 'Пт',
            'сб', 'субота', 'saturday', 'sat' => 'Сб',
            'нд', 'неділя', 'sunday', 'sun' => 'Нд',
            default => null,
        };
    }

    private function normalizeStartTime(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[\.:](\d{2})$/', $raw, $match)) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }

        if (preg_match('/^(\d{1,2})$/', $raw, $match)) {
            $hours = (int) $match[1];
            if ($hours >= 0 && $hours <= 23) {
                return sprintf('%02d:00', $hours);
            }
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $match)) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return sprintf('%02d:%02d', $hours, $minutes);
            }
        }

        return null;
    }

    private function normalizeTaskDueDateTime(mixed $dateValue, mixed $startTimeValue): ?string
    {
        $date = $this->normalizeDate($dateValue);
        if ($date === null) {
            return null;
        }

        $startTime = $this->normalizeStartTime($startTimeValue);
        if ($startTime === null) {
            return $date;
        }

        return $date . ' ' . $startTime . ':00';
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function formatShortTelegramDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('d.m.y', $timestamp);
    }

    private function formatTelegramStatusMarker(string $status, bool $completed): string
    {
        if ($completed) {
            return '✅';
        }

        return match (strtolower(trim($status))) {
            'done' => '✅',
            'postponed' => '⏸️',
            'in-progress' => '🔵',
            'todo' => '📌',
            default => '🔵',
        };
    }
}