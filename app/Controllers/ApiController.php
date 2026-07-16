<?php

namespace App\Controllers;

use App\Models\Company;
use App\Models\Database;
use App\Models\Project;
use App\Models\Result;
use App\Models\Task;
use App\Models\Template;
use App\Models\WeeklyPlan;

class ApiController
{
    private Database $db;
    private Task $taskModel;
    private Result $resultModel;
    private Template $templateModel;
    private WeeklyPlan $weeklyPlanModel;
    private Company $companyModel;
    private Project $projectModel;

    public function __construct()
    {
        $this->db = new Database();
        $this->taskModel = new Task();
        $this->resultModel = new Result();
        $this->templateModel = new Template();
        $this->weeklyPlanModel = new WeeklyPlan();
        $this->companyModel = new Company();
        $this->projectModel = new Project();
        $this->ensureConnectorLogsTable();
    }

    public function handle(string $method, array $parts, array $context): void
    {
        if (!defined('ENABLE_MCP_CONNECTOR') || ENABLE_MCP_CONNECTOR !== true) {
            json_response(['ok' => false, 'error' => 'MCP connector is disabled.'], 503);
        }

        $resource = $parts[2] ?? '';
        $id = $parts[3] ?? null;

        if ($resource === 'tasks') {
            if ($method === 'GET' && $id === null) {
                $this->listTasks($context);
                return;
            }
            if ($method === 'POST' && $id === null) {
                $this->createTask($context);
                return;
            }
            if ($method === 'GET' && $id !== null) {
                $this->getTask((int) $id, $context);
                return;
            }
            if ($method === 'PATCH' && $id !== null) {
                $this->updateTask((int) $id, $context);
                return;
            }
            if ($method === 'DELETE' && $id !== null) {
                $this->deleteTask((int) $id, $context);
                return;
            }
        }

        if ($resource === 'results') {
            if ($method === 'GET' && $id === null) {
                $this->listResults($context);
                return;
            }
            if ($method === 'POST' && $id === null) {
                $this->createResult($context);
                return;
            }
            if ($method === 'GET' && $id !== null) {
                $this->getResult((int) $id, $context);
                return;
            }
            if ($method === 'PATCH' && $id !== null) {
                $this->updateResult((int) $id, $context);
                return;
            }
            if ($method === 'DELETE' && $id !== null) {
                $this->deleteResult((int) $id, $context);
                return;
            }
        }

        if ($resource === 'templates') {
            if ($method === 'GET' && $id === null) {
                $this->listTemplates($context);
                return;
            }
            if ($method === 'POST' && $id === null) {
                $this->createTemplate($context);
                return;
            }
            if ($method === 'PATCH' && $id !== null) {
                $this->updateTemplate((int) $id, $context);
                return;
            }
            if ($method === 'DELETE' && $id !== null) {
                $this->deleteTemplate((int) $id, $context);
                return;
            }
        }

        if ($resource === 'weekly-plans') {
            $subResource = $parts[3] ?? null;
            $subId = $parts[4] ?? null;

            if ($method === 'GET' && $subResource === null) {
                $this->getWeeklyPlan($context);
                return;
            }

            if ($method === 'POST' && $subResource === 'items') {
                $this->addWeeklyPlanItem($context);
                return;
            }

            if ($method === 'PATCH' && $subResource === 'items' && $subId !== null) {
                $this->updateWeeklyPlanItem((int) $subId, $context);
                return;
            }

            if ($method === 'DELETE' && $subResource === 'items' && $subId !== null) {
                $this->deleteWeeklyPlanItem((int) $subId, $context);
                return;
            }

            if ($method === 'GET' && $subResource === 'summary') {
                $this->getPlanSummary($context);
                return;
            }
        }

        if ($resource === 'projects') {
            if ($method === 'GET' && $id === null) {
                $this->listProjects($context);
                return;
            }
            if ($method === 'GET' && $id !== null) {
                $this->getProject((int) $id, $context);
                return;
            }
            if ($method === 'POST' && $id === null) {
                $this->createProject($context);
                return;
            }
            if ($method === 'PATCH' && $id !== null) {
                $this->updateProject((int) $id, $context);
                return;
            }
            if ($method === 'DELETE' && $id !== null) {
                $this->deleteProject((int) $id, $context);
                return;
            }
        }

        if ($resource === 'company' && ($parts[3] ?? '') === 'members' && $method === 'GET') {
            $this->listTeamMembers($context);
            return;
        }

        if ($resource === 'dashboard' && ($parts[3] ?? '') === 'summary' && $method === 'GET') {
            $this->getDashboardSummary($context);
            return;
        }

        json_response(['ok' => false, 'error' => 'Route not found.'], 404);
    }

    private function listTasks(array $context): void
    {
        $companyId = (int) $context['company_id'];
        $status = strtolower(trim((string) get_param('status', '')));
        $assigneeId = (int) get_param('assignee_id', '0');
        $resultId = (int) get_param('result_id', '0');
        $dateFrom = trim((string) get_param('date_from', ''));
        $dateTo = trim((string) get_param('date_to', ''));
        $titleSearch = trim((string) get_param('title_search', ''));
        $limit = (int) get_param('limit', '20');
        if ($limit <= 0) {
            $limit = 20;
        }
        $limit = min($limit, 100);

        $tasks = $this->taskModel->get_by_company($companyId);
        $filtered = array_values(array_filter($tasks, function ($task) use ($status, $assigneeId, $resultId, $dateFrom, $dateTo, $titleSearch) {
            $internalStatus = strtolower(trim((string) ($task['status'] ?? 'todo')));
            $externalStatus = $this->mapInternalTaskStatusToExternal($internalStatus);

            if ($status !== '' && $status !== $externalStatus) {
                return false;
            }

            if ($assigneeId > 0 && (int) ($task['assignee_id'] ?? 0) !== $assigneeId) {
                return false;
            }

            if ($resultId > 0 && (int) ($task['result_id'] ?? 0) !== $resultId) {
                return false;
            }

            $taskDate = '';
            if (!empty($task['due_date'])) {
                $taskDate = date('Y-m-d', strtotime((string) $task['due_date']));
            }

            if ($dateFrom !== '' && $taskDate !== '' && $taskDate < $dateFrom) {
                return false;
            }

            if ($dateTo !== '' && $taskDate !== '' && $taskDate > $dateTo) {
                return false;
            }

            if ($titleSearch !== '' && !str_contains(mb_strtolower((string) ($task['title'] ?? '')), mb_strtolower($titleSearch))) {
                return false;
            }

            return true;
        }));

        $resultTasks = array_map(function ($task) {
            return [
                'id' => (int) ($task['id'] ?? 0),
                'title' => (string) ($task['title'] ?? ''),
                'status' => $this->mapInternalTaskStatusToExternal((string) ($task['status'] ?? 'todo')),
                'assignee' => [
                    'id' => (int) ($task['assignee_id'] ?? 0),
                    'name' => trim((string) (($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''))),
                ],
                'due_date' => !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null,
                'result_id' => !empty($task['result_id']) ? (int) $task['result_id'] : null,
                'description' => $task['description'] ?? null,
                'expected_result' => $task['expected_result'] ?? null,
                'expected_time' => isset($task['expected_time']) ? (int) $task['expected_time'] : null,
                'actual_time' => isset($task['actual_time']) ? (int) $task['actual_time'] : null,
                'actual_result' => $task['actual_result'] ?? null,
                'created_at' => $task['created_at'] ?? null,
                'updated_at' => $task['updated_at'] ?? null,
                'type' => $task['type'] ?? null,
                'reporter_id' => isset($task['reporter_id']) ? (int) $task['reporter_id'] : null,
            ];
        }, array_slice($filtered, 0, $limit));

        $totalExpectedTime = array_sum(array_map(fn($t) => (int) ($t['expected_time'] ?? 0), $resultTasks));

        $this->logConnector('list_tasks', ['query' => $_GET], ['total' => count($filtered), 'returned' => count($resultTasks)], 'ok', null, $context, 0);

        json_response([
            'tasks' => $resultTasks,
            'total' => count($filtered),
            'total_expected_time' => $totalExpectedTime,
        ]);
    }

    private function createTask(array $context): void
    {
        $started = microtime(true);
        $payload = $this->jsonBody();
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            json_response(['ok' => false, 'error' => 'Field title is required.'], 422);
        }
        $expectedResult = trim((string) ($payload['expected_result'] ?? ''));
        if ($expectedResult === '') {
            json_response(['ok' => false, 'error' => 'Field expected_result is required.'], 422);
        }
        $actualTime = (int) ($payload['actual_time'] ?? 0);
        if ($actualTime <= 0) {
            json_response(['ok' => false, 'error' => 'Field actual_time is required and must be > 0.'], 422);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            $idempotentResponse = $this->findIdempotentResponse('create_task', $idempotencyKey, $context);
            if ($idempotentResponse !== null) {
                $this->respondIdempotencyHit($idempotentResponse);
            }
        }

        $companyId = (int) $context['company_id'];
        $userId = (int) ($context['user']['id'] ?? 0);

        $assigneeId = (int) ($payload['assignee_id'] ?? 0);
        if (!$this->isUserInCompany($assigneeId, $companyId)) {
            $assigneeId = $userId;
        }

        $resultId = isset($payload['result_id']) ? (int) $payload['result_id'] : null;
        if ($resultId !== null && !$this->isResultInCompany($resultId, $companyId)) {
            json_response(['ok' => false, 'error' => 'result_id is not in current company scope.'], 422);
        }

        $priority = strtolower(trim((string) ($payload['priority'] ?? 'medium')));
        $type = match ($priority) {
            'high' => 'important-urgent',
            'low' => 'not-important-not-urgent',
            default => 'important-not-urgent',
        };

        $dueDate = $this->normalizeApiDate((string) ($payload['due_date'] ?? ''));
        $dueDateTime = $dueDate !== null ? ($dueDate . ' 10:00:00') : null;

        $preview = [
            'title' => $title,
            'description' => $payload['description'] ?? null,
            'assignee_id' => $assigneeId,
            'result_id' => $resultId,
            'due_date' => $dueDate,
            'priority' => $priority,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $response = ['task_id' => null, 'title' => $title, 'url' => null, 'dry_run' => true, 'preview' => $preview];
            $this->logConnector('create_task', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->taskModel->create([
            'title' => $title,
            'company_id' => $companyId,
            'assignee_id' => $assigneeId,
            'reporter_id' => $userId,
            'status' => 'todo',
            'due_date' => $dueDateTime,
            'description' => $payload['description'] ?? null,
            'expected_result' => $expectedResult,
            'expected_time' => $actualTime,
            'type' => $type,
            'result_id' => $resultId,
        ]);

        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to create task.'], 500);
        }

        $taskId = (int) $this->db->lastInsertId();
        $this->setTaskSource($taskId, 'mcp_claude');

        $response = [
            'task_id' => $taskId,
            'title' => $title,
            'url' => '/tasks/view/' . $taskId,
            'dry_run' => false,
        ];

        $logInput = $payload;
        if ($idempotencyKey !== '') {
            $logInput['idempotency_key'] = $idempotencyKey;
        }
        $this->logConnector('create_task', $logInput, $response, 'ok', null, $context, $this->durationMs($started));

        json_response($response);
    }

    private function getTask(int $taskId, array $context): void
    {
        $task = $this->taskModel->get_by_id($taskId);
        if (!$task || (int) ($task['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Task not found.'], 404);
        }

        $response = [
            'id' => (int) ($task['id'] ?? 0),
            'title' => (string) ($task['title'] ?? ''),
            'description' => $task['description'] ?? null,
            'status' => $this->mapInternalTaskStatusToExternal((string) ($task['status'] ?? 'todo')),
            'assignee' => [
                'id' => (int) ($task['assignee_id'] ?? 0),
                'name' => trim((string) (($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''))),
            ],
            'reporter' => [
                'id' => (int) ($task['reporter_id'] ?? 0),
                'name' => trim((string) (($task['reporter_first_name'] ?? '') . ' ' . ($task['reporter_last_name'] ?? ''))),
            ],
            'result' => !empty($task['result_id']) ? [
                'id' => (int) $task['result_id'],
                'title' => (string) ($task['result_title'] ?? ''),
            ] : null,
            'due_date' => !empty($task['due_date']) ? date('Y-m-d', strtotime((string) $task['due_date'])) : null,
            'created_at' => $task['created_at'] ?? null,
            'source' => $task['source'] ?? 'web',
        ];

        $this->logConnector('get_task', ['task_id' => $taskId], $response, 'ok', null, $context, 0);
        json_response($response);
    }

    private function updateTask(int $taskId, array $context): void
    {
        $started = microtime(true);
        $task = $this->taskModel->get_by_id($taskId);
        if (!$task || (int) ($task['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Task not found.'], 404);
        }

        $payload = $this->jsonBody();
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            json_response(['ok' => false, 'error' => 'fields is required.'], 422);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $updateData = [];
        $updatedFields = [];
        if (array_key_exists('expected_result', $fields)) {
            $expectedResult = trim((string) $fields['expected_result']);
            if ($expectedResult === '') {
                json_response(['ok' => false, 'error' => 'Field expected_result is required.'], 422);
            }
        }
        if (array_key_exists('actual_time', $fields)) {
            $actualTime = (int) $fields['actual_time'];
            if ($actualTime <= 0) {
                json_response(['ok' => false, 'error' => 'Field actual_time is required and must be > 0.'], 422);
            }
        }

        if (array_key_exists('status', $fields)) {
            $updateData['status'] = $this->mapExternalTaskStatusToInternal((string) $fields['status']);
            $updatedFields[] = 'status';
        }

        if (array_key_exists('title', $fields)) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                json_response(['ok' => false, 'error' => 'title cannot be empty.'], 422);
            }
            $updateData['title'] = $title;
            $updatedFields[] = 'title';
        }

        if (array_key_exists('assignee_id', $fields)) {
            $assigneeId = (int) $fields['assignee_id'];
            if (!$this->isUserInCompany($assigneeId, (int) $context['company_id'])) {
                json_response(['ok' => false, 'error' => 'assignee_id is outside current company.'], 422);
            }
            $updateData['assignee_id'] = $assigneeId;
            $updatedFields[] = 'assignee_id';
        }

        if (array_key_exists('due_date', $fields)) {
            $dueDate = $this->normalizeApiDate((string) $fields['due_date']);
            if ($dueDate === null) {
                json_response(['ok' => false, 'error' => 'due_date must be YYYY-MM-DD.'], 422);
            }
            $updateData['due_date'] = $dueDate . ' 10:00:00';
            $updatedFields[] = 'due_date';
        }

        if (array_key_exists('description', $fields)) {
            $updateData['description'] = (string) $fields['description'];
            $updatedFields[] = 'description';
        }

        if ($dryRun) {
            $response = ['task_id' => $taskId, 'updated_fields' => $updatedFields, 'dry_run' => true, 'preview' => $updateData];
            $this->logConnector('update_task', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        if (empty($updateData)) {
            json_response(['ok' => false, 'error' => 'No valid fields to update.'], 422);
        }

        $ok = $this->taskModel->update($taskId, $updateData);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to update task.'], 500);
        }

        $response = ['task_id' => $taskId, 'updated_fields' => $updatedFields, 'dry_run' => false];
        $this->logConnector('update_task', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function deleteTask(int $taskId, array $context): void
    {
        $started = microtime(true);
        $task = $this->taskModel->get_by_id($taskId);
        if (!$task || (int) ($task['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Task not found.'], 404);
        }

        $payload = $this->jsonBody();
        $confirm = (bool) ($payload['confirm'] ?? false);
        $dryRun = (bool) ($payload['dry_run'] ?? false);

        if (!$confirm) {
            json_response(['ok' => false, 'error' => 'confirm=true is required for delete_task.'], 422);
        }

        if ($dryRun) {
            $response = ['task_id' => $taskId, 'deleted' => false, 'dry_run' => true, 'preview' => ['title' => (string) ($task['title'] ?? '')]];
            $this->logConnector('delete_task', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->taskModel->delete($taskId);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to delete task.'], 500);
        }

        $response = ['task_id' => $taskId, 'deleted' => true];
        $this->logConnector('delete_task', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function listResults(array $context): void
    {
        $includeChildren = filter_var(get_param('include_children', 'true'), FILTER_VALIDATE_BOOLEAN);
        $status = strtolower(trim((string) get_param('status', '')));

        $results = $this->resultModel->get_by_company((int) $context['company_id']);
        $children = $this->resultModel->get_all_sub_results((int) $context['company_id']);

        $childrenMap = [];
        foreach ($children as $item) {
            $childrenMap[(int) ($item['parent_id'] ?? 0)][] = $item;
        }

        $filteredParents = array_values(array_filter($results, function ($result) use ($status) {
            if ($status === '') {
                return true;
            }

            $mapped = $this->mapInternalResultStatusToExternal((string) ($result['status'] ?? 'in-progress'), (int) ($result['completed'] ?? 0));
            return $mapped === $status;
        }));

        $buildNode = function (array $row) use (&$buildNode, $includeChildren, $childrenMap): array {
            $id = (int) ($row['id'] ?? 0);
            $childrenRows = $includeChildren ? ($childrenMap[$id] ?? []) : [];

            return [
                'id' => $id,
                'title' => (string) ($row['title'] ?? ''),
                'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
                'status' => $this->mapInternalResultStatusToExternal((string) ($row['status'] ?? 'in-progress'), (int) ($row['completed'] ?? 0)),
                'children' => array_map(static function ($child) use (&$buildNode) {
                    return $buildNode($child);
                }, $childrenRows),
            ];
        };

        $tree = array_map(static function ($parent) use ($buildNode) {
            return $buildNode($parent);
        }, $filteredParents);

        $response = ['results' => $tree];
        $this->logConnector('list_results', ['query' => $_GET], ['total' => count($tree)], 'ok', null, $context, 0);
        json_response($response);
    }

    private function getResult(int $resultId, array $context): void
    {
        $result = $this->resultModel->get_by_id($resultId);
        if (!$result || (int) ($result['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Result not found.'], 404);
        }

        $response = [
            'id' => (int) ($result['id'] ?? 0),
            'title' => (string) ($result['title'] ?? ''),
            'description' => $result['description'] ?? null,
            'expected_result' => $result['expected_result'] ?? null,
            'status' => $this->mapInternalResultStatusToExternal((string) ($result['status'] ?? 'in-progress'), (int) ($result['completed'] ?? 0)),
            'parent_id' => !empty($result['parent_id']) ? (int) $result['parent_id'] : null,
            'assignee' => [
                'id' => (int) ($result['assignee_id'] ?? 0),
                'name' => trim((string) (($result['assignee_first_name'] ?? '') . ' ' . ($result['assignee_last_name'] ?? ''))),
            ],
            'reporter' => [
                'id' => (int) ($result['reporter_id'] ?? 0),
                'name' => trim((string) (($result['reporter_first_name'] ?? '') . ' ' . ($result['reporter_last_name'] ?? ''))),
            ],
            'created_at' => $result['created_at'] ?? null,
        ];

        $this->logConnector('get_result', ['result_id' => $resultId], $response, 'ok', null, $context, 0);
        json_response($response);
    }

    private function createResult(array $context): void
    {
        $started = microtime(true);
        $payload = $this->jsonBody();
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            json_response(['ok' => false, 'error' => 'Field title is required.'], 422);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $parentId = isset($payload['parent_id']) ? (int) $payload['parent_id'] : null;
        if ($parentId !== null && !$this->isResultInCompany($parentId, (int) $context['company_id'])) {
            json_response(['ok' => false, 'error' => 'parent_id is outside current company.'], 422);
        }

        if ($idempotencyKey !== '') {
            $idempotentResponse = $this->findIdempotentResponse('create_result', $idempotencyKey, $context);
            if ($idempotentResponse !== null) {
                $this->respondIdempotencyHit($idempotentResponse);
            }
        }

        if ($dryRun) {
            $response = [
                'result_id' => null,
                'title' => $title,
                'parent_id' => $parentId,
                'url' => null,
                'dry_run' => true,
            ];
            $this->logConnector('create_result', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->resultModel->create([
            'title' => $title,
            'company_id' => (int) $context['company_id'],
            'assignee_id' => (int) ($context['user']['id'] ?? 0),
            'reporter_id' => (int) ($context['user']['id'] ?? 0),
            'description' => $payload['description'] ?? null,
            'expected_result' => $payload['expected_result'] ?? null,
            'deadline' => null,
            'status' => 'in-progress',
            'parent_id' => $parentId,
            'completed' => 0,
        ]);

        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to create result.'], 500);
        }

        $resultId = (int) $this->resultModel->lastInsertId();
        $response = [
            'result_id' => $resultId,
            'title' => $title,
            'parent_id' => $parentId,
            'url' => '/results/view/' . $resultId,
        ];

        $logInput = $payload;
        if ($idempotencyKey !== '') {
            $logInput['idempotency_key'] = $idempotencyKey;
        }
        $this->logConnector('create_result', $logInput, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function updateResult(int $resultId, array $context): void
    {
        $started = microtime(true);
        $result = $this->resultModel->get_by_id($resultId);
        if (!$result || (int) ($result['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Result not found.'], 404);
        }

        $payload = $this->jsonBody();
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            json_response(['ok' => false, 'error' => 'fields is required.'], 422);
        }

        $update = [];
        if (array_key_exists('title', $fields)) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                json_response(['ok' => false, 'error' => 'title cannot be empty.'], 422);
            }
            $update['title'] = $title;
        }

        if (array_key_exists('status', $fields)) {
            $status = strtolower(trim((string) $fields['status']));
            if (!in_array($status, ['active', 'done', 'archived'], true)) {
                json_response(['ok' => false, 'error' => 'Invalid status for result.'], 422);
            }
            $update['status'] = $this->mapExternalResultStatusToInternal($status);
            $update['completed'] = $status === 'done' ? 1 : 0;
        }

        if (empty($update)) {
            json_response(['ok' => false, 'error' => 'No valid fields to update.'], 422);
        }

        $ok = $this->resultModel->update($resultId, $update);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to update result.'], 500);
        }

        $response = ['result_id' => $resultId, 'updated_fields' => array_keys($update)];
        $this->logConnector('update_result', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function deleteResult(int $resultId, array $context): void
    {
        $started = microtime(true);
        $result = $this->resultModel->get_by_id($resultId);
        if (!$result || (int) ($result['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Result not found.'], 404);
        }

        $payload = $this->jsonBody();
        $confirm = (bool) ($payload['confirm'] ?? false);
        $dryRun = (bool) ($payload['dry_run'] ?? false);

        if (!$confirm) {
            json_response(['ok' => false, 'error' => 'confirm=true is required for delete_result.'], 422);
        }

        if ($dryRun) {
            $response = ['result_id' => $resultId, 'deleted' => false, 'dry_run' => true, 'preview' => ['title' => (string) ($result['title'] ?? '')]];
            $this->logConnector('delete_result', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->resultModel->delete($resultId);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to delete result.'], 500);
        }

        $response = ['result_id' => $resultId, 'deleted' => true];
        $this->logConnector('delete_result', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function listTemplates(array $context): void
    {
        $templates = $this->templateModel->get_by_company((int) $context['company_id']);
        $rows = array_map(static function ($template) {
            $assigneeIds = [];
            $raw = trim((string) ($template['assignee_ids'] ?? ''));
            if ($raw !== '') {
                $assigneeIds = array_values(array_filter(array_map('intval', explode(',', $raw)), static fn($id) => $id > 0));
            }

            return [
                'id' => (int) ($template['id'] ?? 0),
                'title' => (string) ($template['name'] ?? ''),
                'repeat_type' => (string) ($template['repeat_type'] ?? 'none'),
                'repeat_day' => $template['repeat_day'] ?? null,
                'assignee_ids' => $assigneeIds,
            ];
        }, $templates);

        $response = ['templates' => $rows];
        $this->logConnector('list_templates', ['query' => $_GET], ['total' => count($rows)], 'ok', null, $context, 0);
        json_response($response);
    }

    private function createTemplate(array $context): void
    {
        $started = microtime(true);
        $payload = $this->jsonBody();
        $title = trim((string) ($payload['title'] ?? ''));
        $repeatType = strtolower(trim((string) ($payload['repeat_type'] ?? 'none')));

        if ($title === '' || $repeatType === '') {
            json_response(['ok' => false, 'error' => 'title and repeat_type are required.'], 422);
        }

        if (!in_array($repeatType, ['daily', 'weekly', 'monthly', 'none'], true)) {
            json_response(['ok' => false, 'error' => 'repeat_type is invalid.'], 422);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            $idempotentResponse = $this->findIdempotentResponse('create_template', $idempotencyKey, $context);
            if ($idempotentResponse !== null) {
                $this->respondIdempotencyHit($idempotentResponse);
            }
        }

        $assigneeIds = [];
        if (!empty($payload['assignee_ids']) && is_array($payload['assignee_ids'])) {
            foreach ($payload['assignee_ids'] as $candidate) {
                $candidateId = (int) $candidate;
                if ($this->isUserInCompany($candidateId, (int) $context['company_id'])) {
                    $assigneeIds[$candidateId] = $candidateId;
                }
            }
        }

        if (empty($assigneeIds)) {
            $selfId = (int) ($context['user']['id'] ?? 0);
            $assigneeIds[$selfId] = $selfId;
        }

        $assigneeIds = array_values($assigneeIds);
        $primaryAssigneeId = (int) ($assigneeIds[0] ?? 0);
        $repeatDay = isset($payload['repeat_day']) ? (string) $payload['repeat_day'] : null;

        if ($dryRun) {
            $response = [
                'template_id' => null,
                'title' => $title,
                'repeat_type' => $repeatType,
                'repeat_day' => $repeatDay,
                'assignee_ids' => $assigneeIds,
                'dry_run' => true,
            ];
            $this->logConnector('create_template', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->templateModel->create([
            'company_id' => (int) $context['company_id'],
            'name' => $title,
            'type' => null,
            'description' => $payload['description'] ?? null,
            'expected_result' => null,
            'assignee_id' => $primaryAssigneeId,
            'assignee_ids' => implode(',', $assigneeIds),
            'reporter_id' => (int) ($context['user']['id'] ?? 0),
            'expected_time' => null,
            'repeat_type' => $repeatType,
            'repeat_day' => $repeatDay,
            'start_time' => $payload['start_time'] ?? null,
        ]);

        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to create template.'], 500);
        }

        $templateId = (int) $this->db->lastInsertId();
        $response = [
            'template_id' => $templateId,
            'title' => $title,
            'repeat_type' => $repeatType,
            'repeat_day' => $repeatDay,
            'assignee_ids' => $assigneeIds,
            'dry_run' => false,
        ];

        $logInput = $payload;
        if ($idempotencyKey !== '') {
            $logInput['idempotency_key'] = $idempotencyKey;
        }
        $this->logConnector('create_template', $logInput, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function updateTemplate(int $templateId, array $context): void
    {
        $started = microtime(true);
        $template = $this->templateModel->get_by_id($templateId);
        if (!$template || (int) ($template['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Template not found.'], 404);
        }

        $payload = $this->jsonBody();
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            json_response(['ok' => false, 'error' => 'fields is required.'], 422);
        }

        $update = [];
        if (array_key_exists('title', $fields)) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                json_response(['ok' => false, 'error' => 'title cannot be empty.'], 422);
            }
            $update['name'] = $title;
        }

        if (array_key_exists('repeat_type', $fields)) {
            $repeatType = strtolower(trim((string) $fields['repeat_type']));
            if (!in_array($repeatType, ['daily', 'weekly', 'monthly', 'none'], true)) {
                json_response(['ok' => false, 'error' => 'repeat_type is invalid.'], 422);
            }
            $update['repeat_type'] = $repeatType;
        }

        if (array_key_exists('repeat_day', $fields)) {
            $update['repeat_day'] = $fields['repeat_day'] !== null && $fields['repeat_day'] !== '' ? (int) $fields['repeat_day'] : null;
        }

        if (array_key_exists('start_time', $fields)) {
            $time = $this->normalizeApiTime((string) $fields['start_time']);
            if ($fields['start_time'] !== null && (string) $fields['start_time'] !== '' && $time === null) {
                json_response(['ok' => false, 'error' => 'start_time must be HH:MM.'], 422);
            }
            $update['start_time'] = $time;
        }

        if (array_key_exists('assignee_ids', $fields) && is_array($fields['assignee_ids'])) {
            $assigneeIds = [];
            foreach ($fields['assignee_ids'] as $candidate) {
                $candidateId = (int) $candidate;
                if ($this->isUserInCompany($candidateId, (int) $context['company_id'])) {
                    $assigneeIds[$candidateId] = $candidateId;
                }
            }
            $assigneeIds = array_values($assigneeIds);
            if (!empty($assigneeIds)) {
                $update['assignee_ids'] = implode(',', $assigneeIds);
                $update['assignee_id'] = (int) $assigneeIds[0];
            }
        }

        if (empty($update)) {
            json_response(['ok' => false, 'error' => 'No valid fields to update.'], 422);
        }

        $ok = $this->templateModel->update($templateId, $update);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to update template.'], 500);
        }

        $response = ['template_id' => $templateId, 'updated_fields' => array_keys($update)];
        $this->logConnector('update_template', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function deleteTemplate(int $templateId, array $context): void
    {
        $started = microtime(true);
        $template = $this->templateModel->get_by_id($templateId);
        if (!$template || (int) ($template['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Template not found.'], 404);
        }

        $payload = $this->jsonBody();
        $confirm = (bool) ($payload['confirm'] ?? false);
        $dryRun = (bool) ($payload['dry_run'] ?? false);

        if (!$confirm) {
            json_response(['ok' => false, 'error' => 'confirm=true is required for delete_template.'], 422);
        }

        if ($dryRun) {
            $response = ['template_id' => $templateId, 'deleted' => false, 'dry_run' => true, 'preview' => ['title' => (string) ($template['name'] ?? '')]];
            $this->logConnector('delete_template', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $ok = $this->templateModel->delete($templateId);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to delete template.'], 500);
        }

        $response = ['template_id' => $templateId, 'deleted' => true];
        $this->logConnector('delete_template', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function getWeeklyPlan(array $context): void
    {
        $weekStart = $this->normalizeWeekStartDate((string) get_param('week_start_date', ''));
        $companyId = (int) $context['company_id'];
        $userId = (int) ($context['user']['id'] ?? 0);

        $plan = $this->weeklyPlanModel->findByUserAndWeek($companyId, $userId, $weekStart);
        if (!$plan) {
            $emptyResponse = [
                'plan_id' => null,
                'week_start' => $weekStart,
                'items' => [],
            ];
            $this->logConnector('get_weekly_plan', ['week_start_date' => $weekStart], ['items' => 0], 'ok', null, $context, 0);
            json_response($emptyResponse);
        }

        $items = $this->weeklyPlanModel->getItems((int) ($plan['id'] ?? 0));
        $responseItems = array_map(function ($item) {
            return [
                'id' => (int) ($item['id'] ?? 0),
                'day_of_week' => (int) ($item['weekday_index'] ?? 1),
                'time' => !empty($item['start_time']) ? substr((string) $item['start_time'], 0, 5) : null,
                'title' => (string) ($item['title'] ?? ''),
                'done' => strtolower(trim((string) ($item['task_status'] ?? ''))) === 'done',
                'fact_notes' => $item['actual_result'] ?? null,
                'linked_task' => !empty($item['linked_task_id']) ? [
                    'id' => (int) $item['linked_task_id'],
                    'status' => $this->mapInternalTaskStatusToExternal((string) ($item['task_status'] ?? 'todo')),
                ] : null,
                'description' => $item['description'] ?? null,
                'expected_result' => $item['expected_result'] ?? null,
                'expected_time' => isset($item['expected_time']) ? (int) $item['expected_time'] : null,
                'actual_time' => isset($item['actual_time']) ? (int) $item['actual_time'] : null,
                'actual_result' => $item['actual_result'] ?? null,
                'type' => $item['type'] ?? null,
                'result_id' => isset($item['result_id']) ? (int) $item['result_id'] : null,
                'assignee_id' => isset($item['assignee_id']) ? (int) $item['assignee_id'] : null,
            ];
        }, $items);

        $totalExpectedTime = array_sum(array_map(fn($i) => (int) ($i['expected_time'] ?? 0), $responseItems));

        $response = [
            'plan_id' => (int) ($plan['id'] ?? 0),
            'week_start' => (string) ($plan['week_start_date'] ?? $weekStart),
            'items' => $responseItems,
            'total_expected_time' => $totalExpectedTime,
        ];
        $this->logConnector('get_weekly_plan', ['week_start_date' => $weekStart], ['items' => count($responseItems)], 'ok', null, $context, 0);
        json_response($response);
    }

    private function addWeeklyPlanItem(array $context): void
    {
        $started = microtime(true);
        $payload = $this->jsonBody();

        $title = trim((string) ($payload['title'] ?? ''));
        $dayOfWeek = (int) ($payload['day_of_week'] ?? 0);
        $expectedResult = trim((string) ($payload['expected_result'] ?? ''));
        $actualTime = (int) ($payload['actual_time'] ?? 0);
        if ($title === '' || $dayOfWeek < 1 || $dayOfWeek > 7) {
            json_response(['ok' => false, 'error' => 'title and day_of_week(1-7) are required.'], 422);
        }
        if ($expectedResult === '') {
            json_response(['ok' => false, 'error' => 'Field expected_result is required.'], 422);
        }
        if ($actualTime <= 0) {
            json_response(['ok' => false, 'error' => 'Field actual_time is required and must be > 0.'], 422);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $weekStart = $this->normalizeWeekStartDate((string) ($payload['week_start_date'] ?? ''));
        $plannedDate = date('Y-m-d', strtotime($weekStart . ' +' . ($dayOfWeek - 1) . ' days'));
        $time = $this->normalizeApiTime((string) ($payload['time'] ?? ''));

        if ($idempotencyKey !== '') {
            $idempotentResponse = $this->findIdempotentResponse('add_plan_item', $idempotencyKey, $context);
            if ($idempotentResponse !== null) {
                $this->respondIdempotencyHit($idempotentResponse);
            }
        }

        if ($dryRun) {
            $response = [
                'item_id' => null,
                'plan_id' => null,
                'day_of_week' => $dayOfWeek,
                'title' => $title,
                'dry_run' => true,
            ];
            $this->logConnector('add_plan_item', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $companyId = (int) $context['company_id'];
        $userId = (int) ($context['user']['id'] ?? 0);
        $planId = $this->weeklyPlanModel->createPlan($companyId, $userId, $userId, $weekStart, 'Created from MCP connector');
        $plan = $this->weeklyPlanModel->getById($companyId, $planId);

        if (!$plan) {
            json_response(['ok' => false, 'error' => 'Failed to resolve weekly plan.'], 500);
        }

        $itemId = $this->weeklyPlanModel->addManualItem($plan, [
            'planned_date' => $plannedDate,
            'start_time' => $time,
            'title' => $title,
            'description' => null,
            'assignee_id' => $userId,
            'reporter_id' => $userId,
            'type' => 'important-not-urgent',
            'expected_result' => $expectedResult,
            'expected_time' => $actualTime,
            'result_id' => null,
        ]);

        if ($itemId <= 0) {
            json_response(['ok' => false, 'error' => 'Failed to add plan item.'], 500);
        }

        if (!empty($payload['linked_task_id'])) {
            $linkedTaskId = (int) $payload['linked_task_id'];
            $this->db->query('UPDATE weekly_plan_items SET linked_task_id = :linked_task_id WHERE id = :id AND weekly_plan_id = :plan_id')
                ->bind(':linked_task_id', $linkedTaskId)
                ->bind(':id', $itemId)
                ->bind(':plan_id', $planId)
                ->execute();
        }

        $response = [
            'item_id' => $itemId,
            'plan_id' => $planId,
            'day_of_week' => $dayOfWeek,
            'title' => $title,
        ];

        $logInput = $payload;
        if ($idempotencyKey !== '') {
            $logInput['idempotency_key'] = $idempotencyKey;
        }
        $this->logConnector('add_plan_item', $logInput, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function updateWeeklyPlanItem(int $itemId, array $context): void
    {
        $started = microtime(true);
        $payload = $this->jsonBody();
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            json_response(['ok' => false, 'error' => 'fields is required.'], 422);
        }

        $row = $this->db
            ->query('SELECT wpi.*, wp.company_id, wp.user_id FROM weekly_plan_items wpi JOIN weekly_plans wp ON wp.id = wpi.weekly_plan_id WHERE wpi.id = :id LIMIT 1')
            ->bind(':id', $itemId)
            ->fetch();

        if (!$row || (int) ($row['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Plan item not found.'], 404);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);

        if (array_key_exists('expected_result', $fields)) {
            $expectedResult = trim((string) $fields['expected_result']);
            if ($expectedResult === '') {
                json_response(['ok' => false, 'error' => 'Field expected_result is required.'], 422);
            }
        }
        if (array_key_exists('actual_time', $fields)) {
            $actualTime = (int) $fields['actual_time'];
            if ($actualTime <= 0) {
                json_response(['ok' => false, 'error' => 'Field actual_time is required and must be > 0.'], 422);
            }
        }

        $updates = [];
        $updatedFields = [];
        if (array_key_exists('time', $fields)) {
            $time = $this->normalizeApiTime((string) $fields['time']);
            if ($time === null) {
                json_response(['ok' => false, 'error' => 'time must be HH:MM.'], 422);
            }
            $updates['start_time'] = $time . ':00';
            $updatedFields[] = 'time';
        }

        if (array_key_exists('title', $fields)) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                json_response(['ok' => false, 'error' => 'title cannot be empty.'], 422);
            }
            $updates['title'] = $title;
            $updatedFields[] = 'title';
        }

        $linkedTaskId = (int) ($row['linked_task_id'] ?? 0);
        $taskUpdates = [];

        if (array_key_exists('done', $fields)) {
            $done = (bool) $fields['done'];
            if ($linkedTaskId > 0) {
                $taskUpdates['status'] = $done ? 'done' : 'in-progress';
            }
            $updatedFields[] = 'done';
        }

        if (array_key_exists('fact_notes', $fields)) {
            $factNotes = (string) $fields['fact_notes'];
            if ($linkedTaskId > 0) {
                $taskUpdates['actual_result'] = $factNotes;
            }
            $updatedFields[] = 'fact_notes';
        }

        if ($dryRun) {
            $response = ['item_id' => $itemId, 'updated_fields' => $updatedFields, 'dry_run' => true, 'preview' => ['item' => $updates, 'task' => $taskUpdates]];
            $this->logConnector('update_plan_item', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        if (!empty($updates)) {
            $this->db->update('weekly_plan_items', $itemId, $updates);
        }

        if ($linkedTaskId > 0 && !empty($taskUpdates)) {
            if (array_key_exists('time', $fields) && !empty($row['planned_date'])) {
                $taskUpdates['due_date'] = (string) $row['planned_date'] . ' ' . ($updates['start_time'] ?? '10:00:00');
            }
            if (array_key_exists('title', $fields)) {
                $taskUpdates['title'] = $updates['title'] ?? (string) $fields['title'];
            }
            $this->taskModel->update($linkedTaskId, $taskUpdates);
        }

        $response = ['item_id' => $itemId, 'updated_fields' => $updatedFields];
        $this->logConnector('update_plan_item', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function deleteWeeklyPlanItem(int $itemId, array $context): void
    {
        $started = microtime(true);
        $row = $this->db
            ->query('SELECT wpi.id, wpi.weekly_plan_id, wp.company_id FROM weekly_plan_items wpi JOIN weekly_plans wp ON wp.id = wpi.weekly_plan_id WHERE wpi.id = :id LIMIT 1')
            ->bind(':id', $itemId)
            ->fetch();

        if (!$row || (int) ($row['company_id'] ?? 0) !== (int) $context['company_id']) {
            json_response(['ok' => false, 'error' => 'Plan item not found.'], 404);
        }

        $payload = $this->jsonBody();
        $confirm = (bool) ($payload['confirm'] ?? false);
        $dryRun = (bool) ($payload['dry_run'] ?? false);

        if (!$confirm) {
            json_response(['ok' => false, 'error' => 'confirm=true is required for delete_plan_item.'], 422);
        }

        if ($dryRun) {
            $response = ['item_id' => $itemId, 'deleted' => false, 'dry_run' => true];
            $this->logConnector('delete_plan_item', $payload, $response, 'dry_run', null, $context, $this->durationMs($started));
            json_response($response);
        }

        $plan = $this->weeklyPlanModel->getById((int) $context['company_id'], (int) ($row['weekly_plan_id'] ?? 0));
        if (!$plan) {
            json_response(['ok' => false, 'error' => 'Weekly plan not found.'], 404);
        }

        $ok = $this->weeklyPlanModel->deleteItem($plan, $itemId);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Failed to delete plan item.'], 500);
        }

        $response = ['item_id' => $itemId, 'deleted' => true];
        $this->logConnector('delete_plan_item', $payload, $response, 'ok', null, $context, $this->durationMs($started));
        json_response($response);
    }

    private function getPlanSummary(array $context): void
    {
        $weekStart = $this->normalizeWeekStartDate((string) get_param('week_start_date', ''));
        $companyId = (int) $context['company_id'];
        $userId = (int) ($context['user']['id'] ?? 0);

        $plan = $this->weeklyPlanModel->findByUserAndWeek($companyId, $userId, $weekStart);
        if (!$plan) {
            $emptyResponse = [
                'week_start' => $weekStart,
                'planned' => 0,
                'done' => 0,
                'percent' => 0,
            ];
            $this->logConnector('get_plan_summary', ['week_start_date' => $weekStart], $emptyResponse, 'ok', null, $context, 0);
            json_response($emptyResponse);
        }

        $items = $this->weeklyPlanModel->getItems((int) ($plan['id'] ?? 0));
        $planned = count($items);
        $done = 0;
        foreach ($items as $item) {
            if (strtolower(trim((string) ($item['task_status'] ?? ''))) === 'done') {
                $done++;
            }
        }

        $percent = $planned > 0 ? (int) round(($done / $planned) * 100) : 0;

        $response = [
            'week_start' => (string) ($plan['week_start_date'] ?? $weekStart),
            'planned' => $planned,
            'done' => $done,
            'percent' => $percent,
        ];
        $this->logConnector('get_plan_summary', ['week_start_date' => $weekStart], $response, 'ok', null, $context, 0);
        json_response($response);
    }

    private function listTeamMembers(array $context): void
    {
        $members = $this->companyModel->get_employees((int) $context['company_id']);
        $result = array_map(static function ($member) {
            return [
                'id' => (int) ($member['user_id'] ?? 0),
                'name' => trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))),
                'email' => (string) ($member['email'] ?? ''),
                'role' => (string) ($member['role'] ?? 'member'),
            ];
        }, $members);

        $response = ['members' => $result];
        $this->logConnector('list_team_members', [], ['total' => count($result)], 'ok', null, $context, 0);
        json_response($response);
    }

    private function listProjects(array $context): void
    {
        $started = microtime(true);
        $companyId = (int) $context['company_id'];
        $projects = $this->projectModel->get_by_company($companyId);
        $result = array_map(static function ($p) {
            return [
                'id' => (int) ($p['id'] ?? 0),
                'name' => (string) ($p['name'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'status' => (string) ($p['status'] ?? 'active'),
                'created_by' => (int) ($p['created_by'] ?? 0),
                'member_count' => (int) ($p['member_count'] ?? 0),
                'created_at' => (string) ($p['created_at'] ?? ''),
            ];
        }, $projects);
        $this->logConnector('list_projects', [], ['total' => count($result)], 'ok', null, $context, $this->durationMs($started));
        json_response(['projects' => $result, 'total' => count($result)]);
    }

    private function getProject(int $projectId, array $context): void
    {
        $started = microtime(true);
        $companyId = (int) $context['company_id'];
        $project = $this->projectModel->get_by_id($projectId);
        if (!$project || (int) ($project['company_id'] ?? 0) !== $companyId) {
            json_response(['ok' => false, 'error' => 'Project not found.'], 404);
        }
        $members = $this->projectModel->get_members($projectId);
        $result = [
            'id' => (int) ($project['id'] ?? 0),
            'name' => (string) ($project['name'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
            'status' => (string) ($project['status'] ?? 'active'),
            'created_by' => (int) ($project['created_by'] ?? 0),
            'created_at' => (string) ($project['created_at'] ?? ''),
            'members' => array_map(static fn($m) => [
                'id' => (int) ($m['id'] ?? 0),
                'name' => trim((string) ($m['first_name'] ?? '') . ' ' . (string) ($m['last_name'] ?? '')),
                'email' => (string) ($m['email'] ?? ''),
            ], $members),
        ];
        $this->logConnector('get_project', ['project_id' => $projectId], ['id' => $result['id']], 'ok', null, $context, $this->durationMs($started));
        json_response($result);
    }

    private function deleteProject(int $projectId, array $context): void
    {
        $started = microtime(true);
        $companyId = (int) $context['company_id'];
        $project = $this->projectModel->get_by_id($projectId);
        if (!$project || (int) ($project['company_id'] ?? 0) !== $companyId) {
            json_response(['ok' => false, 'error' => 'Project not found.'], 404);
        }
        $stmt = $this->db->query("DELETE FROM projects WHERE id = :id AND company_id = :company_id");
        $stmt->bind(':id', $projectId)->bind(':company_id', $companyId)->execute();
        $this->logConnector('delete_project', ['project_id' => $projectId], ['deleted' => true], 'ok', null, $context, $this->durationMs($started));
        json_response(['ok' => true, 'deleted' => true, 'project_id' => $projectId]);
    }

    private function createProject(array $context): void
    {
        $started = microtime(true);
        $companyId = (int) $context['company_id'];
        $userId = (int) ($context['user']['id'] ?? 0);
        $body = $this->jsonBody();
        $dryRun = (bool) ($body['dry_run'] ?? false);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['ok' => false, 'error' => 'name is required.'], 422);
        }

        if ($dryRun) {
            $this->logConnector('create_project', $body, ['dry_run' => true, 'name' => $name], 'dry_run', null, $context, $this->durationMs($started));
            json_response(['ok' => true, 'dry_run' => true, 'name' => $name]);
        }

        $this->db->insert('projects', [
            'company_id' => $companyId,
            'name' => $name,
            'description' => trim((string) ($body['description'] ?? '')),
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $projectId = (int) $this->db->lastInsertId();
        if (!$projectId) {
            json_response(['ok' => false, 'error' => 'Failed to create project.'], 500);
        }

        $this->logConnector('create_project', $body, ['project_id' => $projectId], 'ok', null, $context, $this->durationMs($started));
        json_response(['ok' => true, 'project_id' => $projectId, 'name' => $name], 201);
    }

    private function updateProject(int $projectId, array $context): void
    {
        $started = microtime(true);
        $companyId = (int) $context['company_id'];
        $body = $this->jsonBody();
        $dryRun = (bool) ($body['dry_run'] ?? false);
        $fields = (array) ($body['fields'] ?? []);

        $project = $this->projectModel->get_by_id($projectId);
        if (!$project || (int) ($project['company_id'] ?? 0) !== $companyId) {
            json_response(['ok' => false, 'error' => 'Project not found.'], 404);
        }

        $allowed = ['name', 'description', 'status'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $updates[$key] = (string) $fields[$key];
            }
        }
        if (empty($updates)) {
            json_response(['ok' => false, 'error' => 'No valid fields to update.'], 422);
        }

        if ($dryRun) {
            $this->logConnector('update_project', $body, ['dry_run' => true, 'fields' => $updates], 'dry_run', null, $context, $this->durationMs($started));
            json_response(['ok' => true, 'dry_run' => true, 'fields' => $updates]);
        }

        $set = implode(', ', array_map(static fn($k) => "`$k` = :$k", array_keys($updates)));
        $stmt = $this->db->query("UPDATE projects SET $set WHERE id = :id AND company_id = :company_id");
        foreach ($updates as $k => $v) {
            $stmt->bind(':' . $k, $v);
        }
        $stmt->bind(':id', $projectId)->bind(':company_id', $companyId)->execute();

        $this->logConnector('update_project', $body, ['project_id' => $projectId, 'fields' => $updates], 'ok', null, $context, $this->durationMs($started));
        json_response(['ok' => true, 'project_id' => $projectId, 'updated_fields' => array_keys($updates)]);
    }

    private function getDashboardSummary(array $context): void
    {
        $period = strtolower(trim((string) get_param('period', 'week')));
        if (!in_array($period, ['today', 'week', 'month'], true)) {
            $period = 'week';
        }

        $tasks = $this->taskModel->get_by_company((int) $context['company_id']);
        $now = new \DateTimeImmutable('now');
        $start = match ($period) {
            'today' => $now->setTime(0, 0, 0),
            'month' => $now->modify('first day of this month')->setTime(0, 0, 0),
            default => $now->modify('monday this week')->setTime(0, 0, 0),
        };

        $tasks = array_values(array_filter($tasks, static function ($task) use ($start, $now) {
            $createdAt = !empty($task['created_at']) ? strtotime((string) $task['created_at']) : false;
            if ($createdAt === false) {
                return true;
            }

            return $createdAt >= $start->getTimestamp() && $createdAt <= $now->getTimestamp();
        }));

        $byStatus = [
            'open' => 0,
            'in_progress' => 0,
            'done' => 0,
            'cancelled' => 0,
        ];

        $overdue = 0;
        $topAssignees = [];

        foreach ($tasks as $task) {
            $status = $this->mapInternalTaskStatusToExternal((string) ($task['status'] ?? 'todo'));
            if (!isset($byStatus[$status])) {
                $status = 'open';
            }
            $byStatus[$status]++;

            $dueDate = !empty($task['due_date']) ? strtotime((string) $task['due_date']) : false;
            if ($dueDate !== false && $dueDate < time() && !in_array($status, ['done', 'cancelled'], true)) {
                $overdue++;
            }

            $assigneeId = (int) ($task['assignee_id'] ?? 0);
            if ($assigneeId > 0 && $status === 'done') {
                if (!isset($topAssignees[$assigneeId])) {
                    $topAssignees[$assigneeId] = [
                        'name' => trim((string) (($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''))),
                        'done' => 0,
                    ];
                }
                $topAssignees[$assigneeId]['done']++;
            }
        }

        usort($topAssignees, static function ($a, $b) {
            return (int) ($b['done'] ?? 0) <=> (int) ($a['done'] ?? 0);
        });

        $completed = $byStatus['done'];
        $total = array_sum($byStatus);
        $completionRate = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $response = [
            'period' => $period,
            'tasks_by_status' => $byStatus,
            'completion_rate' => $completionRate,
            'overdue' => $overdue,
            'top_assignees' => array_values(array_slice($topAssignees, 0, 5)),
        ];
        $this->logConnector('get_dashboard_summary', ['period' => $period], ['completion_rate' => $completionRate, 'overdue' => $overdue], 'ok', null, $context, 0);
        json_response($response);
    }

    private function mapExternalTaskStatusToInternal(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'open' => 'todo',
            'in_progress' => 'in-progress',
            'done' => 'done',
            'cancelled' => 'postponed',
            default => 'todo',
        };
    }

    private function mapInternalTaskStatusToExternal(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'todo' => 'open',
            'in-progress' => 'in_progress',
            'done' => 'done',
            'postponed' => 'cancelled',
            default => 'open',
        };
    }

    private function mapExternalResultStatusToInternal(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active' => 'in-progress',
            'done' => 'done',
            'archived' => 'postponed',
            default => 'in-progress',
        };
    }

    private function mapInternalResultStatusToExternal(string $status, int $completed): string
    {
        if ($completed === 1 || strtolower(trim($status)) === 'done') {
            return 'done';
        }

        return match (strtolower(trim($status))) {
            'postponed' => 'archived',
            default => 'active',
        };
    }

    private function isUserInCompany(int $userId, int $companyId): bool
    {
        if ($userId <= 0 || $companyId <= 0) {
            return false;
        }

        $row = $this->db
            ->query('SELECT id FROM company_members WHERE user_id = :user_id AND company_id = :company_id LIMIT 1')
            ->bind(':user_id', $userId)
            ->bind(':company_id', $companyId)
            ->fetch();

        return (bool) $row;
    }

    private function isResultInCompany(int $resultId, int $companyId): bool
    {
        if ($resultId <= 0 || $companyId <= 0) {
            return false;
        }

        $row = $this->db
            ->query('SELECT id FROM results WHERE id = :id AND company_id = :company_id LIMIT 1')
            ->bind(':id', $resultId)
            ->bind(':company_id', $companyId)
            ->fetch();

        return (bool) $row;
    }

    private function normalizeApiDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $obj = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$obj || $obj->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function normalizeApiTime(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        return $time;
    }

    private function normalizeWeekStartDate(string $weekStart): string
    {
        $normalized = $this->normalizeApiDate($weekStart);
        if ($normalized === null) {
            return date('Y-m-d', strtotime('monday this week'));
        }

        return date('Y-m-d', strtotime('monday this week', strtotime($normalized)));
    }

    private function setTaskSource(int $taskId, string $source): void
    {
        if ($taskId <= 0) {
            return;
        }

        try {
            $this->db->query('UPDATE tasks SET source = :source WHERE id = :id')
                ->bind(':source', $source)
                ->bind(':id', $taskId)
                ->execute();
        } catch (\Throwable $e) {
            // Ignore if source column is not yet migrated.
        }
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function durationMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function logConnector(string $action, array $input, array $output, string $status, ?string $errorMsg, array $context, int $durationMs): void
    {
        $safeStatus = in_array($status, ['ok', 'error', 'dry_run'], true) ? $status : 'ok';

        $this->db->insert('connector_logs', [
            'token_id' => (int) ($context['token_id'] ?? 0) ?: null,
            'user_id' => (int) ($context['user']['id'] ?? 0),
            'company_id' => (int) ($context['company_id'] ?? 0),
            'source' => 'mcp_claude',
            'action' => $action,
            'tool_input' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'tool_output' => json_encode($output, JSON_UNESCAPED_UNICODE),
            'status' => $safeStatus,
            'error_msg' => $errorMsg,
            'duration_ms' => $durationMs,
        ]);
    }

    private function findIdempotentResponse(string $action, string $idempotencyKey, array $context): ?array
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $row = $this->db
            ->query('SELECT tool_output FROM connector_logs WHERE token_id = :token_id AND action = :action AND JSON_UNQUOTE(JSON_EXTRACT(tool_input, "$.idempotency_key")) = :idempotency_key ORDER BY id DESC LIMIT 1')
            ->bind(':token_id', (int) ($context['token_id'] ?? 0))
            ->bind(':action', $action)
            ->bind(':idempotency_key', $idempotencyKey)
            ->fetch();

        if (!$row || empty($row['tool_output'])) {
            return null;
        }

        $decoded = json_decode((string) $row['tool_output'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function respondIdempotencyHit(array $response): void
    {
        header('X-Idempotency-Hit: true');
        json_response([
            'warning' => 'Idempotency key already processed. Returning existing resource.',
            'idempotency_hit' => true,
            'existing' => $response,
        ], 409);
    }

    private function ensureConnectorLogsTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS connector_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_id INT UNSIGNED NULL,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'mcp_claude',
            action VARCHAR(64) NOT NULL,
            tool_input JSON NULL,
            tool_output JSON NULL,
            status ENUM('ok','error','dry_run') NOT NULL DEFAULT 'ok',
            error_msg TEXT NULL,
            duration_ms INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_connector_token_time (token_id, created_at),
            KEY idx_connector_user_time (user_id, created_at),
            KEY idx_connector_company_action (company_id, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();
    }
}
